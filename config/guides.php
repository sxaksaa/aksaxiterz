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
            'read_time' => '6 min read',
            'summary' => 'Turn off Hyper-V, Core Isolation, and Windows Hello related security layers before using tools or emulators that need direct virtualization access.',
            'visual' => 'hyperv',
            'requirements' => [
                'Windows 10 or Windows 11',
                'Administrator access',
                'A restart after changes',
                'Re-enable security features when you no longer need this compatibility setup',
            ],
            'steps' => [
                [
                    'title' => 'Open Windows Features',
                    'body' => 'Press Win + R, type optionalfeatures, then press Enter. Wait until the Windows Features window appears.',
                    'visual' => 'features',
                ],
                [
                    'title' => 'Turn off virtualization features',
                    'body' => 'Uncheck Hyper-V, Virtual Machine Platform, Windows Hypervisor Platform, and Windows Sandbox if they are enabled.',
                    'visual' => 'checkboxes',
                ],
                [
                    'title' => 'Turn off Core Isolation',
                    'body' => 'Open Windows Security, go to Device security, then Core isolation details. Turn Memory integrity off, approve the prompt, and restart when Windows asks.',
                    'visual' => 'core-isolation',
                ],
                [
                    'title' => 'Disable Windows Hello sign-in',
                    'body' => 'Open Settings, go to Accounts, then Sign-in options. Turn off the Windows Hello-only sign-in requirement if it appears, then remove PIN, fingerprint, or face sign-in if your setup requires it.',
                    'visual' => 'windows-hello',
                ],
                [
                    'title' => 'Use Registry only if Settings is locked',
                    'body' => 'Most users do not need Regedit. If Windows Hello settings are locked on your own PC, open Registry Editor as administrator, check HKLM\\SOFTWARE\\Policies\\Microsoft\\PassportForWork, and set Enabled to 0 or create it as a DWORD. Restart after changing it.',
                    'visual' => 'regedit',
                ],
                [
                    'title' => 'Restart your PC',
                    'body' => 'Click OK, let Windows apply the changes, then restart. Do not skip the restart because the hypervisor can stay active until reboot.',
                    'visual' => 'restart',
                ],
                [
                    'title' => 'Check your tool again',
                    'body' => 'Open the tool or emulator that needed Hyper-V disabled. If it still fails, run Command Prompt as administrator and use bcdedit /set hypervisorlaunchtype off, then restart again.',
                    'visual' => 'terminal',
                ],
            ],
        ],
    ],
];
