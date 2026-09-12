@extends('layouts.app')

@section('seo_title', $guide['title'].' - Aksa Xiterz')
@section('seo_description', \Illuminate\Support\Str::limit(strip_tags($guide['summary'] ?? $guide['title']), 155))

@section('content')
    <section class="page-shell pb-8 pt-6 md:pt-10">
        <div class="download-hero mx-auto max-w-5xl fade-up">
            <div class="grid gap-6 lg:grid-cols-[1fr_0.72fr] lg:items-end">
                <div>
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <a href="{{ route('guides.index') }}" class="btn-footer-secondary">
                            <x-ui.icon name="book-open" class="h-4 w-4" />
                            <span>All Guides</span>
                        </a>
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
        <div class="mx-auto max-w-5xl">
            <div class="grid gap-4">
                @foreach ($guide['steps'] ?? [] as $index => $step)
                    <article class="product-section motion-card" data-scroll-reveal>
                        <div class="grid gap-5 md:grid-cols-[0.88fr_1.12fr] md:items-start">
                            @include('guides._visual', [
                                'variant' => $step['visual'] ?? 'default',
                                'title' => 'Step ' . ($index + 1),
                                'image' => $step['image'] ?? null,
                            ])

                            <div>
                                <div class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">
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
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">More Guides</p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    @foreach ($relatedGuides as $related)
                        <a href="{{ route('guides.show', $related['slug']) }}" data-scroll-reveal class="download-feature block">
                            <div class="text-sm font-semibold text-white">{{ $related['title'] }}</div>
                            <div class="mt-1 text-xs leading-5 text-gray-400">{{ $related['summary'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
@endsection
