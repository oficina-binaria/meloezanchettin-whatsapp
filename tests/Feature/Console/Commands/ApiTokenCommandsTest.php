<?php

namespace Tests\Feature\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ApiTokenCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_prints_a_token_that_authenticates_and_stores_only_its_hash(): void
    {
        Artisan::call('api-token:create', ['name' => 'Sistema externo']);

        preg_match('/^mz_\w{48}$/m', Artisan::output(), $matches);
        $this->assertNotEmpty($matches, 'The command did not print a token.');
        $token = ApiToken::findActive($matches[0]);
        $this->assertSame('Sistema externo', $token?->name);
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $matches[0]]);
    }

    public function test_revoke_makes_the_token_stop_authenticating(): void
    {
        [$token, $plainText] = ApiToken::issue('Sistema externo');

        $this->artisan('api-token:revoke', ['id' => $token->id])->assertSuccessful();

        $this->assertNull(ApiToken::findActive($plainText));
    }

    public function test_revoke_fails_for_a_token_that_does_not_exist(): void
    {
        $this->artisan('api-token:revoke', ['id' => 999])->assertFailed();
    }

    public function test_list_shows_each_token_with_its_situation(): void
    {
        ApiToken::factory()->create(['name' => 'Sistema ativo']);
        ApiToken::factory()->revoked()->create(['name' => 'Sistema antigo']);

        $this->artisan('api-token:list')
            ->expectsOutputToContain('Sistema ativo')
            ->expectsOutputToContain('Revogado')
            ->assertSuccessful();
    }
}
