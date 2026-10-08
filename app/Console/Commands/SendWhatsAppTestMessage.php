<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

#[Signature('whatsapp:send-test
    {to : Número do destinatário com DDI e DDD, só dígitos (ex.: 5549999999999)}
    {--text= : Envia um texto livre em vez do template hello_world (exige janela de 24h aberta)}')]
#[Description('Envia uma mensagem de teste pela WhatsApp Cloud API')]
class SendWhatsAppTestMessage extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $recipient = preg_replace('/\D+/', '', (string) $this->argument('to'));

        if ($recipient === '') {
            $this->error('Informe o número do destinatário com DDI e DDD, por exemplo 5549999999999.');

            return self::FAILURE;
        }

        try {
            $response = Http::whatsapp()->post(
                config('services.whatsapp.phone_number_id').'/messages',
                $this->payload($recipient),
            );
        } catch (ConnectionException $exception) {
            $this->error('Não foi possível conectar à API do WhatsApp: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error(sprintf(
                'A API do WhatsApp recusou o envio (HTTP %d, código %s): %s',
                $response->status(),
                $response->json('error.code', '?'),
                $response->json('error.message', $response->body()),
            ));

            return self::FAILURE;
        }

        $this->info('Mensagem aceita pela API. ID: '.$response->json('messages.0.id'));

        return self::SUCCESS;
    }

    /**
     * Build the message payload for the given recipient.
     *
     * @return array{messaging_product: string, to: string, type: string, text?: array{body: string}, template?: array{name: string, language: array{code: string}}}
     */
    private function payload(string $recipient): array
    {
        $text = $this->option('text');

        if (filled($text)) {
            return [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'text',
                'text' => ['body' => $text],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => 'hello_world',
                'language' => ['code' => 'en_US'],
            ],
        ];
    }
}
