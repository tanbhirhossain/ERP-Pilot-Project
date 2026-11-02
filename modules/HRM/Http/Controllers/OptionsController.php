<?php
namespace Modules\HRM\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Services\DepartmentService;
use Modules\HRM\Services\DesignationService;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\RosterShiftService;
use Modules\HRM\Services\BiometricDeviceService;
class OptionsController extends Controller {
    public function __invoke(Request $r, string $type): JsonResponse {
        $search=$r->query('search'); $limit=max(1,min(500,(int)$r->query('limit',200)));
        $items=match($type){
            'departments'=>app(DepartmentService::class)->options($search,$limit),
            'designations'=>app(DesignationService::class)->options($search,$limit),
            'employees'=>app(EmployeeService::class)->options($search,$limit),
            'shifts'=>app(RosterShiftService::class)->options($search,$limit),
            'devices'=>app(BiometricDeviceService::class)->options($search,$limit),
            default=>null,
        };
        if($items===null) return response()->json(['error'=>'Unknown type'],404);
        // SearchableSelect remote mode consumes this envelope; bare Collection responses looked empty.
        return response()->json(['items'=>$items->values()]);
    }
}
