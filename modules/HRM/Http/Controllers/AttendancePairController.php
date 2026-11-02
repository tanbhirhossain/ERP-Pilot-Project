<?php
namespace Modules\HRM\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Interfaces\Attendance\AttendancePairRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendancePair;
class AttendancePairController extends Controller {
    public function __construct(private readonly AttendancePairRepositoryInterface $repo) {}
    public function index(Request $r): Response {
        $allowedSorts=['id','duty_date','punch_in','worked_hours','created_at'];
        $allowedFilters=['employee_id','duty_type','shift_id','duty_date','from','to','department_id','designation_id'];
        $dt=DataTableQuery::fromRequest($r,$allowedSorts,$allowedFilters);
        $paginated=$this->repo->paginate($dt);
        $filterDefs=[
            ['key'=>'department_id','label'=>'Department','kind'=>'endpoint','endpoint'=>'/hrm/api/options/departments','placeholder'=>'Filter by department'],
            ['key'=>'designation_id','label'=>'Designation','kind'=>'endpoint','endpoint'=>'/hrm/api/options/designations','placeholder'=>'Filter by designation'],
            ['key'=>'employee_id','label'=>'Employee','kind'=>'endpoint','endpoint'=>'/hrm/api/options/employees','placeholder'=>'Search employee…'],
            ['key'=>'duty_type','label'=>'Type','kind'=>'select','options'=>[['value'=>'Primary','label'=>'Primary'],['value'=>'Overtime','label'=>'Overtime']]],
            ['key'=>'from','label'=>'From Date','kind'=>'date'],
            ['key'=>'to','label'=>'To Date','kind'=>'date'],
        ];
        return Inertia::render('HRM::Attendance/Pairs/Index',[
            'pairs'=>PaginatedResource::from($paginated->through(fn(AttendancePair $p)=>[
                'id'=>$p->id,'employee_id'=>$p->employee_id,'employee'=>$p->employee?->full_name ?? trim(($p->employee?->first_name ?? '').' '.($p->employee?->last_name ?? '')) ?: null,
                'department'=>$p->employee?->department?->name ?? null,'designation'=>$p->employee?->designation?->name ?? null,
                'shift'=>$p->shift?->shift_name ?? null,'shift_time'=> $p->shift ? (substr($p->shift->start_time,0,5).'→'.substr($p->shift->end_time,0,5)) : null,
                'duty_date'=>$p->duty_date?->toDateString(),'punch_in'=>$p->punch_in?->toIso8601String(),'punch_out'=>$p->punch_out?->toIso8601String(),'duty_type'=>$p->duty_type,'shift_type_applied'=>$p->shift_type_applied,'worked_hours'=>$p->worked_hours,'created_at'=>$p->created_at?->toIso8601String()
            ])),
            'filterDefs'=>$filterDefs,
        ]);
    }
    public function destroy(AttendancePair $pair): \Illuminate\Http\RedirectResponse { $this->repo->delete($pair); return back()->with('success','Pair deleted.'); }
}
