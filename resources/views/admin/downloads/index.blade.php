@extends('layouts.app')

@section('content')
    @php
        $addingDownload = old('download_action') === 'create';
        $downloadQuery = request()->only(['search', 'visibility', 'page']);
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <div>
                    <p class="mb-2 text-sm font-semibold text-aksa-accent">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Downloads</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Manage public download cards, setup folders, and direct file links shown on the Downloads page.
                    </p>
                </div>
            </div>

            <div class="admin-stat-grid mt-6 grid gap-3 sm:grid-cols-3">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['total'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Download cards</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['links'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total links</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['hidden'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Hidden cards</div>
                </div>
            </div>
        </section>

        @if (session('info'))
            <div class="mb-4 rounded-xl border border-aksa-accent-30 bg-aksa-accent-10 px-4 py-3 text-sm text-aksa-accent-soft">
                {{ session('info') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="mb-4 flex flex-wrap gap-3">
            <button type="button" class="btn-footer-secondary" data-catalog-panel-toggle aria-controls="downloadAddPanel" aria-expanded="{{ $addingDownload ? 'true' : 'false' }}">
                <x-ui.icon name="box" class="h-4 w-4" />
                <span>Add download</span>
                <x-ui.icon name="chevron-down" class="catalog-chevron h-4 w-4" />
            </button>
            <button type="button" class="btn-footer-secondary" data-catalog-panel-toggle aria-controls="downloadSearchPanel" aria-expanded="false">
                <x-ui.icon name="search" class="h-4 w-4" />
                <span>Search{{ request()->filled('search') ? ' (active)' : '' }}</span>
                <x-ui.icon name="chevron-down" class="catalog-chevron h-4 w-4" />
            </button>
        </div>

        <div id="downloadAddPanel" class="catalog-disclosure-panel" @if (! $addingDownload) hidden @endif>
            <div class="catalog-disclosure-spacing">
                <section class="product-section">
                    <h2 class="mb-4 text-sm font-semibold text-white">Add download</h2>
                    <form action="{{ route('admin.downloads.store') }}" method="POST" class="grid gap-4">
                        @csrf
                        <input type="hidden" name="download_action" value="create">
                        <label class="block text-xs text-gray-400">Name
                            <input name="name" value="{{ $addingDownload ? old('name') : '' }}" class="search-bar mt-2 w-full" placeholder="Download name" required maxlength="120">
                        </label>
                        <label class="block text-xs text-gray-400">Download links
                            <textarea name="links_text" rows="4" maxlength="20000" class="search-bar mt-2 w-full resize-y" placeholder="Setup | https://example.com/setup.zip">{{ $addingDownload ? old('links_text') : '' }}</textarea>
                            <span class="mt-2 block text-xs text-gray-500">One link per line: Label | URL. Leave empty for a card without links.</span>
                        </label>
                        <label class="block text-xs text-gray-400">Visibility
                            <select name="is_visible" class="search-bar mt-2 w-full" required>
                                <option value="1" @selected((string) ($addingDownload ? old('is_visible', '1') : '1') === '1')>Public</option>
                                <option value="0" @selected((string) ($addingDownload ? old('is_visible', '1') : '1') === '0')>Hidden</option>
                            </select>
                            <span class="mt-2 block text-xs text-gray-500">Hidden cards remain available here but do not appear on the public Downloads page.</span>
                        </label>
                        <button type="submit" class="btn-footer h-12 w-fit">Add download</button>
                    </form>
                </section>
            </div>
        </div>

        <div id="downloadSearchPanel" class="catalog-disclosure-panel" hidden>
            <div class="catalog-disclosure-spacing">
                <section class="product-section">
                    <form method="GET" action="{{ route('admin.downloads.index') }}" class="grid items-end gap-3 md:grid-cols-[1fr_180px_auto]">
                        <label class="block text-xs text-gray-400">Search
                            <input name="search" value="{{ request('search') }}" class="search-bar mt-2 w-full" placeholder="Download name">
                        </label>
                        <label class="block text-xs text-gray-400">Visibility
                            <select name="visibility" class="search-bar mt-2 w-full">
                                <option value="">All</option>
                                <option value="visible" @selected(request('visibility') === 'visible')>Public</option>
                                <option value="hidden" @selected(request('visibility') === 'hidden')>Hidden</option>
                            </select>
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" class="btn-footer h-12">Search</button>
                            <a href="{{ route('admin.downloads.index') }}" class="btn-footer-secondary h-12">Reset</a>
                        </div>
                    </form>
                </section>
            </div>
        </div>

        <section class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-white">Download Cards</h2>
                    <p class="mt-1 text-xs text-gray-500">Click a download name to manage it. Only public items appear on the public Downloads page.</p>
                </div>
                <span class="text-xs text-aksa-accent">{{ $downloads->total() }} records</span>
            </div>
            @forelse ($downloads as $download)
                @php
                    $editingDownload = (string) old('edit_download_id') === (string) $download->id;
                    $downloadOpen = $editingDownload || (string) request('edit') === (string) $download->id
                        || (string) old('delete_download_id') === (string) $download->id;
                @endphp
                <details class="product-section catalog-accordion" data-catalog-accordion @if ($downloadOpen) open @endif>
                    <summary class="catalog-accordion-summary text-sm font-semibold text-white">
                        <span class="inline-flex items-center gap-2">
                            <x-ui.icon name="download" class="h-4 w-4" />
                            {{ $download->name }}
                            <span class="rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $download->is_visible ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-zinc-600 bg-zinc-800 text-zinc-400' }}">
                                {{ $download->is_visible ? 'Public' : 'Hidden' }}
                            </span>
                        </span>
                        <x-ui.icon name="chevron-down" class="catalog-chevron h-4 w-4" />
                    </summary>
                    <p class="mt-3 text-xs text-gray-400">{{ count($download->links ?: []) }} links</p>
                    <form data-catalog-edit-form action="{{ route('admin.downloads.update', ['download' => $download, ...$downloadQuery]) }}" method="POST" class="mt-4 space-y-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="edit_download_id" value="{{ $download->id }}">
                        <fieldset data-catalog-fields class="grid gap-4" @disabled(! $editingDownload)>
                            <label class="block text-xs text-gray-400">Name
                                <input name="name" value="{{ $editingDownload ? old('name', $download->name) : $download->name }}" class="search-bar mt-2 w-full" required maxlength="120">
                            </label>
                            <label class="block text-xs text-gray-400">Download links
                                <textarea name="links_text" rows="5" maxlength="20000" class="search-bar mt-2 w-full resize-y" placeholder="Setup | https://example.com/setup.zip">{{ $editingDownload ? old('links_text', $download->links_text) : $download->links_text }}</textarea>
                                <span class="mt-2 block text-xs text-gray-500">One link per line: Label | URL. Leave empty for a card without links.</span>
                            </label>
                            <label class="block text-xs text-gray-400">Visibility
                                <select name="is_visible" class="search-bar mt-2 w-full" required>
                                    <option value="1" @selected((string) ($editingDownload ? old('is_visible', $download->is_visible ? '1' : '0') : ($download->is_visible ? '1' : '0')) === '1')>Public</option>
                                    <option value="0" @selected((string) ($editingDownload ? old('is_visible', $download->is_visible ? '1' : '0') : ($download->is_visible ? '1' : '0')) === '0')>Hidden</option>
                                </select>
                                <span class="mt-2 block text-xs text-gray-500">Set to Hidden to remove this card from the public Downloads page.</span>
                            </label>
                        </fieldset>
                        <button type="button" data-catalog-edit-button class="btn-footer">{{ $editingDownload ? 'Save' : 'Edit' }}</button>
                    </form>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-[#27272A] pt-4">
                        <div class="flex flex-wrap items-center gap-3">
                            @foreach ($download->links ?: [] as $link)
                                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs text-aksa-accent-soft hover:text-white">{{ $link['label'] ?? 'Download' }}</a>
                            @endforeach
                            @if (count($download->links ?: []) > 0)
                                <button type="button" data-download-copy="{{ $download->id }}" data-copy-value="{{ collect($download->links)->pluck('url')->implode("\n") }}" data-copy-title="Download links copied" data-copy-message="The download URLs are ready to paste." class="order-action btn-press">
                                    <x-ui.icon name="copy" class="h-4 w-4" />
                                    <span data-button-label>Copy</span>
                                </button>
                            @endif
                        </div>
                        <form action="{{ route('admin.downloads.destroy', ['download' => $download, ...$downloadQuery]) }}" method="POST" data-confirm="Delete this download?">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="delete_download_id" value="{{ $download->id }}">
                            <button type="submit" class="order-action order-action-danger disabled:opacity-50">Delete download</button>
                        </form>
                    </div>
                </details>
            @empty
                <div class="empty-state">No downloads found</div>
            @endforelse
        </section>

        @include('partials.pagination', [
            'paginator' => $downloads,
            'label' => 'Downloads pagination',
            'itemLabel' => 'downloads',
        ])
    </div>
@endsection
