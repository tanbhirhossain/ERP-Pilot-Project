<?php
namespace Modules\HRM\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class StoreDesignationRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return ['name'=>['required','string','max:255'],'code'=>['nullable','string','max:50',Rule::unique('designations','code')->ignore($this->designation)],'description'=>['nullable','string','max:1000'],'department_id'=>['nullable','exists:departments,id'],'grade'=>['nullable','string','max:50'],'level'=>['nullable','integer','min:0'],'is_active'=>['sometimes','boolean']]; }
}
