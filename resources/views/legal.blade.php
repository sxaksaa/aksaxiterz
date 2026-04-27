@extends('layouts.app')

@section('content')
    @php
        $business = config('links.business', []);
        $support = config('links.support', []);
        $discordUrl = config('links.discord_url');
        $legalLinks = [
            'terms' => ['label' => 'Terms', 'url' => '/terms'],
            'privacy' => ['label' => 'Privacy', 'url' => '/privacy'],
            'refund-policy' => ['label' => 'Refund Policy', 'url' => '/refund-policy'],
            'contact' => ['label' => 'Contact', 'url' => '/contact'],
        ];
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="product-hero mb-6 fade-up">
            <div class="max-w-3xl">
                <p class="mb-2 text-sm font-semibold text-[#C084FC]">{{ $eyebrow ?? 'Legal' }}</p>
                <h1 class="text-3xl font-bold tracking-normal md:text-5xl">{{ $title }}</h1>
                <p class="mt-4 max-w-2xl break-words text-sm leading-6 text-gray-400 md:text-base">
                    {{ $summary }}
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <span class="support-pill">Updated {{ $updatedAt }}</span>
                    <span class="support-pill">Digital delivery</span>
                    <span class="support-pill">Customer support</span>
                </div>
            </div>
        </section>

        <div class="grid gap-5 lg:grid-cols-[260px_1fr]">
            <aside class="product-section h-fit fade-up">
                <h2 class="text-sm font-semibold text-white">Legal Pages</h2>
                <div class="mt-4 grid gap-2 text-sm">
                    @foreach ($legalLinks as $key => $link)
                        <a href="{{ $link['url'] }}"
                            class="footer-link {{ ($slug ?? '') === $key ? 'text-[#C084FC]' : '' }}">
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </div>

                <div class="mt-5 border-t border-[#27272A] pt-5">
                    <h3 class="text-sm font-semibold text-white">{{ $business['name'] ?? 'Aksa Xiterz' }}</h3>
                    <div class="mt-3 grid gap-2 text-xs leading-5 text-gray-400">
                        <span>{{ $support['hours'] ?? 'Daily support' }}</span>
                        @if (!empty($discordUrl))
                            <a href="{{ $discordUrl }}" target="_blank" rel="noopener noreferrer" class="footer-link">
                                Discord Support
                            </a>
                        @else
                            <span class="text-yellow-300">Discord support link is not configured yet.</span>
                        @endif
                    </div>
                </div>
            </aside>

            <main class="grid gap-4">
                @foreach ($sections as $section)
                    <section class="product-section motion-card">
                        <h2 class="text-xl font-semibold text-white">{{ $section['title'] }}</h2>

                        @if (!empty($section['body']))
                            <div class="mt-3 grid gap-3 text-sm leading-6 text-gray-400">
                                @foreach ($section['body'] as $paragraph)
                                    <p>{{ $paragraph }}</p>
                                @endforeach
                            </div>
                        @endif

                        @if (!empty($section['items']))
                            <ul class="mt-3 grid gap-2 text-sm leading-6 text-gray-400">
                                @foreach ($section['items'] as $item)
                                    <li class="rounded-lg border border-[#27272A] bg-black/20 px-3 py-2">
                                        {{ $item }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            </main>
        </div>
    </div>
@endsection
