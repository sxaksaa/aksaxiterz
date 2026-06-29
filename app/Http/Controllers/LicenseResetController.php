<?php

namespace App\Http\Controllers;

use App\Exceptions\BrModsResetException;
use App\Models\License;
use App\Services\BrModsResetService;
use Illuminate\Http\Request;

class LicenseResetController extends Controller
{
    public function store(Request $request, License $license, BrModsResetService $brModsResetService)
    {
        abort_unless((int) $license->user_id === (int) $request->user()->id, 404);

        $license->loadMissing(['product', 'order']);

        abort_unless($brModsResetService->supports($license), 404);

        try {
            $attempt = $brModsResetService->reset($license, $request->user());
        } catch (BrModsResetException $exception) {
            return back()->withErrors([
                'license_reset' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'license_reset_success',
            'HWID for '.$attempt->username.' was reset successfully. You can reset it again in 24 hours.',
        );
    }
}
