@php
    $variant = $variant ?? 'default';
    $title = $title ?? 'Guide preview';
    $image = $image ?? null;
    $imageUrl = $image && Illuminate\Support\Str::startsWith($image, ['http://', 'https://'])
        ? $image
        : ($image ? asset($image) : null);
    $lines = match ($variant) {
        'overview' => ['Step-by-step fixes', 'Windows setup', 'Cleanup checklist', 'Troubleshooting notes'],
        'date-time' => ['Date & time', 'Set time automatically: On', 'Check your time zone', 'Sync now'],
        'date-time-open' => ['Windows Settings', 'Time & language', 'Date & time'],
        'date-time-auto' => ['Date & time', 'Set time automatically: On', 'Keep your clock up to date'],
        'date-time-zone' => ['Set time zone automatically: On', 'Check your local time zone', 'WIB example: UTC+07:00', 'Bangkok, Hanoi, Jakarta'],
        'date-time-sync' => ['Additional settings', 'Synchronize your clock', 'Sync now'],
        'date-time-done' => ['Sync complete', 'Last successful time synchronization', 'Check for a recent timestamp', 'Reopen your app'],
        'hyperv' => ['Windows Features', 'Hyper-V', 'Virtual Machine Platform', 'Windows Hypervisor Platform'],
        'hyperv-download' => ['BlueStacks download', 'Hyper-V Tool', 'HD-DisableHyperV_native_v2.exe'],
        'hyperv-run' => ['Downloads', 'Hyper-V Tool', 'Run as administrator', 'Follow the prompts'],
        'vbs-registry' => ['DisableVBS.reg', 'Back up DeviceGuard first', 'Merge registry file', 'Restart to apply'],
        'features' => ['Run', 'optionalfeatures', 'Open Windows Features'],
        'checkboxes' => ['Hyper-V', 'Virtual Machine Platform', 'Windows Hypervisor Platform'],
        'core-isolation' => ['Windows Security', 'Device security', 'Core isolation', 'Memory integrity: Off'],
        'windows-hello' => ['Settings > Accounts', 'Sign-in options', 'Windows Hello', 'PIN / Face / Fingerprint: Remove'],
        'regedit' => ['Registry Editor', 'PassportForWork', 'Enabled = 0', 'Restart Windows'],
        'terminal' => ['Administrator Command Prompt', 'bcdedit /set hypervisorlaunchtype off', 'The operation completed successfully.'],
        'cleanup' => ['Temporary Files', '12,481 files scanned', 'Ready to clean'],
        'folder' => ['C:\\Windows\\Temp', 'cache.tmp', 'setup.log', 'old-package.tmp'],
        'user-temp' => ['%temp%', 'browser-cache', 'installer-cache', 'session.tmp'],
        'prefetch' => ['C:\\Windows\\Prefetch', 'APP-9F2A.pf', 'SETUP-41D0.pf', 'Rebuilds automatically'],
        'recent' => ['Recent Items', 'setup.lnk', 'downloads.lnk', 'Shortcuts only'],
        'cleanup-tool' => ['Disk Cleanup', 'Temporary files', 'Thumbnails', 'Recycle Bin'],
        'recycle-bin' => ['Recycle Bin', 'Review first', 'Empty selected items', 'Space recovered'],
        'restart' => ['Restart required', 'Save your work', 'Apply changes after reboot'],
        default => ['Aksa Xiterz Guide', 'Step-by-step setup', 'Public support notes'],
    };
@endphp

<div class="relative overflow-hidden rounded-xl border border-aksa-accent-30 bg-[#111115] p-4">
    <div class="absolute inset-0 aksa-guide-visual-glow"></div>

    @if ($imageUrl)
        <img src="{{ $imageUrl }}" alt="{{ $title }}" class="relative aspect-[16/10] w-full rounded-lg border border-[#27272A] object-cover shadow-2xl">
    @else
        <div class="relative rounded-lg border border-[#27272A] bg-black/35 shadow-2xl">
        <div class="flex items-center gap-2 border-b border-[#27272A] px-3 py-2">
            <span class="h-2.5 w-2.5 rounded-full bg-red-400/80"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-yellow-300/80"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-aksa-accent-soft"></span>
            <span class="ml-2 truncate text-[11px] font-semibold text-gray-500">{{ $title }}</span>
        </div>

        <div class="grid gap-2 p-4">
            @foreach ($lines as $line)
                <div class="rounded-lg border border-[#27272A] bg-[#15151B]/90 px-3 py-2 text-xs font-semibold text-gray-300">
                    @if (str_starts_with($variant, 'date-time') && str_ends_with($line, ': On'))
                        <span class="flex items-center justify-between gap-3">
                            <span>{{ substr($line, 0, -4) }}</span>
                            <span class="flex shrink-0 items-center gap-2 text-aksa-accent-soft">
                                On <span aria-hidden="true" class="flex h-4 w-7 items-center justify-end rounded-full bg-aksa-accent-soft px-0.5"><span class="h-3 w-3 rounded-full bg-[#111115]"></span></span>
                            </span>
                        </span>
                    @elseif (str_starts_with($variant, 'date-time') && in_array($line, ['Sync now', 'Sync complete']))
                        <span class="flex items-center gap-2 text-aksa-accent-soft">
                            <x-ui.icon :name="$line === 'Sync complete' ? 'check' : 'rotate-ccw'" class="h-4 w-4" />
                            {{ $line }}
                        </span>
                    @else
                        {{ $line }}
                    @endif
                </div>
            @endforeach
        </div>
        </div>
    @endif
</div>
