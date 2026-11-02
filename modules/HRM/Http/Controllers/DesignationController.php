<?php
namespace Modules\HRM\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Designation;
use Modules\HRM\Requests\StoreDesignationRequest;
use Modules\HRM\Requests\UpdateDesignationRequest;
use Modules\HRM\Services\DepartmentService;
use Modules\HRM\Services\DesignationService;
class DesignationController extends Controller {
    public function __construct(private readonly DesignationService $svc, private readonly DepartmentService $deptSvc) {}
    public function index(Request $r): Response {
        $dt=DataTableQuery::fromRequest($r,['id','name','code','level','created_at'],['department_id','is_active']);
        return Inertia::render('HRM::Designations/Index',[
            'designations'=>PaginatedResource::from($this->svc->paginate($dt)->through(fn(Designation $d)=>['id'=>$d->id,'name'=>$d->name,'code'=>$d->code,'department'=>$d->department?['id'=>$d->department->id,'name'=>$d->department->name]:null,'grade'=>$d->grade,'level'=>$d->level,'is_active'=>$d->is_active,'created_at'=>$d->created_at?->toIso8601String()])),
            'departmentOptions'=>$this->deptSvc->options(null,200)
        ]);
    }
    public function create(): Response { return Inertia::render('HRM::Designations/Create',['departmentOptions'=>$this->deptSvc->options(null,200)]); }
    public function store(StoreDesignationRequest $req): RedirectResponse { $this->svc->create($req->validated()); return redirect()->route('hrm.designations.index')->with('success','Designation created.'); }
    public function edit(Designation $designation): Response { return Inertia::render('HRM::Designations/Edit',['designation'=>['id'=>$designation->id,'name'=>$designation->name,'code'=>$designation->code,'description'=>$designation->description,'department_id'=>$designation->department_id,'grade'=>$designation->grade,'level'=>$designation->level,'is_active'=>$designation->is_active],'departmentOptions'=>$this->deptSvc->options(null,200)]); }
    public function update(UpdateDesignationRequest $req, Designation $designation): RedirectResponse { $this->svc->update($designation,$req->validated()); return redirect()->route('hrm.designations.index')->with('success','Designation updated.'); }
    public function destroy(Designation $designation): RedirectResponse { $this->svc->delete($designation); return redirect()->route('hrm.designations.index')->with('success','Designation deleted.'); }
}
