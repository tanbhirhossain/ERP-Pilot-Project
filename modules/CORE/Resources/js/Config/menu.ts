/* Declarative collapsible-tree sidebar menu for the CORE admin shell. */
import type { LucideIcon } from 'lucide-vue-next';
import {
  LayoutDashboard, ShieldCheck, Users, Shield, KeyRound, Palette, ScrollText, History, Calculator, Wallet, PiggyBank, Settings2,
  Building2, Network, BadgeCent, Fingerprint, Link2, UserCog, Clock3, CalendarRange, CalendarCheck, FileClock, Layers, CalendarDays, RefreshCw, UsersRound, ArrowLeftRight, Timer, Tag, Plane, Bell, ClipboardList, UserCheck, RotateCcw,
  Handshake, Truck, Package, Warehouse, Boxes, Receipt, BookOpen, ShoppingCart, FileText, ClipboardCheck, Percent, Monitor, CreditCard, Landmark, BarChart3, Scale, TrendingUp, FileBarChart, BookMarked, Droplets, Calendar, ListTree, Undo2, FolderOpen, Archive
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
      { key: 'hrm-documents', label: 'Documents Vault 📁', route: 'hrm.documents.index', icon: FolderOpen, permission: 'hrm.documents.view' },
      { key: 'hrm-assets', label: 'Asset Inventory 🎒', route: 'hrm.assets.index', icon: Archive, permission: 'hrm.assets.view' },
      { key: 'hrm-probations', label: 'Probation & Confirm', route: 'hrm.probations.index', icon: BadgeCent, permission: 'hrm.probations.view' },
      { key: 'hrm-movements', label: 'Transfers / Promotions', route: 'hrm.movements.index', icon: ArrowLeftRight, permission: 'hrm.movements.view' },
      { key: 'hrm-checklists', label: 'On/Offboarding', route: 'hrm.checklists.index', icon: ClipboardCheck, permission: 'hrm.checklists.view' },
      { key: 'hrm-clearance', label: 'Exit Clearance', route: 'hrm.clearance.index', icon: ShieldCheck, permission: 'hrm.clearance.view' },
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
      { key: 'payroll-bank-advices', label: 'Bank Advices (BEFTN)', route: 'payroll.bank-advices.index', icon: CreditCard },
      { key: 'payroll-reopens', label: 'Sheet Reopens', route: 'payroll.reopens.index', icon: RotateCcw, permission: 'payroll.reopens.view' },
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
      { key: 'leave-year-end', label: 'Year-End (Carry/Encash)', route: 'hrm.leave-year-end.index', icon: CalendarRange, permission: 'hrm.leave-year-end.view' },
      { key: 'hrm-reports', label: 'HR Reports 📊', route: 'hrm.reports.index', icon: BarChart3, permission: 'hrm.reports.view' },
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
      { key: 'att-regularizations', label: 'Regularizations', route: 'hrm.regularizations.index', icon: FileClock, permission: 'hrm.regularizations.view' },
      { key: 'att-shift-allow', label: 'Shift Allowances', route: 'hrm.shift-allowances.index', icon: BadgeCent, permission: 'hrm.shift-allowances.view' },
      { key: 'att-ot-policies', label: 'OT Policies', route: 'hrm.overtime-policies.index', icon: Timer, permission: 'hrm.ot-policies.view' },
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
  // ── Phase 1: PARTNER (Vendor & Customer Management) ─────────────────────
  {
    key: 'partner', label: 'Vendors & Customers', icon: Handshake,
    children: [
      { key: 'partner-vendors', label: 'Vendors', route: 'partner.vendors.index', icon: Truck, permission: 'partner.vendors.view' },
      { key: 'partner-customers', label: 'Customers', route: 'partner.customers.index', icon: Users, permission: 'partner.customers.view' },
      { key: 'partner-groups', label: 'Partner Groups', route: 'partner.groups.index', icon: Network, permission: 'partner.groups.manage' },
      { key: 'partner-pricelists', label: 'Price Lists', route: 'partner.pricelists.index', icon: Tag, permission: 'partner.pricelists.manage' },
    ],
  },
  // ── Phase 2: INVENTORY — FIFO + Barcode + ABC
  {
    key: 'inventory', label: 'Inventory', icon: Package,
    children: [
      { key: 'inv-products', label: 'Products', route: 'inventory.products.index', icon: Package, permission: 'inventory.products.view' },
      { key: 'inv-barcodes', label: 'Barcode Labels', route: 'inventory.products.barcode-print', icon: Tag, permission: 'inventory.products.view' },
      { key: 'inv-categories', label: 'Categories', route: 'inventory.categories.index', icon: Layers, permission: 'inventory.categories.manage' },
      { key: 'inv-warehouses', label: 'Warehouses', route: 'inventory.warehouses.index', icon: Warehouse, permission: 'inventory.warehouses.manage' },
      { key: 'inv-stocks', label: 'Stock Overview', route: 'inventory.stocks.index', icon: Boxes, permission: 'inventory.stocks.view' },
      { key: 'inv-movements', label: 'Stock Ledger', route: 'inventory.movements.index', icon: History, permission: 'inventory.movements.view' },
      { key: 'inv-transfers', label: 'Transfers', route: 'inventory.transfers.index', icon: ArrowLeftRight, permission: 'inventory.transfers.manage' },
      { key: 'inv-adjustments', label: 'Adjustments', route: 'inventory.adjustments.index', icon: ClipboardList, permission: 'inventory.adjustments.manage' },
      { key: 'inv-kits', label: 'Kits / BOM Production 🧩', route: 'inventory.kits.index', icon: Package, permission: 'inventory.kits.view' },
      { key: 'inv-counts', label: 'Physical Counts', route: 'inventory.counts.index', icon: ClipboardCheck, permission: 'inventory.counts.view' },
      { key: 'inv-reports', label: 'Inventory Reports', icon: BarChart3, permission: 'inventory.stocks.view', children: [
        { key: 'inv-fifo', label: 'FIFO Layers', route: 'inventory.reports.fifo', icon: Layers },
        { key: 'inv-reorder', label: 'Reorder & ABC', route: 'inventory.reports.reorder', icon: TrendingUp },
        { key: 'inv-lots', label: 'Lots & Expiry', route: 'inventory.reports.lots', icon: CalendarDays },
      ]},
    ],
  },
  // ── Phase 3: ACCOUNTS ────────────────────────────────────────────────────
  {
    key: 'accounts', label: 'Accounts', icon: BookOpen,
    children: [
      { key: 'acc-coa', label: 'Chart of Accounts', route: 'accounts.coa.index', icon: ListTree, permission: 'accounts.chart.view' },
      { key: 'acc-vouchers', label: 'Vouchers', route: 'accounts.vouchers.index', icon: Receipt, permission: 'accounts.vouchers.view' },
      { key: 'acc-payables', label: 'Payables (AP)', route: 'accounts.payables.index', icon: CreditCard, permission: 'accounts.payables.view' },
      { key: 'acc-receivables', label: 'Receivables (AR)', route: 'accounts.receivables.index', icon: Wallet, permission: 'accounts.receivables.view' },
      { key: 'acc-banks', label: 'Banks', route: 'accounts.banks.index', icon: Landmark, permission: 'accounts.banks.manage' },
      { key: 'acc-reports', label: 'Financial Reports', icon: BarChart3, permission: 'accounts.reports.view', children: [
        { key: 'acc-tb', label: 'Trial Balance', route: 'accounts.reports.trial', icon: Scale },
        { key: 'acc-pl', label: 'Profit & Loss', route: 'accounts.reports.pl', icon: TrendingUp },
        { key: 'acc-bs', label: 'Balance Sheet', route: 'accounts.reports.bs', icon: FileBarChart },
        { key: 'acc-ledger', label: 'General Ledger', route: 'accounts.reports.ledger', icon: BookMarked },
        { key: 'acc-aging-ap', label: 'AP Aging', route: 'accounts.reports.payables-aging', icon: FileClock },
        { key: 'acc-aging-ar', label: 'AR Aging', route: 'accounts.reports.receivables-aging', icon: FileClock },
        { key: 'acc-reconcile', label: 'Bank Reconcile', route: 'accounts.banks.reconcile', icon: Landmark },
      ]},
    ],
  },
  // ── Phase 4: PURCHASE ────────────────────────────────────────────────────
  {
    key: 'purchase', label: 'Purchase', icon: ShoppingCart,
    children: [
      { key: 'pur-orders', label: 'Purchase Orders', route: 'purchase.orders.index', icon: FileText, permission: 'purchase.orders.view' },
      { key: 'pur-receipts', label: 'Goods Receipts (GRN)', route: 'purchase.receipts.index', icon: Truck, permission: 'purchase.receipts.view' },
      { key: 'pur-bills', label: 'Vendor Bills', route: 'purchase.bills.index', icon: Receipt, permission: 'purchase.bills.view' },
      { key: 'pur-returns', label: 'Purchase Returns', route: 'purchase.returns.index', icon: Undo2, permission: 'purchase.returns.view' },
      { key: 'pur-landed', label: 'Landed Costs 🌊', route: 'purchase.landed-costs.index', icon: Receipt, permission: 'purchase.landed.view' },
    ],
  },
  // ── Phase 5: SALES & POS — Delivery + Returns
  {
    key: 'sales', label: 'Sales & POS', icon: ShoppingCart,
    children: [
      { key: 'sales-quotes', label: 'Quotations', route: 'sales.quotations.index', icon: FileText, permission: 'sales.quotations.view' },
      { key: 'sales-orders', label: 'Sales Orders', route: 'sales.orders.index', icon: ClipboardCheck, permission: 'sales.orders.view' },
      { key: 'sales-deliveries', label: 'Deliveries (Challan)', route: 'sales.deliveries.index', icon: Truck, permission: 'sales.deliveries.view' },
      { key: 'sales-invoices', label: 'Invoices', route: 'sales.invoices.index', icon: Receipt, permission: 'sales.invoices.view' },
      { key: 'sales-returns', label: 'Sales Returns', route: 'sales.returns.index', icon: Undo2, permission: 'sales.returns.view' },
      { key: 'sales-pos', label: 'POS Terminal', route: 'sales.pos.terminal', icon: Monitor, permission: 'pos.terminal.sell' },
    ],
  },
  { key: 'design-system', label: 'Design System', route: 'core.ui-kit', icon: Palette, permission: 'dashboard.view' },
  {
    key: 'compliance', label: 'Audit & Compliance', icon: ScrollText,
    children: [{ key: 'activities', label: 'Audit Trail', route: 'core.activities.index', icon: History, permission: 'audit.view' }],
  },
];
