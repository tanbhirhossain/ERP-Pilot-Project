<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:500'], 'website' => ['nullable', 'url', 'max:255'], 'logo' => ['nullable', 'image', 'max:2048']];
    }
}
