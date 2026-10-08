<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api-token:create {name : Nome da aplicação externa que vai usar o token}')]
#[Description('Cria um token de acesso à API para uma aplicação externa')]
class CreateApiToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        [$token, $plainText] = ApiToken::issue($this->argument('name'));

        $this->info("Token #{$token->id} criado para \"{$token->name}\".");
        $this->line($plainText);
        $this->warn('Guarde este valor agora: ele não é armazenado e não pode ser exibido de novo.');

        return self::SUCCESS;
    }
}
