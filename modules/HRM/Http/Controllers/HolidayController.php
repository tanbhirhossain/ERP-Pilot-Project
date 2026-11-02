<?php
namespace Modules\HRM\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Holiday;
class HolidayController extends Controller {
    public function index(): Response {
        abort_unless(auth()->user()?->can('hrm.holidays.view')||auth()->user()?->can('hrm.holidays.manage'),403);
        $items=Holiday::with('department:id,name')->orderBy('holiday_date')->paginate(30)->withQueryString();
        return Inertia::render('HRM::Holidays/Index',['holidays'=>$items->through(fn(Holiday $h)=>['id'=>$h->id,'name'=>$h->name,'holiday_date'=>$h->holiday_date?->toDateString(),'holiday_type'=>$h->holiday_type,'department_id'=>$h->department_id,'department'=>$h->department?->name,'notes'=>$h->notes]),'departmentOptions'=>Department::query()->orderBy('name')->get(['id','name'])->map(fn($d)=>['value'=>$d->id,'label'=>$d->name])->values(),'canManage'=>(bool)auth()->user()?->can('hrm.holidays.manage')]);
    }
    public function store(Request $r): RedirectResponse {
        abort_unless(auth()->user()?->can('hrm.holidays.manage'),403);
        $d=$r->validate(['name'=>['required','string','max:150'],'holiday_date'=>['required','date'],'holiday_type'=>['required',Rule::in(['Public Holiday','Company Holiday','Religious Holiday','Other'])],'department_id'=>['nullable','exists:departments,id'],'notes'=>['nullable','string','max:2000']]);
        Holiday::create($d); return back()->with('success','Holiday added to calendar.');
    }
    public function update(Request $r, Holiday $holiday): RedirectResponse {
        abort_unless(auth()->user()?->can('hrm.holidays.manage'),403);
        $d=$r->validate(['name'=>['required','string','max:150'],'holiday_date'=>['required','date'],'holiday_type'=>['required',Rule::in(['Public Holiday','Company Holiday','Religious Holiday','Other'])],'department_id'=>['nullable','exists:departments,id'],'notes'=>['nullable','string','max:2000']]);
        $holiday->update($d); return back()->with('success','Holiday updated.');
    }
    public function destroy(Holiday $holiday): RedirectResponse { abort_unless(auth()->user()?->can('hrm.holidays.manage'),403); $holiday->delete(); return back()->with('success','Holiday removed.'); }
}
