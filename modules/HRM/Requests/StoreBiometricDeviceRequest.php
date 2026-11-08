<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBiometricDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'device_name' => ['nullable', 'string', 'max:255'], 'ip_address' => ['required', 'ip'], 'port' => ['required', 'integer', 'min:1', 'max:65535'], 'serial_number' => ['nullable', 'string', 'max:100'], 'device_model' => ['nullable', 'string', 'max:100'], 'location' => ['nullable', 'string', 'max:255'], 'status' => ['required', 'in:Active,Inactive,Maintenance'], 'is_enabled' => ['sometimes', 'boolean'], 'auto_sync_enabled' => ['sometimes', 'boolean'], 'sync_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440']];
    }
}
