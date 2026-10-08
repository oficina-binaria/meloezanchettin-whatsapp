<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api-token:list')]
#[Description('Lista os tokens de acesso à API')]
class ListApiTokens extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->table(
            ['#', 'Aplicação', 'Situação', 'Criado em (UTC)', 'Último uso (UTC)'],
            ApiToken::query()->orderBy('id')->get()->map(fn (ApiToken $token): array => [
                $token->id,
                $token->name,
                $token->isRevoked() ? 'Revogado' : 'Ativo',
                $token->created_at?->format('d/m/Y H:i'),
                $token->last_used_at?->format('d/m/Y H:i') ?? 'Nunca',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
