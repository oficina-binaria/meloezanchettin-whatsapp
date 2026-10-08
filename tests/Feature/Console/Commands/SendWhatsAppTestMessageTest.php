<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendWhatsAppTestMessageTest extends TestCase
{
    private const string MESSAGES_URL = 'https://graph.facebook.com/v25.0/1234567890/messages';

    private const string ACCESS_TOKEN = 'fake-access-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.access_token' => self::ACCESS_TOKEN,
        ]);
    }

    public function test_sends_the_hello_world_template_to_the_recipient(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response(['messages' => [['id' => 'wamid.TEST']]]),
        ]);

        $this->artisan('whatsapp:send-test', ['to' => '+55 (49) 99999-9999'])
            ->expectsOutputToContain('wamid.TEST')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::MESSAGES_URL
            && $request->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN)
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'to' => '5549999999999',
                'type' => 'template',
                'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_US']],
            ]);
    }

    public function test_sends_free_text_when_the_text_option_is_given(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response(['messages' => [['id' => 'wamid.TEST']]]),
        ]);

        $this->artisan('whatsapp:send-test', ['to' => '5549999999999', '--text' => 'Olá!'])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'text'
            && $request['text'] === ['body' => 'Olá!']);
    }

    public function test_fails_and_shows_the_api_error_when_the_api_rejects_the_message(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token.']], 401),
        ]);

        $this->artisan('whatsapp:send-test', ['to' => '5549999999999'])
            ->expectsOutputToContain('Invalid OAuth access token.')
            ->assertFailed();
    }

    public function test_fails_when_the_api_is_unreachable(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::failedConnection(),
        ]);

        $this->artisan('whatsapp:send-test', ['to' => '5549999999999'])
            ->expectsOutputToContain('Não foi possível conectar')
            ->assertFailed();
    }

    public function test_fails_without_calling_the_api_when_the_recipient_has_no_digits(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('whatsapp:send-test', ['to' => 'abc'])
            ->assertFailed();

        Http::assertNothingSent();
    }
}
