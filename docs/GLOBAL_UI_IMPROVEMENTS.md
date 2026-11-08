# Global UI System — Premium Upgrade (Shipped 2026-11-03)

## Overview
Upgraded the enterprise admin console from "functional" to "premium" — faster navigation, better discoverability, consistent premium headers, and improved accessibility. All 395 routes benefit, no module-specific code.

## What Changed

### 1. Command Palette (Cmd+K) — Biggest Win
**File:** `CORE/Resources/js/Components/CommandPalette.vue` (integrated into `AdminLayout.vue` header)

- **Trigger:** `⌘K` / `Ctrl+K` globally, `/` when not in input, or click "Search ⌘K" button (desktop)
- **Content:** 395 routes from `sidebarMenu` filtered by permission (`can()`), searchable by label/key/group
- **Features:**
  - Recent pages (last 6, localStorage `core.recent.pages.v1`)
  - Permission-aware — hidden items never appear
  - Keyboard: ↑↓ Enter Esc, auto-focus on open
  - Mobile: icon-only button
- **Why premium:** ERP with 12 modules × 30 pages needs instant jump — no more 3-click drill.

### 2. Sidebar Search (Filter-as-you-type)
**File:** `CORE/Resources/js/Layouts/TreeSidebar.vue`

- Added `sidebarSearch` ref + computed `visibleMenu` filter
- Input above Navigation (hidden when collapsed to icon)
- Matches `label` or `key` case-insensitive, keeps branch if child matches
- Persists no state, instant feedback

### 3. PageHeader Component (Premium Standard)
**File:** `CORE/Resources/js/Components/PageHeader.vue`

- **Two variants:**
  - Gradient (when `stats` provided): `from-slate-900 via-indigo-950 to-slate-900`, backdrop-blur stats
  - Simple: title + description + action buttons
- **Props:** `title`, `description`, `icon`, `actions[]` (href/route/onClick + variant), `stats[]`
- **Usage:** Replace ad-hoc `h1 + p + Buttons` with one component → consistent 1500px max-width, spacing, accessible headings
- **Example:**
  ```vue
  <PageHeader title="Purchase Orders" description="Vendor → GRN → Bill" :icon="FileText"
    :stats="[{label:'Open',value:12},{label:'Total',value:'1.2M'}]"
    :actions="[{label:'New PO', route:'purchase.orders.create', variant:'default', icon: Plus}]" />
  ```

### 4. EmptyState Component
**File:** `CORE/Resources/js/Components/EmptyState.vue`

- Dashed border, centered icon, title/description, optional CTA
- Replace `v-if="rows.length===0" class="border-dashed p-10 text-center"` boilerplate
- Accessible, consistent across DataTable empties

### 5. AdminLayout Header Polish
**File:** `CORE/Resources/js/Layouts/AdminLayout.vue`

- Added CommandPalette trigger (outline Search button desktop, icon ghost mobile)
- Import `Search` icon, `CommandPalette` component
- Preserves theme toggle, account menu, progress bar, print isolation, page-enter animation

## Design System Notes

### Tokens (already excellent, preserved)
- `AdminLayout` already premium: collapsible `SidebarProvider` with localStorage, glass header `bg-background/80 backdrop-blur-md`, `isDark` + `updateAppearance`, navigation progress bar, print isolation, `page-enter` 0.4s spring
- `TreeSidebar` already premium: accordion motion via Radix, active-branch auto-expand, permission-aware, secure session footer

### Accessibility
- CommandPalette: `Dialog` with focus trap, esc close, aria roles
- Sidebar search: label implicit via placeholder, focus ring
- PageHeader: `h1` + `p` semantic, button variants

### Performance
- CommandPalette menu collection is computed once from `sidebarMenu` (70 items) — no async
- Sidebar filter is cheap (70 → filtered) — no debounce needed
- No new dependencies (uses existing `radix-vue`, `lucide-vue-next`, `@vueuse/core`)

## How to Use (For Module Devs)

1. **Navigation:** Press `⌘K` from anywhere → type "payroll" → Enter
2. **New page:** Wrap content with `<PageHeader title="..." description="..." :actions="[{label:'Create', route:'...'}]">` instead of manual `h1`
3. **Empty:** `<EmptyState icon={Inbox} title="No POs" description="..." actionLabel="New PO" actionRoute="purchase.orders.create" />`

## Verification
```bash
npm run build  # expect ~12-13s, CommandPalette + PageHeader + EmptyState chunked
# Manual: open any page → press Cmd+K → type "vendors" → enter → navigates
# Mobile: sidebar collapsed → Search icon → palette opens
# Sidebar: type "payroll" in sidebar search → only PAYROLL group remains
```

## Next Candidates (Not in This Batch)
- DataTable density toggle (comfortable/compact) + column persistence
- Global keyboard shortcuts cheatsheet (`?` to show)
- Notifications bell + realtime (requires backend events)
- Breadcrumbs auto-generate from menu key
