<nav id="navbar"
    class="fixed top-0 left-0 w-full z-50 
bg-[#111115]/80 backdrop-blur-md 
border-b border-[#27272A] transition-transform duration-300">


    <div class="w-full px-4 md:px-12 py-4 flex items-center justify-between relative">

        <!-- LOGO -->
        <a href="/" class="flex shrink-0 items-center" aria-label="Aksa Xiterz home">
            <img src="{{ asset('images/brand/aksa-xiterz-logo.png') }}" alt="Aksa Xiterz"
                class="block h-8 w-auto max-w-[136px] drop-shadow-[0_0_18px_rgba(147,51,234,0.35)] sm:h-9 sm:max-w-[150px] md:h-10 md:max-w-[170px]"
                width="612" height="195" draggable="false">
        </a>

        <!-- MENU DESKTOP -->
        <div id="navMenu" class="hidden md:flex absolute left-1/2 -translate-x-1/2 gap-10 text-sm">

            <a href="/" class="nav-item {{ request()->is('/') ? 'active' : '' }}">
                Products
            </a>

            <a href="/downloads" class="nav-item {{ request()->is('downloads*') ? 'active' : '' }}">
                Downloads
            </a>

            @auth
                <a href="/orders" class="nav-item {{ request()->is('orders*') ? 'active' : '' }}">
                    Orders
                </a>
                <a href="/licenses" class="nav-item {{ request()->is('licenses*') ? 'active' : '' }}">
                    Licenses
                </a>
            @endauth

            <span id="navIndicator" class="nav-indicator"></span>

        </div>

        <!-- RIGHT -->
        <div class="flex items-center gap-3">
            @php $discordUrl = config('links.discord_url'); @endphp

            <a href="{{ $discordUrl ?: '#' }}" @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                class="discord-nav-cta {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                <span>Discord</span>
                <span class="hidden rounded-md bg-white/[0.12] px-2 py-1 text-[10px] font-semibold text-white/90 lg:inline">
                    Support
                </span>
            </a>

            <!-- MOBILE MENU BUTTON -->
            <button id="menuBtn" onclick="toggleMobileMenu(event)" class="md:hidden text-white text-sm p-2">
                Menu
            </button>

            <!-- DESKTOP PROFILE -->
            @auth
                @php $user = auth()->user(); @endphp
                <div class="relative hidden md:block">

                    <button onclick="toggleProfileDropdown()"
                        class="flex items-center gap-2 text-gray-300 hover:text-white transition">

                        @if ($user->avatar)
                            <img src="{{ $user->avatar }}" alt="{{ $user->name }}"
                                class="w-8 h-8 rounded-full object-cover border border-[#9333EA]/40">
                        @else
                            <span
                                class="w-8 h-8 flex items-center justify-center
                bg-[#9333EA]/20 text-[#C084FC] rounded-full text-xs font-bold">
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

                        <form action="/logout" method="POST">
                            @csrf
                            <button
                                class="w-full text-left px-4 py-3 text-sm text-red-400 hover:bg-[#9333EA]/10 transition">
                                Logout
                            </button>
                        </form>

                    </div>

                </div>
            @else
                <a href="/auth/google" class="hidden md:block text-gray-400 hover:text-white transition">
                    Login
                </a>
            @endauth

        </div>

    </div>


</nav>

<div id="mobileMenu"
    class="md:hidden fixed top-[64px] left-0 w-full 
    bg-[#111115]/95 border-b border-[#27272A] 
    px-6 py-4 z-40
    max-h-[calc(100vh-64px)] overflow-y-auto


opacity-0 -translate-y-5 pointer-events-none
transition-all duration-300 ease-out">

    <div class="flex flex-col gap-4 text-sm">

        <a href="/" onclick="toggleMobileMenu(event)" class="nav-item">Products</a>
        <a href="/downloads" onclick="toggleMobileMenu(event)" class="nav-item">Downloads</a>

        @auth
            <a href="/orders" onclick="toggleMobileMenu(event)" class="nav-item">Orders</a>
            <a href="/licenses" onclick="toggleMobileMenu(event)" class="nav-item">Licenses</a>

            @php $discordUrl = config('links.discord_url'); @endphp
            <a href="{{ $discordUrl ?: '#' }}" @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                class="mobile-discord-link {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                <span>Discord</span>
                <span class="text-xs text-[#C084FC]">Support</span>
            </a>

            <div class="border-t border-[#27272A] pt-3 text-xs text-gray-400 flex items-center gap-2">
                @if (auth()->user()->avatar)
                    <img src="{{ auth()->user()->avatar }}" alt="{{ auth()->user()->name }}"
                        class="w-7 h-7 rounded-full object-cover border border-[#9333EA]/40">
                @else
                    <span
                        class="w-7 h-7 flex items-center justify-center bg-[#9333EA]/20 text-[#C084FC] rounded-full text-[10px] font-bold">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </span>
                @endif
                <span>{{ auth()->user()->name }}</span>
            </div>

            <form action="/logout" method="POST">
                @csrf
                <button class="text-red-400 text-left text-sm">
                    Logout
                </button>
            </form>
        @endauth

        @guest
            <a href="/auth/google" class="text-gray-400">
                Login
            </a>

            @php $discordUrl = config('links.discord_url'); @endphp
            <a href="{{ $discordUrl ?: '#' }}" @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                class="mobile-discord-link {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                <span>Discord</span>
                <span class="text-xs text-[#C084FC]">Support</span>
            </a>
        @endguest

    </div>


</div>

<div class="h-20"></div>

<style>
    .nav-item {
        color: #9CA3AF;
        position: relative;
        transition: all 0.25s ease;
    }

    .nav-item:hover {
        color: white;
    }

    .nav-item.active {
        color: #C084FC;
    }

    .nav-indicator {
        position: absolute;
        bottom: -6px;
        height: 2px;
        background: #9333EA;
        border-radius: 2px;
        transition: all 0.3s ease;
    }
</style>

<script>
    let mobileOpen = false;

    /* MOBILE MENU */
    function toggleMobileMenu(e) {
        const menu = document.getElementById('mobileMenu');
        const btn = e.currentTarget;

        mobileOpen = !mobileOpen;

        if (mobileOpen) {
            menu.classList.remove('opacity-0', '-translate-y-5', 'pointer-events-none');
            menu.classList.add('opacity-100', 'translate-y-0');

            btn.innerText = 'Close';
        } else {
            menu.classList.add('opacity-0', '-translate-y-5', 'pointer-events-none');
            menu.classList.remove('opacity-100', 'translate-y-0');

            btn.innerText = 'Menu';
        }
    }

    /* CLICK OUTSIDE */
    window.addEventListener('click', function(e) {

        const menu = document.getElementById('mobileMenu');
        const button = document.getElementById('menuBtn');

        if (!menu.contains(e.target) && !button.contains(e.target)) {

            if (mobileOpen) {
                menu.classList.add('opacity-0', '-translate-y-5', 'pointer-events-none');
                menu.classList.remove('opacity-100', 'translate-y-0');

                button.innerText = 'Menu';
                mobileOpen = false;
            }
        }
    });

    /* DROPDOWN PROFILE */
    function toggleProfileDropdown() {
        document.getElementById("dropdown").classList.toggle("hidden");
    }

    /* NAV INDICATOR */
    let activeItem = null;

    function moveIndicator(el) {
        const indicator = document.getElementById("navIndicator");
        if (!indicator) return;

        indicator.style.width = el.offsetWidth + "px";
        indicator.style.left = el.offsetLeft + "px";
    }

    window.addEventListener('load', () => {
        activeItem = document.querySelector(".nav-item.active");
        if (activeItem) moveIndicator(activeItem);
    });

    document.querySelectorAll(".nav-item").forEach(item => {

        item.addEventListener("mouseenter", () => {
            moveIndicator(item);
        });

        item.addEventListener("mouseleave", () => {
            if (activeItem) moveIndicator(activeItem);
        });

    });

    /* HIDE NAVBAR ON SCROLL */
    let lastScroll = 0;
    const navbar = document.getElementById("navbar");

    window.addEventListener("scroll", () => {
        const currentScroll = window.pageYOffset;

        if (currentScroll > lastScroll && currentScroll > 50) {
            navbar.style.transform = "translateY(-100%)";
        } else {
            navbar.style.transform = "translateY(0)";
        }

        lastScroll = currentScroll;
    });
</script>
