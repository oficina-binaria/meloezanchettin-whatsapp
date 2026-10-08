<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\Http;

class TemplateControllerTest extends ApiTestCase
{
    public function test_lists_the_approved_templates_with_variables_and_buttons(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());

        $this->getJson(route('api.v1.templates.index'))
            ->assertOk()
            ->assertExactJson(['data' => [[
                'name' => 'rf_link',
                'language' => 'pt_BR',
                'body' => 'Olá, {{1}}! Segue o link: {{2}}',
                'variables' => 2,
                'buttons' => ['Concluído'],
            ]]]);
    }

    public function test_returns_502_when_meta_cannot_be_reached(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TEMPLATES_URL => Http::failedConnection()]);

        $this->getJson(route('api.v1.templates.index'))
            ->assertStatus(502)
            ->assertJsonPath('error', 'meta_unavailable');
    }
}
