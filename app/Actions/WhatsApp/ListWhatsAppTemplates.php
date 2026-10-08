<?php

namespace App\Actions\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ListWhatsAppTemplates
{
    /**
     * The number of seconds the template list is kept before asking Meta again.
     */
    private const int CACHE_SECONDS = 60;

    /**
     * Get the approved templates of the business account that this app is able to send.
     *
     * Only templates made of plain text with numbered body variables are supported.
     *
     * @return list<array{key: string, name: string, language: string, body: string, variables: int, buttons: list<string>}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function handle(): array
    {
        return Cache::remember('whatsapp.templates', self::CACHE_SECONDS, function (): array {
            /** @var list<array<string, mixed>> $templates */
            $templates = Http::whatsapp()
                ->get(config('services.whatsapp.business_account_id').'/message_templates', [
                    'fields' => 'name,status,language,components',
                    'limit' => 100,
                ])
                ->throw()
                ->json('data', []);

            $sendable = [];

            foreach ($templates as $template) {
                if ($template['status'] === 'APPROVED' && $this->isSupported($template)) {
                    $sendable[] = $this->describe($template);
                }
            }

            usort($sendable, fn (array $first, array $second): int => $first['key'] <=> $second['key']);

            return $sendable;
        });
    }

    /**
     * Determine whether the template can be sent with text parameters for the body alone.
     *
     * @param  array<string, mixed>  $template
     */
    private function isSupported(array $template): bool
    {
        $hasBody = false;

        foreach ($template['components'] ?? [] as $component) {
            $supported = match ($component['type'] ?? null) {
                'BODY' => $hasBody = true,
                'FOOTER' => true,
                'HEADER' => ($component['format'] ?? null) === 'TEXT' && ! str_contains($component['text'] ?? '', '{{'),
                'BUTTONS' => array_all(
                    $component['buttons'] ?? [],
                    fn (array $button): bool => ($button['type'] ?? null) === 'QUICK_REPLY',
                ),
                default => false,
            };

            if (! $supported) {
                return false;
            }
        }

        return $hasBody;
    }

    /**
     * Reduce the template to what is needed to fill it in and send it.
     *
     * @param  array<string, mixed>  $template
     * @return array{key: string, name: string, language: string, body: string, variables: int, buttons: list<string>}
     */
    private function describe(array $template): array
    {
        /** @var array<string, array<string, mixed>> $components */
        $components = array_column($template['components'], null, 'type');
        $body = $components['BODY']['text'] ?? '';

        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);

        return [
            'key' => $template['name'].'|'.$template['language'],
            'name' => $template['name'],
            'language' => $template['language'],
            'body' => $body,
            'variables' => $matches[1] === [] ? 0 : max(array_map(intval(...), $matches[1])),
            'buttons' => array_column($components['BUTTONS']['buttons'] ?? [], 'text'),
        ];
    }
}
