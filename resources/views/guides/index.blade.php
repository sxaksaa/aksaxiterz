@extends('layouts.app')

@section('seo_title', 'Setup Guides - Aksa Xiterz')
@section('seo_description', 'Read practical setup, troubleshooting, and product usage guides from Aksa Xiterz.')

@section('content')
    <section class="page-shell pb-16 pt-14 md:pb-20 md:pt-16">
        <div class="mx-auto mb-6 max-w-5xl fade-up">
            <h1 class="text-3xl font-semibold text-white md:text-4xl">Guides</h1>
        </div>

        <div class="mx-auto grid max-w-5xl gap-4 md:grid-cols-2">
            @forelse ($guides as $guide)
                <a href="{{ route('guides.show', $guide['slug']) }}" data-scroll-reveal class="download-card motion-card block">
                    @include('guides._visual', [
                        'variant' => $guide['visual'] ?? 'default',
                        'title' => $guide['title'],
                        'image' => $guide['image'] ?? null,
                    ])

                    <div class="mt-5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="support-pill">{{ $guide['category'] ?? 'Guide' }}</span>
                            <span class="text-xs font-semibold text-gray-500">{{ $guide['read_time'] ?? 'Quick read' }}</span>
                        </div>

                        <h2 class="mt-4 text-xl font-semibold text-white">{{ $guide['title'] }}</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-400">{{ $guide['summary'] }}</p>
                    </div>
                </a>
            @empty
                <div class="empty-state md:col-span-2">
                    No public guides have been configured yet.
                </div>
            @endforelse
        </div>
    </section>
@endsection
