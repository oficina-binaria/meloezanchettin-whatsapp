<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexMessagesRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NumberMessageController extends Controller
{
    /**
     * List the messages exchanged with the phone number, sent and received.
     */
    public function index(IndexMessagesRequest $request, string $phone): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $messages = Message::query()
            ->with('contact')
            ->where('contact_id', Contact::findByPhone($phone)->id ?? 0)
            ->when(isset($filters['direction']), fn (Builder $query) => $query->where('direction', $filters['direction']))
            ->when(isset($filters['since']), fn (Builder $query) => $query->where('created_at', '>=', $filters['since']))
            ->when(isset($filters['after_id']), fn (Builder $query) => $query->where('id', '>', $filters['after_id']))
            ->orderBy('id', $filters['order'] ?? 'desc')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return MessageResource::collection($messages);
    }
}
