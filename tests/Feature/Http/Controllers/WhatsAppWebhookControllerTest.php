<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string APP_SECRET = 'fake-app-secret';

    private const string VERIFY_TOKEN = 'fake-verify-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => self::APP_SECRET,
            'services.whatsapp.webhook_verify_token' => self::VERIFY_TOKEN,
            'services.whatsapp.phone_number_id' => '1234567890',
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

    public function test_notification_signed_with_the_app_secret_is_stored_and_returns_200(): void
    {
        $body = json_encode($this->inboundNotification(), JSON_THROW_ON_ERROR);

        $response = $this->postNotification($body, 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET));

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['wamid' => 'wamid.IN1', 'body' => 'Bom dia']);
    }

    public function test_notification_returns_401_and_is_not_stored_when_the_signature_is_wrong(): void
    {
        $body = json_encode($this->inboundNotification(), JSON_THROW_ON_ERROR);

        $response = $this->postNotification($body, 'sha256='.hash_hmac('sha256', $body, 'another-secret'));

        $response->assertUnauthorized();
        $this->assertDatabaseCount('messages', 0);
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
     * @return array<string, mixed>
     */
    private function inboundNotification(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '4529573614035882',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550000000', 'phone_number_id' => '1234567890'],
                        'contacts' => [['profile' => ['name' => 'Maria Souza'], 'wa_id' => '556799990000']],
                        'messages' => [[
                            'from' => '556799990000',
                            'id' => 'wamid.IN1',
                            'timestamp' => '1791475717',
                            'type' => 'text',
                            'text' => ['body' => 'Bom dia'],
                        ]],
                    ],
                ]],
            ]],
        ];
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
