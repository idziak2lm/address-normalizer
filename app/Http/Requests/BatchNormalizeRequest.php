<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatchNormalizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBatch = config('normalizer.batch_max_size', 50);

        return [
            'addresses' => ['required', 'array', 'min:1', "max:{$maxBatch}"],
            'addresses.*.country' => ['required', 'string', 'size:2'],
            'addresses.*.city' => ['required', 'string', 'max:255'],
            'addresses.*.address' => ['required', 'string', 'max:500'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.full_name' => ['nullable', 'string', 'max:255'],
            'addresses.*.id' => ['nullable', 'string', 'max:100'],
            'google_validate' => ['nullable', 'boolean'],
        ];
    }
}
