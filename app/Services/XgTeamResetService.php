<?php

namespace App\Services;

use App\Exceptions\LicenseResetException;
use App\Models\License;
use App\Models\LicenseReset;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class XgTeamResetService
{
    public const PROVIDER = 'xgteam';

    public function supports(License $license): bool
    {
        $product = $license->relationLoaded('product')
            ? $license->product
            : $license->product()->first();

        return $this->supportsProduct($product);
    }

    public function supportsProduct(?Product $product): bool
    {
        return $product && hash_equals(
            Str::lower(trim((string) config('services.xgteam.product_slug', 'xg-team'))),
            Str::lower(trim((string) $product->slug)),
        );
    }

    public function extractLicenseKey(string $credential): ?string
    {
        $licenseKey = trim($credential);

        if (preg_match('/\b(AksaXg-[A-Za-z0-9_-]+)\b/i', $licenseKey, $matches)) {
            $licenseKey = (string) $matches[1];
        }

        if (
            $licenseKey === '' ||
            mb_strlen($licenseKey) > 120 ||
            preg_match('/[\x00-\x1F\x7F\s]/u', $licenseKey)
        ) {
            return null;
        }

        return $licenseKey;
    }

    public function state(License $license): array
    {
        $supported = $this->supports($license);
        $licenseKey = $supported ? $this->extractLicenseKey((string) $license->license_key) : null;
        $order = $license->relationLoaded('order')
            ? $license->order
            : $license->order()->first();
        $isPaidPurchase = $order &&
            $order->status === 'paid' &&
            (int) $order->user_id === (int) $license->user_id;
        $lastReset = LicenseReset::query()
            ->where('license_id', $license->id)
            ->where('provider', self::PROVIDER)
            ->where('status', LicenseReset::STATUS_SUCCEEDED)
            ->whereNotNull('succeeded_at')
            ->latest('succeeded_at')
            ->first();
        $availableAt = $lastReset?->succeeded_at
            ? $lastReset->succeeded_at->copy()->addHours($this->cooldownHours())
            : null;
        $remainingSeconds = $availableAt?->isFuture()
            ? max(0, (int) now()->diffInSeconds($availableAt, false))
            : 0;

        return [
            'supported' => $supported,
            'provider' => self::PROVIDER,
            'provider_label' => 'XG Team',
            'identifier' => $licenseKey,
            'identifier_label' => 'license',
            'username' => $licenseKey,
            'is_paid_purchase' => (bool) $isPaidPurchase,
            'configured' => $this->isConfigured(),
            'available_at' => $availableAt,
            'remaining_seconds' => $remainingSeconds,
            'cooldown_hours' => $this->cooldownHours(),
            'can_reset' => $supported &&
                $isPaidPurchase &&
                $licenseKey !== null &&
                $this->isConfigured() &&
                $remainingSeconds === 0,
        ];
    }

    public function reset(License $license, User $user): LicenseReset
    {
        [$lockedLicense, $licenseKey, $attempt] = DB::transaction(function () use ($license, $user): array {
            $lockedLicense = License::with(['product', 'order'])
                ->whereKey($license->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedLicense->user_id !== (int) $user->id) {
                throw new LicenseResetException('This license does not belong to your account.', 'not_owned');
            }

            if (! $this->supports($lockedLicense)) {
                throw new LicenseResetException('HWID reset is not available for this product.', 'unsupported');
            }

            if (
                ! $lockedLicense->order ||
                $lockedLicense->order->status !== 'paid' ||
                (int) $lockedLicense->order->user_id !== (int) $user->id
            ) {
                throw new LicenseResetException('Only a paid XG Team license can be reset.', 'not_paid');
            }

            if (! $this->isConfigured()) {
                throw new LicenseResetException(
                    'HWID reset is temporarily unavailable. Please contact support.',
                    'not_configured',
                );
            }

            $licenseKey = $this->extractLicenseKey((string) $lockedLicense->license_key);

            if ($licenseKey === null) {
                throw new LicenseResetException(
                    'This XG Team license format cannot be reset automatically. Please contact support.',
                    'invalid_credential',
                );
            }

            $lastReset = LicenseReset::query()
                ->where('license_id', $lockedLicense->id)
                ->where('provider', self::PROVIDER)
                ->where('status', LicenseReset::STATUS_SUCCEEDED)
                ->whereNotNull('succeeded_at')
                ->latest('succeeded_at')
                ->lockForUpdate()
                ->first();
            $availableAt = $lastReset?->succeeded_at
                ? $lastReset->succeeded_at->copy()->addHours($this->cooldownHours())
                : null;

            if ($availableAt?->isFuture()) {
                throw new LicenseResetException(
                    'This license can be reset again in '.$this->humanRemaining($availableAt).'.',
                    'cooldown',
                    $availableAt,
                );
            }

            $hasRecentAttempt = LicenseReset::query()
                ->where('license_id', $lockedLicense->id)
                ->where('provider', self::PROVIDER)
                ->where('status', LicenseReset::STATUS_PENDING)
                ->where('created_at', '>=', now()->subMinutes($this->pendingTimeoutMinutes()))
                ->lockForUpdate()
                ->exists();

            if ($hasRecentAttempt) {
                throw new LicenseResetException(
                    'An HWID reset for this license is already in progress.',
                    'in_progress',
                );
            }

            $attempt = LicenseReset::create([
                'license_id' => $lockedLicense->id,
                'user_id' => $user->id,
                'provider' => self::PROVIDER,
                'username' => $licenseKey,
                'status' => LicenseReset::STATUS_PENDING,
            ]);

            return [$lockedLicense, $licenseKey, $attempt];
        });

        try {
            $response = Http::acceptJson()
                ->connectTimeout(max(1, (int) config('services.xgteam.connect_timeout_seconds', 5)))
                ->timeout(max(2, (int) config('services.xgteam.timeout_seconds', 15)))
                ->get((string) config('services.xgteam.reset_url'), [
                    'secret' => (string) config('services.xgteam.secret'),
                    'license' => $licenseKey,
                ]);
        } catch (ConnectionException $exception) {
            $attempt->update([
                'status' => LicenseReset::STATUS_FAILED,
                'provider_message' => 'Connection failed',
            ]);

            Log::warning('XG Team HWID reset connection failed.', [
                'license_id' => $lockedLicense->id,
                'user_id' => $user->id,
                'attempt_id' => $attempt->id,
                'exception' => $exception->getMessage(),
            ]);

            throw new LicenseResetException(
                'XG Team could not be reached. Please try again in a moment.',
                'connection_failed',
            );
        }

        $providerMessage = $this->providerMessage($response);

        if (! $this->responseSucceeded($response)) {
            $attempt->update([
                'status' => LicenseReset::STATUS_FAILED,
                'http_status' => $response->status(),
                'provider_message' => $providerMessage,
            ]);

            Log::warning('XG Team HWID reset was rejected.', [
                'license_id' => $lockedLicense->id,
                'user_id' => $user->id,
                'attempt_id' => $attempt->id,
                'http_status' => $response->status(),
            ]);

            throw new LicenseResetException(
                $this->customerRejectionMessage($providerMessage),
                $this->isCooldownMessage($providerMessage) ? 'provider_cooldown' : 'provider_rejected',
            );
        }

        $attempt->update([
            'status' => LicenseReset::STATUS_SUCCEEDED,
            'http_status' => $response->status(),
            'provider_message' => $providerMessage,
            'succeeded_at' => now(),
        ]);

        Log::info('XG Team HWID reset completed.', [
            'license_id' => $lockedLicense->id,
            'user_id' => $user->id,
            'attempt_id' => $attempt->id,
        ]);

        return $attempt->fresh();
    }

    public function isConfigured(): bool
    {
        $url = trim((string) config('services.xgteam.reset_url'));
        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));

        return filled(config('services.xgteam.secret')) &&
            filter_var($url, FILTER_VALIDATE_URL) !== false &&
            $scheme === 'https';
    }

    private function responseSucceeded(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $data = $response->json();

        if (! is_array($data)) {
            return true;
        }

        foreach (['success', 'ok'] as $field) {
            if (array_key_exists($field, $data)) {
                return $this->truthy($data[$field]);
            }
        }

        if (array_key_exists('error', $data) && $this->truthy($data['error'])) {
            return false;
        }

        $status = Str::lower(trim((string) ($data['status'] ?? '')));

        if (in_array($status, ['error', 'failed', 'failure', 'rejected', 'unauthorized'], true)) {
            return false;
        }

        return true;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        $normalized = Str::lower(trim((string) $value));

        return $normalized !== '' &&
            ! in_array($normalized, ['0', 'false', 'no', 'null', 'error', 'failed', 'failure', 'unauthorized'], true);
    }

    private function providerMessage(Response $response): ?string
    {
        $data = $response->json();
        $message = is_array($data)
            ? ($data['message'] ?? $data['error'] ?? $data['status'] ?? null)
            : null;

        if (is_array($message) || is_object($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (! is_scalar($message)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $message)) ?? '');

        return $clean !== '' ? Str::limit($clean, 1000, '') : null;
    }

    private function customerRejectionMessage(?string $providerMessage): string
    {
        if ($this->isCooldownMessage($providerMessage)) {
            return $providerMessage;
        }

        return 'XG Team could not reset this HWID. Please try again later or contact support.';
    }

    private function isCooldownMessage(?string $providerMessage): bool
    {
        return $providerMessage !== null &&
            str_contains(Str::lower($providerMessage), 'cooldown');
    }

    private function cooldownHours(): int
    {
        return max(1, (int) config('services.xgteam.cooldown_hours', 48));
    }

    private function pendingTimeoutMinutes(): int
    {
        return max(1, (int) config('services.xgteam.pending_timeout_minutes', 2));
    }

    private function humanRemaining(CarbonInterface $availableAt): string
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($availableAt, false) / 60));
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours > 0) {
            return $hours.'h'.($remainingMinutes > 0 ? ' '.$remainingMinutes.'m' : '');
        }

        return $remainingMinutes.'m';
    }
}
