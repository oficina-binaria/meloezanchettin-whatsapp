<?php

use App\Actions\WhatsApp\ListWhatsAppTemplates;
use App\Actions\WhatsApp\SendWhatsAppMessage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Enviar mensagem')] class extends Component {
    /**
     * The option of the message type selector that stands for free-form text.
     */
    public const string FREE_TEXT = 'text';

    public ?int $contactId = null;
    public string $kind = '';
    public string $body = '';

    /** @var array<int, string> */
    public array $parameters = [];

    public ?int $lastMessageId = null;

    /**
     * Start over when another contact is selected.
     */
    public function updatedContactId(): void
    {
        $this->reset('body', 'parameters', 'lastMessageId');
        $this->resetValidation();

        $this->kind = $this->contact?->hasOpenServiceWindow() ? self::FREE_TEXT : '';
    }

    /**
     * Prepare the variables of the newly selected template.
     */
    public function updatedKind(): void
    {
        $this->reset('parameters');
        $this->resetValidation();

        if (($this->template['variables'] ?? 0) > 0 && $this->contact !== null) {
            $this->parameters[1] = Str::before($this->contact->name, ' ');
        }
    }

    /**
     * Send free-form text or the selected template to the selected contact.
     */
    public function send(SendWhatsAppMessage $sendMessage): void
    {
        $this->validate(
            ['contactId' => ['required', 'integer', 'exists:contacts,id']],
            ['contactId.*' => __('Escolha um destinatário.')],
        );

        $message = $this->kind === self::FREE_TEXT
            ? $this->sendText($sendMessage)
            : $this->sendTemplate($sendMessage);

        $this->lastMessageId = $message->id;

        if ($message->status !== MessageStatus::Failed) {
            $this->reset('body');
        }

        unset($this->contact, $this->messages, $this->lastMessage);
    }

    /**
     * Send the typed text, which Meta only delivers while the service window is open.
     */
    private function sendText(SendWhatsAppMessage $sendMessage): Message
    {
        if (! $this->contact->hasOpenServiceWindow()) {
            throw ValidationException::withMessages(['kind' => __('A janela de 24 horas está fechada. Escolha um template.')]);
        }

        $this->validate(
            ['body' => ['required', 'string', 'max:4096']],
            ['body.required' => __('Escreva a mensagem.'), 'body.max' => __('A mensagem pode ter no máximo 4096 caracteres.')],
        );

        return $sendMessage->sendText($this->contact, $this->body);
    }

    /**
     * Send the selected template with the values typed for its variables.
     */
    private function sendTemplate(SendWhatsAppMessage $sendMessage): Message
    {
        $template = $this->template;

        if ($template === null) {
            throw ValidationException::withMessages(['kind' => __('Escolha o que enviar.')]);
        }

        if ($template['variables'] > 0) {
            $this->validate(
                ['parameters' => ['array'], ...collect(range(1, $template['variables']))->mapWithKeys(
                    fn (int $number): array => ["parameters.{$number}" => ['required', 'string', 'max:1024', 'not_regex:/[\r\n\t]| {5,}/']],
                )->all()],
                [
                    'parameters.*.required' => __('Preencha esta variável.'),
                    'parameters.*.max' => __('Use no máximo 1024 caracteres.'),
                    'parameters.*.not_regex' => __('Variáveis não aceitam quebra de linha, tabulação ou muitos espaços seguidos.'),
                ],
            );
        }

        return $sendMessage->sendTemplate($this->contact, $template, $this->orderedParameters($template['variables']));
    }

    /**
     * Get the typed variable values in positional order.
     *
     * @return list<string>
     */
    private function orderedParameters(int $count): array
    {
        return $count === 0
            ? []
            : array_map(fn (int $number): string => trim($this->parameters[$number] ?? ''), range(1, $count));
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

    /**
     * Get the approved templates, or null when Meta could not be reached.
     *
     * @return list<array{key: string, name: string, language: string, body: string, variables: int, buttons: list<string>}>|null
     */
    #[Computed]
    public function templates(): ?array
    {
        try {
            return app(ListWhatsAppTemplates::class)->handle();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array{key: string, name: string, language: string, body: string, variables: int, buttons: list<string>}|null
     */
    #[Computed]
    public function template(): ?array
    {
        return collect($this->templates ?? [])->firstWhere('key', $this->kind);
    }

    #[Computed]
    public function preview(): string
    {
        return $this->template === null
            ? ''
            : SendWhatsAppMessage::renderTemplate($this->template['body'], $this->orderedParameters($this->template['variables']));
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
                @else
                    <flux:callout icon="clock" color="amber">
                        <flux:callout.heading>{{ __('Janela de 24 horas fechada') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('Este contato não escreveu para a empresa nas últimas 24 horas, então o WhatsApp não entrega texto livre. Envie um template aprovado; quando o contato responder, a janela abre e o texto livre fica disponível.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif

                @if ($this->templates === null)
                    <flux:callout variant="warning" icon="exclamation-triangle" data-test="templates-unavailable">
                        <flux:callout.text>{{ __('Não foi possível carregar os templates da Meta agora. Recarregue a página em instantes.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:select wire:model.live="kind" :label="__('Tipo de mensagem')" :placeholder="__('Escolha o que enviar')">
                    @if ($this->contact->hasOpenServiceWindow())
                        <flux:select.option :value="$this::FREE_TEXT">{{ __('Texto livre') }}</flux:select.option>
                    @endif

                    @foreach ($this->templates ?? [] as $option)
                        <flux:select.option :value="$option['key']" wire:key="template-option-{{ $option['key'] }}">
                            {{ __('Template :name (:language)', ['name' => $option['name'], 'language' => $option['language']]) }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                @if ($kind === $this::FREE_TEXT)
                    <flux:textarea wire:model="body" :label="__('Mensagem')" rows="4" />
                @elseif ($this->template)
                    @for ($number = 1; $number <= $this->template['variables']; $number++)
                        <flux:input
                            wire:key="parameter-{{ $this->template['key'] }}-{{ $number }}"
                            wire:model.live.debounce.300ms="parameters.{{ $number }}"
                            :label="__('Variável :number', ['number' => $number])"
                            type="text"
                        />
                    @endfor

                    <div class="flex flex-col gap-2" data-test="template-preview">
                        <flux:label>{{ __('Pré-visualização') }}</flux:label>

                        <div class="flex flex-col gap-3 rounded-lg border border-green-200 bg-green-50 px-3 py-2 dark:border-green-900 dark:bg-green-950">
                            <flux:text class="whitespace-pre-line break-words text-zinc-800 dark:text-zinc-100">{{ $this->preview }}</flux:text>

                            @if ($this->template['buttons'] !== [])
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($this->template['buttons'] as $button)
                                        <flux:badge wire:key="template-button-{{ $loop->index }}" color="zinc">{{ $button }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($kind !== '')
                    <div>
                        <flux:button variant="primary" type="submit" icon="paper-airplane" data-test="send-message-button">
                            {{ __('Enviar') }}
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
                        <flux:text class="whitespace-pre-line break-words text-zinc-800 dark:text-zinc-100">{{ $message->body ?? __('Mensagem do tipo :type', ['type' => $message->type]) }}</flux:text>

                        <flux:text size="sm">
                            @if ($message->template_name)
                                {{ __('Template :name', ['name' => $message->template_name]) }} ·
                            @endif
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
