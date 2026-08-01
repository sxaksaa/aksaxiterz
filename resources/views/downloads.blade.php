@extends('layouts.app')

@section('content')
    <section class="page-shell pb-10 pt-10 md:pb-14 md:pt-16">
        <div class="download-hero mx-auto max-w-5xl fade-up">
            <h1 class="hero-title">
                All setup files in <span class="hero-accent">one clean place.</span>
            </h1>
            <p class="hero-copy">
                Public setup packages, companion files, emulator resources, and required runtimes. Pick the matching
                product below and open the exact file you need.
            </p>
        </div>
    </section>

    <section class="page-shell pb-16 md:pb-20">
        <div class="mx-auto mb-5 flex max-w-5xl flex-col gap-2">
            <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Files</p>
            <h2 class="text-2xl font-semibold text-white">Choose what you need</h2>
            <p class="max-w-2xl text-sm leading-6 text-gray-400">
                Open a product, then pick the exact file, video, or setup resource you need.
            </p>
            <input id="downloadSearch" type="search" class="search-bar mt-3" placeholder="Search downloads..."
                autocomplete="off">
        </div>

        <div class="mx-auto grid max-w-5xl gap-4" data-download-accordion-group>
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

                        if (in_array($extension, ['exe', 'ipa', 'xapk', 'apk', 'zip', 'rar', '7z', 'dll', 'msi'], true)) {
                            return 'download';
                        }

                        return 'download';
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

                <details class="download-card download-accordion motion-card text-left" data-download-accordion
                    data-download-search="{{ Str::lower($download['name'].' '.$links->pluck('label')->implode(' ')) }}">
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
