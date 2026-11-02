<?php
namespace Modules\HRM\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class UpdateDepartmentRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return ['name'=>['required','string','max:255'],'code'=>['nullable','string','max:50',Rule::unique('departments','code')->ignore($this->department)],'description'=>['nullable','string','max:1000'],'parent_id'=>['nullable','exists:departments,id'],'is_active'=>['sometimes','boolean']]; }
}
