<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Department;
use Modules\HRM\Requests\StoreDepartmentRequest;
use Modules\HRM\Requests\UpdateDepartmentRequest;
use Modules\HRM\Services\DepartmentService;

class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['id', 'name', 'code', 'created_at'], ['is_active', 'parent_id']);

        return Inertia::render('HRM::Departments/Index', [
            'departments' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code, 'description' => $d->description, 'parent' => $d->parent ? ['id' => $d->parent->id, 'name' => $d->parent->name] : null, 'is_active' => $d->is_active, 'created_at' => $d->created_at?->toIso8601String()])),
            'parentOptions' => $this->svc->options(null, 100),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('HRM::Departments/Create', ['parentOptions' => $this->svc->options(null, 100)]);
    }

    public function store(StoreDepartmentRequest $req): RedirectResponse
    {
        $this->svc->create($req->validated());

        return redirect()->route('hrm.departments.index')->with('success', 'Department created.');
    }

    public function edit(Department $department): Response
    {
        return Inertia::render('HRM::Departments/Edit', ['department' => ['id' => $department->id, 'name' => $department->name, 'code' => $department->code, 'description' => $department->description, 'parent_id' => $department->parent_id, 'is_active' => $department->is_active], 'parentOptions' => $this->svc->options(null, 100)]);
    }

    public function update(UpdateDepartmentRequest $req, Department $department): RedirectResponse
    {
        $this->svc->update($department, $req->validated());

        return redirect()->route('hrm.departments.index')->with('success', 'Department updated.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        $this->svc->delete($department);

        return redirect()->route('hrm.departments.index')->with('success', 'Department deleted.');
    }
}
