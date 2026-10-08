<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reduce the recipient number to its digits before it is validated.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('to'))) {
            $this->merge(['to' => preg_replace('/\D+/', '', $this->input('to'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', 'digits_between:10,15'],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(['text', 'template'])],
            'text' => ['required_if:type,text', 'string', 'max:4096'],
            'template' => ['required_if:type,template', 'array'],
            'template.name' => ['required_if:type,template', 'string', 'max:512'],
            'template.language' => ['required_if:type,template', 'string', 'max:20'],
            'template.parameters' => ['sometimes', 'array', 'list'],
            'template.parameters.*' => ['required', 'string', 'max:1024', 'not_regex:/[\r\n\t]| {5,}/'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.digits_between' => 'Informe o número com DDI e DDD, entre 10 e 15 dígitos.',
            'template.parameters.*.not_regex' => 'Variáveis não aceitam quebra de linha, tabulação ou mais de quatro espaços seguidos.',
        ];
    }
}
