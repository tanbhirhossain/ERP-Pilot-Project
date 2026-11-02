<?php
namespace Modules\HRM\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveTypeSetting;
use Modules\HRM\Requests\StoreLeaveTypeRequest;
use Modules\HRM\Services\LeaveTypeService;
class LeaveTypeController extends Controller {
    public function __construct(private readonly LeaveTypeService $svc) {}
    public function index(Request $r): Response {
        $dt=DataTableQuery::fromRequest($r,['id','name','created_at'],['is_active','is_paid']);
        $page=$this->svc->paginate($dt);
        $mapped=$page->through(fn(LeaveType $t)=>['id'=>$t->id,'name'=>$t->name,'code'=>$t->code,'days_per_year'=>$t->days_per_year,'is_paid'=>$t->is_paid,'color'=>$t->color,'is_active'=>$t->is_active,'description'=>$t->description,'created_at'=>$t->created_at?->toIso8601String()]);
        return Inertia::render('HRM::LeaveTypes/Index',['leaveTypes'=>PaginatedResource::from($mapped)]);
    }
    public function create(): Response { return Inertia::render('HRM::LeaveTypes/Create',['policy'=>['day_count_mode'=>'calendar_days','accrual_mode'=>'annual','proration_mode'=>'none','working_days_csv'=>'0,1,2,3,4','approval_steps_text'=>'HR']]); }
    public function store(StoreLeaveTypeRequest $req): RedirectResponse { $data=$req->validated(); $policy=$this->extractPolicy($data); $type=$this->svc->create($data); $this->savePolicy($type,$policy); return redirect()->route('hrm.leave-types.index')->with('success','Leave type created'); }
    public function edit(LeaveType $leave_type): Response { $p=LeaveTypeSetting::firstOrCreate(['leave_type_id'=>$leave_type->id],[]); return Inertia::render('HRM::LeaveTypes/Create',['leaveType'=>['id'=>$leave_type->id,'name'=>$leave_type->name,'code'=>$leave_type->code,'days_per_year'=>$leave_type->days_per_year,'is_paid'=>$leave_type->is_paid,'color'=>$leave_type->color,'is_active'=>$leave_type->is_active,'description'=>$leave_type->description],'policy'=>['day_count_mode'=>$p->day_count_mode,'accrual_mode'=>$p->accrual_mode,'proration_mode'=>$p->proration_mode,'working_days_csv'=>implode(',', $p->working_days??[0,1,2,3,4]),'approval_steps_text'=>implode(', ',$p->approval_steps??['HR'])]]); }
    public function update(StoreLeaveTypeRequest $req, LeaveType $leave_type): RedirectResponse { $data=$req->validated(); $policy=$this->extractPolicy($data); $this->svc->update($leave_type,$data); $this->savePolicy($leave_type,$policy); return redirect()->route('hrm.leave-types.index')->with('success','Leave type updated'); }
    private function extractPolicy(array &$data): array { $policy=[]; foreach(['day_count_mode','accrual_mode','proration_mode','working_days_csv','approval_steps_text'] as $key){$policy[$key]=$data[$key]??null;unset($data[$key]);} return $policy; }
    private function savePolicy(LeaveType $type,array $p): void { $days=collect(explode(',',(string)($p['working_days_csv']?:'0,1,2,3,4')))->map(fn($v)=>(int)trim($v))->filter(fn($v)=>$v>=0&&$v<=6)->unique()->values()->all(); $steps=collect(explode(',',(string)($p['approval_steps_text']?:'HR')))->map(fn($v)=>trim($v))->filter()->values()->all(); LeaveTypeSetting::updateOrCreate(['leave_type_id'=>$type->id],['day_count_mode'=>$p['day_count_mode']??'calendar_days','accrual_mode'=>$p['accrual_mode']??'annual','proration_mode'=>$p['proration_mode']??'none','working_days'=>$days?:[0,1,2,3,4],'approval_steps'=>$steps?:['HR']]); }
    public function destroy(LeaveType $leave_type): RedirectResponse { $this->svc->delete($leave_type); return back()->with('success','Leave type removed'); }
}
