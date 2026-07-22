<?php

namespace App\Http\Controllers;

use App\Services\GopayWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GopayNotificationController extends Controller
{
    public function __invoke(Request $request, GopayWebhookService $service): JsonResponse
    {
        $result = $service->handle($request);

        return response()->json($result['payload'], $result['http_status']);
    }
}
