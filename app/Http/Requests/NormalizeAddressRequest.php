<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NormalizeAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country' => ['required', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'google_validate' => ['nullable', 'boolean'],
        ];
    }
}
