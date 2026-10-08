<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticateApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_when_no_token_is_provided(): void
    {
        $this->getJson(route('api.v1.numbers.window.show', '5567999990000'))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'unauthenticated')
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_returns_401_when_the_token_is_unknown(): void
    {
        ApiToken::issue('Sistema externo');

        $this->withToken('mz_unknown')
            ->getJson(route('api.v1.numbers.window.show', '5567999990000'))
            ->assertUnauthorized();
    }

    public function test_returns_401_when_the_token_was_revoked(): void
    {
        [$token, $plainText] = ApiToken::issue('Sistema externo');
        $token->update(['revoked_at' => now()]);

        $this->withToken($plainText)
            ->getJson(route('api.v1.numbers.window.show', '5567999990000'))
            ->assertUnauthorized();
    }

    public function test_valid_token_is_accepted_and_its_last_use_is_recorded(): void
    {
        [$token, $plainText] = ApiToken::issue('Sistema externo');

        $this->withToken($plainText)
            ->getJson(route('api.v1.numbers.window.show', '5567999990000'))
            ->assertOk();

        $this->assertNotNull($token->refresh()->last_used_at);
    }
}
