<?php
namespace Modules\HRM\Database\Seeders;
use Illuminate\Database\Seeder;
use Modules\HRM\Models\Leave\LeaveType;
class LeaveTypeSeeder extends Seeder {
    public function run(): void {
        $rows=[
            ['name'=>'Casual Leave','code'=>'CL','days_per_year'=>10,'is_paid'=>true,'color'=>'#0ea5e9','description'=>'Short casual leaves'],
            ['name'=>'Sick Leave','code'=>'SL','days_per_year'=>16,'is_paid'=>true,'color'=>'#ef4444','description'=>'Medical / sick'],
            ['name'=>'Earned Leave','code'=>'EL','days_per_year'=>14,'is_paid'=>true,'color'=>'#10b981','description'=>'Annual earned'],
            ['name'=>'Maternity Leave','code'=>'ML','days_per_year'=>180,'is_paid'=>true,'color'=>'#ec4899','description'=>'Maternity'],
            ['name'=>'Paternity Leave','code'=>'PL','days_per_year'=>7,'is_paid'=>true,'color'=>'#8b5cf6','description'=>'Paternity'],
            ['name'=>'Compensatory Off','code'=>'CO','days_per_year'=>0,'is_paid'=>true,'color'=>'#f59e0b','description'=>'Comp off against extra duty'],
        ];
        foreach($rows as $r){ LeaveType::updateOrCreate(['name'=>$r['name']], $r); }
    }
}
