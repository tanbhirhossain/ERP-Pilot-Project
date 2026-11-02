<?php
namespace Modules\HRM\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Requests\StoreEmployeeRequest;
use Modules\HRM\Requests\UpdateEmployeeRequest;
use Modules\HRM\Services\DepartmentService;
use Modules\HRM\Services\DesignationService;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\OrganizationService;
class EmployeeController extends Controller {
    public function __construct(private readonly EmployeeService $svc, private readonly DepartmentService $dept, private readonly DesignationService $desig, private readonly OrganizationService $org) {}
    public function index(Request $r): Response {
        $dt=DataTableQuery::fromRequest($r,['id','employee_id','biometric_id','full_name','created_at','joining_date'],['department_id','designation_id','status']);
        return Inertia::render('HRM::Employees/Index',[
            'employees'=>PaginatedResource::from($this->svc->paginate($dt)->through(fn(Employee $e)=>[
                'id'=>$e->id,'employee_id'=>$e->employee_id,'biometric_id'=>$e->biometric_id,'full_name'=>$e->full_name??trim($e->first_name.' '.$e->last_name),'email'=>$e->email,'phone'=>$e->phone,'department'=>$e->department?['id'=>$e->department->id,'name'=>$e->department->name]:null,'designation'=>$e->designation?['id'=>$e->designation->id,'name'=>$e->designation->name]:null,'employment_type'=>$e->employment_type,'status'=>$e->status,'joining_date'=>$e->joining_date?->toDateString(),'created_at'=>$e->created_at?->toIso8601String()
            ])),
            'departmentOptions'=>$this->dept->options(null,200),
            'designationOptions'=>$this->desig->options(null,200)
        ]);
    }
    public function create(): Response {
        return Inertia::render('HRM::Employees/Create',[
            'departmentOptions'=>$this->dept->options(null,200),
            'designationOptions'=>$this->desig->options(null,200),
            'organizationOptions'=> $this->org->get() ? [['value'=>$this->org->get()->id,'label'=>$this->org->get()->name]] : [],
            'userOptions'=> \Modules\CORE\Models\User::orderBy('name')->limit(100)->get()->map(fn($u)=>['value'=>$u->id,'label'=>$u->name,'description'=>$u->email])->values()->all(),
        ]);
    }
    public function store(StoreEmployeeRequest $req): RedirectResponse {
        $data=$req->validated();
        if($req->hasFile('photo')) $data['photo']=$req->file('photo')->store('employees','public');
        $this->svc->create($data);
        return redirect()->route('hrm.employees.index')->with('success','Employee created successfully.');
    }
    public function edit(Employee $employee): Response {
        $employee->load(['profile','policy','salaries','educations','experiences','skills']);
        return Inertia::render('HRM::Employees/Edit',[
            'employee'=>[
                'id'=>$employee->id,'employee_id'=>$employee->employee_id,'biometric_id'=>$employee->biometric_id,'user_id'=>$employee->user_id,'first_name'=>$employee->first_name,'last_name'=>$employee->last_name,'full_name'=>$employee->full_name,'email'=>$employee->email,'phone'=>$employee->phone,'nid'=>$employee->nid,'gender'=>$employee->gender,'date_of_birth'=>$employee->date_of_birth?->toDateString(),'joining_date'=>$employee->joining_date?->toDateString(),'card_issue_date'=>$employee->card_issue_date?->toDateString(),'organization_id'=>$employee->organization_id,'department_id'=>$employee->department_id,'designation_id'=>$employee->designation_id,'employment_type'=>$employee->employment_type,'job_type'=>$employee->job_type,'joining_salary'=>$employee->joining_salary,'allow_overtime'=>$employee->allow_overtime,'allow_bonus'=>$employee->allow_bonus,'payment_criteria'=>$employee->payment_criteria,'service_year'=>$employee->service_year,'file_no'=>$employee->file_no,'status'=>$employee->status,'blood_group'=>$employee->blood_group,'photo'=>$employee->photo,
                'profile'=>$employee->profile?->toArray(),'policy'=>array_merge($employee->policy?->toArray() ?? [], ['fallback_off_day'=>\Modules\HRM\Models\Employee\EmployeeFallbackOffDay::where('employee_id',$employee->employee_id)->value('day_of_week')]),'salary'=>$employee->salaries->first()?->toArray(),'salaries'=>$employee->salaries->toArray(),'educations'=>$employee->educations->toArray(),'experiences'=>$employee->experiences->toArray(),'skills'=>$employee->skills->toArray()
            ],
            'departmentOptions'=>$this->dept->options(null,200),
            'designationOptions'=>$this->desig->options(null,200),
            'organizationOptions'=> $this->org->get() ? [['value'=>$this->org->get()->id,'label'=>$this->org->get()->name]] : [],
            'userOptions'=> \Modules\CORE\Models\User::orderBy('name')->limit(100)->get()->map(fn($u)=>['value'=>$u->id,'label'=>$u->name,'description'=>$u->email])->values()->all(),
        ]);
    }
    public function show(Employee $employee): Response {
        $employee->load(['organization','department','designation','user','profile','policy','salaries','educations','experiences','skills','supervisors.supervisor','supervisors.supervisorEmployee','attendanceDailies'=>fn($q)=>$q->orderBy('duty_date','desc')->limit(10)]);
        // Attach flexible display for Inertia
        $employee->supervisors->each->append('supervisor_display');
        // Also load subordinates where this employee is supervisor (employee-as-supervisor flexibility)
        $subordinates = \Modules\HRM\Models\Employee\EmployeeSupervisor::with(['employee:id,employee_id,full_name'])
            ->where('supervisor_employee_id',$employee->employee_id)->get()->map(fn($s)=>[
                'id'=>$s->id,'employee_id'=>$s->employee_id,'employee'=>$s->employee?->full_name ?? $s->employee_id,'supervisor_type'=>$s->supervisor_type,'is_active'=>$s->is_active
            ]);
        return Inertia::render('HRM::Employees/Show',['employee'=>$employee,'subordinates'=>$subordinates]);
    }
    public function update(UpdateEmployeeRequest $req, Employee $employee): RedirectResponse {
        $data=$req->validated();
        if($req->hasFile('photo')) $data['photo']=$req->file('photo')->store('employees','public');
        $this->svc->update($employee,$data);
        return redirect()->route('hrm.employees.index')->with('success','Employee updated.');
    }
    public function destroy(Employee $employee): RedirectResponse { $this->svc->delete($employee); return redirect()->route('hrm.employees.index')->with('success','Employee deleted.'); }
}