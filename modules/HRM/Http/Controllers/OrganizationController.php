<?php
namespace Modules\HRM\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Requests\StoreOrganizationRequest;
use Modules\HRM\Services\OrganizationService;
class OrganizationController extends Controller {
    public function __construct(private readonly OrganizationService $org) {}
    public function index(): Response {
        return Inertia::render('HRM::Organization/Index', ['organization'=>$this->org->get()]);
    }
    public function store(StoreOrganizationRequest $request): RedirectResponse {
        $data=$request->validated();
        if($request->hasFile('logo')){ $data['logo']=$request->file('logo')->store('organizations','public'); }
        $this->org->upsert($data);
        return redirect()->route('hrm.organizations.index')->with('success','Organization saved successfully.');
    }
    public function update(StoreOrganizationRequest $request): RedirectResponse {
        $data=$request->validated();
        if($request->hasFile('logo')){ $data['logo']=$request->file('logo')->store('organizations','public'); }
        $this->org->upsert($data);
        return redirect()->route('hrm.organizations.index')->with('success','Organization updated.');
    }
}
