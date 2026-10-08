<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppWebhookControllerTest extends TestCase
{
    private const string APP_SECRET = 'fake-app-secret';

    private const string VERIFY_TOKEN = 'fake-verify-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => self::APP_SECRET,
            'services.whatsapp.webhook_verify_token' => self::VERIFY_TOKEN,
        ]);
    }

    public function test_verification_returns_the_challenge_when_the_verify_token_matches(): void
    {
        $response = $this->get(route('webhooks.whatsapp.verify', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => self::VERIFY_TOKEN,
            'hub.challenge' => '1158201444',
        ]));

        $response->assertOk();
        $response->assertContent('1158201444');
    }

    public function test_verification_returns_403_when_the_verify_token_does_not_match(): void
    {
        $response = $this->get(route('webhooks.whatsapp.verify', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong-token',
            'hub.challenge' => '1158201444',
        ]));

        $response->assertForbidden();
    }

    public function test_verification_returns_403_when_no_verify_token_is_configured(): void
    {
        config(['services.whatsapp.webhook_verify_token' => null]);

        $response = $this->get(route('webhooks.whatsapp.verify', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => '',
            'hub.challenge' => '1158201444',
        ]));

        $response->assertForbidden();
    }

    public function test_notification_signed_with_the_app_secret_is_logged_and_returns_200(): void
    {
        Log::spy();
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '123']]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->postNotification($body, 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET));

        $response->assertOk();
        Log::shouldHaveReceived('info')
            ->once()
            ->with('WhatsApp webhook received.', ['payload' => $payload]);
    }

    public function test_notification_returns_401_and_is_not_logged_when_the_signature_is_wrong(): void
    {
        Log::spy();
        $body = json_encode(['object' => 'whatsapp_business_account'], JSON_THROW_ON_ERROR);

        $response = $this->postNotification($body, 'sha256='.hash_hmac('sha256', $body, 'another-secret'));

        $response->assertUnauthorized();
        Log::shouldNotHaveReceived('info');
    }

    public function test_notification_returns_401_when_the_signature_header_is_missing(): void
    {
        $response = $this->postNotification('{"object":"whatsapp_business_account"}', null);

        $response->assertUnauthorized();
    }

    public function test_notification_returns_401_when_no_app_secret_is_configured(): void
    {
        config(['services.whatsapp.app_secret' => null]);
        $body = '{"object":"whatsapp_business_account"}';

        $response = $this->postNotification($body, 'sha256='.hash_hmac('sha256', $body, ''));

        $response->assertUnauthorized();
    }

    /**
     * Post a raw notification body, so the signature covers the exact bytes sent.
     */
    private function postNotification(string $body, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return $this->call('POST', route('webhooks.whatsapp.store'), server: $server, content: $body);
    }
}
