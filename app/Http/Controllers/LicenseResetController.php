<?php

namespace App\Http\Controllers;

use App\Exceptions\LicenseResetException;
use App\Models\License;
use App\Services\LicenseResetManager;
use Illuminate\Http\Request;

class LicenseResetController extends Controller
{
    public function store(Request $request, License $license, LicenseResetManager $licenseResetManager)
    {
        abort_unless((int) $license->user_id === (int) $request->user()->id, 404);

        $license->loadMissing(['product', 'order']);

        abort_unless($licenseResetManager->supports($license), 404);

        try {
            $attempt = $licenseResetManager->reset($license, $request->user());
        } catch (LicenseResetException $exception) {
            return back()->withErrors([
                'license_reset' => $exception->getMessage(),
            ]);
        }

        $providerLabel = $licenseResetManager->labelForProvider($attempt->provider);
        $cooldownHours = $licenseResetManager->cooldownHoursForProvider($attempt->provider);

        return back()->with(
            'license_reset_success',
            $providerLabel.' HWID for '.$attempt->username.' was reset successfully. You can reset it again in '.$cooldownHours.' hours.',
        );
    }
}
