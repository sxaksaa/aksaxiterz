@extends('layouts.app')

@section('content')
    @php
        $isEditing = (bool) $editDownload;
        $formAction = $isEditing
            ? route('admin.downloads.update', $editDownload)
            : route('admin.downloads.store');
        $linksText = $isEditing ? $editDownload->links_text : '';
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Downloads</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Manage public download cards, setup folders, and direct file links shown on the Downloads page.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['total'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Download cards</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['links'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total links</div>
                </div>
            </div>
        </section>

        @if (session('info'))
            <div class="mb-4 rounded-xl border border-[#9333EA]/30 bg-[#9333EA]/10 px-4 py-3 text-sm text-[#D8B4FE]">
                {{ session('info') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="product-section mb-6 fade-up">
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">
                        {{ $isEditing ? 'Edit Download' : 'New Download' }}
                    </p>
                    <h2 class="mt-1 text-xl font-semibold text-white">
                        {{ $isEditing ? $editDownload->name : 'Add Download Card' }}
                    </h2>
                </div>

                @if ($isEditing)
                    <a href="{{ route('admin.downloads.index') }}" class="btn-footer-secondary w-fit">
                        <x-ui.icon name="x" class="h-4 w-4" />
                        <span>Cancel Edit</span>
                    </a>
                @endif
            </div>

            <form action="{{ $formAction }}" method="POST" class="grid gap-4">
                @csrf
                @if ($isEditing)
                    @method('PATCH')
                @endif

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Name</span>
                    <input name="name" value="{{ old('name', $editDownload->name ?? '') }}" class="search-bar w-full"
                        placeholder="Enter download name" required maxlength="120">
                </label>

                <label class="block lg:col-span-2">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Download links</span>
                    <textarea name="links_text" rows="5" class="search-bar min-h-32 w-full resize-y"
                        placeholder="Download Files | https://example.com/file.zip&#10;Mirror | https://example.com/mirror">{{ old('links_text', $linksText) }}</textarea>
                    <span class="mt-2 block text-xs text-gray-500">Use one link per line: Label | URL</span>
                </label>

                <div class="flex flex-wrap items-center gap-3 lg:col-span-2">
                    <button class="btn-footer h-12">
                        <x-ui.icon name="{{ $isEditing ? 'save' : 'download' }}" class="h-4 w-4" />
                        <span>{{ $isEditing ? 'Save Download' : 'Add Download' }}</span>
                    </button>
                </div>
            </form>
        </section>

        <section class="product-section mb-6 fade-up">
            <form method="GET" action="{{ route('admin.downloads.index') }}"
                class="grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="Name">
                </label>

                <div class="flex gap-2">
                    <button class="btn-footer h-12">
                        <x-ui.icon name="filter" class="h-4 w-4" />
                        <span>Filter</span>
                    </button>
                    <a href="{{ route('admin.downloads.index') }}" class="btn-footer-secondary h-12">
                        <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden md:block">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Download Cards</h2>
                    <p class="mt-1 text-xs text-gray-500">Every item listed here appears on the public Downloads page.</p>
                </div>
                <span class="rounded-lg border border-[#9333EA]/30 bg-[#9333EA]/10 px-3 py-1 text-xs font-semibold text-[#C084FC]">
                    {{ $downloads->total() }} records
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Download</th>
                            <th class="p-4 text-left">Links</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($downloads as $download)
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $download->name }}</div>
                                </td>
                                <td class="p-4">
                                    <div class="grid gap-2">
                                        @forelse ($download->links ?: [] as $link)
                                            <a href="{{ $link['url'] ?? '#' }}" target="_blank" rel="noopener noreferrer"
                                                class="max-w-[320px] truncate text-xs font-semibold text-[#D8B4FE] hover:text-white">
                                                {{ $link['label'] ?? 'Download' }}
                                            </a>
                                        @empty
                                            <span class="text-xs text-gray-500">No link</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="p-4 text-right">
                                    <div class="inline-flex justify-end gap-2">
                                        <a href="{{ route('admin.downloads.index', array_merge(request()->query(), ['edit' => $download->id])) }}"
                                            class="order-action">
                                            <x-ui.icon name="edit-3" class="h-4 w-4" />
                                            <span>Edit</span>
                                        </a>
                                        <form action="{{ route('admin.downloads.destroy', $download) }}" method="POST"
                                            data-confirm="Delete this download item?">
                                            @csrf
                                            @method('DELETE')
                                            <button class="order-action order-action-danger">
                                                <x-ui.icon name="trash-2" class="h-4 w-4" />
                                                <span>Delete</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="p-8">
                                    <div class="empty-state">No download items found</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 md:hidden">
            @forelse ($downloads as $download)
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-white">{{ $download->name }}</div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('admin.downloads.index', array_merge(request()->query(), ['edit' => $download->id])) }}"
                            class="order-action">
                            <x-ui.icon name="edit-3" class="h-4 w-4" />
                            <span>Edit</span>
                        </a>
                        <form action="{{ route('admin.downloads.destroy', $download) }}" method="POST"
                            data-confirm="Delete this download item?">
                            @csrf
                            @method('DELETE')
                            <button class="order-action order-action-danger">
                                <x-ui.icon name="trash-2" class="h-4 w-4" />
                                <span>Delete</span>
                            </button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="empty-state">No download items found</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $downloads,
            'label' => 'Downloads pagination',
            'itemLabel' => 'downloads',
        ])
    </div>
@endsection
