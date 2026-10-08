<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WhatsApp\ListWhatsAppTemplates;
use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TemplateController extends Controller
{
    /**
     * List the approved templates that can be sent through the API.
     */
    public function index(ListWhatsAppTemplates $listTemplates): JsonResponse
    {
        try {
            $templates = $listTemplates->handle();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return response()->json(
                ['message' => 'Não foi possível consultar os templates na Meta. Tente novamente em instantes.', 'error' => 'meta_unavailable'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return response()->json(['data' => array_map(
            fn (array $template): array => [
                'name' => $template['name'],
                'language' => $template['language'],
                'body' => $template['body'],
                'variables' => $template['variables'],
                'buttons' => $template['buttons'],
            ],
            $templates,
        )]);
    }
}
