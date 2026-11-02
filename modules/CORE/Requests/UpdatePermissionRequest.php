<?php

namespace Modules\CORE\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^(\*|[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+)$/',
                Rule::unique('permissions', 'name')
                    ->where('guard_name', 'web')
                    ->ignore($this->route('permission')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please enter a permission name.',
            'name.unique' => 'A permission with this name already exists.',
            'name.regex' => 'Use the resource.action format — for example "users.create" (or "*" for wildcard).',
        ];
    }
}
