<?php

return [
    'updated_at' => 'April 28, 2026',

    'items' => [
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
        [
            'slug' => 'clean-windows-temp-files',
            'title' => 'Clean Windows Temporary Files',
            'category' => 'Maintenance',
            'read_time' => '3 min read',
            'summary' => 'Clear common temporary folders to free space and reduce setup errors caused by stale files.',
            'visual' => 'cleanup',
            'requirements' => [
                'Windows 10 or Windows 11',
                'Close active apps before cleaning',
                'Skip files Windows says are in use',
            ],
            'steps' => [
                [
                    'title' => 'Open the temp folder',
                    'body' => 'Press Win + R, type temp, then press Enter. Select the files inside the folder and delete what Windows allows.',
                    'visual' => 'folder',
                ],
                [
                    'title' => 'Open the user temp folder',
                    'body' => 'Press Win + R again, type %temp%, then press Enter. Delete the files inside this folder too.',
                    'visual' => 'user-temp',
                ],
                [
                    'title' => 'Use Disk Cleanup',
                    'body' => 'Search Disk Cleanup from Start, select your Windows drive, then clean temporary files, thumbnails, and recycle bin items if needed.',
                    'visual' => 'cleanup-tool',
                ],
                [
                    'title' => 'Restart before reinstalling',
                    'body' => 'Restart Windows before reinstalling or opening setup tools. This gives Windows a clean session and clears locked temporary files.',
                    'visual' => 'restart',
                ],
            ],
        ],
    ],
];
