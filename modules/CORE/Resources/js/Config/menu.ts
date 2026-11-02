/* Declarative collapsible-tree sidebar menu for the CORE admin shell. */
import type { LucideIcon } from 'lucide-vue-next';
import {
  LayoutDashboard, ShieldCheck, Users, Shield, KeyRound, Palette, ScrollText, History, Calculator, Wallet, PiggyBank, Settings2,
  Building2, Network, BadgeCent, Fingerprint, Link2, UserCog, Clock3, CalendarRange, CalendarCheck, FileClock, Layers, CalendarDays, RefreshCw, UsersRound, ArrowLeftRight, Timer, Tag, Plane, Bell, ClipboardList, UserCheck
} from 'lucide-vue-next';

export interface SidebarItem {
  key: string;
  label: string;
  route?: string;
  routeParams?: Record<string, any>;
  href?: string;
  icon: LucideIcon;
  permission?: string;
  children?: SidebarItem[];
}

export const sidebarMenu: SidebarItem[] = [
  { key: 'dashboard', label: 'Dashboard', route: 'core.dashboard', icon: LayoutDashboard, permission: 'dashboard.view' },
  {
    key: 'hrm', label: 'HRM', icon: UserCog,
    children: [
      { key: 'hrm-organization', label: 'Organization', route: 'hrm.organizations.index', icon: Building2 },
      { key: 'hrm-departments', label: 'Departments', route: 'hrm.departments.index', icon: Network },
      { key: 'hrm-designations', label: 'Designations', route: 'hrm.designations.index', icon: BadgeCent },
      { key: 'hrm-employees', label: 'Employees', route: 'hrm.employees.index', icon: Users },
      { key: 'hrm-resignations', label: 'Resignations', route: 'hrm.resignations.index', icon: UserCheck },
      { key: 'hrm-supervisors', label: 'Supervisors', route: 'hrm.supervisors.index', icon: UsersRound },
      { key: 'hrm-device-users', label: 'Device Users', route: 'hrm.device-users.index', icon: Link2 },
      { key: 'hrm-devices', label: 'Biometric Devices', route: 'hrm.devices.index', icon: Fingerprint },
    ],
  },
  {
    key: 'payroll', label: 'PAYROLL', icon: Calculator,
    children: [
      { key: 'payroll-dashboard', label: 'Payroll Overview', route: 'payroll.dashboard', icon: LayoutDashboard },
      { key: 'payroll-sheets', label: 'Salary Sheets', route: 'payroll.sheets.index', icon: ScrollText },
      { key: 'payroll-increments', label: 'Salary Increments', route: 'payroll.increments.index', icon: BadgeCent },
      { key: 'payroll-loans', label: 'Employee Loans', route: 'payroll.loans.index', icon: Wallet },
      { key: 'payroll-adjustments', label: 'Salary Adjustments', route: 'payroll.adjustments.index', icon: ClipboardList },
      { key: 'payroll-tax', label: 'Tax Profiles', route: 'payroll.tax.index', icon: ScrollText },
      { key: 'payroll-pf', label: 'Provident Fund', route: 'payroll.pf.index', icon: PiggyBank },
      { key: 'payroll-bonuses', label: 'Festival Bonuses', route: 'payroll.bonuses.index', icon: BadgeCent },
      { key: 'payroll-settlements', label: 'Settlements', route: 'payroll.settlements.index', icon: UserCheck },
      { key: 'payroll-settings', label: 'Payroll Settings', route: 'payroll.settings.index', icon: Settings2 },
    ],
  },
  {
    key: 'roster', label: 'Roster', icon: CalendarRange,
    children: [
      { key: 'roster-shifts', label: 'Shifts', route: 'hrm.shifts.index', icon: Clock3 },
      { key: 'roster-duties', label: 'Roster Duties', route: 'hrm.duties.index', icon: CalendarRange },
      { key: 'roster-swaps', label: 'Roster Swaps', route: 'hrm.swaps.index', icon: ArrowLeftRight },
    ],
  },
  {
    key: 'leave-management', label: 'Leave Management', icon: ClipboardList,
    children: [
      { key: 'hrm-holidays', label: 'Holiday Calendar', route: 'hrm.holidays.index', icon: CalendarDays, permission: 'hrm.holidays.view' },
      { key: 'leave-types', label: 'Leave Types', route: 'hrm.leave-types.index', icon: Tag },
      { key: 'leave-requests', label: 'Leave Requests', route: 'hrm.leaves.index', icon: Plane },
      { key: 'leave-balances', label: 'Leave Balances', route: 'hrm.leaves.balances', icon: CalendarDays, permission: 'hrm.leaves.balance.view' },
      { key: 'informed', label: 'Informed Panel', route: 'hrm.informed.index', icon: Bell },
    ],
  },
  {
    key: 'overtime', label: 'Overtime & Extra Duty', icon: Timer,
    children: [
      { key: 'overtime-approval', label: 'Overtime Approval', route: 'hrm.overtimes.index', icon: Timer },
      { key: 'extraduty-approval', label: 'Extra Duty Approval', route: 'hrm.extra-duty.index', icon: UserCheck },
    ],
  },
  {
    key: 'attendance', label: 'Attendance', icon: CalendarCheck,
    children: [
      { key: 'att-logs', label: 'Attendance Logs', route: 'hrm.attendance.logs.index', icon: FileClock },
      { key: 'att-pairs', label: 'Attendance Pairs', route: 'hrm.attendance.pairs.index', icon: Layers },
      { key: 'att-dailies', label: 'Daily Attendance', route: 'hrm.attendance.dailies.index', icon: CalendarDays },
      { key: 'att-sync', label: 'Sync Panel', route: 'hrm.attendance.sync.index', icon: RefreshCw },
      { key: 'att-fix', label: 'Fix Pairing', route: 'hrm.attendance.fix.index', icon: RefreshCw },
    ],
  },
  {
    key: 'access-control', label: 'Access Control', icon: ShieldCheck,
    children: [
      { key: 'users', label: 'Users', route: 'core.users.index', icon: Users, permission: 'users.view' },
      { key: 'roles', label: 'Roles', route: 'core.roles.index', icon: Shield, permission: 'roles.view' },
      { key: 'permissions', label: 'Permissions', route: 'core.permissions.index', icon: KeyRound, permission: 'permissions.view' },
    ],
  },
  { key: 'design-system', label: 'Design System', route: 'core.ui-kit', icon: Palette, permission: 'dashboard.view' },
  {
    key: 'compliance', label: 'Audit & Compliance', icon: ScrollText,
    children: [{ key: 'activities', label: 'Audit Trail', route: 'core.activities.index', icon: History, permission: 'audit.view' }],
  },
];
