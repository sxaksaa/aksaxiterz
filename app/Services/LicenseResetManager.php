<?php

namespace App\Services;

use App\Exceptions\LicenseResetException;
use App\Models\License;
use App\Models\LicenseReset;
use App\Models\User;

class LicenseResetManager
{
    public function __construct(
        private readonly BrModsResetService $brModsResetService,
        private readonly XgTeamResetService $xgTeamResetService,
    ) {}

    public function supports(License $license): bool
    {
        return $this->providerFor($license) !== null;
    }

    public function state(License $license): array
    {
        $provider = $this->providerFor($license);

        return $provider ? $provider->state($license) : $this->emptyState();
    }

    public function reset(License $license, User $user): LicenseReset
    {
        $provider = $this->providerFor($license);

        if (! $provider) {
            throw new LicenseResetException('HWID reset is not available for this product.', 'unsupported');
        }

        return $provider->reset($license, $user);
    }

    public function labelForProvider(?string $provider): string
    {
        return match ($provider) {
            BrModsResetService::PROVIDER => 'BR Mods',
            XgTeamResetService::PROVIDER => 'XG Team',
            default => 'HWID',
        };
    }

    public function cooldownHoursForProvider(?string $provider): int
    {
        return max(1, match ($provider) {
            XgTeamResetService::PROVIDER => (int) config('services.xgteam.cooldown_hours', 48),
            default => (int) config('services.brmods.cooldown_hours', 24),
        });
    }

    private function providerFor(License $license): ?object
    {
        foreach ($this->providers() as $provider) {
            if ($provider->supports($license)) {
                return $provider;
            }
        }

        return null;
    }

    private function providers(): array
    {
        return [
            $this->brModsResetService,
            $this->xgTeamResetService,
        ];
    }

    private function emptyState(): array
    {
        return [
            'supported' => false,
            'provider' => null,
            'provider_label' => null,
            'identifier' => null,
            'identifier_label' => 'license',
            'username' => null,
            'is_paid_purchase' => false,
            'configured' => false,
            'available_at' => null,
            'remaining_seconds' => 0,
            'cooldown_hours' => 24,
            'can_reset' => false,
        ];
    }
}
