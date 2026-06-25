@if ($promoVoucher ?? null)
    @php
        $discordUrl = config('links.discord_url');
    @endphp

    <div class="promo-banner {{ $promoClass ?? '' }}">
        <div class="promo-banner-copy">
            <span class="promo-eyebrow">
                <x-ui.icon name="discord" class="h-4 w-4" />
                <span>Discord member promo</span>
            </span>
            <div class="promo-title">
                Member voucher drops available
            </div>
            <p>
                Join Discord to claim promo codes, restock alerts, setup help, and buyer support.
            </p>
        </div>

        <div class="promo-actions">
            <a href="{{ $discordUrl ?: '#' }}" @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                class="promo-discord-pill {{ $discordUrl ? '' : 'pointer-events-none opacity-50' }}">
                <x-ui.icon name="discord" class="h-4 w-4" />
                <span>Claim on Discord</span>
            </a>
        </div>
    </div>
@endif
