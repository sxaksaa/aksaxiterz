<?php

namespace App\Services;

use App\Exceptions\BrModsResetException;
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

class BrModsResetService
{
    public const PROVIDER = 'brmods';

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
            Str::lower(trim((string) config('services.brmods.product_slug', 'br-mods-pc'))),
            Str::lower(trim((string) $product->slug)),
        );
    }

    public function extractUsername(string $credential): ?string
    {
        if (! preg_match('/👤\s*([^🔑\r\n]+?)\s*🔑/u', $credential, $matches)) {
            return null;
        }

        $username = trim((string) ($matches[1] ?? ''));

        if (
            $username === '' ||
            mb_strlen($username) > 120 ||
            preg_match('/[\x00-\x1F\x7F]/u', $username)
        ) {
            return null;
        }

        return $username;
    }

    public function state(License $license): array
    {
        $supported = $this->supports($license);
        $username = $supported ? $this->extractUsername((string) $license->license_key) : null;
        $order = $license->relationLoaded('order')
            ? $license->order
            : $license->order()->first();
        $isPaidPurchase = $order &&
            $order->status === 'paid' &&
            (int) $order->user_id === (int) $license->user_id;
        $lastReset = $license->relationLoaded('latestSuccessfulReset')
            ? $license->latestSuccessfulReset
            : $license->latestSuccessfulReset()->first();
        $availableAt = $lastReset?->succeeded_at
            ? $lastReset->succeeded_at->copy()->addHours($this->cooldownHours())
            : null;
        $remainingSeconds = $availableAt?->isFuture()
            ? max(0, (int) now()->diffInSeconds($availableAt, false))
            : 0;

        return [
            'supported' => $supported,
            'username' => $username,
            'is_paid_purchase' => (bool) $isPaidPurchase,
            'configured' => $this->isConfigured(),
            'available_at' => $availableAt,
            'remaining_seconds' => $remainingSeconds,
            'can_reset' => $supported &&
                $isPaidPurchase &&
                $username !== null &&
                $this->isConfigured() &&
                $remainingSeconds === 0,
        ];
    }

    public function reset(License $license, User $user): LicenseReset
    {
        [$lockedLicense, $username, $attempt] = DB::transaction(function () use ($license, $user): array {
            $lockedLicense = License::with(['product', 'order'])
                ->whereKey($license->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedLicense->user_id !== (int) $user->id) {
                throw new BrModsResetException('This license does not belong to your account.', 'not_owned');
            }

            if (! $this->supports($lockedLicense)) {
                throw new BrModsResetException('HWID reset is not available for this product.', 'unsupported');
            }

            if (
                ! $lockedLicense->order ||
                $lockedLicense->order->status !== 'paid' ||
                (int) $lockedLicense->order->user_id !== (int) $user->id
            ) {
                throw new BrModsResetException('Only a paid BR Mods license can be reset.', 'not_paid');
            }

            if (! $this->isConfigured()) {
                throw new BrModsResetException(
                    'HWID reset is temporarily unavailable. Please contact support.',
                    'not_configured',
                );
            }

            $username = $this->extractUsername((string) $lockedLicense->license_key);

            if ($username === null) {
                throw new BrModsResetException(
                    'This BR Mods license format cannot be reset automatically. Please contact support.',
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
                throw new BrModsResetException(
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
                throw new BrModsResetException(
                    'An HWID reset for this license is already in progress.',
                    'in_progress',
                );
            }

            $attempt = LicenseReset::create([
                'license_id' => $lockedLicense->id,
                'user_id' => $user->id,
                'provider' => self::PROVIDER,
                'username' => $username,
                'status' => LicenseReset::STATUS_PENDING,
            ]);

            return [$lockedLicense, $username, $attempt];
        });

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->withHeaders(['X-API-Key' => (string) config('services.brmods.api_key')])
                ->connectTimeout(max(1, (int) config('services.brmods.connect_timeout_seconds', 5)))
                ->timeout(max(2, (int) config('services.brmods.timeout_seconds', 15)))
                ->post((string) config('services.brmods.reset_url'), [
                    'username' => $username,
                ]);
        } catch (ConnectionException $exception) {
            $attempt->update([
                'status' => LicenseReset::STATUS_FAILED,
                'provider_message' => 'Connection failed',
            ]);

            Log::warning('BRMods HWID reset connection failed.', [
                'license_id' => $lockedLicense->id,
                'user_id' => $user->id,
                'attempt_id' => $attempt->id,
                'exception' => $exception->getMessage(),
            ]);

            throw new BrModsResetException(
                'BRMods could not be reached. Please try again in a moment.',
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

            Log::warning('BRMods HWID reset was rejected.', [
                'license_id' => $lockedLicense->id,
                'user_id' => $user->id,
                'attempt_id' => $attempt->id,
                'http_status' => $response->status(),
            ]);

            throw new BrModsResetException(
                'BRMods could not reset this HWID. Please try again later or contact support.',
                'provider_rejected',
            );
        }

        $attempt->update([
            'status' => LicenseReset::STATUS_SUCCEEDED,
            'http_status' => $response->status(),
            'provider_message' => $providerMessage,
            'succeeded_at' => now(),
        ]);

        Log::info('BRMods HWID reset completed.', [
            'license_id' => $lockedLicense->id,
            'user_id' => $user->id,
            'attempt_id' => $attempt->id,
        ]);

        return $attempt->fresh();
    }

    public function isConfigured(): bool
    {
        $url = trim((string) config('services.brmods.reset_url'));
        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));

        return filled(config('services.brmods.api_key')) &&
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

        if (in_array($status, ['error', 'failed', 'failure', 'rejected', 'erro'], true)) {
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
            ! in_array($normalized, ['0', 'false', 'no', 'null', 'error', 'failed', 'failure', 'erro'], true);
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

    private function cooldownHours(): int
    {
        return max(1, (int) config('services.brmods.cooldown_hours', 24));
    }

    private function pendingTimeoutMinutes(): int
    {
        return max(1, (int) config('services.brmods.pending_timeout_minutes', 2));
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
