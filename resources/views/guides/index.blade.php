@extends('layouts.app')

@section('content')
    <section class="page-shell pb-8 pt-6 md:pt-10">
        <div class="download-hero mx-auto max-w-5xl fade-up">
            <div class="grid gap-6 lg:grid-cols-[1fr_0.72fr] lg:items-end">
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Public Guides</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-5xl">
                        Some Guides to Fix Windows Problems.
                    </h1>
                    <p class="mt-4 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Practical tutorials for common setup issues.
                    </p>
                </div>

                @include('guides._visual', ['variant' => 'overview', 'title' => 'Guide preview'])
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <span class="support-pill">Windows setup</span>
                <span class="support-pill">Troubleshooting</span>
                <span class="support-pill">Updated {{ $updatedAt }}</span>
            </div>
        </div>
    </section>

    <section class="page-shell pb-16 md:pb-20">
        <div class="mx-auto mb-5 flex max-w-5xl flex-col gap-2">
            <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Knowledge Base</p>
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
