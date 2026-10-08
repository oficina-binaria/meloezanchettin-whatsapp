<?php

use App\Actions\WhatsApp\SendWhatsAppMessage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Enviar mensagem')] class extends Component {
    public ?int $contactId = null;
    public string $body = '';
    public ?int $lastMessageId = null;

    /**
     * Clear the previous result when another contact is selected.
     */
    public function updatedContactId(): void
    {
        $this->reset('body', 'lastMessageId');
        $this->resetValidation();
    }

    /**
     * Send free-form text while the service window is open, or the fallback template otherwise.
     */
    public function send(SendWhatsAppMessage $sendMessage): void
    {
        $this->validate(
            ['contactId' => ['required', 'integer', 'exists:contacts,id']],
            ['contactId.*' => __('Escolha um destinatário.')],
        );

        $contact = $this->contact;
        $text = null;

        if ($contact->hasOpenServiceWindow()) {
            $this->validate(
                ['body' => ['required', 'string', 'max:4096']],
                ['body.required' => __('Escreva a mensagem.'), 'body.max' => __('A mensagem pode ter no máximo 4096 caracteres.')],
            );

            $text = $this->body;
        }

        $message = $sendMessage->handle($contact, $text);

        $this->lastMessageId = $message->id;

        if ($message->status !== MessageStatus::Failed) {
            $this->reset('body');
        }

        unset($this->contact, $this->messages, $this->lastMessage);
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contacts(): Collection
    {
        return Contact::query()->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function contact(): ?Contact
    {
        return $this->contactId === null ? null : Contact::query()->find($this->contactId);
    }

    #[Computed]
    public function lastMessage(): ?Message
    {
        return $this->lastMessageId === null ? null : Message::query()->find($this->lastMessageId);
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function messages(): Collection
    {
        if ($this->contact === null) {
            return new Collection;
        }

        return $this->contact->messages()->latest('id')->limit(20)->get();
    }
}; ?>

<section class="flex w-full max-w-3xl flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Enviar mensagem') }}</flux:heading>
        <flux:subheading>{{ __('Envia uma mensagem pelo WhatsApp da empresa para um contato cadastrado.') }}</flux:subheading>
    </div>

    @if ($this->contacts->isEmpty())
        <flux:callout icon="users">
            <flux:callout.heading>{{ __('Nenhum contato cadastrado') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Cadastre um destinatário antes de enviar.') }}
                <flux:callout.link :href="route('contacts.index')" wire:navigate>{{ __('Ir para Contatos') }}</flux:callout.link>
            </flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="send" class="flex flex-col gap-6">
            <flux:select wire:model.live="contactId" :label="__('Destinatário')" :placeholder="__('Escolha um contato')">
                @foreach ($this->contacts as $option)
                    <flux:select.option :value="$option->id" wire:key="contact-option-{{ $option->id }}">
                        {{ $option->name }} ({{ $option->phone }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->contact)
                @if ($this->contact->hasOpenServiceWindow())
                    <div>
                        <flux:badge color="green">
                            {{ __('Janela de 24 horas aberta por mais :hours h', ['hours' => $this->contact->serviceWindowHoursLeft()]) }}
                        </flux:badge>
                    </div>

                    <flux:textarea wire:model="body" :label="__('Mensagem')" rows="4" />

                    <div>
                        <flux:button variant="primary" type="submit" icon="paper-airplane" data-test="send-message-button">
                            {{ __('Enviar') }}
                        </flux:button>
                    </div>
                @else
                    <flux:callout icon="clock" color="amber">
                        <flux:callout.heading>{{ __('Janela de 24 horas fechada') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('Este contato não escreveu para a empresa nas últimas 24 horas, então o WhatsApp não entrega texto livre. Você pode enviar o template :template; quando o contato responder, a janela abre e o texto livre fica disponível.', ['template' => SendWhatsAppMessage::FALLBACK_TEMPLATE]) }}
                        </flux:callout.text>
                    </flux:callout>

                    <div>
                        <flux:button variant="primary" type="submit" icon="paper-airplane" data-test="send-template-button">
                            {{ __('Enviar template :template', ['template' => SendWhatsAppMessage::FALLBACK_TEMPLATE]) }}
                        </flux:button>
                    </div>
                @endif
            @endif
        </form>

        @if ($this->lastMessage)
            @if ($this->lastMessage->status === MessageStatus::Failed)
                <flux:callout variant="danger" icon="x-circle" data-test="send-error">
                    <flux:callout.heading>{{ __('A mensagem não foi enviada') }}</flux:callout.heading>
                    <flux:callout.text>
                        @if ($this->lastMessage->error_code)
                            {{ __('Erro :code da Meta:', ['code' => $this->lastMessage->error_code]) }}
                        @endif
                        {{ $this->lastMessage->error_message }}
                    </flux:callout.text>
                </flux:callout>
            @else
                <flux:callout variant="success" icon="check-circle" data-test="send-success">
                    <flux:callout.heading>{{ __('Mensagem aceita pela Meta') }}</flux:callout.heading>
                    <flux:callout.text class="break-all">{{ $this->lastMessage->wamid }}</flux:callout.text>
                </flux:callout>
            @endif
        @endif

        @if ($this->contact)
            <div class="flex flex-col gap-3" wire:poll.10s>
                <flux:heading level="2">{{ __('Últimas mensagens') }}</flux:heading>

                @forelse ($this->messages as $message)
                    <div
                        wire:key="message-{{ $message->id }}"
                        @class([
                            'flex max-w-[85%] flex-col gap-1 rounded-lg border px-3 py-2',
                            'self-start border-zinc-200 dark:border-zinc-700' => $message->direction === MessageDirection::Inbound,
                            'self-end border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950' => $message->direction === MessageDirection::Outbound,
                        ])
                    >
                        <flux:text class="whitespace-pre-line break-words text-zinc-800 dark:text-zinc-100">
                            @if ($message->type === 'template')
                                {{ __('Template :template', ['template' => $message->body]) }}
                            @elseif ($message->body !== null)
                                {{ $message->body }}
                            @else
                                {{ __('Mensagem do tipo :type', ['type' => $message->type]) }}
                            @endif
                        </flux:text>

                        <flux:text size="sm">
                            {{ $message->direction === MessageDirection::Inbound ? __('Recebida') : $message->status?->label() }}
                            · {{ ($message->status_at ?? $message->sent_at ?? $message->created_at)->locale('pt_BR')->diffForHumans() }}
                            @if ($message->status === MessageStatus::Failed && $message->error_message)
                                · {{ $message->error_code ? $message->error_code.': ' : '' }}{{ $message->error_message }}
                            @endif
                        </flux:text>
                    </div>
                @empty
                    <flux:text>{{ __('Nenhuma mensagem trocada com este contato ainda.') }}</flux:text>
                @endforelse
            </div>
        @endif
    @endif
</section>
