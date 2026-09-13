<?php

return [
    'updated_at' => 'September 12, 2026',

    'items' => [
        [
            'slug' => 'sync-windows-date-time',
            'title' => 'Sync Windows Date & Time',
            'category' => 'Windows setup',
            'read_time' => '3 min read',
            'summary' => 'Keep your PC clock accurate before signing in or opening your tools. Enable automatic time, check your time zone, and sync the clock with Windows.',
            'visual' => 'date-time',
            'requirements' => [
                'Windows 11 or Windows 10; labels may vary slightly',
                'An active internet connection',
                'Use the time zone for your actual location',
                'No restart is normally needed',
            ],
            'steps' => [
                [
                    'title' => 'Open Date & time',
                    'body' => 'Press Win + I to open Settings. Go to Time & language, then Date & time. You can also right-click the taskbar clock and choose Adjust date and time.',
                    'visual' => 'date-time-open',
                ],
                [
                    'title' => 'Enable automatic time',
                    'body' => 'Turn on Set time automatically. Leave it on so Windows can keep your clock updated instead of relying on a manually entered date and time.',
                    'visual' => 'date-time-auto',
                ],
                [
                    'title' => 'Check your time zone',
                    'body' => 'Turn on Set time zone automatically and check the detected location. If this option is unavailable or the zone is wrong, turn it off and choose your zone manually. For WIB, select (UTC+07:00) Bangkok, Hanoi, Jakarta. Use your own local zone if you live elsewhere.',
                    'visual' => 'date-time-zone',
                ],
                [
                    'title' => 'Click Sync now',
                    'body' => 'Scroll to Additional settings or Synchronize your clock, then click Sync now. Wait for Windows to finish; the button may be disabled while synchronization is running.',
                    'visual' => 'date-time-sync',
                ],
                [
                    'title' => 'Confirm the sync succeeded',
                    'body' => 'Check that Last successful time synchronization shows a recent time and that the taskbar date and time are correct. Reopen your app and try again. If sync fails, check your internet connection and retry. On a work or school PC with locked settings, ask your administrator.',
                    'visual' => 'date-time-done',
                ],
            ],
        ],
        [
            'slug' => 'disable-hyper-v-windows',
            'title' => 'Disable Hyper-V on Windows',
            'category' => 'Windows setup',
            'read_time' => '3 min read',
            'summary' => 'Download the Hyper-V tool, apply DisableVBS.reg, turn off Memory integrity, and restart Windows for tools that require this compatibility setup.',
            'visual' => 'hyperv',
            'steps' => [
                [
                    'title' => 'Download the Hyper-V tool',
                    'body' => 'Download HD-DisableHyperV_native_v2.exe from the BlueStacks link below. Save your work before continuing. Disabling Hyper-V and VBS reduces Windows security protections and can affect WSL2, Docker, and virtual machines; use this setup only when your tool requires it.',
                    'visual' => 'hyperv-download',
                    'download_url' => 'https://cdn3.bluestacks.com/support_files/HD-DisableHyperV_native_v2.exe',
                    'download_label' => 'Download Hyper-V Tool',
                ],
                [
                    'title' => 'Run the tool as administrator',
                    'body' => 'Open Downloads, right-click HD-DisableHyperV_native_v2.exe, and select Run as administrator. Approve the Windows permission prompt and follow the tool instructions. If it asks you to restart, do so, then return to this guide.',
                    'visual' => 'hyperv-run',
                ],
                [
                    'title' => 'Download and merge DisableVBS.reg',
                    'body' => 'Download DisableVBS.reg below. Before importing it, export the DeviceGuard key from Registry Editor as a backup. Double-click the downloaded file, approve the administrator prompt, then choose Yes to merge it. This file sets VBS, platform security requirements, and the DeviceGuard WindowsHello scenario to 0. It does not remove your Windows Hello PIN.',
                    'visual' => 'vbs-registry',
                    'download_path' => 'storage/guides/DisableVBS.reg',
                    'download_label' => 'Download DisableVBS.reg',
                ],
                [
                    'title' => 'Turn off Memory integrity',
                    'body' => 'Open Windows Security from Start. Select Device security, then Core isolation details. Turn Memory integrity off and approve the administrator prompt if it appears. If it is already off, leave it off for this setup. If the setting is managed by your organization, contact your administrator. Restart in the next step to apply the change.',
                    'visual' => 'core-isolation',
                ],
                [
                    'title' => 'Restart and check the result',
                    'body' => 'Restart Windows after importing the registry file. Press Win + R, type msinfo32, and check Virtualization-based security in System Summary. If it still shows Running, the changes have not fully taken effect; managed policies or firmware settings may override them. Reopen your tool after the restart. Restore your previous security settings when this compatibility setup is no longer needed.',
                    'visual' => 'restart',
                ],
            ],
        ],
    ],
];
