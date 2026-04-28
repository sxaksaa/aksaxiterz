@php
    $variant = $variant ?? 'default';
    $title = $title ?? 'Guide preview';
    $image = $image ?? null;
    $imageUrl = $image && Illuminate\Support\Str::startsWith($image, ['http://', 'https://'])
        ? $image
        : ($image ? asset($image) : null);
    $lines = match ($variant) {
        'hyperv' => ['Windows Features', '[ ] Hyper-V', '[ ] Virtual Machine Platform', '[ ] Windows Hypervisor Platform'],
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

<div class="relative overflow-hidden rounded-xl border border-[#9333EA]/30 bg-[#111115] p-4">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_0%,rgba(147,51,234,0.28),transparent_18rem)]"></div>

    @if ($imageUrl)
        <img src="{{ $imageUrl }}" alt="{{ $title }}" class="relative aspect-[16/10] w-full rounded-lg border border-[#27272A] object-cover shadow-2xl">
    @else
        <div class="relative rounded-lg border border-[#27272A] bg-black/35 shadow-2xl">
        <div class="flex items-center gap-2 border-b border-[#27272A] px-3 py-2">
            <span class="h-2.5 w-2.5 rounded-full bg-red-400/80"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-yellow-300/80"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-green-400/80"></span>
            <span class="ml-2 truncate text-[11px] font-semibold text-gray-500">{{ $title }}</span>
        </div>

        <div class="grid gap-2 p-4">
            @foreach ($lines as $line)
                <div class="rounded-lg border border-[#27272A] bg-[#15151B]/90 px-3 py-2 text-xs font-semibold text-gray-300">
                    {{ $line }}
                </div>
            @endforeach
        </div>
        </div>
    @endif
</div>
