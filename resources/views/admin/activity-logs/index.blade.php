@extends('layouts.app')

@section('content')
    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <p class="mb-2 text-sm font-semibold text-aksa-accent">Admin</p>
                <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Activity</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                    Review successful catalog, stock, voucher, download, and order changes made by administrators.
                </p>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['total'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">All activity</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['today'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Today</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['seven_days'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Last 7 days</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['admins'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Admins recorded</div>
                </div>
            </div>
        </section>

        <section class="product-section mb-6 fade-up">
            <form method="GET" action="{{ route('admin.activity.index') }}"
                class="grid gap-3 md:grid-cols-2 md:items-end xl:grid-cols-[1fr_0.7fr_0.6fr_auto]">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="Admin, target, or action">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Area</span>
                    <select name="section" class="search-bar w-full">
                        <option value="">All areas</option>
                        @foreach ($sectionOptions as $sectionValue => $sectionLabel)
                            <option value="{{ $sectionValue }}" @selected(request('section') === $sectionValue)>
                                {{ $sectionLabel }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Period</span>
                    <select name="period" class="search-bar w-full">
                        <option value="all" @selected($period === 'all')>All time</option>
                        <option value="today" @selected($period === 'today')>Today</option>
                        <option value="7" @selected($period === '7')>7 days</option>
                        <option value="30" @selected($period === '30')>30 days</option>
                    </select>
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="btn-footer h-12">
                        <x-ui.icon name="filter" class="h-4 w-4" />
                        <span>Filter</span>
                    </button>
                    <a href="{{ route('admin.activity.index') }}" class="btn-footer-secondary h-12">
                        <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden lg:block">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Admin Activity</h2>
                    <p class="mt-1 text-xs text-gray-500">Only successful write actions are recorded.</p>
                </div>
                <span class="rounded-lg border border-aksa-accent-30 bg-aksa-accent-10 px-3 py-1 text-xs font-semibold text-aksa-accent">
                    {{ $logs->total() }} records
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1120px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Time</th>
                            <th class="p-4 text-left">Admin</th>
                            <th class="p-4 text-left">Activity</th>
                            <th class="p-4 text-left">Target</th>
                            <th class="p-4 text-left">Details</th>
                            <th class="p-4 text-left">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr class="orders-table-row">
                                <td class="p-4 text-xs text-gray-300">
                                    <div>{{ $log->created_at?->timezone(config('app.timezone'))->format('d M Y') ?? '-' }}</div>
                                    <div class="mt-1 text-gray-500">{{ $log->created_at?->timezone(config('app.timezone'))->format('H:i:s') ?? '-' }} WIB</div>
                                </td>
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $log->admin_name }}</div>
                                    <div class="mt-1 max-w-[220px] truncate text-xs text-gray-500">{{ $log->admin_email }}</div>
                                </td>
                                <td class="p-4">
                                    <span class="inline-flex rounded-lg border border-aksa-accent-30 bg-aksa-accent-10 px-2.5 py-1 text-xs font-semibold text-aksa-accent-soft">
                                        {{ $log->section_label }}
                                    </span>
                                    <div class="mt-2 font-semibold text-white">{{ $log->action_label }}</div>
                                </td>
                                <td class="p-4">
                                    <div class="max-w-[220px] truncate font-semibold text-white">{{ $log->subject_label ?: '-' }}</div>
                                    @if ($log->subject_type)
                                        <div class="mt-1 text-xs text-gray-500">
                                            {{ $log->subject_type }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}
                                        </div>
                                    @endif
                                </td>
                                <td class="p-4 text-xs leading-5 text-gray-400">{{ $log->display_details ?: '-' }}</td>
                                <td class="p-4 font-mono text-xs text-gray-400" title="{{ $log->user_agent }}">
                                    {{ $log->ip_address ?: '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-8">
                                    <div class="empty-state">No admin activity found</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 lg:hidden">
            @forelse ($logs as $log)
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-white">{{ $log->action_label }}</div>
                            <div class="mt-1 truncate text-xs text-gray-500">{{ $log->admin_name }} · {{ $log->admin_email }}</div>
                        </div>
                        <span class="inline-flex shrink-0 rounded-lg border border-aksa-accent-30 bg-aksa-accent-10 px-2.5 py-1 text-xs font-semibold text-aksa-accent-soft">
                            {{ $log->section_label }}
                        </span>
                    </div>

                    <div class="mt-4 grid gap-2 text-sm text-gray-400">
                        <div>Target: <span class="font-semibold text-white">{{ $log->subject_label ?: '-' }}</span></div>
                        @if ($log->display_details)
                            <div>Details: <span class="text-gray-300">{{ $log->display_details }}</span></div>
                        @endif
                        <div>Time: {{ $log->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i:s') ?? '-' }} WIB</div>
                        <div>IP: <span class="font-mono">{{ $log->ip_address ?: '-' }}</span></div>
                    </div>
                </article>
            @empty
                <div class="empty-state">No admin activity found</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $logs,
            'label' => 'Admin activity pagination',
            'itemLabel' => 'records',
        ])
    </div>
@endsection
