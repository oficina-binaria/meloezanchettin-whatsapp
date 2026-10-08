<?php

use App\Models\Contact;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Contatos')] class extends Component {
    public string $name = '';
    public string $phone = '';

    /**
     * Create a contact from the submitted name and phone number.
     */
    public function createContact(): void
    {
        $this->phone = preg_replace('/\D+/', '', $this->phone);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'digits_between:10,15', Rule::unique(Contact::class, 'phone')],
        ], [
            'phone.digits_between' => __('Informe o número com DDI e DDD, entre 10 e 15 dígitos.'),
            'phone.unique' => __('Já existe um contato com este número.'),
        ]);

        Contact::create($validated);

        $this->reset('name', 'phone');
        unset($this->contacts);

        Flux::toast(variant: 'success', text: __('Contato cadastrado.'));
    }

    /**
     * Delete the given contact together with its message history.
     */
    public function deleteContact(int $contactId): void
    {
        Contact::query()->findOrFail($contactId)->delete();

        unset($this->contacts);

        Flux::toast(variant: 'success', text: __('Contato excluído.'));
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contacts(): Collection
    {
        return Contact::query()->orderBy('name')->orderBy('id')->get();
    }
}; ?>

<section class="flex w-full max-w-4xl flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Contatos') }}</flux:heading>
        <flux:subheading>{{ __('Destinatários das mensagens do WhatsApp. Quem escreve para o número da empresa é cadastrado automaticamente.') }}</flux:subheading>
    </div>

    <form wire:submit="createContact" class="flex flex-col gap-4 sm:flex-row sm:items-start">
        <flux:input wire:model="name" :label="__('Nome')" type="text" required class="sm:flex-1" />

        <flux:input
            wire:model="phone"
            :label="__('Número com DDI e DDD')"
            type="tel"
            inputmode="numeric"
            placeholder="5567999999999"
            required
            class="sm:flex-1"
        />

        <flux:button variant="primary" type="submit" class="sm:mt-6.5" data-test="create-contact-button">
            {{ __('Cadastrar') }}
        </flux:button>
    </form>

    @if ($this->contacts->isEmpty())
        <flux:text>{{ __('Nenhum contato cadastrado ainda.') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Nome') }}</flux:table.column>
                <flux:table.column>{{ __('Número') }}</flux:table.column>
                <flux:table.column>{{ __('Janela de 24 horas') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->contacts as $contact)
                    <flux:table.row wire:key="contact-{{ $contact->id }}">
                        <flux:table.cell>{{ $contact->name }}</flux:table.cell>
                        <flux:table.cell>{{ $contact->phone }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($contact->hasOpenServiceWindow())
                                <flux:badge color="green" size="sm">
                                    {{ __('Aberta por mais :hours h', ['hours' => $contact->serviceWindowHoursLeft()]) }}
                                </flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('Fechada') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                wire:click="deleteContact({{ $contact->id }})"
                                wire:confirm="{{ __('Excluir :name e todo o histórico de mensagens?', ['name' => $contact->name]) }}"
                                :aria-label="__('Excluir :name', ['name' => $contact->name])"
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
