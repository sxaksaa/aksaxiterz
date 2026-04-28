@extends('layouts.app')

@section('content')
    <section class="page-shell pb-8 pt-6 md:pt-10">
        <div class="download-hero mx-auto max-w-5xl fade-up">
            <div class="grid gap-6 lg:grid-cols-[1fr_0.72fr] lg:items-end">
                <div>
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <a href="{{ route('guides.index') }}" class="btn-footer-secondary">All Guides</a>
                        <span class="support-pill">{{ $guide['category'] ?? 'Guide' }}</span>
                    </div>

                    <h1 class="text-3xl font-bold tracking-normal md:text-5xl">
                        {{ $guide['title'] }}
                    </h1>
                    <p class="mt-4 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        {{ $guide['summary'] }}
                    </p>
                </div>

                @include('guides._visual', [
                    'variant' => $guide['visual'] ?? 'default',
                    'title' => $guide['title'],
                    'image' => $guide['image'] ?? null,
                ])
            </div>
        </div>
    </section>

    <section class="page-shell pb-16 md:pb-20">
        <div class="mx-auto grid max-w-5xl gap-5 lg:grid-cols-[0.72fr_1.28fr] lg:items-start">
            <aside class="product-section fade-up">
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Before You Start</p>
                <div class="mt-4 grid gap-3">
                    @foreach ($guide['requirements'] ?? [] as $requirement)
                        <div class="rounded-lg border border-[#27272A] bg-black/20 px-3 py-3 text-sm text-gray-300">
                            {{ $requirement }}
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 rounded-lg border border-[#9333EA]/30 bg-[#9333EA]/10 px-3 py-3 text-xs leading-5 text-[#D8B4FE]">
                    Updated {{ $updatedAt }}. Follow the steps carefully and restart Windows when requested.
                </div>
            </aside>

            <div class="grid gap-4">
                @foreach ($guide['steps'] ?? [] as $index => $step)
                    <article class="product-section motion-card">
                        <div class="grid gap-5 md:grid-cols-[0.88fr_1.12fr] md:items-start">
                            @include('guides._visual', [
                                'variant' => $step['visual'] ?? 'default',
                                'title' => 'Step ' . ($index + 1),
                                'image' => $step['image'] ?? null,
                            ])

                            <div>
                                <div class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">
                                    Step {{ $index + 1 }}
                                </div>
                                <h2 class="mt-2 text-xl font-semibold text-white">{{ $step['title'] }}</h2>
                                <p class="mt-3 text-sm leading-6 text-gray-400">{{ $step['body'] }}</p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>

        @if ($relatedGuides->isNotEmpty())
            <div class="mx-auto mt-8 max-w-5xl">
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">More Guides</p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    @foreach ($relatedGuides as $related)
                        <a href="{{ route('guides.show', $related['slug']) }}" class="download-feature block">
                            <div class="text-sm font-semibold text-white">{{ $related['title'] }}</div>
                            <div class="mt-1 text-xs leading-5 text-gray-400">{{ $related['summary'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
@endsection
