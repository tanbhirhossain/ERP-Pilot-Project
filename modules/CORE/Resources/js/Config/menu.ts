/* Declarative collapsible-tree sidebar menu for the CORE admin shell. */
import type { LucideIcon } from 'lucide-vue-next';
import {
  SwatchBook, LayoutDashboard, ShieldCheck, Users, Shield, KeyRound, Palette, ScrollText, History, Calculator, Wallet, PiggyBank, Settings2,
  Building2, Network, BadgeCent, Fingerprint, Link2, UserCog, Clock3, CalendarRange, CalendarCheck, FileClock, Layers, CalendarDays, RefreshCw, UsersRound, ArrowLeftRight, Timer, Tag, Plane, Bell, ClipboardList, UserCheck, RotateCcw,
  Handshake, Truck, Package, Warehouse, Boxes, Receipt, BookOpen, ShoppingCart, FileText, ClipboardCheck,  Monitor, CreditCard, Landmark, BarChart3, Scale, TrendingUp, FileBarChart, HandCoins, BookMarked, ListTree, Undo2, FolderOpen, Archive
} from 'lucide-vue-next';

export interface SidebarItem {
  key: string;
  label: string;
  route?: string;
  routeParams?: Record<string, any>;
  href?: string;
  icon: LucideIcon;
  permission?: string;
  /**
   * Which band of the sidebar this section belongs to. Sections render in declaration order and a
   * header is drawn whenever the band changes, so sections that share a band must be adjacent: the
   * tree follows the workflow — people, then stock and trade, then the books, then administration —
   * rather than the order the modules happened to be built in.
   */
  group?: string;
  children?: SidebarItem[];
}

