<?php

namespace Tests\Feature\Actions\WhatsApp;

use App\Actions\WhatsApp\ListWhatsAppTemplates;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListWhatsAppTemplatesTest extends TestCase
{
    private const string TEMPLATES_URL = 'https://graph.facebook.com/v25.0/9876543210/message_templates*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.business_account_id' => '9876543210',
            'services.whatsapp.access_token' => 'fake-access-token',
        ]);
    }

    public function test_returns_approved_text_templates_with_their_variables_and_buttons(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['data' => [
                $this->template('rf_link', 'APPROVED', [
                    ['type' => 'BODY', 'text' => 'Olá, {{1}}! Segue o link: {{2}}'],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Concluído']]],
                ]),
            ]]),
        ]);

        $templates = app(ListWhatsAppTemplates::class)->handle();

        $this->assertSame([[
            'key' => 'rf_link|pt_BR',
            'name' => 'rf_link',
            'language' => 'pt_BR',
            'body' => 'Olá, {{1}}! Segue o link: {{2}}',
            'variables' => 2,
            'buttons' => ['Concluído'],
        ]], $templates);
    }

    public function test_leaves_out_templates_that_are_not_approved_or_need_more_than_body_text(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['data' => [
                $this->template('em_analise', 'PENDING', [['type' => 'BODY', 'text' => 'Texto']]),
                $this->template('com_imagem', 'APPROVED', [
                    ['type' => 'HEADER', 'format' => 'IMAGE'],
                    ['type' => 'BODY', 'text' => 'Texto'],
                ]),
                $this->template('com_botao_de_link', 'APPROVED', [
                    ['type' => 'BODY', 'text' => 'Texto'],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Abrir', 'url' => 'https://exemplo.com/{{1}}']]],
                ]),
                $this->template('simples', 'APPROVED', [['type' => 'BODY', 'text' => 'Texto']]),
            ]]),
        ]);

        $templates = app(ListWhatsAppTemplates::class)->handle();

        $this->assertSame(['simples'], array_column($templates, 'name'));
    }

    public function test_asks_meta_only_once_while_the_list_is_cached(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['data' => []]),
        ]);

        app(ListWhatsAppTemplates::class)->handle();
        app(ListWhatsAppTemplates::class)->handle();

        Http::assertSentCount(1);
    }

    public function test_throws_when_meta_rejects_the_request(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token.']], 401),
        ]);

        $this->expectException(RequestException::class);

        app(ListWhatsAppTemplates::class)->handle();
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function template(string $name, string $status, array $components): array
    {
        return ['name' => $name, 'status' => $status, 'language' => 'pt_BR', 'components' => $components];
    }
}
