<?php

namespace Tests\Unit;

use App\Services\GopayWebhookService;
use Illuminate\Http\Request;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GopayWebhookAuthenticationTest extends TestCase
{
    private const TOKEN = 'unit-webhook-token';

    private const SECRET = 'unit-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gopay_qris.webhook_token' => self::TOKEN,
            'services.gopay_qris.webhook_secret' => self::SECRET,
            'services.gopay_qris.allowed_devices' => ['aksa-gopay-primary'],
            'services.gopay_qris.webhook_max_skew_seconds' => 300,
        ]);
    }

    public function test_bearer_token_and_hmac_signature_are_both_required(): void
    {
        [$request, $rawBody, $payload] = $this->signedRequest('Bearer '.self::TOKEN);

        $this->authenticate($request, $rawBody, $payload);

        $this->addToAssertionCount(1);
    }

    public function test_invalid_bearer_token_is_rejected_before_event_processing(): void
    {
        [$request, $rawBody, $payload] = $this->signedRequest('Bearer wrong-token');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid webhook authorization');

        $this->authenticate($request, $rawBody, $payload);
    }

    private function signedRequest(string $authorization): array
    {
        $timestamp = (string) now()->getTimestampMs();
        $payload = [
            'device_id' => 'aksa-gopay-primary',
            'sent_at' => (int) $timestamp,
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, self::SECRET);
        $request = Request::create(
            '/api/payments/gopay-qris/notifications',
            'POST',
            server: [
                'HTTP_AUTHORIZATION' => $authorization,
                'HTTP_X_AKSA_DEVICE' => 'aksa-gopay-primary',
                'HTTP_X_AKSA_TIMESTAMP' => $timestamp,
                'HTTP_X_AKSA_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $rawBody,
        );

        return [$request, $rawBody, $payload];
    }

    private function authenticate(Request $request, string $rawBody, array $payload): void
    {
        $service = app(GopayWebhookService::class);
        $method = new ReflectionMethod($service, 'authenticate');
        $method->invoke($service, $request, $rawBody, $payload);
    }
}
