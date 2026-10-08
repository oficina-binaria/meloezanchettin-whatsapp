<?php

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Mensagens')] class extends Component {
    use WithPagination;

    /**
     * The option of the status filter that stands for messages received from contacts.
     */
    public const string RECEIVED = 'received';

    #[Url(as: 'contato')]
    public string $contactId = '';

    #[Url(as: 'situacao')]
    public string $status = '';

    /**
     * Go back to the first page whenever a filter changes.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['contactId', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contacts(): Collection
    {
        return Contact::query()->orderBy('name')->orderBy('id')->get();
    }

    /**
     * @return LengthAwarePaginator<int, Message>
     */
    #[Computed]
    public function messages(): LengthAwarePaginator
    {
        return Message::query()
            ->with('contact')
            ->when($this->contactId !== '', fn (Builder $query) => $query->where('contact_id', (int) $this->contactId))
            ->when($this->status === self::RECEIVED, fn (Builder $query) => $query->where('direction', MessageDirection::Inbound))
            ->when(
                MessageStatus::tryFrom($this->status) !== null,
                fn (Builder $query) => $query->where('direction', MessageDirection::Outbound)->where('status', $this->status),
            )
            ->latest('id')
            ->paginate(25);
    }
}; ?>

<section class="flex w-full flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Mensagens') }}</flux:heading>
        <flux:subheading>{{ __('Todas as mensagens enviadas e recebidas pelo WhatsApp da empresa, das mais recentes para as mais antigas.') }}</flux:subheading>
    </div>

    <div class="flex flex-col gap-4 sm:flex-row">
        <flux:select wire:model.live="contactId" :label="__('Contato')" class="sm:max-w-xs">
            <flux:select.option value="">{{ __('Todos os contatos') }}</flux:select.option>

            @foreach ($this->contacts as $option)
                <flux:select.option :value="$option->id" wire:key="contact-filter-{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="status" :label="__('Situação')" class="sm:max-w-xs">
            <flux:select.option value="">{{ __('Todas') }}</flux:select.option>
            <flux:select.option :value="$this::RECEIVED">{{ __('Recebidas') }}</flux:select.option>

            @foreach (MessageStatus::cases() as $option)
                <flux:select.option :value="$option->value" wire:key="status-filter-{{ $option->value }}">
                    {{ __('Enviadas: :status', ['status' => $option->label()]) }}
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="flex flex-col gap-4" wire:poll.15s>
        @if ($this->messages->isEmpty())
            <flux:text>{{ __('Nenhuma mensagem encontrada.') }}</flux:text>
        @else
            <flux:table :paginate="$this->messages">
                <flux:table.columns>
                    <flux:table.column>{{ __('Data') }}</flux:table.column>
                    <flux:table.column>{{ __('Contato') }}</flux:table.column>
                    <flux:table.column>{{ __('Sentido') }}</flux:table.column>
                    <flux:table.column>{{ __('Mensagem') }}</flux:table.column>
                    <flux:table.column>{{ __('Situação') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->messages as $message)
                        <flux:table.row wire:key="message-{{ $message->id }}">
                            <flux:table.cell class="whitespace-nowrap align-top">
                                {{ ($message->sent_at ?? $message->created_at)->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}
                            </flux:table.cell>

                            <flux:table.cell class="align-top">
                                <div>{{ $message->contact->name }}</div>
                                <flux:text size="sm">{{ $message->contact->phone }}</flux:text>
                            </flux:table.cell>

                            <flux:table.cell class="align-top">
                                @if ($message->direction === MessageDirection::Inbound)
                                    <flux:badge size="sm" color="zinc" icon="arrow-down-left">{{ __('Recebida') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="blue" icon="arrow-up-right">{{ __('Enviada') }}</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="max-w-md whitespace-normal align-top">
                                @if ($message->template_name)
                                    <flux:text size="sm">{{ __('Template :name', ['name' => $message->template_name]) }}</flux:text>
                                @endif

                                <div class="line-clamp-4 whitespace-pre-line break-words">{{ $message->body ?? __('Mensagem do tipo :type', ['type' => $message->type]) }}</div>
                            </flux:table.cell>

                            <flux:table.cell class="max-w-xs whitespace-normal align-top">
                                @if ($message->direction === MessageDirection::Inbound)
                                    <flux:text size="sm">—</flux:text>
                                @elseif ($message->status)
                                    <flux:badge
                                        size="sm"
                                        :color="match ($message->status) {
                                            MessageStatus::Failed => 'red',
                                            MessageStatus::Read => 'green',
                                            MessageStatus::Delivered => 'lime',
                                            MessageStatus::Sent => 'sky',
                                            MessageStatus::Accepted => 'zinc',
                                        }"
                                    >{{ $message->status->label() }}</flux:badge>

                                    @if ($message->status === MessageStatus::Failed && $message->error_message)
                                        <flux:text size="sm" class="mt-1">
                                            {{ $message->error_code ? __('Erro :code:', ['code' => $message->error_code]) : '' }}
                                            {{ $message->error_message }}
                                        </flux:text>
                                    @endif
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
