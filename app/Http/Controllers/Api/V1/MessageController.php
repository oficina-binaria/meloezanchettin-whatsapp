<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WhatsApp\ListWhatsAppTemplates;
use App\Actions\WhatsApp\SendWhatsAppMessage;
use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\ApiToken;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MessageController extends Controller
{
    /**
     * Send a free-form text or an approved template to a phone number.
     */
    public function store(
        StoreMessageRequest $request,
        SendWhatsAppMessage $sendMessage,
        ListWhatsAppTemplates $listTemplates,
    ): JsonResponse {
        /** @var ApiToken $token */
        $token = $request->attributes->get('apiToken');
        $idempotencyKey = $this->idempotencyKey($request);

        if ($idempotencyKey !== null) {
            $previous = Message::query()
                ->with('contact')
                ->where('api_token_id', $token->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($previous !== null) {
                return MessageResource::make($previous)->response()->setStatusCode(Response::HTTP_OK);
            }
        }

        $message = $request->validated('type') === 'text'
            ? $this->sendText($request, $sendMessage)
            : $this->sendTemplate($request, $sendMessage, $listTemplates);

        if ($message instanceof JsonResponse) {
            return $message;
        }

        $failed = $message->status === MessageStatus::Failed;

        $message->update([
            'api_token_id' => $token->id,
            'idempotency_key' => $failed ? null : $idempotencyKey,
        ]);

        if (! $failed) {
            return MessageResource::make($message)->response()->setStatusCode(Response::HTTP_CREATED);
        }

        return response()->json([
            'message' => $message->error_code === null
                ? 'Não foi possível conectar à API do WhatsApp. A mensagem não foi enviada.'
                : 'A Meta recusou a mensagem.',
            'error' => $message->error_code === null ? 'meta_unavailable' : 'meta_rejected',
            'data' => MessageResource::make($message),
        ], $message->error_code === null ? Response::HTTP_BAD_GATEWAY : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Show a message, with its current delivery status.
     */
    public function show(Message $message): MessageResource
    {
        return MessageResource::make($message->load('contact'));
    }

    /**
     * Send free-form text, which Meta only delivers while the service window is open.
     */
    private function sendText(StoreMessageRequest $request, SendWhatsAppMessage $sendMessage): Message|JsonResponse
    {
        $contact = Contact::findByPhone($request->validated('to'));

        if ($contact === null || ! $contact->hasOpenServiceWindow()) {
            return response()->json([
                'message' => 'A janela de 24 horas deste número está fechada. Texto livre só pode ser enviado até 24 horas depois da última mensagem recebida do número; envie um template aprovado.',
                'error' => 'service_window_closed',
            ], Response::HTTP_CONFLICT);
        }

        return $sendMessage->sendText($contact, $request->validated('text'));
    }

    /**
     * Send an approved template, checking it against the templates Meta reports.
     */
    private function sendTemplate(
        StoreMessageRequest $request,
        SendWhatsAppMessage $sendMessage,
        ListWhatsAppTemplates $listTemplates,
    ): Message|JsonResponse {
        try {
            $templates = $listTemplates->handle();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return response()->json(
                ['message' => 'Não foi possível consultar os templates na Meta. Tente novamente em instantes.', 'error' => 'meta_unavailable'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        $key = $request->validated('template.name').'|'.$request->validated('template.language');
        $template = array_find($templates, fn (array $template): bool => $template['key'] === $key);

        if ($template === null) {
            throw ValidationException::withMessages([
                'template.name' => 'Template não encontrado entre os aprovados da conta para este idioma. Consulte GET /api/v1/templates.',
            ]);
        }

        /** @var list<string> $parameters */
        $parameters = $request->validated('template.parameters', []);

        if (count($parameters) !== $template['variables']) {
            throw ValidationException::withMessages([
                'template.parameters' => sprintf(
                    'Este template exige %d variável(is) e foram enviadas %d.',
                    $template['variables'],
                    count($parameters),
                ),
            ]);
        }

        $contact = Contact::findOrCreateByPhone($request->validated('to'), $request->validated('name'));

        return $sendMessage->sendTemplate($contact, $template, $parameters);
    }

    /**
     * Get the idempotency key sent by the client, if any.
     */
    private function idempotencyKey(StoreMessageRequest $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (blank($key)) {
            return null;
        }

        if (mb_strlen($key) > 255) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'O cabeçalho Idempotency-Key pode ter no máximo 255 caracteres.',
            ]);
        }

        return $key;
    }
}
