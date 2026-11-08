<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Services\HrmReportsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrmReportsController extends Controller
{
    public function __construct(private readonly HrmReportsService $reports) {}

    public function index(Request $r): Response
    {
        return Inertia::render('HRM::Reports/Index', [
            'overview' => $this->reports->overview(),
            'filters' => ['year' => $r->input('year', date('Y')), 'months' => $r->input('months', 6)],
        ]);
    }

    public function headcount(): Response
    {
        return Inertia::render('HRM::Reports/Headcount', ['data' => $this->reports->headcount()]);
    }

    public function attrition(Request $r): Response
    {
        return Inertia::render('HRM::Reports/Attrition', ['data' => $this->reports->attrition((int) $r->input('months', 12))]);
    }

    public function leave(Request $r): Response
    {
        return Inertia::render('HRM::Reports/Leave', ['data' => $this->reports->leaveLiability((int) $r->input('year', date('Y')))]);
    }

    public function attendance(Request $r): Response
    {
        return Inertia::render('HRM::Reports/Attendance', ['data' => $this->reports->attendanceTrend((int) $r->input('months', 6))]);
    }

    public function ot(Request $r): Response
    {
        return Inertia::render('HRM::Reports/Ot', ['data' => $this->reports->otCost($r->input('from'), $r->input('to'))]);
    }

    public function export(Request $r): StreamedResponse
    {
        $type = $r->input('type', 'headcount');
        $data = match ($type) {
            'headcount' => $this->reports->headcount(),
            'attrition' => $this->reports->attrition(12),
            'leave' => $this->reports->leaveLiability(),
            'attendance' => $this->reports->attendanceTrend(12),
            'ot' => $this->reports->otCost(),
            default => $this->reports->overview(),
        };
        $filename = "hrm-{$type}-".date('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Report', 'Value']);
            foreach ($data as $k => $v) {
                fputcsv($out, [$k, is_scalar($v) ? $v : json_encode($v)]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
