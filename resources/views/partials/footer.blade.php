@php
    $discordUrl = config('links.discord_url');
    $footerBinancePayAvailable = app()->environment('local') || (
        (bool) config('services.binance.pay.enabled') &&
        filled(config('services.binance.pay.pay_id')) &&
        filled(config('services.binance.pay.api_key')) &&
        filled(config('services.binance.pay.api_secret'))
    );
@endphp

<footer class="site-footer mt-10">
    <div class="page-shell py-10 md:py-12">
        <div class="grid gap-8 md:grid-cols-[1.35fr_0.8fr_0.9fr_0.95fr]">
            <div class="footer-brand-block">
                <a href="/" data-soft-nav class="footer-brand" aria-label="Aksa Xiterz home">
                    <img src="{{ asset('images/brand/aksa-xiterz-logo.webp') }}" alt="Aksa Xiterz" loading="lazy" decoding="async"
                        class="block h-10 w-auto max-w-[180px] aksa-logo-glow"
                        width="612" height="195" draggable="false">
                </a>

                <p class="mt-3 max-w-sm text-sm leading-6 text-gray-400">
                    Digital licenses, setup resources, secure checkout, and customer support in one place.
                </p>

                <div class="footer-meta-row">
                    <span>
                        <x-ui.icon name="shield-check" class="h-3.5 w-3.5" />
                        Secure checkout
                    </span>
                    <span>
                        <x-ui.icon name="key-round" class="h-3.5 w-3.5" />
                        Instant delivery
                    </span>
                </div>
            </div>

            <div class="footer-column">
                <h2 class="footer-heading">Payment Methods</h2>
                <ul class="mt-3 grid gap-3 text-sm">
                    <li class="flex items-start gap-2.5">
                        <x-ui.icon name="qr-code" class="mt-0.5 h-4 w-4 shrink-0 text-aksa-accent" />
                        <span>
                            <span class="block text-gray-300">QRIS</span>
                            <span class="block text-xs text-gray-400">{{ config('services.gopay_qris.enabled') ? 'Pay in IDR' : 'Temporarily unavailable' }}</span>
                        </span>
                    </li>
                    @if ($footerBinancePayAvailable)
                        <li class="flex items-start gap-2.5">
                            <x-ui.icon name="binance" class="mt-0.5 h-4 w-4 shrink-0 text-aksa-accent" />
                            <span>
                                <span class="block text-gray-300">Binance Pay</span>
                                <span class="block text-xs text-gray-400">USDT · USDC</span>
                            </span>
                        </li>
                    @endif
                    <li class="flex items-start gap-2.5">
                        <x-ui.icon name="wallet" class="mt-0.5 h-4 w-4 shrink-0 text-aksa-accent" />
                        <span>
                            <span class="block text-gray-300">Crypto Address</span>
                            <span class="block text-xs text-gray-400">USDT · USDC</span>
                        </span>
                    </li>
                </ul>
            </div>

            <div class="footer-column">
                <h2 class="footer-heading">Support</h2>
                <p class="mt-3 text-sm leading-6 text-gray-400">
                    Setup help, license delivery checks, reset requests, and payment support.
                </p>

                <a href="{{ $discordUrl ?: '#' }}"
                    @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                    class="footer-discord-link {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                    <span class="footer-discord-icon">
                        <x-ui.icon name="discord" class="h-4 w-4" />
                    </span>
                    <span>Discord</span>
                </a>
            </div>

            <div class="footer-column">
                <h2 class="footer-heading">Legal</h2>
                <div class="mt-3 grid gap-2 text-sm">
                    <a href="/terms" data-soft-nav class="footer-link">Terms</a>
                    <a href="/privacy" data-soft-nav class="footer-link">Privacy Policy</a>
                    <a href="/refund-policy" data-soft-nav class="footer-link">Refund Policy</a>
                    <a href="/faq" data-soft-nav class="footer-link">FAQ</a>
                    <a href="/contact" data-soft-nav class="footer-link">Contact</a>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <span>© {{ date('Y') }} Aksa Xiterz. Since 2024. All rights reserved.</span>
        </div>
    </div>
</footer>
