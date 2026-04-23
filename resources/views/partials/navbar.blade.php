<nav id="navbar"
    class="fixed top-0 left-0 w-full z-50 
bg-[#111115]/80 backdrop-blur-md 
border-b border-[#27272A] transition-transform duration-300">

    <div class="w-full px-12 py-4 flex items-center justify-between relative">

        <!-- LOGO -->
        <a href="/" class="flex items-center gap-2">
            <span class="text-xl font-bold text-[#9333EA]">Aksa</span>
            <span class="text-xl font-semibold text-white">Xiterz</span>
        </a>

        <!-- MENU -->
        <div id="navMenu" class="absolute left-1/2 -translate-x-1/2 flex gap-10 text-sm">

            <a href="/" class="nav-item {{ request()->is('/') ? 'active' : '' }}">
                Home
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
        <div class="flex items-center gap-4">

            @auth
                <div class="relative">

                    <button onclick="toggleDropdown()"
                        class="flex items-center gap-2 text-gray-300 hover:text-white transition">

                        <span
                            class="w-8 h-8 flex items-center justify-center 
                    bg-[#9333EA]/20 text-[#C084FC] rounded-full text-xs font-bold">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </span>

                    </button>

                    <div id="dropdown"
                        class="hidden absolute right-0 mt-3 w-44 
                bg-[#15151B] border border-[#27272A] rounded-xl shadow-lg overflow-hidden">

                        <div class="px-4 py-3 text-xs text-gray-400 border-b border-[#27272A]">
                            {{ auth()->user()->name }}
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
                <a href="/auth/google" class="text-gray-400 hover:text-white transition">
                    Login
                </a>
            @endauth

        </div>

    </div>

</nav>

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

    /* UNDERLINE SLIDE */
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
    function toggleDropdown() {
        document.getElementById("dropdown").classList.toggle("hidden");
    }

    window.addEventListener('click', function(e) {
        const dropdown = document.getElementById("dropdown");

        if (!e.target.closest('button') && !dropdown.contains(e.target)) {
            dropdown.classList.add("hidden");
        }
    });

    /* INDICATOR */
    let activeItem = null;

    function moveIndicator(el) {
        const indicator = document.getElementById("navIndicator");

        indicator.style.width = el.offsetWidth + "px";
        indicator.style.left = el.offsetLeft + "px";
    }

    window.onload = () => {
        activeItem = document.querySelector(".nav-item.active");
        if (activeItem) moveIndicator(activeItem);
    };

    document.querySelectorAll(".nav-item").forEach(item => {

        item.addEventListener("mouseenter", () => {
            moveIndicator(item);
        });

        item.addEventListener("mouseleave", () => {
            if (activeItem) moveIndicator(activeItem);
        });

    });

    /* NAVBAR HIDE ON SCROLL */
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
