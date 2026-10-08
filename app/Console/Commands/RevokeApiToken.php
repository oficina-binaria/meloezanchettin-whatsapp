<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api-token:revoke {id : Número do token, como aparece em api-token:list}')]
#[Description('Revoga um token de acesso à API')]
class RevokeApiToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $token = ApiToken::query()->find($this->argument('id'));

        if ($token === null) {
            $this->error('Não existe token com esse número.');

            return self::FAILURE;
        }

        if ($token->isRevoked()) {
            $this->warn("O token #{$token->id} (\"{$token->name}\") já estava revogado.");

            return self::SUCCESS;
        }

        $token->update(['revoked_at' => now()]);

        $this->info("Token #{$token->id} (\"{$token->name}\") revogado.");

        return self::SUCCESS;
    }
}
