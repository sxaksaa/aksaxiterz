<nav id="navbar"
    class="site-navbar fixed inset-x-0 top-2 z-50 px-3 transition-transform duration-300 sm:top-3 sm:px-4 lg:top-4 lg:px-6">

    <div
        class="site-navbar-pill mx-auto grid w-full max-w-7xl grid-cols-[auto_1fr_auto] items-center px-3 py-3 sm:px-5 lg:px-6 xl:grid-cols-[minmax(170px,1fr)_auto_minmax(170px,1fr)]">

        <!-- LOGO -->
        <a href="/" class="site-brand-link flex shrink-0 items-center" aria-label="Aksa Xiterz home" data-soft-nav>
            <img src="{{ asset('images/brand/aksa-xiterz-logo.png') }}" alt="Aksa Xiterz"
                class="block h-8 w-auto max-w-[136px] aksa-logo-glow sm:h-9 sm:max-w-[150px] md:h-10 md:max-w-[170px]"
                width="612" height="195" draggable="false">
        </a>

        <!-- MENU DESKTOP -->
        <div id="navMenu" class="relative hidden justify-self-center whitespace-nowrap text-sm xl:flex">
            <span class="nav-active-glider" data-nav-glider aria-hidden="true"></span>

            <a href="/" data-soft-nav class="nav-item {{ request()->is('/') ? 'active' : '' }}">
                <x-ui.icon name="box" class="nav-icon" />
                <span>Products</span>
            </a>

            <a href="{{ route('guides.index') }}" data-soft-nav class="nav-item {{ request()->is('guides*') ? 'active' : '' }}">
                <x-ui.icon name="book-open" class="nav-icon" />
                <span>Guides</span>
            </a>

            <a href="/downloads" data-soft-nav class="nav-item {{ request()->is('downloads*') ? 'active' : '' }}">
                <x-ui.icon name="download" class="nav-icon" />
                <span>Downloads</span>
            </a>

            @auth
                <a href="/orders" data-soft-nav class="nav-item {{ request()->is('orders*') ? 'active' : '' }}">
                    <x-ui.icon name="receipt" class="nav-icon" />
                    <span>Orders</span>
                </a>
                <a href="/licenses" data-soft-nav class="nav-item {{ request()->is('licenses*') ? 'active' : '' }}">
                    <x-ui.icon name="key-round" class="nav-icon" />
                    <span>Licenses</span>
                </a>
            @endauth

        </div>

        <!-- RIGHT -->
        <div data-navbar-actions class="flex min-w-max justify-self-end items-center justify-end gap-2">
            @php
                $discordUrl = config('links.discord_url');
            @endphp

            @if ($showDisplayCurrencySwitcher ?? false)
                @include('partials.currency-switcher', ['compact' => true])
            @endif

            @auth
                <div class="mini-cart-root relative" data-mini-cart-root
                    data-mini-cart-url="{{ route('cart.preview') }}" data-mini-cart-loaded="false">
                    <a href="{{ route('cart.index') }}" data-mini-cart-trigger
                        class="nav-cart-link relative inline-flex min-h-10 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-full border px-3 text-sm font-semibold text-gray-200 transition hover:text-white"
                        aria-label="Open cart with {{ $cartCount }} items" aria-haspopup="dialog" aria-expanded="false">
                        <x-ui.icon name="shopping-cart" class="h-4 w-4 text-aksa-accent" />
                        <span>Cart</span>
                        <span data-cart-count
                            class="{{ $cartCount > 0 ? '' : 'hidden' }} rounded-full bg-aksa-accent px-2 py-0.5 text-[10px] font-bold text-white">
                            {{ $cartCount }}
                        </span>
                    </a>

                    <button type="button" class="mini-cart-backdrop" data-mini-cart-close aria-label="Close cart preview"></button>
                    <div class="mini-cart-panel" data-mini-cart-panel role="dialog" aria-label="Cart preview">
                        <div class="mini-cart-mobile-handle" aria-hidden="true"></div>
                        <button type="button" class="mini-cart-close" data-mini-cart-close aria-label="Close cart preview">
                            <x-ui.icon name="x" class="h-4 w-4" />
                        </button>
                        <div data-mini-cart-content class="mini-cart-loading" role="status">
                            <span class="mini-cart-loading-dot" aria-hidden="true"></span>
                            <span>Loading cart...</span>
                        </div>
                    </div>
                </div>
            @endauth

            <a href="{{ $discordUrl ?: '#' }}" @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                data-desktop-discord aria-label="Open Discord support" title="Discord support"
                class="discord-nav-cta {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                <x-ui.icon name="discord" class="discord-nav-icon shrink-0" />
            </a>

            <!-- MOBILE MENU BUTTON -->
            <button id="menuBtn" type="button" data-mobile-menu-toggle aria-controls="mobileMenu"
                aria-expanded="false" class="nav-menu-button inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-2 text-sm text-white xl:hidden">
                <x-ui.icon name="menu" class="h-5 w-5 text-aksa-accent" />
                <span data-button-label>Menu</span>
            </button>

            <!-- DESKTOP PROFILE -->
            @auth
                @php $user = auth()->user(); @endphp
                <div class="relative hidden shrink-0 xl:block">

                    <button type="button" data-profile-toggle
                        class="flex shrink-0 items-center gap-2 text-gray-300 transition hover:text-white">

                        @if ($user->avatar)
                            <img src="{{ $user->avatar }}" alt="{{ $user->name }}"
                                class="h-8 w-8 max-w-none shrink-0 rounded-full border border-aksa-accent-40 object-cover">
                        @else
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                bg-aksa-accent-20 text-xs font-bold text-aksa-accent">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </span>
                        @endif

                    </button>

                    <div id="dropdown"
                        class="hidden absolute right-0 mt-3 w-44 z-50
        bg-[#15151B] border border-[#27272A] rounded-xl shadow-lg overflow-hidden">

                        <div class="px-4 py-3 text-xs text-gray-400 border-b border-[#27272A]">
                            {{ $user->name }}
                        </div>

                        @if ($user->isAdmin())
                            <div class="border-b border-[#27272A]">
                                <a href="{{ route('admin.dashboard') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="home" class="h-4 w-4 text-aksa-accent" />
                                    <span>Admin Dashboard</span>
                                </a>
                                <a href="{{ route('admin.activity.index') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="activity" class="h-4 w-4 text-aksa-accent" />
                                    <span>Admin Activity</span>
                                </a>
                                <a href="{{ route('admin.gopay-events.index') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="qr-code" class="h-4 w-4 text-aksa-accent" />
                                    <span>QRIS Events</span>
                                </a>
                                <a href="{{ route('admin.products.index') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="boxes" class="h-4 w-4 text-aksa-accent" />
                                    <span>Admin Catalog</span>
                                </a>
                                <a href="{{ route('admin.categories.index') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="filter" class="h-4 w-4 text-aksa-accent" />
                                    <span>Admin Categories</span>
                                </a>
                                <a href="{{ route('admin.downloads.index') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="download" class="h-4 w-4 text-aksa-accent" />
                                    <span>Admin Downloads</span>
                                </a>
                                <a href="{{ route('admin.orders.index') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="receipt" class="h-4 w-4 text-aksa-accent" />
                                    <span>Admin Orders</span>
                                </a>
                                <a href="{{ route('admin.license-stocks.index') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="key-round" class="h-4 w-4 text-aksa-accent" />
                                    <span>Admin Stock</span>
                                </a>
                                <a href="{{ route('admin.users.index') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="users" class="h-4 w-4 text-aksa-accent" />
                                    <span>Admin Users</span>
                                </a>
                                <a href="{{ route('admin.vouchers.index') }}"
                                    class="flex items-center gap-2 px-4 py-3 text-sm text-gray-300 transition hover:bg-aksa-accent-10 hover:text-white">
                                    <x-ui.icon name="ticket-percent" class="h-4 w-4 text-aksa-accent" />
                                    <span>Admin Vouchers</span>
                                </a>
                            </div>
                        @endif

                        <form action="/logout" method="POST">
                            @csrf
                            <button
                                class="flex w-full items-center gap-2 px-4 py-3 text-left text-sm text-red-400 transition hover:bg-aksa-accent-10">
                                <x-ui.icon name="log-out" class="h-4 w-4" />
                                <span>Logout</span>
                            </button>
                        </form>

                    </div>

                </div>
            @else
                <a href="/auth/google" class="nav-login-link hidden shrink-0 items-center gap-2 whitespace-nowrap rounded-full px-3 py-2 text-gray-400 transition hover:text-white xl:inline-flex">
                    <x-ui.icon name="log-in" class="h-4 w-4 text-aksa-accent" />
                    <span>Login</span>
                </a>
            @endauth

        </div>

    </div>


</nav>

<div id="mobileMenu"
    class="mobile-nav-panel fixed inset-x-3 top-[5.25rem] z-40 max-h-[calc(100dvh-6.25rem)] overflow-y-auto rounded-[1.35rem] border px-4 py-4 opacity-0 -translate-y-5 pointer-events-none transition-all duration-300 ease-out sm:left-auto sm:right-4 sm:w-[23rem] xl:hidden"
    aria-hidden="true">

    <div class="flex flex-col gap-4 text-sm">

        @if ($showDisplayCurrencySwitcher ?? false)
            @include('partials.currency-switcher')
        @endif

        <a href="/" data-mobile-menu-link data-soft-nav class="nav-item">
            <x-ui.icon name="box" class="nav-icon" />
            <span>Products</span>
        </a>
        <a href="{{ route('guides.index') }}" data-mobile-menu-link data-soft-nav class="nav-item">
            <x-ui.icon name="book-open" class="nav-icon" />
            <span>Guides</span>
        </a>
        <a href="/downloads" data-mobile-menu-link data-soft-nav class="nav-item">
            <x-ui.icon name="download" class="nav-icon" />
            <span>Downloads</span>
        </a>

        @auth
            <a href="{{ route('cart.index') }}" data-mobile-menu-link class="nav-item">
                <x-ui.icon name="shopping-cart" class="nav-icon" />
                <span>Cart{{ $cartCount > 0 ? ' (' . $cartCount . ')' : '' }}</span>
            </a>
            <a href="/orders" data-mobile-menu-link data-soft-nav class="nav-item">
                <x-ui.icon name="receipt" class="nav-icon" />
                <span>Orders</span>
            </a>
            <a href="/licenses" data-mobile-menu-link data-soft-nav class="nav-item">
                <x-ui.icon name="key-round" class="nav-icon" />
                <span>Licenses</span>
            </a>
            @if (auth()->user()?->isAdmin())
                <a href="{{ route('admin.dashboard') }}" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="home" class="nav-icon" />
                    <span>Admin Dashboard</span>
                </a>
                <a href="{{ route('admin.activity.index') }}" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="activity" class="nav-icon" />
                    <span>Admin Activity</span>
                </a>
                <a href="{{ route('admin.gopay-events.index') }}" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="qr-code" class="nav-icon" />
                    <span>QRIS Events</span>
                </a>
                <a href="{{ route('admin.products.index') }}" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="boxes" class="nav-icon" />
                    <span>Admin Catalog</span>
                </a>
                <a href="{{ route('admin.categories.index') }}" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="filter" class="nav-icon" />
                    <span>Admin Categories</span>
                </a>
                <a href="{{ route('admin.downloads.index') }}" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="download" class="nav-icon" />
                    <span>Admin Downloads</span>
                </a>
                <a href="{{ route('admin.orders.index') }}" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="receipt" class="nav-icon" />
                    <span>Admin Orders</span>
                </a>
                <a href="/admin/license-stocks" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="key-round" class="nav-icon" />
                    <span>Admin Stock</span>
                </a>
                <a href="{{ route('admin.users.index') }}" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="users" class="nav-icon" />
                    <span>Admin Users</span>
                </a>
                <a href="{{ route('admin.vouchers.index') }}" data-mobile-menu-link class="nav-item">
                    <x-ui.icon name="ticket-percent" class="nav-icon" />
                    <span>Admin Vouchers</span>
                </a>
            @endif

            @php $discordUrl = config('links.discord_url'); @endphp
            <a href="{{ $discordUrl ?: '#' }}" @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                class="mobile-discord-link {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                <span class="inline-flex items-center gap-2">
                    <x-ui.icon name="discord" class="h-4 w-4" />
                    <span>Discord</span>
                </span>
                <span class="text-xs text-aksa-accent">Support</span>
            </a>

            <div class="border-t border-[#27272A] pt-3 text-xs text-gray-400 flex items-center gap-2">
                @if (auth()->user()->avatar)
                    <img src="{{ auth()->user()->avatar }}" alt="{{ auth()->user()->name }}"
                        class="w-7 h-7 rounded-full object-cover border border-aksa-accent-40">
                @else
                    <span
                        class="w-7 h-7 flex items-center justify-center bg-aksa-accent-20 text-aksa-accent rounded-full text-[10px] font-bold">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </span>
                @endif
                <span>{{ auth()->user()->name }}</span>
            </div>

            <form action="/logout" method="POST">
                @csrf
                <button class="inline-flex items-center gap-2 text-left text-sm text-red-400">
                    <x-ui.icon name="log-out" class="h-4 w-4" />
                    <span>Logout</span>
                </button>
            </form>
        @endauth

        @guest
            <a href="/auth/google" class="nav-item">
                <x-ui.icon name="log-in" class="nav-icon" />
                <span>Login</span>
            </a>

            @php $discordUrl = config('links.discord_url'); @endphp
            <a href="{{ $discordUrl ?: '#' }}" @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                class="mobile-discord-link {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                <span class="inline-flex items-center gap-2">
                    <x-ui.icon name="discord" class="h-4 w-4" />
                    <span>Discord</span>
                </span>
                <span class="text-xs text-aksa-accent">Support</span>
            </a>
        @endguest

    </div>


</div>

<div class="h-[5.5rem] sm:h-24" aria-hidden="true"></div>
