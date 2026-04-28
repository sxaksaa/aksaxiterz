@extends('layouts.app')

@section('content')
    <section class="page-shell pb-8 pt-6 md:pt-10">
        <div class="download-hero mx-auto max-w-5xl fade-up">
            <div class="max-w-3xl">
                <p class="mb-2 text-sm font-semibold text-[#C084FC]">Public Download Tools</p>
                <h1 class="text-3xl font-bold tracking-normal md:text-5xl">
                    Get instant access to all tools and setup files in one place.
                </h1>
                <p class="mt-4 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                    Latest public download collection for setup packages, companion files, emulator resources, and required
                    runtimes. Use the matching card below and open the file folder you need.
                </p>
            </div>

            <div class="mt-6 grid max-w-4xl gap-3 md:grid-cols-3">
                <div class="download-feature">
                    <div class="text-sm font-semibold text-white">Public files</div>
                    <div class="mt-1 text-xs leading-5 text-gray-400">Download folders and direct setup files.</div>
                </div>
                <div class="download-feature">
                    <div class="text-sm font-semibold text-white">Setup included</div>
                    <div class="mt-1 text-xs leading-5 text-gray-400">Tutorials, setup packages, and requirements when available.</div>
                </div>
                <div class="download-feature">
                    <div class="text-sm font-semibold text-white">Support ready</div>
                    <div class="mt-1 text-xs leading-5 text-gray-400">Need help or reset? Discord is available from the navbar.</div>
                </div>
            </div>

        </div>
    </section>

    <section class="page-shell pb-16 md:pb-20">
        <div class="mx-auto mb-5 flex max-w-5xl flex-col gap-2">
            <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Files</p>
            <h2 class="text-2xl font-semibold text-white">Choose what you need</h2>
            <p class="max-w-2xl text-sm leading-6 text-gray-400">
                Each card opens the latest public file folder or direct setup package.
            </p>
        </div>

        <div class="mx-auto grid max-w-5xl gap-4 md:grid-cols-2">
            @forelse ($downloads as $download)
                @php
                    $links = collect($download['links'] ?? [])->filter(fn ($link) => filled($link['url'] ?? null));
                @endphp

                <article class="download-card motion-card flex flex-col text-left">
                    <div class="flex flex-col items-start gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-white">{{ $download['name'] }}</h2>
                            <p class="mt-2 text-sm leading-6 text-gray-400">{{ $download['description'] }}</p>
                        </div>

                        <span
                            class="self-start rounded-lg border border-[#9333EA]/35 bg-[#9333EA]/10 px-3 py-1 text-xs font-semibold text-[#C084FC]">
                            {{ $download['version'] }}
                        </span>
                    </div>

                    <dl class="download-meta mt-5 grid gap-3 rounded-xl border border-[#27272A] bg-black/20 p-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs uppercase tracking-normal text-gray-500">Platform</dt>
                            <dd class="mt-1 text-gray-300">{{ $download['platform'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-normal text-gray-500">Files</dt>
                            <dd class="mt-1 text-gray-300">
                                {{ $links->count() }} download {{ $links->count() === 1 ? 'link' : 'links' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-auto grid gap-3 pt-5">
                        @forelse ($links as $link)
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer"
                                class="inline-flex w-full items-center justify-center rounded-xl px-4 py-3 text-sm font-semibold transition btn-main">
                                {{ $link['label'] }}
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
