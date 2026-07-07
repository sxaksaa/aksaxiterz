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
                Open a product, then pick the exact file, video, or setup resource you need.
            </p>
        </div>

        <div class="mx-auto grid max-w-5xl gap-4">
            @forelse ($downloads as $download)
                @php
                    $links = collect($download['links'] ?? [])
                        ->filter(fn ($link) => filled($link['url'] ?? null))
                        ->values();
                    $resourceLabel = $links->count() . ' ' . Str::plural('resource', $links->count());
                    $resourceIcon = static function (array $link): string {
                        $label = Str::lower((string) ($link['label'] ?? ''));
                        $url = Str::lower((string) ($link['url'] ?? ''));
                        $path = (string) parse_url($url, PHP_URL_PATH);
                        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

                        if (str_contains($label, 'video') || str_contains($label, 'tutorial') || in_array($extension, ['mp4', 'mov', 'mkv', 'webm'], true)) {
                            return 'play-circle';
                        }

                        if (str_contains($label, 'guide') || str_contains($label, 'setup') || in_array($extension, ['pdf', 'txt'], true)) {
                            return 'book-open';
                        }

                        if (in_array($extension, ['exe', 'zip', 'rar', '7z', 'dll', 'apk'], true)) {
                            return 'download';
                        }

                        return 'external-link';
                    };
                    $resourceMeta = static function (array $link): string {
                        $url = (string) ($link['url'] ?? '');
                        $path = (string) parse_url($url, PHP_URL_PATH);
                        $extension = Str::upper(pathinfo($path, PATHINFO_EXTENSION));

                        if ($extension !== '') {
                            return $extension;
                        }

                        $host = (string) parse_url($url, PHP_URL_HOST);

                        return $host !== '' ? $host : 'Link';
                    };
                @endphp

                <details class="download-card download-accordion motion-card text-left" @if ($loop->first) open @endif
                    data-download-accordion>
                    <summary class="download-accordion-summary">
                        <span class="min-w-0">
                            <span class="block truncate text-lg font-semibold text-white">{{ $download['name'] }}</span>
                            <span class="mt-1 block text-xs text-gray-500">{{ $resourceLabel }}</span>
                        </span>
                        <span class="download-accordion-chevron" aria-hidden="true">
                            <x-ui.icon name="chevron-down" class="h-4 w-4" />
                        </span>
                    </summary>

                    <div class="download-accordion-panel">
                        @forelse ($links as $link)
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer"
                                class="download-resource-link">
                                <span class="download-resource-icon">
                                    <x-ui.icon name="{{ $resourceIcon($link) }}" class="h-4 w-4" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-white">
                                        {{ $link['label'] ?? 'Download' }}
                                    </span>
                                    <span class="mt-0.5 block text-xs text-gray-500">
                                        {{ $resourceMeta($link) }}
                                    </span>
                                </span>
                                <x-ui.icon name="external-link" class="h-4 w-4 shrink-0 text-gray-500" />
                            </a>
                        @empty
                            <span class="download-resource-empty">
                                Download link not set
                            </span>
                        @endforelse
                    </div>
                </details>
            @empty
                <div class="empty-state">
                    No public downloads have been configured yet.
                </div>
            @endforelse
        </div>
    </section>
@endsection
