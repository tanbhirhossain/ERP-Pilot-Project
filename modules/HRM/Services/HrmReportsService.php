<?php

namespace Modules\HRM\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

final class HrmReportsService
{
    public function headcount(): array
    {
        $byDept = DB::table('employees as e')
            ->leftJoin('departments as d','d.id','=','e.department_id')
            ->selectRaw('COALESCE(d.name,"Unassigned") as dept, COUNT(*) as cnt, SUM(CASE WHEN e.status="Active" THEN 1 ELSE 0 END) as active')
            ->groupBy('d.name')->orderByDesc('cnt')->get();

        $byDesig = DB::table('employees as e')
            ->leftJoin('designations as dg','dg.id','=','e.designation_id')
            ->selectRaw('COALESCE(dg.name,"Unassigned") as desig, COUNT(*) as cnt')
            ->groupBy('dg.name')->orderByDesc('cnt')->limit(10)->get();

        $byStatus = DB::table('employees')->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->get();
        $byType = DB::table('employees')->selectRaw('employment_type, COUNT(*) as cnt')->groupBy('employment_type')->get();
        $total = DB::table('employees')->count();
        $active = DB::table('employees')->where('status','Active')->count();

        return compact('byDept','byDesig','byStatus','byType','total','active');
    }

    public function attrition(int $months = 12): array
    {
        $from = Carbon::now()->subMonths($months-1)->startOfMonth();
        $resignations = DB::table('employee_resignations')
            ->selectRaw("strftime('%Y-%m', resignation_date) as ym, COUNT(*) as cnt, SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved")
            ->where('resignation_date','>=',$from->toDateString())
            ->groupBy(DB::raw("strftime('%Y-%m', resignation_date)"))->orderBy('ym')->get();

        $movements = DB::table('employee_movements')
            ->selectRaw("strftime('%Y-%m', effective_date) as ym, COUNT(*) as cnt")
            ->where('effective_date','>=',$from->toDateString())
            ->groupBy(DB::raw("strftime('%Y-%m', effective_date)"))->orderBy('ym')->get();

        $totalAvg = DB::table('employees')->count() ?: 1;
        $monthlyRate = $resignations->map(fn($r)=>['ym'=>$r->ym,'rate'=>round($r->cnt/$totalAvg*100,2)])->values();

        return ['resignations'=>$resignations,'movements'=>$movements,'monthlyRate'=>$monthlyRate,'periodMonths'=>$months];
    }

    public function leaveLiability(?int $year = null): array
    {
        $year = $year ?? (int)date('Y');
        $pending = DB::table('leave_requests')->whereYear('from_date',$year)->where('status','Pending')->sum('days');
        $approved = DB::table('leave_requests')->whereYear('from_date',$year)->where('status','Approved')->sum('days');
        $byType = DB::table('leave_requests as lr')->join('leave_types as lt','lt.id','=','lr.leave_type_id')
            ->selectRaw('lt.name as type, SUM(CASE WHEN lr.status="Approved" THEN lr.days ELSE 0 END) as approved, SUM(CASE WHEN lr.status="Pending" THEN lr.days ELSE 0 END) as pending')
            ->whereYear('lr.from_date',$year)->groupBy('lt.name')->get();

        $carry = 0; $lapsed = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('hrm_leave_balances')) {
            $carry = (float)DB::table('hrm_leave_balances')->where('year',$year)->sum('carry_forward');
            $lapsed = (float)DB::table('hrm_leave_balances')->where('year',$year)->sum('lapsed');
        }

        $topTakers = DB::table('leave_requests')->where('status','Approved')
            ->selectRaw('employee_id, SUM(days) as total_days')->whereYear('from_date',$year)
            ->groupBy('employee_id')->orderByDesc('total_days')->limit(10)->get();

        return compact('pending','approved','byType','carry','lapsed','topTakers','year');
    }

    public function attendanceTrend(int $months = 6): array
    {
        $from = Carbon::now()->subMonths($months-1)->startOfMonth()->toDateString();
        $trend = DB::table('attendance_dailies')
            ->selectRaw("strftime('%Y-%m', duty_date) as ym, COUNT(*) as total, SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status='Late' THEN 1 ELSE 0 END) as late, SUM(CASE WHEN status LIKE '%Early%' THEN 1 ELSE 0 END) as early_out, SUM(CASE WHEN status='Absent' THEN 1 ELSE 0 END) as absent, SUM(CASE WHEN status='On Leave' THEN 1 ELSE 0 END) as on_leave, SUM(CASE WHEN status LIKE 'Missing%' THEN 1 ELSE 0 END) as missing, SUM(CASE WHEN is_regularized=1 THEN 1 ELSE 0 END) as regularized")
            ->where('duty_date','>=',$from)->groupBy(DB::raw("strftime('%Y-%m', duty_date)"))->orderBy('ym')->get();

        $lateByDept = DB::table('attendance_dailies as ad')->leftJoin('employees as e','e.employee_id','=','ad.employee_id')->leftJoin('departments as d','d.id','=','e.department_id')
            ->selectRaw('COALESCE(d.name,"Unassigned") as dept, COUNT(*) as late_cnt')
            ->where('ad.status','Late')->where('ad.duty_date','>=',$from)->groupBy('d.name')->orderByDesc('late_cnt')->limit(8)->get();

        return compact('trend','lateByDept','months');
    }

    public function otCost(?string $from = null, ?string $to = null): array
    {
        $from = $from ?? Carbon::now()->startOfMonth()->toDateString();
        $to = $to ?? Carbon::now()->endOfMonth()->toDateString();

        $ot = DB::table('roster_overtimes')
            ->selectRaw("status, COUNT(*) as cnt, SUM(approved_hours) as hrs, SUM(approved_hours * COALESCE(hourly_rate,0)) as cost")
            ->whereBetween('duty_date',[$from,$to])->groupBy('status')->get();

        $byDept = DB::table('roster_overtimes as ot')->leftJoin('employees as e','e.employee_id','=','ot.employee_id')->leftJoin('departments as d','d.id','=','e.department_id')
            ->selectRaw('COALESCE(d.name,"Unassigned") as dept, COUNT(*) as cnt, SUM(ot.approved_hours) as hrs')
            ->whereBetween('ot.duty_date',[$from,$to])->where('ot.status','Approved')->groupBy('d.name')->orderByDesc('hrs')->limit(8)->get();

        $topOt = DB::table('roster_overtimes')->where('status','Approved')->whereBetween('duty_date',[$from,$to])
            ->selectRaw('employee_id, SUM(approved_hours) as hrs')->groupBy('employee_id')->orderByDesc('hrs')->limit(10)->get();

        return compact('ot','byDept','topOt','from','to');
    }

    public function overview(): array
    {
        return [
            'headcount' => $this->headcount(),
            'attrition' => $this->attrition(6),
            'leave' => $this->leaveLiability(),
            'attendance' => $this->attendanceTrend(6),
            'ot' => $this->otCost(),
        ];
    }
}
