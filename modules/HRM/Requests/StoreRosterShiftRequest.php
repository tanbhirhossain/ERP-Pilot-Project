<?php
namespace Modules\HRM\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreRosterShiftRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return ['shift_name'=>['required','string','max:255'],'start_time'=>['nullable','date_format:H:i'],'end_time'=>['nullable','date_format:H:i'],'punch_in_window_start'=>['nullable','date_format:H:i'],'punch_in_window_end'=>['nullable','date_format:H:i'],'crosses_midnight'=>['sometimes','boolean'],'max_duration_hours'=>['required','integer','min:1','max:72'], 'description'=>['nullable','string']]; }
}