export const sidebarMenu: SidebarItem[] = [
  { key: 'dashboard', label: 'Dashboard', route: 'core.dashboard', icon: LayoutDashboard, permission: 'dashboard.view' },
  // ── People: everyone the business employs, and everything that happens to them
  {
    key: 'hrm', label: 'HRM', group: 'People', icon: UserCog,
    children: [
      { key: 'hrm-organization', label: 'Organization', route: 'hrm.organizations.index', icon: Building2 },
      { key: 'hrm-departments', label: 'Departments', route: 'hrm.departments.index', icon: Network },
      { key: 'hrm-designations', label: 'Designations', route: 'hrm.designations.index', icon: BadgeCent },
      { key: 'hrm-employees', label: 'Employees', route: 'hrm.employees.index', icon: Users },
      { key: 'hrm-resignations', label: 'Resignations', route: 'hrm.resignations.index', icon: UserCheck },
      { key: 'hrm-supervisors', label: 'Supervisors', route: 'hrm.supervisors.index', icon: UsersRound },
      { key: 'hrm-documents', label: 'Documents Vault', route: 'hrm.documents.index', icon: FolderOpen, permission: 'hrm.documents.view' },
      { key: 'hrm-assets', label: 'Asset Inventory', route: 'hrm.assets.index', icon: Archive, permission: 'hrm.assets.view' },
      { key: 'hrm-probations', label: 'Probation & Confirm', route: 'hrm.probations.index', icon: BadgeCent, permission: 'hrm.probations.view' },
      { key: 'hrm-movements', label: 'Transfers / Promotions', route: 'hrm.movements.index', icon: ArrowLeftRight, permission: 'hrm.movements.view' },
      { key: 'hrm-checklists', label: 'On/Offboarding', route: 'hrm.checklists.index', icon: ClipboardCheck, permission: 'hrm.checklists.view' },
      { key: 'hrm-clearance', label: 'Exit Clearance', route: 'hrm.clearance.index', icon: ShieldCheck, permission: 'hrm.clearance.view' },
      { key: 'hrm-device-users', label: 'Device Users', route: 'hrm.device-users.index', icon: Link2 },
      { key: 'hrm-devices', label: 'Biometric Devices', route: 'hrm.devices.index', icon: Fingerprint },
    ] },
  {
    key: 'payroll', label: 'Payroll', group: 'People', icon: Calculator,
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
    ] },
  {
    key: 'roster', label: 'Roster', group: 'People', icon: CalendarRange,
    children: [
      { key: 'roster-shifts', label: 'Shifts', route: 'hrm.shifts.index', icon: Clock3 },
      { key: 'roster-duties', label: 'Roster Duties', route: 'hrm.duties.index', icon: CalendarRange },
      { key: 'roster-swaps', label: 'Roster Swaps', route: 'hrm.swaps.index', icon: ArrowLeftRight },
    ] },
  {
    key: 'leave-management', label: 'Leave', group: 'People', icon: ClipboardList,
    children: [
      { key: 'hrm-holidays', label: 'Holiday Calendar', route: 'hrm.holidays.index', icon: CalendarDays, permission: 'hrm.holidays.view' },
      { key: 'leave-types', label: 'Leave Types', route: 'hrm.leave-types.index', icon: Tag },
      { key: 'leave-requests', label: 'Leave Requests', route: 'hrm.leaves.index', icon: Plane },
      { key: 'leave-balances', label: 'Leave Balances', route: 'hrm.leaves.balances', icon: CalendarDays, permission: 'hrm.leaves.balance.view' },
      { key: 'leave-year-end', label: 'Year-End (Carry/Encash)', route: 'hrm.leave-year-end.index', icon: CalendarRange, permission: 'hrm.leave-year-end.view' },
      { key: 'hrm-reports', label: 'HR Reports', route: 'hrm.reports.index', icon: BarChart3, permission: 'hrm.reports.view' },
      { key: 'informed', label: 'Informed Panel', route: 'hrm.informed.index', icon: Bell },
    ] },
  {
    key: 'overtime', label: 'OT & ED', group: 'People', icon: Timer,
    children: [
      { key: 'overtime-approval', label: 'Overtime Approval', route: 'hrm.overtimes.index', icon: Timer },
      { key: 'extraduty-approval', label: 'Extra Duty Approval', route: 'hrm.extra-duty.index', icon: UserCheck },
    ] },
  {
    key: 'attendance', label: 'Attendance', group: 'People', icon: CalendarCheck,
    children: [
      { key: 'att-logs', label: 'Attendance Logs', route: 'hrm.attendance.logs.index', icon: FileClock },
      { key: 'att-pairs', label: 'Attendance Pairs', route: 'hrm.attendance.pairs.index', icon: Layers },
      { key: 'att-dailies', label: 'Daily Attendance', route: 'hrm.attendance.dailies.index', icon: CalendarDays },
      { key: 'att-regularizations', label: 'Regularizations', route: 'hrm.regularizations.index', icon: FileClock, permission: 'hrm.regularizations.view' },
      { key: 'att-shift-allow', label: 'Shift Allowances', route: 'hrm.shift-allowances.index', icon: BadgeCent, permission: 'hrm.shift-allowances.view' },
      { key: 'att-ot-policies', label: 'OT Policies', route: 'hrm.overtime-policies.index', icon: Timer, permission: 'hrm.ot-policies.view' },
      { key: 'att-sync', label: 'Sync Panel', route: 'hrm.attendance.sync.index', icon: RefreshCw },
      { key: 'att-fix', label: 'Fix Pairing', route: 'hrm.attendance.fix.index', icon: RefreshCw },
    ] },
  // ── Supply & Sales: partners, stock, buying and selling
  {
    key: 'partner', label: 'Ven & Cust', group: 'Supply & Sales', icon: Handshake,
    children: [
      { key: 'partner-vendors', label: 'Vendors', route: 'partner.vendors.index', icon: Truck, permission: 'partner.vendors.view' },
      { key: 'partner-customers', label: 'Customers', route: 'partner.customers.index', icon: Users, permission: 'partner.customers.view' },
      { key: 'partner-groups', label: 'Partner Groups', route: 'partner.groups.index', icon: Network, permission: 'partner.groups.manage' },
      { key: 'partner-pricelists', label: 'Price Lists', route: 'partner.pricelists.index', icon: Tag, permission: 'partner.pricelists.manage' },
    ] },
  {
    key: 'inventory', label: 'Inventory', group: 'Supply & Sales', icon: Package,
    children: [
      { key: 'inv-products', label: 'Products', route: 'inventory.products.index', icon: Package, permission: 'inventory.products.view' },
      { key: 'inv-barcodes', label: 'Barcode Labels', route: 'inventory.products.barcode-print', icon: Tag, permission: 'inventory.products.view' },
      { key: 'inv-categories', label: 'Categories', route: 'inventory.categories.index', icon: Layers, permission: 'inventory.categories.manage' },
      { key: 'inv-warehouses', label: 'Warehouses', route: 'inventory.warehouses.index', icon: Warehouse, permission: 'inventory.warehouses.manage' },
      { key: 'inv-stocks', label: 'Stock Overview', route: 'inventory.stocks.index', icon: Boxes, permission: 'inventory.stocks.view' },
      { key: 'inv-movements', label: 'Stock Ledger', route: 'inventory.movements.index', icon: History, permission: 'inventory.movements.view' },
      { key: 'inv-transfers', label: 'Transfers', route: 'inventory.transfers.index', icon: ArrowLeftRight, permission: 'inventory.transfers.manage' },
      { key: 'inv-adjustments', label: 'Adjustments', route: 'inventory.adjustments.index', icon: ClipboardList, permission: 'inventory.adjustments.manage' },
      { key: 'inv-kits', label: 'Kits / BOM Production', route: 'inventory.kits.index', icon: Package, permission: 'inventory.kits.view' },
      { key: 'inv-counts', label: 'Physical Counts', route: 'inventory.counts.index', icon: ClipboardCheck, permission: 'inventory.counts.view' },
      { key: 'inv-reports', label: 'Inventory Reports', icon: BarChart3, permission: 'inventory.stocks.view', children: [
        { key: 'inv-fifo', label: 'FIFO Layers', route: 'inventory.reports.fifo', icon: Layers },
        { key: 'inv-reorder', label: 'Reorder & ABC', route: 'inventory.reports.reorder', icon: TrendingUp },
        { key: 'inv-lots', label: 'Lots & Expiry', route: 'inventory.reports.lots', icon: CalendarDays },
      ]},
    ] },
  {
    key: 'purchase', label: 'Purchase', group: 'Supply & Sales', icon: ShoppingCart,
    children: [
      { key: 'pur-orders', label: 'Purchase Orders', route: 'purchase.orders.index', icon: FileText, permission: 'purchase.orders.view' },
      { key: 'pur-receipts', label: 'Goods Receipts (GRN)', route: 'purchase.receipts.index', icon: Truck, permission: 'purchase.receipts.view' },
      { key: 'pur-bills', label: 'Vendor Bills', route: 'purchase.bills.index', icon: Receipt, permission: 'purchase.bills.view' },
      { key: 'pur-returns', label: 'Purchase Returns', route: 'purchase.returns.index', icon: Undo2, permission: 'purchase.returns.view' },
      { key: 'pur-landed', label: 'Landed Costs', route: 'purchase.landed-costs.index', icon: Receipt, permission: 'purchase.landed.view' },
    ] },
  {
    key: 'sales', label: 'Sales & POS', group: 'Supply & Sales', icon: ShoppingCart,
    children: [
      { key: 'sales-quotes', label: 'Quotations', route: 'sales.quotations.index', icon: FileText, permission: 'sales.quotations.view' },
      { key: 'sales-orders', label: 'Sales Orders', route: 'sales.orders.index', icon: ClipboardCheck, permission: 'sales.orders.view' },
      { key: 'sales-deliveries', label: 'Deliveries (Challan)', route: 'sales.deliveries.index', icon: Truck, permission: 'sales.deliveries.view' },
      { key: 'sales-invoices', label: 'Invoices', route: 'sales.invoices.index', icon: Receipt, permission: 'sales.invoices.view' },
      { key: 'sales-returns', label: 'Sales Returns', route: 'sales.returns.index', icon: Undo2, permission: 'sales.returns.view' },
      { key: 'sales-pos', label: 'POS Terminal', route: 'sales.pos.terminal', icon: Monitor, permission: 'pos.terminal.sell' },
      // These two routes existed with no way to reach them from the shell, so drawers and sales
      // history were only discoverable by typing a URL.
      { key: 'sales-pos-sales', label: 'POS Sales', route: 'sales.pos.history', icon: Receipt, permission: 'pos.terminal.sell' },
      { key: 'sales-pos-drawers', label: 'POS Drawers', route: 'sales.pos.sessions', icon: Clock3, permission: 'pos.sessions.manage' },
      { key: 'sales-pos-dayend', label: 'POS Day-End & Collections', route: 'sales.pos.reports.dayEnd', icon: FileBarChart, permission: 'pos.reports.view' },
      // The register behind that report's collection figures: per collector, per customer, per
      // method, including money taken with no drawer open.
      { key: 'sales-pos-collections', label: 'POS Collections', route: 'sales.pos.collections', icon: HandCoins, permission: 'pos.reports.view' },
    ] },
  // ── Finance: the books
  {
    key: 'accounts', label: 'Accounts', group: 'Finance', icon: BookOpen,
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
    ] },
  // ── Administration: access, audit and the UI kit
  {
    key: 'access-control', label: 'Access Control', group: 'Administration', icon: ShieldCheck,
    children: [
      { key: 'users', label: 'Users', route: 'core.users.index', icon: Users, permission: 'users.view' },
      { key: 'roles', label: 'Roles', route: 'core.roles.index', icon: Shield, permission: 'roles.view' },
      { key: 'permissions', label: 'Permissions', route: 'core.permissions.index', icon: KeyRound, permission: 'permissions.view' },
    ] },
  {
    key: 'compliance', label: 'Audit & Compliance', group: 'Administration', icon: ScrollText,
    children: [{ key: 'activities', label: 'Audit Trail', route: 'core.activities.index', icon: History, permission: 'audit.view' }] },
  { key: 'design-system', label: 'Design System', group: 'Administration', route: 'core.ui-kit', icon: Palette, permission: 'dashboard.view' },
  // Item 42: colour scheme and mode. `dashboard.view` is the gate because choosing your own
  // interface is not a privilege — the company default inside it is gated separately, on
  // `settings.appearance.manage`.
  { key: 'ui-settings', label: 'UI Settings', group: 'Administration', route: 'core.settings.appearance', icon: SwatchBook, permission: 'dashboard.view' },

];
