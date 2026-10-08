<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wamid' => $this->wamid,
            'direction' => $this->direction->value,
            'phone' => $this->contact->phone,
            'type' => $this->type,
            'template' => $this->template_name,
            'body' => $this->body,
            'status' => $this->status?->value,
            'status_at' => $this->status_at?->toIso8601ZuluString(),
            'error' => $this->error_code === null && $this->error_message === null ? null : [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ],
            'sent_at' => $this->sent_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
