<?php

namespace App\Http\Requests;

use App\Models\Generation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(Generation::SIZES)],
            'quality' => ['required', Rule::in(Generation::QUALITIES)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['string', 'url:http,https'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('reference_images') === null) {
            $this->merge(['reference_images' => []]);
        }
    }
}
