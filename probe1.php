<?php
$out = [];
foreach (['employee_policies','roster_shifts','payroll_settings','payroll_cutlists','payroll_cutlist_items','payroll_slip','payroll_slips','payroll_salary_sheets','device_users','holidays','acc_banks','leave_type_settings','employee_fallback_off_days','payroll_adjustments'] as $t) {
    $cols = DB::select("PRAGMA table_info($t)");
    if (!$cols) { $out[$t] = 'MISSING'; continue; }
    foreach ($cols as $c) { $out[$t][] = $c->name.($c->dflt_value !== null ? '='.$c->dflt_value : '') . ($c->notnull ? '*' : ''); }
}
file_put_contents('C:/tools/probe2.json', json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
