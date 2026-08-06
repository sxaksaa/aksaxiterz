@extends('layouts.app')

@section('seo_title', 'Setup Guides - Aksa Xiterz')
@section('seo_description', 'Read practical setup, troubleshooting, and product usage guides from Aksa Xiterz.')

@section('content')
    <section class="page-shell pb-10 pt-10 md:pb-14 md:pt-16">
        <div class="download-hero mx-auto max-w-5xl fade-up">
            <h1 class="hero-title">
                Practical guides for <span class="hero-accent">clean setup</span> fixes.
            </h1>
            <p class="hero-copy">
                Short tutorials for common Windows, emulator, and setup problems.
            </p>
        </div>
    </section>

    <section class="page-shell pb-16 md:pb-20">
        <div class="mx-auto mb-5 flex max-w-5xl flex-col gap-2">
            <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Knowledge Base</p>
            <h2 class="text-2xl font-semibold text-white">Choose a guide</h2>
            <p class="max-w-2xl text-sm leading-6 text-gray-400">
                Open a guide and follow each step in order.
            </p>
        </div>

        <div class="mx-auto grid max-w-5xl gap-4 md:grid-cols-2">
            @forelse ($guides as $guide)
                <a href="{{ route('guides.show', $guide['slug']) }}" class="download-card motion-card block">
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
