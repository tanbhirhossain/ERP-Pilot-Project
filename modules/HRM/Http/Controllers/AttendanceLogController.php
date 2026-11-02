<?php
namespace Modules\HRM\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Requests\StoreAttendanceLogRequest;
use Modules\HRM\Interfaces\Attendance\AttendanceLogRepositoryInterface;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;
class AttendanceLogController extends Controller {
    public function __construct(private readonly AttendanceLogRepositoryInterface $repo, private readonly AttendanceProcessingService $processor) {}
    public function index(Request $r): Response {
        $allowedSorts=['id','punch_time','created_at'];
        $allowedFilters=['employee_id','is_processed','device_id','duty_date','from','to','department_id','designation_id'];
        $dt=DataTableQuery::fromRequest($r,$allowedSorts,$allowedFilters);
        $paginated=$this->repo->paginate($dt);
        $filterDefs=[
            ['key'=>'department_id','label'=>'Department','kind'=>'endpoint','endpoint'=>'/hrm/api/options/departments','placeholder'=>'Filter by department'],
            ['key'=>'designation_id','label'=>'Designation','kind'=>'endpoint','endpoint'=>'/hrm/api/options/designations','placeholder'=>'Filter by designation'],
            ['key'=>'employee_id','label'=>'Employee','kind'=>'endpoint','endpoint'=>'/hrm/api/options/employees','placeholder'=>'Search employee…'],
            ['key'=>'is_processed','label'=>'Status','kind'=>'select','options'=>[['value'=>'0','label'=>'Pending'],['value'=>'1','label'=>'Processed']]],
            ['key'=>'from','label'=>'From Date','kind'=>'date'],
            ['key'=>'to','label'=>'To Date','kind'=>'date'],
        ];
        return Inertia::render('HRM::Attendance/Logs/Index',[
            'logs'=>PaginatedResource::from($paginated->through(fn(AttendanceLog $l)=>['id'=>$l->id,'device_user_id'=>$l->device_user_id,'employee_id'=>$l->employee_id,'employee'=>$l->employee?->full_name ?? trim(($l->employee?->first_name ?? '').' '.($l->employee?->last_name ?? '')) ?: null,'department'=>$l->employee?->department?->name ?? null,'designation'=>$l->employee?->designation?->name ?? null,'punch_time'=>$l->punch_time?->toIso8601String(),'device_id'=>$l->device_id,'punch_type'=>$l->punch_type,'is_processed'=>$l->is_processed,'created_at'=>$l->created_at?->toIso8601String()])),
            'filterDefs'=>$filterDefs,
        ]);
    }
    public function store(StoreAttendanceLogRequest $req): RedirectResponse { $this->repo->create($req->validated()); return back()->with('success','Log saved.'); }
    public function destroy(AttendanceLog $log): RedirectResponse { $this->repo->delete($log); return back()->with('success','Log deleted.'); }
    public function process(Request $r): RedirectResponse {
        $res=$this->processor->processPending($r->integer('limit',500));
        return back()->with('success',"Processed {$res['processed']} logs, {$res['paired']} pairs, {$res['errors']} errors");
    }
}
