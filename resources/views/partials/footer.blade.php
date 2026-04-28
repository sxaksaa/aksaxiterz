@php
    $discordUrl = config('links.discord_url');
@endphp

<footer class="mt-8 border-t border-[#27272A] bg-[#09090C]/80">
    <div class="page-shell py-8 md:py-10">
        <div class="grid gap-8 md:grid-cols-[1.35fr_0.8fr_0.9fr_0.95fr]">
            <div>
                <a href="/" class="footer-brand" aria-label="Aksa Xiterz home">
                    <img src="{{ asset('images/brand/aksa-xiterz-logo.png') }}" alt="Aksa Xiterz"
                        class="block h-10 w-auto max-w-[180px] drop-shadow-[0_0_20px_rgba(147,51,234,0.32)]"
                        width="612" height="195" draggable="false">
                </a>

                <p class="mt-3 max-w-sm text-sm leading-6 text-gray-400">
                    Digital licenses, setup resources, secure checkout, and customer support in one place.
                </p>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-white">Quick Links</h2>
                <div class="mt-3 grid gap-2 text-sm">
                    <a href="/" class="footer-link">Products</a>
                    <a href="{{ route('guides.index') }}" class="footer-link">Guides</a>
                    <a href="/downloads" class="footer-link">Downloads</a>
                    @auth
                        <a href="/orders" class="footer-link">Orders</a>
                        <a href="/licenses" class="footer-link">Licenses</a>
                    @endauth
                </div>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-white">Support</h2>
                <p class="mt-3 text-sm leading-6 text-gray-400">
                    Setup help, license delivery checks, reset requests, and payment support.
                </p>

                <a href="{{ $discordUrl ?: '#' }}"
                    @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                    class="footer-discord-link {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                    <span class="footer-discord-icon">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="currentColor"
                                d="M20.32 4.37A19.8 19.8 0 0 0 16.56 3c-.16.29-.35.68-.49 1a18.3 18.3 0 0 0-4.14 0c-.14-.32-.33-.71-.49-1a19.8 19.8 0 0 0-3.76 1.37C5.3 7.92 4.66 11.38 4.98 14.79a20 20 0 0 0 4.6 2.33c.37-.5.7-1.04.98-1.6-.54-.2-1.06-.45-1.54-.75l.37-.3a10.83 10.83 0 0 0 9.16 0l.38.3c-.49.3-1.01.55-1.55.75.28.56.61 1.1.98 1.6a20 20 0 0 0 4.61-2.33c.38-3.96-.64-7.39-2.65-10.42ZM9.68 12.71c-.9 0-1.63-.82-1.63-1.84s.72-1.83 1.63-1.83c.92 0 1.65.83 1.63 1.83 0 1.02-.72 1.84-1.63 1.84Zm4.65 0c-.9 0-1.63-.82-1.63-1.84s.72-1.83 1.63-1.83c.92 0 1.65.83 1.63 1.83 0 1.02-.71 1.84-1.63 1.84Z" />
                        </svg>
                    </span>
                    <span>Discord</span>
                </a>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-white">Legal</h2>
                <div class="mt-3 grid gap-2 text-sm">
                    <a href="/terms" class="footer-link">Terms</a>
                    <a href="/privacy" class="footer-link">Privacy Policy</a>
                    <a href="/refund-policy" class="footer-link">Refund Policy</a>
                    <a href="/contact" class="footer-link">Contact</a>
                </div>
            </div>
        </div>

        <div class="mt-8 border-t border-[#27272A] pt-5 text-center text-xs text-gray-500">
            <span>© {{ date('Y') }} Aksa Xiterz. Since 2024. All rights reserved.</span>
        </div>
    </div>
</footer>
