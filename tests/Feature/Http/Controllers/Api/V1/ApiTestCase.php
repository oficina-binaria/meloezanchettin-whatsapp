<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const string MESSAGES_URL = 'https://graph.facebook.com/v25.0/1234567890/messages';

    protected const string TEMPLATES_URL = 'https://graph.facebook.com/v25.0/9876543210/message_templates*';

    protected ApiToken $apiToken;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.business_account_id' => '9876543210',
            'services.whatsapp.access_token' => 'fake-access-token',
        ]);

        [$this->apiToken, $plainText] = ApiToken::issue('Sistema externo');

        $this->withToken($plainText);
    }

    /**
     * Fake Meta's template list and, when given, its answer to a send request.
     *
     * @param  array<string, mixed>|null  $sendResponse
     * @return array<string, mixed>
     */
    protected function metaResponses(?array $sendResponse = null, int $sendStatus = 200): array
    {
        $responses = [
            self::TEMPLATES_URL => Http::response(['data' => [[
                'name' => 'rf_link',
                'status' => 'APPROVED',
                'language' => 'pt_BR',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Olá, {{1}}! Segue o link: {{2}}'],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Concluído']]],
                ],
            ]]]),
        ];

        if ($sendResponse !== null) {
            $responses[self::MESSAGES_URL] = Http::response($sendResponse, $sendStatus);
        }

        return $responses;
    }
}
