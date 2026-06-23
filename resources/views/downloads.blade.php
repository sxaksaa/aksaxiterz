@extends('layouts.app')

@section('content')
    <section class="page-shell pb-8 pt-6 md:pt-10">
        <div class="download-hero mx-auto flex max-w-5xl items-center fade-up">
            <div class="max-w-3xl">
                <p class="mb-2 text-sm font-semibold text-[#C084FC]">Public Download Tools</p>
                <h1 class="text-3xl font-bold tracking-normal md:text-5xl">
                    Get instant access to all tools and download files in one place.
                </h1>
                <p class="mt-4 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                    Public setup packages, companion files, emulator resources, and required runtimes. Pick the matching
                    card below and open the file folder you need.
                </p>
            </div>
        </div>
    </section>

    <section class="page-shell pb-16 md:pb-20">
        <div class="mx-auto mb-5 flex max-w-5xl flex-col gap-2">
            <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Files</p>
            <h2 class="text-2xl font-semibold text-white">Choose what you need</h2>
            <p class="max-w-2xl text-sm leading-6 text-gray-400">
                Each card opens a public file folder or direct download file.
            </p>
        </div>

        <div class="mx-auto grid max-w-5xl gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($downloads as $download)
                @php
                    $links = collect($download['links'] ?? [])->filter(fn ($link) => filled($link['url'] ?? null));
                @endphp

                <article class="download-card motion-card flex flex-col text-left">
                    <h2 class="text-lg font-semibold text-white">{{ $download['name'] }}</h2>

                    <div class="mt-auto grid gap-3 pt-5">
                        @forelse ($links as $link)
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer"
                                class="inline-flex w-full items-center justify-center rounded-xl px-4 py-3 text-sm font-semibold transition btn-main">
                                <x-ui.icon name="download" class="h-4 w-4" />
                                <span>{{ $link['label'] }}</span>
                            </a>
                        @empty
                            <span
                                class="inline-flex w-full cursor-not-allowed items-center justify-center rounded-xl border border-[#27272A] bg-[#15151B] px-4 py-3 text-sm font-semibold text-gray-500">
                                Download link not set
                            </span>
                        @endforelse
                    </div>
                </article>
            @empty
                <div class="empty-state md:col-span-2">
                    No public downloads have been configured yet.
                </div>
            @endforelse
        </div>
    </section>
@endsection
