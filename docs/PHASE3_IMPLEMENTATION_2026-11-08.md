# Phase 3 — Bangladesh Pack · Implementation Report

**Date:** 2026-11-08 · **Base:** `f8546a3` + uncommitted Phase 1 + Phase 2 · **Status:** F17 (TDS/VDS + Mushak 9.1), U19 (Bengali UI / RTL locale kernel + key screens), F15 (Fixed assets & depreciation), F14 (Budgets & variance), **F13 (REST API + Sanctum tokens + generated OpenAPI)** and **F22 + U21 (offline POS + installable till)** all shipped and fully verified — see §5–§9.

Phase 3 opens the ERP to the Bangladesh market. This report covers the **F17 tax-compliance slice** — the NBR month-end pack — which the operator requested first. Everything below is exercised by automated tests run against a real PHP 8.4 runtime (see §4); nothing is written blind.

---

## 1. Scoreboard

| Item | Scope | Status |
|---|---|---|
| **F17.a** | Mushak 9.1 VAT return engine | ✅ `VatReturnService` — output (invoices + POS − credit notes) vs input (bills − debit notes), rate-bucketed, cents-exact; net-payable XOR credit-carried-forward; VDS credit only when certified |
| **F17.b** | TDS/VDS withholding engine | ✅ `WithholdingService` — rule-driven inward deduction with threshold gate + idempotency; outward VDS capture + Mushak 6.6 certification; all-or-nothing deposit challan (single tax type) |
| **F17.c** | Schema | ✅ `tax_withholding_rules` / `tax_deposits` / `tax_withholdings` (migration runs clean; verified on SQLite + in-memory) |
| **F17.d** | Operator UI | ✅ **Reports → VAT Return** (Month picker, working-paper table, sales/purchase registers, print + PDF) and **Tax → Withholding** desk (Inward / Outward / Deposits / Rules tabs) — both on the CORE menu |
| **F17.e** | Mushak 9.1 PDF | ✅ `accounts::mushak91` Blade — bilingual bn/en headings, entity/BIN/period header, Part 1–12 working paper, filing caveat, signature blocks (DejaVu, A4) |
| **F17.f** | Permissions | ✅ `accounts.withholding.view` / `accounts.withholding.manage` seeded; both new pages + the VAT return gated |
| **U19.a** | Locale kernel (server) | ✅ `Locale` support class (catalogue, RTL dir, company default via `core_settings`) + `SetLocale` middleware + `core.locale.update` / `core.locale.company` routes; shared as an Inertia prop and rendered into `<html lang dir>` on first paint |
| **U19.b** | Locale kernel (client) | ✅ pure `i18n` module (`translate`/`directionFor`, English fallback) + `useLocale()` + header `LanguageSwitcher` (EN / বাংলা); `dir` flips with the language |
| **U19.c** | Bangla coverage | ✅ app chrome (all top-level menu labels, via a one-line `t(item.label)` in the sidebar) + the F17 VAT Return & Withholding screens; rest of the app is a drop-in `t('key')` away |
| **U19.d** | Tests | ✅ 7 Pest (session > company > default precedence, refusal, server-rendered `dir`, `setCompany` guard) + 11 Vitest (fallbacks, nav-by-English-label trick, interpolation, direction) |

---

## 2. What was built

### The Mushak 9.1 return (`VatReturnService`)
Computes a month's return straight from the posted ledger, in **integer paise** so the arithmetic is exact and re-runs identically:

- **Output VAT** = every **posted** sales invoice (VAT bucketed by line `vat_rate`: ≥15% standard, 0<r<15% reduced, 0% zero-rated) **+** every **completed** POS sale (standard bucket — POS does not store a per-line rate) **−** approved sales-return credit notes (decreasing adjustment).
- **Input VAT** = every **posted** vendor bill **−** posted purchase-return debit notes.
- **VDS credit** = outward VDS you have suffered on your own sales, **but only rows that are `certified`** (i.e. you hold the customer's Mushak 6.6 certificate). An uncertified deduction is deliberately *not* claimable — this was caught by a test and fixed.
- **Net** = `max(0, output − input − vds)` as **payable**, else the remainder as **credit carried forward**. Exactly one is positive; a credit never shows as a negative payable (cash refunds need a separate NBR application, so credit simply rolls).
- Returns the sales register and purchase register alongside the working paper so the operator can file from one screen.

### The withholding engine (`WithholdingService`)
- `applyToBill(bill, rule)` — inward TDS/VDS. Refuses non-posted bills (422). Base = bill subtotal; **below the rule threshold → no deduction (null)**; **idempotent per (bill, rule)** — re-applying returns the existing row, never a duplicate.
- `recordOutward(invoice, rate, base)` — VDS a customer has withheld from us.
- `certify(row, certificate_no)` — attaches the Mushak 6.6 certificate and flips `pending → certified`, which is what makes it claimable on the return. **Outward-only** (422 if attempted on an inward row).
- `deposit(ids, …)` — one bank challan per tax type. **TDS and VDS cannot share a challan (422).** Runs in a transaction: writes a `tax_deposits` row (auto `TDEP-<year>-####`), links and flips every selected row to `deposited` together, or nothing moves.

### Where it lands
| Layer | Path |
|---|---|
| Migration | `modules/ACCOUNTS/Database/Migrations/2026_11_08_000010_create_tax_withholdings.php` |
| Models | `modules/ACCOUNTS/Models/{WithholdingRule,TaxDeposit,Withholding}.php` (decimal casts, `active/inward/outward/pending` scopes) |
| Services | `modules/ACCOUNTS/Services/{VatReturnService,WithholdingService}.php` |
| Controllers | `modules/ACCOUNTS/Http/Controllers/{VatReturnController,WithholdingController}.php` |
| Routes | `modules/ACCOUNTS/Routes/web.php` (9 F17 routes) |
| Vue pages | `modules/ACCOUNTS/Resources/js/Pages/{Reports/VatReturn,Tax/Withholding}.vue` |
| PDF | `modules/ACCOUNTS/Resources/views/mushak91.blade.php` |
| Menu | `modules/CORE/Resources/js/Config/menu.ts` (`acc-vat-return`, `acc-withholding`) |

**Routes:** `accounts.reports.vat-return(.pdf)`, `accounts.tax.withholding.{index,rules.store,rules.toggle,apply,outward,certify,deposit}` — the read paths on `accounts.withholding.view` / `accounts.reports.view`, the mutations on `accounts.withholding.manage`.

### U19 — the Bengali / RTL locale kernel
Built as a deliberate mirror of the existing `UiTheme` engine, so the shell treats "which language" exactly like "which palette": **one resolver** that `SetLocale` middleware and `app.blade.php` both consult, so the `<html dir>` baked into the first paint and the `dir` shared with Inertia can never disagree.

- **Precedence** (highest first): the person's session choice → the company default (`core_settings.ui.locale`) → `en`. A preference is not a privilege, so `core.locale.update` needs only `auth`; the company default rides the existing `settings.appearance.manage` permission (locale is shell chrome, not a fresh privilege to grant everywhere). The session store was chosen over a `users` column on purpose — it survives every navigation and reload with **zero migration**, so it touches none of the money-column / model-scan guards.
- **Direction is derived, never set.** `dir` comes from the code (`directionFor`), so a screen cannot be left showing Bangla in an LTR layout or Latin in RTL — the two flip together. Numbers/dates are untouched (`Intl` remains each screen's concern).
- **Incremental by construction.** `translate(key)` falls back `bn → en → key`. The sidebar localises with a single `t(item.label)` and no per-item keys: it passes the item's **English label** in, the dictionary is keyed by that label, and an item nobody has translated yet still shows correct English rather than a blank. So the whole nav spine reads in বাংলা now, and every one of the other ~400 pages needs only one `t()` call to join — with no risk to the ones not yet converted.
- **Optimistic flip.** `setLocale()` mirrors `document.documentElement.dir` on the click, then the PUT persists it; the next full load re-renders `<html dir>` server-side from the same session value. No `localStorage` on the client, because (unlike the palette) the server always knows the session value — there is no second place that could disagree.

**New files:** `CORE/Support/Locale.php` · `CORE/Http/Middleware/SetLocale.php` · `CORE/Http/Controllers/LocaleController.php` · `CORE/Resources/js/i18n/index.ts` · `CORE/Resources/js/Composables/useLocale.ts` · `CORE/Resources/js/Components/LanguageSwitcher.vue`. **Edited:** `bootstrap/app.php` (middleware), `HandleInertiaRequests` (share), `app.blade.php` (`dir`), `AdminLayout.vue` (switcher), `TreeSidebar.vue` (`t(item.label)`), both F17 pages (localised headings/tabs/buttons).

---

## 3. How to use it
1. **Tax → Withholding → Rules:** add a rule (TDS/VDS, rate %, per-bill threshold).
2. **Inward tab:** pick posted vendor bills → *Apply* to book the deduction; select booked rows → *Deposit* to file one challan (pick the bank).
3. **Outward tab:** record the VDS a customer withheld on your invoice; when their Mushak 6.6 certificate arrives, *Certify* it.
4. **Reports → VAT Return:** choose the month → review the working paper → **Download PDF** or print. Certified VDS is already netted; the register tabs back up the totals.

---

## 4. Verification (all green)
- **F17 feature suite** `tests/Feature/ACCOUNTS/Phase3TaxComplianceTest.php` — **15 passed / 34 assertions** (return math incl. bucketing, draft/void exclusion, credit-note & debit-note adjustments, payable vs carry-forward, VDS-only-when-certified; withholding threshold, idempotency, posted-only refusal, deposit single-type + status flips; permission gates on both pages; PDF content-type).
- **Guard contracts re-measured and green** — the `.vue` census pin **411 → 413** (F17 pages) **→ 414** (U19 `LanguageSwitcher`), print-engine callers **17 → 18**, print regions **6 → 7** (VatReturn), model scan **162 → 165**, money-column census **245 → 250** (5 new `decimal` columns; `threshold` stays out by design), permission vocabulary **183 → 185** (F17 `withholding.view`/`.manage`; U19 adds **no** new permission — the company default reuses `settings.appearance.manage`). **PrintContract + MoneyColumnCast + OptionEndpointAuthorisation + RouteIntegrity: 33 passed / 4,658 assertions.**
- **U19 locale suite** — `tests/Feature/CORE/LocaleTest.php` **7 passed** (precedence, refusal of unknown codes, the shared `locale.dir` prop, and a real Inertia response asserting the server renders `dir="rtl"` / `lang="bn"` for a Bangla session) and `i18n.test.ts` **11 Vitest passed** (the fallback contracts the whole incremental scheme rests on).
- **Accounts regression** — `tests/Feature/ACCOUNTS/` + `DemoDataIntegrity` + `MigrationDataSeparation`: **87 passed / 410 assertions**, zero regressions; RouteIntegrity's "every permission enforced by a route is seeded" runs the seeder and confirms both new permissions.
- **Frontend** — `npx eslint … --max-warnings=0` clean (caught + fixed a `vue/no-dupe-keys`); **Vitest 262 passed** (11 files, incl. component-resolution over the new pages); **`npm run build` ✓**.
- **Style** — **Pint PASS** on all 14 changed PHP files.

---

## 5. Caveats & next slice (honest notes)
- **Filing is still manual.** This produces and prints the return; it does **not** submit to `vat.gov.bd`. No NBR portal API is integrated.
- **POS input-side rate:** POS sales are booked at the standard bucket (their `vat_amount` is stored without a per-line rate). Documented in `VatReturnService`.
- **VDS claim = certified only.** By design; import a certificate to make a row claimable.
- **Permissions:** the two `withholding` permissions are seeded for **Super Admin** only so far (not yet granted to the Auditor read-only set) — widen per tenant as needed.
- **U19 is a kernel + key screens, not a full retrofit.** The language machinery (session/company/default precedence, RTL, the switcher, the `t()` fallback) is built and tested end-to-end, and the app chrome + the F17 tax screens are in বাংলা. The remaining ~400 pages are **not yet wrapped in `t()`** — each needs one call to join and none can regress before they do. Chosen over a risky find/replace; confirm before a whole-app pass. (The operator was offered the scope choice and deferred it, so the low-risk default was taken.)
- **No `users.locale` column.** A personal choice lives in the session, so it does not follow a person to a *different browser* the way the colour palette does (which persists per-account). Deliberate, to avoid a migration touching the model/money guards; adding an account column later is a one-liner on the resolver.
- **Server-side strings are not yet localised via `__()`.** `lang/bn.php` JSON is not shipped; the PDF templates stay bilingual by hand (Mushak 9.1 prints bn/en headings either way). Only the Vue layer reads the dictionary today.

---

## 6. F15 — Fixed Assets & Depreciation (continuing Phase 3 beyond the BD pack)

**Status:** shipped and verified. This is a general enterprise module (not Bangladesh-specific) taken next because it is self-contained, has genuinely verifiable math, and lands new visible screens — unlike multi-currency/multi-branch, which would have far larger blast radius.

### Scoreboard
| Item | Scope | Status |
|---|---|---|
| **F15.a** | Depreciation engine | ✅ `DepreciationService::computeCharge` — straight-line **and** declining-balance, **pro-rated by days in service**, **capped at salvage** (NBV never dips below salvage); integer-paise, deterministic |
| **F15.b** | The run | ✅ one `DepreciationRun` per period (YYYY-MM); writes per-asset lines + posts **one balanced journal** (Dr depreciation expense, Cr accumulated depreciation, grouped by category accounts) through the existing `JournalService`; **one run per period** (idempotent), **all-or-nothing** rollback on a misconfigured category |
| **F15.c** | Lifecycle | ✅ `capitalize` (freeze category defaults onto the row, open to depreciation) + `dispose` (leaves the register; write-off journal left to a normal JV) |
| **F15.d** | Schema | ✅ `acc_asset_categories`, `acc_fixed_assets`, `acc_depreciation_runs`, `acc_asset_depreciations` (migration runs clean) |
| **F15.e** | Operator UI | ✅ **Accounts → Fixed Assets** (register: status filters, capture-as-draft, capitalise, dispose, live cost/accumulated/NBV stats) and **Accounts → Depreciation Run** (see-what-will-post preview + history linking to the journal) — both on the CORE menu, both localised (U19) |
| **F15.f** | Permissions | ✅ `accounts.assets.view` / `accounts.assets.manage` seeded; register gated on `.view`, all writes on `.manage`; **read view granted to Administrator/Manager/Auditor** (with the withholding view) |

### Why the accounts live on the *category*
A category carries the three posting accounts (gross asset, accumulated-depreciation contra, depreciation expense) and the default method/life/salvage/DB-factor. That is what lets a single month's run post a correct, balanced journal for a whole class at once — and why a half-configured category is caught loudly (the `JournalService` account validation runs inside the run's transaction) instead of posting a lopsided entry.

### The conventions the tests pin (`Phase3FixedAssetsTest`, 15 passed / 41 assertions)
- straight-line = `(cost − salvage) / life / 12`; declining-balance = `NBV × (factor / life) / 12`;
- acquisition and disposal months are charged `× in-service-days ÷ days-in-month`;
- charge capped at `remaining depreciable`, so NBV floors at salvage and the asset then reports `fully_depreciated`;
- a not-yet-in-service or fully-written-down asset charges nothing;
- re-running a period is refused; an empty period closes with a zero run (no journal);
- a group-header/inactive account rolls the whole run back to `DepreciationRun::count() === 0`.

### Verification added for F15
- `.vue` census pin **414 → 416** (the two asset pages); model scan **165 → 169** (4 asset models); money-column census **250 → 255** (5 more `decimal:2` columns; the rest — `accumulated_dep`, `closing_nbv`, `charge`, `disposal_proceeds`, category defaults — are decimals kept `decimal:2` too, just outside the name pattern); permission vocabulary **185 → 187**.
- Guards re-run green: PrintContract + MoneyColumnCast + OptionEndpointAuthorisation + RouteIntegrity + F15 + F17 + Locale = **70 passed / 4,817 assertions**. Broad regression (all `tests/Feature/ACCOUNTS`, Demo/Migration integrity, ShellBinding, Sales e2e) = **109 passed**. Frontend: **ESLint 0** (caught + fixed a `}}` typo in the dispose dialog), **Vitest 274**, **`npm run build` ✓**.

### Honest caveats
- **No auto write-off journal on disposal** by design — proceeds/gain/loss is a manual JV, so a category needs no disposal account. A one-click disposal journal is a clean follow-on if wanted.
- **Reversal is via the journal void** (the existing ledger tool), not a dedicated "un-run".
- **No per-asset branch/cost-centre** yet (F12 multi-branch, when it lands, can add a branch dimension to the register and the run's journal lines).

---

## 7. F14 — Budgets & Variance (plan vs. the books you already have)

**Status:** shipped and verified. Chosen after F15 because it needs no new posting machinery at all: the ledger, fiscal calendar and journal engine already exist, and a budget is only a plan laid over them.

### Scoreboard
| Item | Scope | Status |
|---|---|---|
| **F14.a** | Plan storage | ✅ `acc_budgets` (name+code unique **per fiscal year**, department, status `draft → approved → locked`, approver + timestamp) and `acc_budget_lines` (`account_id × YYYY-MM × amount`, `unique(budget_id, account_id, period)`) |
| **F14.b** | Actuals | ✅ **never stored.** Read live from `acc_account_ledger` for the budget's periods, grouped by account, signed into each account's natural direction (`debit` nature → `debit−credit`, else `credit−debit`) — the same convention `LedgerService::trialBalance()` uses |
| **F14.c** | Variance | ✅ `budget − actual`, utilisation %, and a **nature-dependent** favourable test: an expense is on-plan when actual ≤ budget, income is on-plan when actual ≥ budget — one rule, no per-row sign juggling |
| **F14.d** | Lifecycle | ✅ approve requires draft status + at least one line + an **open** fiscal year; lock freezes edits; editing a locked budget is refused |
| **F14.e** | Operator UI | ✅ **Accounts → Budgets** (create, pick a budget, add/remove account×month lines, approve, lock) and **Accounts → Budget Variance** (budget/month picker, per-account table, off-plan callout, printable) — both on the CORE menu, both localised (U19) |
| **F14.f** | Permissions | ✅ `accounts.budgets.view` / `accounts.budgets.manage`; pages on `.view`, every write on `.manage`; `.view` granted to Administrator/Manager/Auditor so the auditor can read plan-vs-books without touching it |

### Why actuals are not persisted
A budget snapshot of "actual" is a second book of the same money, and it goes stale the moment anyone posts a journal — then the variance report argues with the general ledger. Reading the ledger at request time makes drift impossible: whatever the report says, the trial balance says the same thing. It also means no backfill job, no rebuild button, and no "recalculate" step to explain.

The cost is a `groupBy` over the ledger per report, which at this scale is nothing; if a period is ever closed for posting (`FiscalPeriod`), its actuals stop moving anyway, so the only cache-worthy case is already frozen by the fiscal calendar.

### Line editing is idempotent by construction
`upsertLine` keys on `unique(budget_id, account_id, period)`: re-entering the same account and month **updates** that line rather than duplicating it, and entering `0` (or a negative) **deletes** it. So the picker on the page can be hammered without creating phantom plan lines, and "zero this line out" needs no separate delete action — while `removeLine` remains for the explicit case. Lines are refused on non-posting/group-header accounts and outside the budget's fiscal year, and rejected outright once the budget is `locked`.

### The conventions the tests pin (`Phase3BudgetsTest`, 13 passed / 42 assertions)
- re-adding an account+month updates one row (`BudgetLine::count() === 1`), not two;
- amount ≤ 0 deletes the line and returns `null`;
- a period outside the fiscal year is a 422; so is a group-header account;
- **actuals come from a posted journal**: budget 1000, post 600 to the expense → `variance 400`, `favourable`, `overrun_count 0`;
- overspending an expense (budget 500, actual 900) → `variance −400`, **not** favourable, and it appears in the `unfavorable` list, sorted worst-first;
- beating an income plan (budget 5000, actual 6000) is `variance −1000` yet **favourable** — the nature flip, verified end-to-end through a real journal;
- whole-plan mode sums every month with lines and reports the period list;
- approve with no lines → 422; approve sets `approved_at`; lock then refuses a new line;
- `accounts.budgets.view` gates the page (403 without), `.manage` gates creation (a viewer's POST is 403, a manager's creates the budget), and the variance page renders a selected budget.

### Verification added for F14
- `.vue` census pin **416 → 418** (the two budget pages); print-through callers **18 → 19** and print regions **7 → 8** (the variance page prints its report block and deliberately leaves the picker off the sheet); model scan **169 → 171**; money-column census **255 → 256** (`acc_budget_lines.amount` only — `acc_budgets` stores no money at all, which is the design point above); permission vocabulary **187 → 189**.
- Guards re-run green: PrintContract + MoneyColumnCast + OptionEndpointAuthorisation + RouteIntegrity + F14 + F15 + F17 + Locale = **83 passed / 4,883 assertions**. Broad regression (all `tests/Feature/ACCOUNTS`, Demo/Migration integrity, ShellBinding, Sales e2e) = **122 passed / 535 assertions** (+13 = exactly the new suite, nothing broken). Frontend: **ESLint 0**, **Vitest 274**, **`npm run build` ✓ 23.5s**.
- Infrastructure note: the verification mirror moved from `/tmp/erp-work` to `/var/tmp/erp-work`, because `/tmp` here is a 992 MB tmpfs and a 466 MB mirror on it left the Vite build OOM-killed at the chunk-render phase. `sync-to-mirror.sh` was updated; on-disk the build passes.

### Honest caveats
- **Budgets are account-scoped, not department×account.** `department` is a label on the budget header (one plan per department per year), not a ledger dimension — a true cost-centre on every journal line is F12's job and would widen this into every posting module.
- **No workflow/approval hierarchy** — approve is a permission, not `Phase1` approval-policies. Wiring budgets into the approval engine is a small, clean follow-on.
- **No rolling re-forecast** and no "budget for remaining months" suggestion; the plan is fixed once locked, by design.
- **Actuals include manual journals** on the account, which is correct for plan-vs-books but means a reclassification JV moves the variance without changing spend. Reports show `posted` ledger only (unposted drafts are excluded), so the number is auditable.

### Next slice
F13 (API + Sanctum + OpenAPI) is the next self-contained one: no schema change, and every module benefits. F22 (offline POS/PWA) and F18 (RFQ) follow; F12 multi-branch and F16 multi-currency stay deliberately last because they touch every posting path in the app.

---

## 8. F13 — REST API, Sanctum tokens, OpenAPI (the machine-readable ERP)

**Status:** shipped and verified. Chosen next because it changes no ledger semantics, yet every other module benefits: the app already had the services an integrator wants (invoices, trial balance, journals, budgets), so F13 is a *door*, not a second brain.

### Scoreboard
| Item | Scope | Status |
|---|---|---|
| **F13.a** | Auth | ✅ `laravel/sanctum ^4.3` (personal access tokens only, no SPA guard), migration `…000013_create_personal_access_tokens`, `ApiTokenService` issuing `id\|secret` once and storing only its SHA-256 hash |
| **F13.b** | Surface | ✅ **10 paths / 11 operations** under `/api/v1` — `auth/me`, `auth/token` (issue + revoke), `sales/invoices` (list + show), `accounts/trial-balance`, `accounts/journals` (list + show + create **draft**), `accounts/budgets` (list + variance), `openapi.json` |
| **F13.c** | Docs that cannot lie | ✅ the spec is **generated** from the same `#[ApiEndpoint]` attributes the handlers carry (`php artisan api:spec`), and `api:spec --check` fails the suite if `docs/openapi.json` is stale — no second copy of the truth to maintain |
| **F13.d** | Authorisation | ✅ the **same permissions as the screens** (`permission:…` copied into `x-required-permission`) plus token **ability scopes** (`accounts:read`, `sales:write`, presets `read`/`write`/`manage`) enforced by `CheckApiAbility` |
| **F13.e** | Failure semantics | ✅ `ForceApiJson` + `CORE\Exceptions\Handler` means a machine client is **never handed an HTML page**: 401/403/422/429 as JSON, validation in `errors`, `DomainException` → 422 with a message an operator would recognise |
| **F13.f** | Operator UI | ✅ **Developer → API Tokens** (name + expiry + ability preset, one-time reveal, revoke) and **Developer → API Reference** (renders `docs/openapi.json`, so the screen and the file are the same artefact) |
| **F13.g** | Limits | ✅ `api` 60/min **per authenticated user** (`auth:id`) else per IP; `token-issue` 5/min per IP, so a stolen password can't mint an unlimited API identity |

### Why the doc lives on the handler, not in a YAML file
A checked-in OpenAPI file drifts within a week. Here `#[ApiEndpoint(summary: …, response: …, query: [...])]` sits **above the method** that implements it, and `ApiSpecBuilder` walks the actual `api` route list — so:
- a route **without** the attribute makes the generator **throw** (no undocumented endpoint can ship);
- the `permission:` / `ability:` middleware strings are *parsed off the route* into `x-required-permission` / `x-required-ability`, so the doc's auth line is the **enforced** line, not a restated one;
- `{param}` placeholders in the attribute must match the route or it throws, and every `request`/`response` must name a registered schema;
- `api:spec --check` in a test pins the file byte-for-byte against live reflection. Editing a description without regenerating is a red build.

### Money across the boundary
Money is a **two-decimal string** in and out (`"1234.56"`, `"0.00"`), so a JS client can't round a total through a float — `InvoiceResource::money()` is the single cast. Percentages stay **numbers**, because they are not amounts. Where a service still returns floats (budgets compute `budget − actual` in `BudgetService::dollars()`), the **controller** converts at the boundary (`BudgetApiController::money()`) — deliberately visible, so the next person can see which side of the line is exact and which is derived. `meta` on every list carries `total`, `current_page`, `last_page`, `per_page`, `from`, `to`.

### The conventions the tests pin (`Phase3ApiContractTest`, 23 passed / 120 assertions)
- **credentials → token → request** round-trips; the secret appears **exactly once** (a `GET /auth/me` after issue works, and the token screen flashes it then never renders it again);
- a missing, malformed, expired, revoked, or **orphaned-by-user-deletion** token are **indistinguishable 401s** — no oracle about why;
- the 6th `token-issue` in a minute is a **429**, and `auth/me` is not throttled by it;
- **JSON, never 302**: `Accept: */*` (what bare `curl` sends) and an absent header both get 401 JSON; an explicit `text/html` preference is *respected* — that's a browser, and browsers belong on the web guard;
- ability narrowing: a `accounts:read` token gets 200 on trial balance and **403 on invoices**; the `read` preset is exactly `['accounts:read','sales:read']`; `write` implies `read`; no abilities = full access;
- ACL parity: every documented `permission:` string still exists on a **web** route — the API can't quietly invent or drop a gate;
- money arrives as strings, including the float-returning budget boundary;
- `trial-balance` signs against a **real posted journal**, not a fixture;
- `POST journals` creates a **draft** (`status: draft`, `line_count: 2`) and an unbalanced or empty posting is a **422 before any row is written**; a period with no open fiscal year is a 422 that says *fiscal year*;
- the spec file equals freshly generated reflection (`api:spec --check`).

### Verification added for F13
- Frontend: ESLint `--max-warnings=0` clean on all six touched `.vue`/`.ts` files, **Vitest 276 passed** (12 files — the +2 vs 274 is by design: `resources/js/componentResolution.test.ts` does `it.each(files)` over every `.vue`, so each new page adds a case, and both new pages resolving every component is itself the assertion), **`npm run build` ✓ 26.57s**.
- `.vue` census in `PrintContractTest` re-pinned **418 → 420** (the two Developer pages) **with the reason in a comment**, as the guard asks.
- **Two ratchets caught real defects from earlier slices** and were fixed at the source, not by moving the pin:
  - `NativeDialogTest` went to 47 because **F17's `Tax/Withholding.vue` called `window.prompt`** — migrated to the a11y `promptDialog()` (and the handler had been calling `useForm()` *inside* the function, which is also wrong; it's now setup-scope). Baseline stays **46**, untouched.
  - `SharedComponentThemeTest`'s page-palette backlog reached **146 vs a pin of 143**. Rather than bump it, **four raw neutrals were tokenised**: the `NotificationBell` alarm badge (`bg-rose-500 text-white` → `bg-destructive text-destructive-foreground`), the Approvals approve CTA (`bg-emerald-600 … text-white` → `bg-success text-background hover:bg-success/90`), its toggle knob (`bg-white` → `bg-background`), and the Dashboard tile icon ink (`text-white` → `text-hero-ink`, the token `--color-hero-ink` exists precisely for ink-on-a-fill). **Both ratchets now pass at their original pins with nothing moved.**
- The standing guard batch **grew**: `PrintContract`, `MoneyColumnCast`, `OptionEndpointAuthorisation`, `NativeDialog`, `SharedComponentTheme`, `RouteIntegrity` ×2 + the four Phase 3 suites + `LocaleTest` = **121 passed / 5,073 assertions**. The two new members are there because two independent drifts had sat undetected for one slice each — a guard nobody runs is decoration.
- `MoneyColumnCastTest` **did not move** (256): F13 adds no columns.
- Broad regression — all of `tests/Feature/CORE/`, `tests/Feature/ACCOUNTS/`, `RouteIntegrityTest`, `SALES/InvoiceContractTest` (269s) = **321 passed / 6,682 assertions, 0 failed**. It was 319 + 2 failures before the two ratchet fixes above, so those two tests are the whole delta. Model scan stays 171, permission vocabulary 189 — no new models, no new permissions (token issuing is deliberately **self-service**, so it needs neither).

### Honest caveats
- **Read-mostly.** The only write is a **draft** journal; no posting, no payment, no invoice mutation. Posting a journal over the API means an approval conversation (F1's policies) that this slice deliberately doesn't fake. POS, bills, assets and budget *writes* are still screen-only.
- **Page-numbered, not cursor.** Lists take `page` / `per_page` (≤ 100) / `sort` / `direction` / `search` through the *same* `DataTableQuery` the grids use. Convenient and consistent; it means deep pagination is OFFSET-deep, and there's no `since=` delta pull yet — an integrator syncing nightly will re-read pages.
- **No OAuth2, no client-credentials flow, no per-token rate limit.** Tokens are long-lived (≤ 365 days) bearer secrets: rotation is manual, and `api` throttle is per *user*, so two integrations sharing one human share one budget. Scoped service accounts are an F12/SSO-era decision.
- **The spec is public** (`/api/v1/openapi.json` needs no token) — deliberate, so `curl` works from a terminal, but it does publish the surface area of the system to anyone who finds it.
- `expires_at` is enforced at auth time, **last-used is tracked but not acted on** — nothing auto-revokes an idle token yet.
- **No CI job runs `api:spec --check`** because there is no CI in this repo; the drift guard runs as part of the normal test suite instead. If CI ever exists, that command is the one to pin.

### Next slice
**F22 — offline POS / PWA** (the last big one that touches no ledger semantics beyond what already exists), then **F18 (RFQ)** and **SSO**. F12 multi-branch and F16 multi-currency stay deliberately last: both widen every posting path in the app.

---

## 9. F22 — Offline POS + PWA (U21): a counter that keeps selling when the network drops

**Status:** shipped and verified. Chosen after F13 because it is the one gap on the list that *no* amount of server-side depth fixes: a Chattogram store with a flaky link does not have a software-quality problem, it has a "the queue at 8pm is physical" problem. It also touches no ledger semantics beyond what `PosService` already enforces — which is exactly why it is safe to build as a client feature with a thin, idempotent server door.

### Scoreboard

| Item | Scope | Status |
|---|---|---|
| **F22.a** | Schema | ✅ `pos_sales.client_ref` `string(36)` **unique** + `rang_up_at` `timestamp` nullable, both `hasColumn`-guarded, `down()` drops the index before the column. No money columns, so `MoneyColumnCastTest` stays at 256 |
| **F22.b** | Replay door | ✅ `POST /sales/pos/offline/sync` (web + auth + `permission:pos.terminal.sell`) — **always 200** with per-row verdicts, batch cap 25, and `422` only for a broken envelope. All arithmetic goes through `PosService::sell()`, never around it |
| **F22.c** | Idempotency | ✅ the device mints a UUID per ticket; lookup-before-validate plus the unique index means a replay, a double-tap, and two tabs racing all produce **one sale**: `already-posted` / `concurrent-replay` / `repeat-in-batch` |
| **F22.d** | Refusals, not repairs | ✅ `stale_date` (a ticket from a closed trading day is refused, never silently re-dated), `business_rule` (+ its `errors`), `validation` (+ dotted field errors); a money drift is **posted with a warning** — `Posted, but the total moved: the price list on the device was stale.` |
| **F22.e** | Queue desk | ✅ **Sales → Offline POS Queue** (`SALES::Pos/Offline`): status/engine/catalogue cards, per-ticket verdicts, *Try again* / *Post to today* / *Discard*, a printed slip for anything the ledger took, and the server's own ledger for today beside it |
| **F22.f** | The till itself | ✅ `Terminal.vue` queues on the network's failure instead of losing the sale, keeps a cached catalogue for scan/search offline, warns about oversell before tender, and now sends `client_ref` on the **live** path too — so a double-tap can no longer create two receipts |
| **F22.g** | Durability ladder | ✅ IndexedDB → `localStorage` → in-memory, one `engine` string the desk shows you; the retry ladder is `0 / 2s / 5s / 15m…` so a 6-hour outage costs nothing but the last row is still retried hourly |
| **U21.a** | Installable shell | ✅ `public/manifest.webmanifest` (POS terminal as `start_url`/`id`, `scope: /`, two shortcuts, 4 icons), 4 generated PNGs + their generator committed in `tools/`, `theme_color` = the app's `--primary` |
| **U21.b** | Service worker | ✅ `public/sw.js`: `/build/**` cache-first (immutable hashes, FIFO trim at 400, never deleted on activate, so a deploy during an offline spell still renders the version it has); documents **only** for the two POS pages, 12 h max age; `/sales/pos/offline/sync` is an explicit pass-through |
| **U21.c** | Offline chrome | ✅ `OfflineBadge.vue` in the header — reads the queue's storage signal, never imports SALES; `public/offline.html` is inline-styled and bilingual, so the notice renders with nothing to load |
| **U21.d** | A latent bug, fixed at the source | ✅ the shell emitted **no `csrf-token` meta tag** while five components (DataTable saved views, NotificationBell, BarcodePrint, BulkWeek, the new sync) put it into `X-CSRF-TOKEN`. Every one of those JSON posts would have 419'd in production; invisible because Laravel skips CSRF verification under tests |

### The design, in the order the risks arrived

**A ticket is not a draft.** The device never stores "a sale someone might confirm": it stores `buildSalePayload(...)` — the same shape the live form posts — plus exactly three fields (`client_ref`, `rang_up_at`, `expected_total`) and one opt-in (`acknowledge_stale_date`). Nothing on the till can therefore be a second source of truth about money; the queue is a *transcript of an intention*, and the server decides.

**The server owns everything that could disagree.** `sale_date` and `pos_no` come from the server, always. `expected_total` is compared in integer paise, and if the device's price list moved, the sale is still posted (the customer has already been given the goods) with a `warning` — refusing would push the cashier into fixing a pricing problem with the drawer.

**Per-row verdicts, because the money is already in the drawer.** A batch is not a transaction: row 3 failing cannot un-post row 1, or the till would have to *un-take* cash. So the endpoint always answers 200 and each row carries its own verdict; only a malformed envelope (which means "you are not talking to the right door") is 422, and only a guest is 401.

**"Was this rung on a device?" is `rang_up_at IS NOT NULL`** — `PosSale::scopeRungOnDevice()`. It is deliberately *not* "came through the sync route": live sales now carry a `client_ref` too, so the ledger and the queue differ only in arrival, not in origin, and the drawer report counts both.

**No journal until the drawer closes.** The sync writes sales; day-end writes the journal. That is inherited from `PosService` untouched, and it is what makes an offline batch safe to post at 3 a.m.: nothing about it pretends a trading day is finished.

**A queue ticket is cleared only on a verdict, never on transport.** `in_flight` → `posted`/`attention` requires the server's word for that `client_ref`. A `TypeError` from `fetch` is a retry, with backoff; an `attention` row stops being retried until a human chooses *Try again* / *Post to today* / *Discard* — otherwise a validation error becomes a machine-gun against a server that has already said no.

**The catalogue is disposable, the queue is not**, so they live in two stores with two policies: the queue one ticket at a time in IndexedDB, the catalogue as one whole JSON blob in `localStorage` that a failed write deletes rather than half-writes. `POST /inventory/api/pos-catalog` is the cached-snapshot door: ≤ 2000 rows, `truncated` honestly reported, and **no `cost_price` in the payload** — margin must not ride along in a till's cache.

**The header badge is the only cross-module seam**, and it is a storage key, not an import: `sales.offline.pending.v1`, versioned, with `waiting` / `attention` / `value`. CORE must not `import` SALES — SALES has to stay removable without CORE noticing — so the writer lives in the till and the reader re-implements eleven lines. `Phase3OfflinePwaTest` pins the key, the version, the fields and the same-tab `CustomEvent` on both sides, because a contract that isn't checked is a contract that drifts.

**Documents are cached for two URLs only.** An Inertia response embeds its own data in `data-page`; caching a *page* is therefore caching a *customer list*, on a tablet that is shared and handed over. So: the terminal and the queue desk, 12 h max age, everything else network-or-nothing. `/offline.html` is the fallback, and it is inline-CSS and bilingual, because the one time it renders is the time there is no network to fetch a stylesheet with.

### The conventions the tests pin

`tests/Feature/SALES/Phase3OfflinePosTest.php` (Pest, live SQLite — **18 passed / 141 assertions**): the two columns and the unique index enforced by the database; one sale for a replay, a double-tap (`assertRedirect` + an `already recorded` flash, `Journal::count()` unmoved, no second `PosSale`) and two tabs; per-row isolation; `stale_date` refused and not re-dated, then accepted with `acknowledge_stale_date`; drift posted with a warning; **sync never writes a journal** until day-end; the closed-drawer desk counts `rungOnDevice()` rows; the catalogue endpoint is `403 {type:'pos-catalog'}` for a user without the till grant and never carries `cost_price`; and a **parity test** — every field `payload.ts` writes must exist in `PosSaleRequest::rowRules()`, so a client that invents a key fails the build instead of failing at a counter.

`tests/Feature/CORE/Phase3OfflinePwaTest.php` (Pest — **10 passed / 128 assertions**): the manifest parses and carries what Chrome demands; every icon it promises exists, is a real PNG, and is the size its `sizes` claims; the two shortcuts resolve to pages the admin can open; `sw.js` contains the sync pass-through verbatim and its document list is exactly the two POS screens; `offline.html` has no external stylesheet, script or font; the rendered shell emits a 40-character `csrf-token` and a manifest link; the badge and the queue agree on key/version/fields and CORE does not import `@sales/`; the queue desk is in the menu, its route is registered, guarded by `pos.terminal.sell`, and really renders `SALES::Pos/Offline` with its four props.

`queue.test.ts` + `catalog.test.ts` (Vitest, **60 passed**): the backoff ladder; a ticket surviving `in_flight` across a crash; `applyResults` clearing only acknowledged refs; `prunePosted`; `sortForReplay`; blob-shape rejection; a cache from another warehouse flagged `mismatched`; `searchCatalog` as substring-with-limit; a row absent from the cache reported as "not in this device's catalogue", never as zero stock.

### Verification added for F22

- **Full suite: `php artisan test` = 782 passed / 9,743 assertions, 0 failed** (949 s, real PHP 8.4 + SQLite). The run before the last rename was 781 + 1 failure, and that one failure was a ratchet earning its keep — see below.
- **Vitest 337 passed (14 files)**, up from 276. The new weight is in `offline/`: **60 tests** — `queue.test.ts` 43 and `catalog.test.ts` 17. `componentResolution.test.ts` also grew on its own (182 cases now), because it is an `it.each` over every `.vue`: adding a page adds an assertion that every component that page imports resolves.
- **ESLint `--max-warnings=0` clean** across `modules/` and `resources/js`, including the two `watch(cart, …)` blocks in `Terminal.vue` (mine clears the submission ref; the stock-warning one predates it) and the whole `offline/` directory.
- **`vite build` ✓ 20.70 s**, `node --check public/sw.js` ✓, `php -l` clean on every PHP file touched, and **`pint --test` PASS across 107 files** — all of `tests/Feature/CORE`, `tests/Feature/SALES`, `modules/SALES` and the inventory catalogue controller. Four style issues were fixed at the source (one of them in `OptionEndpointAuthorisationTest`, which F22 extended); nothing was excused with a `// pint:ignore`.
- **A ratchet caught a real defect**: `NativeDialogTest` went red because `Offline.vue` wrote `const prompt = installEvent.value` — which shadows `window.prompt` for the whole file, the exact class of bug that makes a dialog silently do nothing. Renamed to `install`; the baseline count (46) did **not** move, because the fix was the rename, not a new allowlist entry.
- Guard re-pins, each with the reason written next to the number as the guard asks: `.vue` census **420 → 422** (queue desk + badge), print callers **19 → 20** and print regions **8 → 9** (the desk prints the slip alone, engine and coverage cards stay on screen), `SCT_PAGE_CANDIDATES` **143 → 144** (a new page joining the backlog on the existing terms). `OptionEndpointAuthorisationTest` went 9 → **11** by design: it is the guard that every JSON options door is authorised, and F22 opened a new one (`/inventory/api/pos-catalog`), so the endpoint list gained a case rather than the test gaining a rule. Model scan 171, permission vocabulary 189, `MoneyColumnCastTest` 256 — **all unmoved**: F22 adds no models, no permissions and no money columns. That last one is the design: an offline till that needed a new permission or a new ledger column would be a second POS, not a mode of this one.
- i18n: +45 `en` / +43 `bn` keys, and the two dictionaries were diffed programmatically — every key the queue desk and badge use exists in both (the 2 remaining `bn`-only keys predate F22).

### Honest caveats
- **Offline selling needs an open drawer.** The queue makes a *sale* without a network; it does not invent a till shift. `PosRepository::openSession` still refuses a second open drawer per warehouse, and a device with no catalogue snapshot and no network can take a manual line but not search — the desk says which of the three stores and whether the cache is stale, because "offline" is three different states and pretending otherwise is how a cashier loses an hour.
- **Stock is advisory offline, decisive at sync.** `available` in the cache is a photograph of a warehouse others are still working; the till warns and posts, the ledger rejects. Overselling is possible on paper for the minutes between the last sync and this one, and no client-side trick fixes that — it is a race with a neighbour, not a bug.
- **The worker cannot pre-cache a cold tablet's first day.** A device that has never been online inside this app has no build assets to serve; what it gets is `offline.html`. The queue survives that anyway — IndexedDB is the till's, not the worker's.
- **No Background Sync API.** Replay is driven by `online`, `visibilitychange`, retry buttons and the ladder — not by a `sync` event the browser delivers after the tab is gone. Chrome-only anyway on the platforms that matter here, and a till is left open on purpose; a real background push belongs with F12, when a branch has a server of its own to be late to.
- **Registration is production-only**, so this cannot be exercised in a dev server, and **no test here runs a service worker**: `sw.js` is read as text. That is stated in the file's own docblock — it proves the prohibitions (no sync caching, no page caching), not the caching itself.
- **Install is not forced and iOS is partial**: `apple-touch-icon` + `mobile-web-app-capable` are emitted, `manifest.webmanifest` is not what iOS reads for its tile; the desk offers a manual "Install app / Add to Home screen" line rather than pretending the prompt always arrives.
- **No barcode *generation* offline** (printing is a server-rendered sheet), and the cached catalogue is a *snapshot of one warehouse* — switching tills on a plane is a mismatch, correctly flagged, not a refresh.
- **`expected_total` is compared, never trusted**: it exists to explain a difference, not to prevent one. A device with a genuinely wrong price list will sell at the wrong price and be told so at sync — the same trade every offline-first till makes, written down here so nobody discovers it in an audit.

### Next slice
**F18 — purchase requisition / RFQ**, then **SSO**. F12 (multi-branch) and F16 (multi-currency) stay deliberately last: F22 is the first feature whose *absence* a branch would feel, and when F12 lands the offline till's `warehouse_id` becomes the branch key it already half is — which is why the cache is stamped with it today.

## 10. F18 — Requisitions & RFQ: the half of purchasing that happens before the order

**Status:** shipped and verified. The audit's line was one row — *"No purchase requisition / RFQ … procurement starts at PO"* — and it is the most ordinary gap in the file: every factory in the country runs on a paper note that someone signs. Until this slice the ERP could only be told to buy something by someone who had already decided to, at a price they had already agreed with the vendor alone.

### Scoreboard

| Item | Scope | Status |
|---|---|---|
| **F18.a** | Schema | ✅ 7 tables in one migration — `purchase_requisitions` + `_items`, `purchase_rfqs` + `_lines`, `purchase_rfq_quotes` + `_items`, `purchase_rfq_awards` — plus `purchase_orders.requisition_id` (nullable FK, `restrictOnDelete`). Unique: `req_no`, `rfq_no`, `(rfq_id, vendor_id)` on answers, `rfq_line_id` on awards |
| **F18.b** | Requisition lifecycle | ✅ `draft → submitted → approved \| rejected → converted \| cancelled`; `REQ-` + pad 5 (`PR-` was already the returns prefix); `update`/`delete` are draft-only; `req_no`/`status`/`requested_by` are never writable from a form |
| **F18.c** | Money discipline | ✅ every total is derived, never entered: the header is the sum of the *stored* line estimates (integer poisha, `decimal:2`), `estimated_amount` is stripped from both `create()` and `update()` payloads, and `line_estimate` is computed server-side |
| **F18.d** | Segregation | ✅ raising ≠ deciding: `approve()` refuses the requester outright, `submit` requires a line with quantity, `reject` requires a reason, `cancel` refuses once `converted`, and the edit route is `manage`-gated so a decided document's form is not even offered |
| **F18.e** | Budget control | ✅ an optional `account_id` ties a requisition to the F14 plan for the month of `need_by`; `submit()` refuses over headroom with the numbers in the message and leaves the record a draft. There is deliberately no `config` switch to turn the check off — the control is opt-in where it belongs (F14's budget line, per account, per month), not opt-out here |
| **F18.f** | The override, in one act | ✅ the waiver is **not** on the submit button — the person asking cannot push a blocked request through. `approve($req, $overrideBudget)` is the only door, it re-checks `purchase.requisitions.approve` before anything else, and it accepts a still-draft document *only* with that flag, so a refusal is answered by one decision stamped `reviewed_by`/`reviewed_at`. No approval-note field is offered anywhere: a requisition has one reason column (`rejection_reason`), and a text box the server would discard is a worse promise than a dialog that names the numbers being waived |
| **F18.g** | Approvals, reused | ✅ an active `ApprovalPolicy` for `purchase.requisition` holds the requisition at `draft` and queues the approver permission's holders — the same inbox as the rest of the ERP, no new approval engine |
| **F18.h** | The round | ✅ `draft → sent → closed \| cancelled`, `requisition_id` nullable so a round can also be a market sounding; `openRfq()` carries **only the outstanding remainder** of each line (`qty − qty_ordered`) and refuses anything not `approved` |
| **F18.i** | Answers | ✅ one quote per vendor per round (upsert by `(rfq_id, vendor_id)`), re-entering replaces the answer **whole** and recomputes the header total from its lines; no VAT on a quote (VAT is the order's business, not the bid's); `withdraw` refuses the winner |
| **F18.j** | Comparison | ✅ computed in the service and passed as props, never assembled in the browser: per-line best cell, per-quote coverage `n/m`, a missing cell ≠ a zero cell, expired and withdrawn answers excluded, and the round-level recommendation is the **lowest complete, in-date total** (partial bids deliberately not eligible) |
| **F18.k** | The award | ✅ creates **draft** purchase orders — never a confirmed one — one per vendor for a split award, at the quoted price, capped at what is still outstanding, writing `purchase_rfq_awards` per line, bumping `qty_ordered`, setting `purchase_orders.requisition_id` and `converted_order_id`; refuses re-award, duplicate picks, expired answers, a line with no `product_id`, a fully-ordered line and a round with no requisition and no warehouse |
| **F18.l** | Permissions | ✅ **6** new grants, split twice on purpose: `purchase.requisitions.view \| manage \| approve` (ask vs decide) and `purchase.rfq.view \| manage \| award` (key in a bid vs commit to it). Vocabulary **189 → 195** |
| **F18.m** | Visible UI | ✅ 8 files: `Requisitions/{Index,Create,Edit,Show}.vue`, `Rfqs/{Index,Create,Show}.vue`, the shared `RequisitionForm`, plus the sidebar (`Requisitions` above the order it becomes, `Quote Rounds` beside it) and a `requisitions` option picker scoped to approved requisitions with something left to buy |

### The design, in the order the risks arrived

**A requisition is not a purchase order with fewer fields.** It is a different *object*: it has a requester, an approver and a reason, and no vendor. So it has its own tables, its own number series, its own permissions and its own timeline — and nothing it does touches stock or the ledger. One test asserts exactly that (`nothing a requisition does writes the ledger`), because the temptation in a modular ERP is always to "helpfully" create a payable early.

**The estimate is arithmetic done once, in one place.** `qty × price` looks trivial and is where documents start disagreeing with themselves: three lines at 10.10 could sum to 30.30 or 30.29 depending on who rounded. So both the line and the header go through the same `multiply()`/`cents()` pair, the header is the sum of the **stored** line estimates rather than of the posted ones, and the browser's total is labelled a preview. `0.333 × 99.99 = 33.2967 → 33.30` is in the test file for that reason.

**Refusals carry their numbers.** A budget block says *"P3rq Fuel for 2026-11 has 1,000.00 planned, 0.00 already posted, leaving 1,000.00 — this request needs 1,600.00"*, not *"over budget"*. The requester cannot act on a verdict they cannot check, and every refusal is a `DomainException` the controller flashes, so the reason survives the redirect instead of becoming a stack trace.

**Budget headroom is checked at submit, and only there.** Checking at approval would let a requisition queue up an approver for a request that cannot be honoured; checking at PO creation would make it a report, not a control. `null` account or an unplanned month is **not** a violation — the Show page says so in words ("uncontrolled spend, and the difference matters at reporting time"), because silently skipping the check is how a control becomes folklore.

**Waiver and approval are one act.** The first draft had `submit(…, override: true)`, which is how a waiver becomes a checkbox nobody owns. Now the flag exists only on `approve()`, which checks `purchase.requisitions.approve` *before* it looks at the document, so it cannot double as a way to skip submission. The UI follows the service: the button is labelled "Approve with override", it is rendered only when the server says the budget is breached, and its confirmation quotes the three numbers involved (requested, headroom, account) so the click is made against figures rather than a feeling. A one-line explainer next to it answers the question a requester will ask — *why is this not on the submit button?* There is no text box for a justification, because there is nowhere to store one: `rejection_reason` is the only reason column on the table, and a field the server would discard is a worse promise than no field.

**Comparison is per line; the winner is per round, and they are not the same decision.** Cheapest-line-by-line often beats the cheapest complete bid — and splitting a round across four vendors buys the saving with four deliveries, four receipts and four bills. The page therefore offers both buttons ("Take the recommended vendor" / "Cheapest line by line") and tells you how many vendors and orders that will make. The recommendation only ever considers complete, unexpired, positive-total answers, with ties broken by earlier `valid_until` then vendor id, so it is reproducible from the data alone.

**An answer is a document, so re-keying it replaces it.** `recordQuote` upserts by `(rfq_id, vendor_id)` and swaps every item line wholesale — a vendor's second email supersedes their first; it does not amend rows 2 and 3. The form says this before you save (it knows the vendor already has an answer on the round), and a quote with no lines is refused: a blank answer is not a bid.

**Awarding writes a draft order and nothing more.** Confirming, receiving and paying keep their own permissions and their own states. The award does not "helpfully" confirm either. Splitting an award across vendors makes N orders rather than one order with N vendors, because a `purchase_order` has exactly one `vendor_id` and pretending otherwise would corrupt the AP side.

**No requisition, no warehouse — stop.** A market sounding has nowhere to send goods, and `purchase_order_items` has no line without a destination. Inheriting from the requisition is the normal path; a bare round must name a warehouse. Guessing "the first warehouse" is a stock error with a paperwork trail, so the service throws instead.

**Off-catalogue lines stay off-catalogue up to the last moment.** A requisition line may be description-only — that is what "we need 40 racking bays, installed" is. But an order line needs a `product_id`, so the description-only line is *refusable* at award, not silently matched to a product by name. The test pins the refusal, and the refusal is the instruction — `"Floor scale, 5t" has no product on it, and a purchase order line must. Pick one on the requisition and award again.` — flashed back onto the round, where the buyer is standing.

### The conventions the tests pin

`tests/Feature/PURCHASE/Phase3RequisitionRfqTest.php` (Pest, live SQLite — **42 passed / 293 assertions**). The first 39 were written against the services; the 40th exists because `rfqs/quotes/{quote}` is the only route in F18 that binds a record other than the round; and the last two are page-level tests, which is where this slice earned its keep (see the two bullets marked *caught* below). The shape of it, and why each group is there:

- **Schema** — the 7 tables exist, `purchase_orders.requisition_id` exists, and the two unique keys are enforced *by the database* (a duplicate `(rfq_id, vendor_id)` and a duplicate `rfq_line_id` award both throw `QueryException`, so the invariant survives any future service refactor).
- **Money** — the poisha sum including `0.333 × 99.99`; `estimated_amount` immutability from a form; blank item rows dropped by `prepareForValidation` rather than counted as zero-line rows.
- **Lifecycle** — `REQ-` numbering, draft-only edit **and** delete (with the lines going with it), submit-needs-quantity, `reject` requires a reason, `cancel` refuses a converted document, and *nothing writes the ledger*: `acc_account_ledger` and `Stock::count()` unmoved across the whole path.
- **Segregation** — requester cannot approve their own; a draft cannot be approved and a decided requisition cannot be approved again; budget override refused without the grant *and* the `override_budget` field on the submit route changing nothing; override-then-approve records `reviewed_by`/`reviewed_at`; the same call without the flag on a draft is still refused.
- **Approvals** — an active policy holds the requisition at `draft` with a `DomainException` naming the policy, and re-submitting does not spam a second pending `ApprovalRequest`.
- **RFQ** — `openRfq` asks for exactly the outstanding remainder; a foreign `rfq_line_id` in a quote is refused; a quote replaces whole and its total follows its lines; comparison finds per-line best, excludes a partial bid from the recommendation, never recommends an expired answer (and does not mark its cell "best" either), and drops withdrawn answers from the grid; award creates the draft PO, closes the loop, caps the quantity, refuses twice, and splits per vendor.
- **Routes** — `403` without the grant and `200` with it for every new page; `record a quote` (`purchase.rfq.manage`) and `choose the winner` (`purchase.rfq.award`) are separately enforced on their own routes; withdrawing an answer is exercised through its **own** route, because `rfqs/quotes/{quote}` is the only model binding in F18 that is not bound to the round.
- **Render contract** — each of the seven pages asserts its Inertia *component name* plus the props its template reads, including `->missing('requisition.review_note')` and `->where('requisition.converted_order.id', …)`. Those two lines are the difference between a page that works and a page that renders a confident empty panel forever.
- **Vocabulary** — all 6 grants exist in the seeded permission set, so the config and the seeder cannot drift apart.

### Verification added for F18

- **Full suite: `php artisan test` = 824 passed / 10,124 assertions, 0 failed** in 976 s (real PHP 8.4 + in-memory SQLite, no exclusions). The run two before it was 819 passed / 2 failed, and both failures were guards catching F18's own UI (the native-dialog ratchet and the VAT-on-lines contract) — fixed at the source, not pinned; the run before that was 822 green with 40 of the 42 tests.
- **`tests/Feature/PURCHASE` = 50 passed / 341 assertions** — the module's eight pre-existing tests, green next to the 42 new ones.
- **Guard re-pins, four of them, each with its reason written beside the number:** model scan **171 → 178**, money census **256 → 262** (the six new money columns are listed by name in the comment; `purchase_rfqs` gains *no* money column deliberately — a round has one total per vendor), permission vocabulary **189 → 195**, and the `.vue` census **422 → 430** (8 files). `SCT_PAGE_CANDIDATES` stayed at **144**: the seven new pages carry no raw-palette class, so the backlog did not grow. Print callers (20) and regions (9) unmoved — F18 ships no print sheet.
- **The option-endpoint matrix went 39 → 40** because F18 opened a new picker door (`/purchase/api/options/requisitions`), and it is in the matrix rather than skipped: that door *filters* (approved requisitions with quantity outstanding only), and a filter is where a leak happens. Its grants were added to the three other modules' `PERMISSIONS` maps too — INVENTORY `products`/`warehouses`/`uoms`, PARTNER `vendors`, ACCOUNTS `accounts` — so a requisition form cannot be opened by someone whose pickers would 403.
- **Front end:** ESLint `--max-warnings=0` clean over all of `modules` and `resources/js`; **Vitest 337 passed (14 files)**; `vite build` ✓ 21.84 s after the final page edits, and the date handling on both Show pages and in the form prefill was tightened then (a serialised `date` cast is an ISO string; a document wants a day, and `<input type="date">` only accepts `YYYY-MM-DD`). `componentResolution.test.ts` covers the new pages automatically — it is an `it.each` over every `.vue`, so each import in the seven pages is now an assertion.
- **A page test caught two invented keys.** The first draft of `Requisitions/Show.vue` read `req.review_note` (there is no such column — the table carries `rejection_reason`) and `req.convertedOrder`. The second one is the interesting failure: `Model::$snakeAttributes` is still `true` in Laravel 12, so an eager-loaded relation serialises as `converted_order`, and `convertedOrder` is simply absent — no error, no 500, no console warning, just an empty "Became order" panel on a page that passes every `assertOk()` you could write. Both are now asserted by name.
- **Two guards caught real things, and both were fixed at the source rather than pinned away.**
  `NativeDialogTest`'s ratchet went 46 → 55: both new Show pages destructured `useConfirm()` (`const { confirm, prompt } = useConfirm();`), so every call site read `confirm({…})` — pattern-identical to `window.confirm()`, and the guard is a pattern scan, not a symbol resolver. The fix is the repo's own migrated idiom (`import { confirmDialog, promptDialog }`), which removes the ambiguity instead of teaching the scanner to forgive it; the baseline stayed at **46**, no entry added.
  `SalesOrderContractTest`'s "every line-item editor that collects a price also collects VAT" then flagged `RequisitionForm.vue` — correctly, as a *question*: the form collects `expected_unit_price` and `qty`. A requisition line has no `vat_rate` column to reset, because VAT is applied on the order, so the file joins `VATLESS_LINE_FORMS` with that reason, next to the three precedents. The RFQ form never reached the check, since a round collects no price at all.
- **`pint --test` PASS across `modules/PURCHASE` and the four touched guard/test files (70 files)**: nine files were flagged and all nine were fixed at the source: fully-qualified class names replaced by imports in two models, two services, two controllers and the test file; an `ordered_imports` shuffle in `PURCHASE/Routes/web.php`; a stray blank `* ` line in a docblock; one double-quoted string that needed no interpolation; and two genuinely **unused imports** (`DB` in the migration, `PurchaseRequisitionItem` in the RFQ service — both verified unreferenced by grep before deletion, since "pint says unused" is not the same evidence as "nothing uses it"). Nothing was excused with `// pint:ignore`, and after the fix `pint --test` reports PASS while `diff -rq` shows the mirror and the workspace identical.
- **A fix found while writing the tests, then pinned by one:** `withdrawQuote()` type-hinted `PurchaseRfqQuote` without importing it. Under Laravel's implicit binding that is a fatal on the route, and no service-level test could ever see it — hence the route test above.
- **`exportBuilder()` was written and then deleted**, and the F18 lists carry no `exportResource`: `CORE/ExportController` matches four literal keys against CORE services, so a fifth method with no key to reach it is dead code plus a test that only *looks* like a check. The right fix is an export registry in CORE, listed below as follow-on work.

### Honest caveats

- **Nothing is *sent*.** "Send" marks the round open for answers and tells the vendor's contacts nothing; there is no email queue for RFQs. Until there is, the buyer attaches the round to a mail by hand. `notify` rows would be the smallest honest step and are deliberately not faked here.
- **No vendor portal, no quote upload.** Answers are keyed in by the buyer from what the vendor sent. That is a real control weakness — the person who wants the deal can also type the price — and the mitigation shipped here is the permission split (`award` cannot be granted by giving `manage`) plus the activity log on the header, not a claim that the risk is gone.
- **Requisition approval does not create the PO by itself** — an approved requisition is a licence to buy, not an order. `convert` is intentionally not a button: the PO form can be pointed at a requisition, and if it ever gains a one-click path the loop is already closed by `purchase_orders.requisition_id`.
- **Budget control reads the plan for one month only** (`need_by`, or today). A requisition that straddles a quarter-end is judged on the month it is needed in, which is the right rule and a surprising one; the message shows the period so the reader can see which month was consulted.
- **The demo dataset has no requisitions yet.** `DemoDataSeeder::purchaseChain()` builds POs directly, and bolting a requisition onto those orders would mean inventing backdated approvals. A fresh install therefore shows the register's empty state; the seeded roles do carry the right grants (`Manager` → approve/award, `Staff` → manage), so the permission split can be exercised immediately.
- **`qty_ordered` is the only reconciliation between a line and its orders.** It is bumped by the award and by nothing else — a PO raised by hand against the same product, or a PO line deleted later, does not move it. The requisition's `converted` status is a document fact, not a stock one.
- **No print/PDF template** for either document, and **no export**. The last one is not laziness: `DataTable` enables exports through an `exportResource` string, and `CORE/ExportController` resolves that string with a `match` over four hardcoded CORE exports (users, roles, permissions, activities). There is no per-module registry to join, so `exportBuilder()` was deleted from both F18 services instead of shipping as unreachable code with a test that only looked like a check. Until exports get a registry, a paper approval trail means printing the page from the browser — which is what the print CSS in F22's queue desk was built for.
- **Multi-currency quotes are out of scope**: a quote is in the company currency, and F16 (multi-currency) is the slice that will decide what a USD-denominated bid means against a BDT budget. That is why `purchase_rfq_quotes` has no `currency` column to fill in later.

### Next slice

**SSO** (the last item on the list that is not a data-model change), then **F12 multi-branch** and **F16 multi-currency**, both deliberately last: F18 is the first feature with an *accounting* consequence to a branch decision (which warehouse's budget absorbs an award?) and the first to want a currency on a bid, so pinning the requisition/round tables before either dimension exists is what lets both land as columns instead of migrations against a moving target.

---

## 11. SSO — signing in with somebody else's database

**Why this one.** A company's directory already answers "who works here, and are they still employed". An ERP that keeps its own answer to the same question is a second list that has to be maintained by hand, and the maintenance never happens: the person who left in March still has a password. Single sign-on moves the decision to the IdP, and this slice adds the three things that make that usable rather than a demo button — provisioning for the people the ERP has never heard of, adoption for the accounts it already has, and a link manager that refuses the removal that would strand someone.

### Scoreboard

| Check | Result |
| --- | --- |
| `tests/Feature/CORE/Phase3SsoTest.php` | **26 passed / 184 assertions** |
| SSO + Money + Print + Theme + RouteIntegrity + NativeDialog + Option endpoints (7 files, final tree) | **74 passed / 5,098 assertions** |
| `vendor/bin/pint --test` | **PASS — 1021 files** (whole tree, not a subset) |
| ESLint `--max-warnings=0` | clean |
| Vitest | **338 passed** (14 files) |
| `vite build` | ✓ 28.00 s |
| Full suite | **not re-run this slice** — see the note under caveats |

Files: migration `2026_11_09_000030_create_sso_identities_table`, `SsoIdentity` model, `SsoService`, `SsoController`, `Pages/Sso/Accounts.vue`; edits to `CORE/Config/core.php` (a `sso` block), `CORE/Models/User.php` (the `ssoIdentities` hasMany), `CORE/Routes/web.php` (four routes), `resources/js/pages/auth/Login.vue`, `App\Http\Controllers\Auth\AuthenticatedSessionController`, `menu.ts`, `.env`. **No new permission** — the vocabulary census stays at 195 — and no new module. The model census moves 178 → 179 and the `.vue` census 430 → 431, both re-pinned with the reason in the comment.

### The decisions worth defending

1. **`userinfo`, not the `id_token`.** Socialite is not a dependency of this project, and the alternative — fetch the discovery document, download JWKS, verify RS256 signatures, handle key rotation — is a hand-rolled JWT verifier, which is the kind of code that reads fine in review and fails open in production. `GET /userinfo` with the bearer token is authoritative for the same reason an API is: answering requires a token the provider just minted. TLS is the trust boundary, and `token_url` / `userinfo_url` are env-overridable so Keycloak, ADFS or an on-prem Entra-compatible gateway need no code change.
2. **The subject is the identity; the address is a label.** `unique (provider, provider_user_id)`, with `email` stored beside it for display and for the adoption lookup. The test for this is the one that reads like a naming test and isn't: same `sub`, moved address ⇒ one user, one identity, updated email, and *no* account created for the new address.
3. **Only a `false` is a refusal.** `email_verified` is checked with `array_key_exists`, because Google and Entra send the flag and Keycloak realms routinely omit it. Treating "absent" as "false" locks everyone out of a private IdP; treating an explicit `false` as "fine" lets an unverified mailbox open an account.
4. **The state is consumed before it is checked.** `session()->pull()` on the first line of `complete()`, then compare with `hash_equals`, then the TTL. A callback URL in someone's history or forwarded to a colleague is inert even though every parameter on it still reads as valid — and "replay after a successful login" is now a test, not a comment.
5. **Adopt, don't duplicate.** No link row + a local account with that verified address ⇒ the identity is attached to the existing user. That single choice is what lets an administrator turn SSO on for a live installation instead of migrating people.
6. **A provisioned account has no password, and says so.** `users.password` is `NOT NULL`, so provisioning writes `Hash::make(Str::random(64))` — noise nobody has ever seen. The first run of the tests caught that `email_verified_at` was being *silently dropped* by `User::create()`: the app model keeps it out of `$fillable` on purpose, so it now goes through `forceFill`. Left unfixed, every SSO user would have read as unverified to every other guard in the app.
7. **Unlinking proves a way in, or is refused.** The server cannot tell "hash of random bytes" from "hash of a real password" — that indistinguishability is the point of hashing — so when the removal would leave a *single* sign-in path, the form asks for the password and `Hash::check` answers. When other links remain, nothing is asked. The `password.confirm` middleware was tried first and rejected: it redirects to the confirmation page and then *intends* back to the original URL, which for a DELETE is a 405 (a replayed GET does not carry the verb), so the action would silently not happen.
8. **The routes always exist.** Registered whether or not the feature is switched on, because `Login.vue` builds their URLs unconditionally and a missing route name is a 500 on the sign-in form of every installation with SSO off — which is all of them until someone pastes a client id. `isConfigured()` gates each action with a 404.
9. **A throttle for amplification, not for guessing.** There is no secret on these two URLs to brute force; the limiter exists because one anonymous hit costs the installation an outbound HTTPS exchange plus a profile fetch. 15/min per IP, pinned by a test that reads the route's middleware, because that is the kind of line a later refactor drops without noticing.
10. **Nothing rides in `HandleInertiaRequests::share()`.** `ssoProviders` and `ssoError` are props of the login page, set in `AuthenticatedSessionController::create()`. The shared-props whitelist exists so the full `User` model never rides along on every response; a feature flag for one page is not a global.

### The conventions the tests pin

- `availableProviders()` requires enabled **and** `client_id` **and** `client_secret` — a half-configured provider cannot render a button that 500s.
- The `{tenant}` placeholder resolves in both `authorize_url` and `token_url` (a single-tenant Entra app is a config change, not a fork).
- `redirect_uri` sent to the provider is `route('core.sso.callback', $provider)`, asserted against the route helper rather than a literal, so the check survives an `APP_URL` change.
- An existing identity's `last_login_at` and `email` are the only fields refreshed on sign-in; a provider's display name never overwrites the directory (`assertNotSame('Different Name', …)`).
- Linking from a signed-in session attaches to *that* account and never provisions; an identity another account holds is refused outright.
- The accounts page offers only configured, not-yet-used providers (`available` is a map keyed by provider, because the key is what builds the redirect URL).
- The guard pins: `auth.defaults.guard` = `web`, `auth.guards.web.driver` = `session`. SSO logging in through a stateless guard would be a silently broken feature, and nothing else in the suite would notice.

### Honest caveats

- **No SAML, no LDAP, no SCIM.** OIDC only. The de-provisioning half — a person leaves the company and the ERP keeps its account — is *not* solved here: the identity row stays, `last_login_at` stops moving, and disabling the user is still a human act. The next feature in that direction is a scheduled "identity has not signed in for N days" report, which is small and worth doing.
- **`auto_provision` creates accounts with no roles.** Deliberate — a person who cannot see a single screen is a visible problem, and one who silently inherits `Manager` is not. But it does mean an administrator has to notice the new row.
- **`allowed_domains` is the only content gate.** Anyone whose IdP says `@corp.test` gets in. Fine for one company per installation, wrong for a group of subsidiaries sharing one ERP, where the answer is a per-domain role map — which is F12-shaped and belongs after it.
- **A verified address is trusted exactly as far as the issuer's own policy.** `sub` is stable for Google and for Entra; for a hand-configured IdP with a rotating `sub`, an administrator must point `userinfo` at something whose subject does not move. The code does the right thing with what it is given and cannot detect the difference.
- **The demo ships with `SSO_ENABLED=false`, so the login page looks unchanged.** What is visible without credentials is the new **Sign-in Methods** page (`/core/sso/accounts`, Administration → Sign-in Methods), which explains that the feature is off and names the two keys that would turn it on. Nothing is seeded into `sso_identities`: a fabricated Google subject in a demo dataset would teach the wrong lesson about what the table is for.
- **The full suite was not re-run for this slice.** The change is additive — one table, one model, four routes, one page, two login-page props — and the guard set that would notice any of those five things (model census, `.vue` census, print census, route integrity including "every Inertia render target has a page" and "every permission a route enforces is seeded", the native-dialog ratchet) ran green, as did pint, ESLint, Vitest and the build. The last full-suite number on this tree's predecessor is 824 passed / 10,124 assertions.

---

## 12. UX pass from user feedback — journal lines inline, sidebar keeps its place (2026-11-10)

Two reports, both about the shell and the accounting form rather than about features: *"make the input field of a line be one inline, like Account, Memo, Debit, Credit will be inline"* and *"the sidebar is not stable — when I click something at the bottom that needed scrolling, after the click the sidebar goes back first, which is very disturbing."*

### Scoreboard

| Check | Result |
| --- | --- |
| `tests/Feature/ACCOUNTS/JournalLineRowTest.php` (new) | **10 passed / 93 assertions** |
| Journal form + print + dialog + theme + render guards, `tests/Feature/ACCOUNTS` in full | **149 passed / 5,131 assertions** |
| Vitest | **343 passed (15 files)** — 5 of them the new `treeSidebarScroll.test.ts` |
| ESLint `--max-warnings=0`, `pint --test` (1022 files), `vite build` | clean / PASS / ✓ 22.78 s |

### The journal line, one row

The line block was `sm:grid-cols-2 xl:grid-cols-4` with the Memo field carrying `span="full"`, so four fields never shared a row: under 1280px it was a two-by-two stack, and at 1280px and above the memo wrapped onto a second line *below* the money. Now the row is `gutter · Account · Memo · Debit · Credit` with the remove button pinned to the card's edge, and the heading row repeats the same column template so the labels stay over their fields.

Two choices worth naming:

- **A container query, not a viewport one.** `@container` on the lines list, `@3xl:` on the row and heading. The form lives in a shell whose sidebar collapses to an icon rail, which widens the content area by 200px without the window resizing — a viewport breakpoint keeps the lines stacked on exactly the screens that just got room for them. Verified in the compiled CSS rather than assumed: `app-*.css` carries `container-type:inline-size` and a `@container (min-width:48rem)` block holding the emitted `1.9rem minmax(0,1.15fr) …` template.
- **The gutter keeps the side, drops the amount.** `DR 1,000.00` has no room in a 1.9rem column and duplicates the Debit field beside it, so at `@3xl` the pill shows `DR` and the number goes back below the breakpoint, where the pill is the line's only summary.

### The sidebar holds its place

Every page renders its own `AdminLayout`, so an Inertia visit tears the shell down and mounts a new one — and a fresh scroll container always starts at `scrollTop: 0`. The open/closed branches were already remembered in `localStorage`; the *offset* was the half nobody saved, which is the whole of "goes back first". `useSidebarScroll` keeps it at module scope instead: it survives client-side navigation and resets on a real reload, which is the one case where the top is correct. On mount it restores the offset and then nudges by the minimum needed to bring the current item back inside the box — hand-computed rather than `scrollIntoView`, because that would also scroll the *window* and the page jump belongs to Inertia. The save happens on a coalesced `scroll` frame **and** once more at unmount, because a click that navigates can land in the same frame as the scroll before it. Leaf links carry `data-nav-target` so `[data-nav-target][data-active="true"]` identifies the current page without inventing a second source of truth.

### What the checks caught

- **A range-regex edit swallowed two `computed` definitions** (`currentUser`, `appName`) from `TreeSidebar.vue`. Every test in the sidebar suite still passed; the only trace was a `[Vue warn]: Property "appName" was accessed during render but is not defined` line in Vitest's stderr, and it would have shipped as a blank sidebar header and footer. Reading the file back after a surgical edit is not ceremony.
- **The field order was wrong in the DOM, not just on screen.** `Memo` sat last in the markup, so "Account, Memo, Debit, Credit" was only a class-string wish; the new contract test walks the labels by index and would have failed either way — that is the difference between pinning markup and pinning an ordering.
- **Two ticks, not one:** the branch containing the current page opens inside `onMounted`, so the test that looks for the active leaf polls a bounded number of flushes instead of hard-coding a tick count nobody could verify.

### Honest caveats

- `@3xl` is a *container* width of 48rem (768px), so a laptop with the sidebar expanded goes inline and a narrow external monitor keeps the stacked layout with visible labels. Both are intended; neither is tested by a browser, because nothing here renders CSS.
- The scroll offset is per-load, not per-tab-history: back/forward navigation restores the sidebar's offset as of whenever the shell last unmounted, which is close enough and is not what the report was about.
- The same jump still affects any *other* scroll container in the shell (none exists today — the sidebar is the only one) and the page body itself, which Inertia resets to the top by design.

---

## 13. Two reports from the field: dialogs that say "Delete", and regularization that "does not work"

Two one-line reports, both real, both more interesting than they looked.

### 13.1 The red Delete button on "Award this round?"

> *"Some of the Confirmation Message uses delete form, when click on delete its will confirm [it's] too funny version of AI"*

`ConfirmDialog.vue` was written for one job — standing in front of a deletion — and it carried its whole
personality in component defaults: `Are you absolutely sure?`, `This action cannot be undone.`,
`confirmLabel: 'Delete'`, `danger: true`. `confirmDialog('Revoke this token?')` sets a description and
nothing else, so it inherited all four. Of 21 `confirmDialog()` call sites only 11 passed a label; the
other ten were approving, awarding, allocating, backfilling and posting behind a button that said
**Delete**. The 46th time a page asks a question, patching the page is not a fix — the default was wrong.

So the seam does the reading now. `describeAction()` (exported from `useConfirm.ts`, used by both
`confirmDialog` and `promptDialog`) takes the wording the page already chose and derives `confirmLabel` and
`danger`:

- **the verb the sentence leads with wins outright** — `Revoke this token?` → `Revoke`;
- **otherwise the last clause wins**, because these prompts are built as `context — question?`, and the
  action lives at the end: `Over budget — approve anyway?` → `Approve`;
- **inflections count** (`rejected` is `reject`) but a word that merely starts like a verb does not, so
  `Clearly this will take a moment` is not a request to *Clear* anything;
- **a destructive verb anywhere keeps the warning tone even when a constructive one won the label**:
  `Clear and rebuild attendance` is an approval, and it still wipes rows, so it reads `Clear` in red —
  honest on both counts;
- **no recognised verb → `Confirm`** (`Submit` for a prompt), never `Delete`.

`ConfirmDialog.vue`'s own defaults went neutral (`Please confirm`, no description at all, `Confirm`, not
dangerous), so a page that renders the component directly cannot regress to the old behaviour either. An
explicit `confirmLabel`/`danger` always outranks the derivation — three sites in `Probations/Index.vue`
keep their hand-written labels untouched, which is the point.

11 new Vitest cases pin the derivation and, more importantly, mount the real host and assert that
`confirmDialog('Award this round?')` renders a button labelled **Award** with no `bg-destructive`, and that
`This action cannot be undone.` is not on screen for it.

### 13.2 "Attendence Regularization is not working"

Nothing in the suite had ever driven this feature — `grep -rln regulariz tests` was empty — and five
independent defects were hiding behind a single symptom: a form that does nothing when you press Submit,
and an Approve that does nothing when you press it.

| # | Defect | Why it looked like "not working" |
|---|--------|--------------------------------|
| 1 | `store()` validated `in:Missing In,Missing Out,Short Hours,Wrong Shift`; the picker offers eight and the column is an `enum` of those eight | 5 of the 8 selections were rejected outright, and the validator's own invention `Short Hours` was storable by nobody |
| 2 | the page used `reactive()` + `router.post` and rendered no `form.errors` | every rejection was silent — a dead button, not a validation message |
| 3 | the service's `\DomainException` (duplicate, re-review) was never caught | a second click of Submit was a 500 page |
| 4 | `request()` de-duplicated with `where('duty_date', $data['duty_date'])` | the guard *never fires*: `duty_date` is a `date` cast, stored as `2026-11-04 00:00:00`, so comparing it to the bare form string matched nothing and the `uniq_emp_date_type` index answered instead |
| 5 | `$locked->duty_date.' '.$locked->requested_in_time` | both sides are Carbon, so it built `2026-11-04 00:00:00 2026-11-10 09:00:00` — `Carbon::parse` throws, and on the create path the same garbage went into the daily's punch column. **This is the Approve button dying.** |
| 6 | punch patching was gated on `regularization_type === 'Missing In' / 'Missing Out'` | a `Late Correction` or `Wrong Shift` was approved in name and the attendance row stayed exactly as it was |
| 7 | the flash claimed `patched and recalculated to Present` for every approval | the reviewer was told something about payroll-relevant data that had not happened |

Fixes, in the same order: one source of truth `AttendanceRegularization::TYPES` feeding the page (as an
Inertia prop), the validator (`Rule::in`) and the schema; `useForm` with per-field `form.errors.*`;
`try/catch (\DomainException)` → `back()->with('error', …)` in all three write actions (the flash reaches the
user through the existing `ToastStack` bridge); `whereDate()` + `withTrashed()` in the duplicate guard, which
also gives a rejected request a real second life (reopen the row, since the unique index allows no other);
a `stamp()` helper that formats both halves explicitly; times are applied whatever the type, with
`status` left to the engine when the day already had punches (`is_regularized` + remarks + the daily id are
still written); and an `outcomeFor()` flash that re-reads the daily and reports the status it actually holds.

Two deliberate non-fixes:

- **Approve/Reject still require `hrm.regularizations.approve`, which the Manager role does not have.** The
  page copy invites "Manager/HR approves", so this reads like an oversight, but a manager who can file a
  request should not also be able to approve their own. The buttons are now gated on the permission the
  server enforces (props `canManage` / `canApprove`), and a manager sees *Awaiting a reviewer* instead of a
  row of 403s.
- **The reviewer's remarks are collected with `promptDialog` now, not `prompt()`.** Native modals are blocked
  in a sandboxed iframe, so the approval flow was un-completable in the preview. `ND_BASELINE_CALLS` fell
  46 → 45 and the page joined `ND_MIGRATED`.

`tests/Feature/HRM/RegularizationFlowTest.php` is the first test this feature has had: 18 cases / 75
assertions, including a data-provider pass over **all eight** types (filing one of each, end to end), the
`Short Hours` refusal, the duplicate-as-message, the 403 without approve, a `Late Correction` that must move
the in-punch to 09:05 and recompute 9.02h while the day *keeps* `Late`, a from-nothing daily created at
`Present`/8.5h, a missing-in day that must **not** be presented as Present, and re-decision refused.

### What the checks caught this time

- The first draft of the regularization page rewrite lost its `</script>` tag to the same class of surgical
  edit that bit §12: Vitest, PHP feature tests and the PHP linters were all green because none of them
  parses that file's template. ESLint caught it. The rule from §12 — read the file back after an edit that
  splices a block — is not retired by having been learned once.
- `(float)` on `primary_worked_hours` was needed in the test: the column is cast `decimal:2`, so it comes
  back as the string `'9.02'` and `toBe(9.02)` fails.
- `assertViewHas` sees nothing on an Inertia response; the props live in `viewData('page')['props']`.

### Honest caveats

- The verb derivation is a heuristic over wording, not a type system. It reads the strings the page passes at
  runtime, so a title built from data (`Delete ${row.ref}?`) is resolved correctly, but a title built from a
  variable holding unrelated prose will fall through to `Confirm`. The escape hatch is the one that has
  always worked: pass `confirmLabel`.
- `danger` is deliberately conservative — an unrecognised sentence keeps the warning tone. A page asking a
  harmless question therefore still shows a red-ish Confirm. That is the failure mode I chose.
- Fix 4's duplicate reopen replaces the earlier decision in place (same row, `REG-nnnnn` retained, review
  fields cleared). It does not keep two attempts; the unique index makes that impossible without a schema
  change, which is out of scope here.
- The `date`-cast comparison trap (defects 4 and the daily lookups) is a SQLite-influenced class of bug: the
  same code behaves differently on MySQL, where `duty_date` is a real `DATE`. `whereDate()` is correct on
  both.

---

## 14. Tax: a picker that erased itself, and a name nobody searched

> *"i cannot see out all employee in Employee Tax override form and its not selectable"*
> *"Tax Allocation i have no idea where it is"*

### 14.1 The employee picker (seven payroll forms, not just tax)

`EmployeeSearchSelect` is the picker behind Tax Profiles, Loans (create + monthly allocation), Provident Fund
allocation, Increments, Settlements and Adjustments. Its query watcher fought its own selection watcher:

```ts
watch([() => props.modelValue, () => props.options, () => remoteOptions.value], ([value]) => {
  if (!value) { selectedOption.value = null; if (query.value) query.value = ''; … }
```

`remoteOptions` is reassigned (`= []`) by the query watcher on *every keystroke*, so this watcher ran on
every keystroke too — and because every form starts with `modelValue: ''`, its "nothing is selected" branch
cleared the input and aborted the fetch that had just been scheduled. **The search box erased itself as the
user typed.** What was left to choose from was the page's seed list, `visibleOptions` sliced to 50 — so the
pickers showed the first fifty employees alphabetically, refused to narrow, and refused to widen. "Cannot
see all employees and it's not selectable" is exactly that, and it was reachable in a browser click: the
dropdown is fine, the *typing* is not.

Now:

- the two jobs are separate watchers — a **selection** change resets the display, an **option-set** change
  only re-resolves the label (`syncSelected()`);
- an empty term is a *browse* request (`?search=&limit=200`), fired when the list is opened, so the picker
  reaches the roster the page could not fit into a prop;
- the silent `.slice(0, 50)` is a windowed list with an explicit `Show 120 more · 80 of 200 listed`, plus a
  "Showing the first 200 — type to search all of them" line when the server's page is full, so a limit is
  never mistaken for a missing employee;
- search starts at one character, not two;
- `EmployeeMultiSearchSelect` (the "selected employees" scope on PF/Loans/Increments) got the same browse
  request and windowing — it did not have the self-clearing bug, because its watcher is keyed on
  `modelValue` alone and its array is always truthy. Worth noting: the pair had drifted.

`modules/PAYROLL/Resources/js/Components/employeeSearchSelect.test.ts` (10 cases) mounts the component for
real. **Seven of them fail against the pristine file** — `expected '' to be 'rafiq'` is the self-clearing
box, `expected … length of 80 but got 50` is the ceiling — and the three that pass either way are the
parent-in-charge cases, which the split deliberately does not change.

The same page had a second silence: `mapEmployee()` throws
`ValidationException::withMessages(['salary' => "No HRM salary-history record covers EMP-x on DATE; …"])`,
and the override form rendered errors for its own six inputs only. Pick an employee without salary history —
in a half-seeded demo, most of them — press Save, and nothing appears to happen. The form now renders a
loose bucket for any error key it has no field for, and `PayrollTaxOverrideTest` asserts that every key
`storeProfile()` validates, plus the thrown `salary`, has somewhere to land, reading the field list out of
the controller so the check cannot drift.

### 14.2 "Tax Allocation"

There is no screen by that name, and no allocation step to build: payroll tax is a *rule*, not a fund —
every employee follows the annual configuration unless an override exists, and `profiles.meta.total` says
how many do. The two places tax actually lives are now labelled so a search finds them:

- **Payroll → Tax Profiles** (`payroll.tax.index`) — slabs, per-employee overrides, versions, audit;
- **Accounts → TDS / VDS Withholding → Deposits** (`accounts.tax.withholding.index`) — the step that
  *allocates* withheld amounts onto one treasury challan per tax type.

The sidebar filter used to match a substring of the label, key or translated label. For any **two-word**
query that is empty by construction, which is how "I have no idea where it is" becomes "the feature is
missing". `menuMatchesQuery()` is now exported from `menu.ts` (the SFC cannot export a test seam) and adds:

- `keywords` on a `SidebarItem` — `tax allocation, tax slabs, tax override…` on the payroll page,
  `challan, treasury, tax deposit, mushak 6.6…` on the withholding desk;
- a multi-word fallback where **every** word must appear. Phrase-first, then AND: `tax allocation` finds
  both screens, `tax slabs` does not reach the challan desk, and `allocation sheets` finds nothing. Widening
  to "any word" would have been a fire hose.

The page says the same thing in prose, and the two screens answer "where is the deposit step" with a
pointer rather than a link — the withholding desk is permission-gated on `accounts.withholding.view`, and a
payroll officer following a link to a 403 learns nothing.

### What the checks caught

- `git show HEAD:…` into the mirror, then re-running the new tests, is how both this section and §13 were
  verified: a test that passes before *and* after the change pins nothing, and saying which of them fail
  before is the only honest version of "10 new tests".
- `find()` in the menu test recursed by calling a function that **throws** on a miss, so the first subtree
  without a match aborted the whole walk — four tests reported `no sidebar item with key payroll-tax`, which
  is a broken test helper, not a broken matcher. Lookups return `null`; only the top-level helper throws.
- The multi-word fallback started as OR, and `acc-withholding` immediately matched `tax slabs`. AND is what
  keeps aliases in their own lane.
- `pint --test` caught a double space in a docblock — and that docblock was also wrong about what
  `ptxRoster()` returns, which no test would have noticed.

### Honest caveats

- 200 is the browse page and 500 is the endpoint's hard cap, so a company with more than ~500 employees
  still cannot *scroll* to all of them — they can only search, and the footer says so. Raising the cap is a
  payload decision, not a picker one.
- The picker's seed prop is unchanged (`employeeOptions(null, 200)` in `TaxConfigurationController`), because
  seven pages share it; the component no longer depends on it being complete.
- `keywords` are not translated. A Bangla query still matches a translated label; it will not match an
  English alias. Fine for now, and the hygiene test keeps them lowercase and comma-separated so a future
  translation pass has something regular to work on.
- Nothing here verifies that the *rendered* dropdown is scrollable at 200 px in a real browser; jsdom has no
  layout, so the windowing is proven by counts and copy, not by pixels.

## 15. "Don't punish the payroll for my attendance rule" + the cutlist becomes a real review desk

Reported from the live desk, two halves of one complaint:

> `No HRM salary-history record covers M302012 on 2026-10-01; payroll was stopped rather than silently
> underpaying the employee` — "if employee attendance policy is not Punishable then it must be hit on payroll
> without any punishment"

and

> "i want to add Cutlist system which will exactly like salary sheet but its not final it is for review of salary"

### 15.1 The non-punishable rule, enforced everywhere it was leaky

`EmployeePolicy.is_punishable` is HRM's switch that says *this employee's attendance carries no discipline*.
Payroll honoured it for the late/early-out punishment deduction but leaked it in three places; all three are
now closed, and each is pinned by `tests/Feature/PAYROLL/PayrollCutlistReviewAndNonPunishableTest.php`:

- **The stoppage.** `HrmEmployeePayrollDataProvider::mapEmployee()` threw `ValidationException` for *any*
  employee whose salary history didn't cover a day of the month — killing the whole run. For a
  **non-punishable** employee that is exactly the "silently underpaying" behaviour turned into
  "loudly not paying": the employee loses the entire month because HRM has an unentered increment. Such an
  employee now gets **rate continuation**: the uncovered day is paid at the nearest known salary record
  (latest effective on/before the day, else the earliest one after — *including rows that expired before
  the month*, which is the M302012 case: their increment simply wasn't entered). The segment carries
  `rate_from_other_period`, the DTO carries `notes['salary_rate_fallback_days']`, and the slip breakdown
  prints a `payroll_notes` line telling payroll to backfill the history in HRM. A **punishable** employee
  keeps the hard stop — there, records must be exact and a guessed rate would itself be a mispayment; and
  an employee with *no salary rows at all* is still fatal under either policy, because there is no rate to
  continue.
- **Missing punches.** `GenerateSalaryCutlistAction` wrote `missing_punch_count × penalty` regardless of the
  policy, while late/early-out deductions checked it. Missing-punch is attendance discipline — it now
  requires `punishable` too. Attendance *counts* still appear on the cutlist (the reviewer must see the
  facts); only the money is switched off. Unpaid leave is deliberately **not** exempted: an unpaid-leave
  deduction is a factual absence, not a punishment.
- **The engine, belt-and-braces.** A stale `missing_punch_deduction` sitting on a non-punishable cutlist row
  (e.g. written by an old run) is now ignored by `PayrollCalculationEngineService`, which gates the column
  on `$cutlistItem->punishable`.

### 15.2 The cutlist: salary-sheet-shaped, review-only, approvable

The generator and `Show` page existed but the "system" didn't: one POST endpoint, one page, no list, no
entry, no lifecycle beyond `generated`, and nothing connected payroll queueing to it. It is now a first-class
desk — **Payroll → Salary Cutlists** (`payroll.cutlists.index`), exactly like the salary sheet in content,
never final until signed off:

- **Statuses**: `draft → generated → approved`, plus the internal `locked`. Migration
  `2026_11_10_000001_payroll_cutlist_review_stage.php` widens the enum and adds `approval_note`,
  `approved_by`, `approved_at` (its `down()` demotes approved rows before narrowing the enum and is
  allowlisted in `MigrationDataSeparationTest` for exactly that reason).
- **Reviewer adjustments are what gets paid.** The engine already reads the five amount columns off
  `payroll_cutlist_items` (unpaid leave / punishment / missing punch deductions, OT and extra-duty pay), so
  `PATCH payroll/cutlists/{cutlist}/items/{item}` edits *those*, requires a `reason` (3–1000 chars), and
  appends a per-row `{at, by, name, reason, changes:{field:{from,to}}}` entry to `source_breakdown.review`.
  Rows with a history show an "N reviewer adjustments" chip and the log inline. Edits are allowed only while
  `generated` and only while no salary sheet has consumed the cutlist (linked sheet queued/processing/ready/
  released, or slips existing → refuse; paid figures change via Sheet Reopens, not by editing the review).
- **Approve / reopen.** Approve requires a note, refuses empty cutlists, records signer + timestamp; reopen
  requires a reason and returns it to `generated`. Regenerating a month that carries reviewer adjustments
  needs an explicit "overwrite" confirmation; regenerating an **approved** month is refused until reopened.
- **The gate, opt-in.** `payroll_settings.cutlist_review_required` (default **off** — legacy runs unchanged,
  which is why `SalaryPaymentPostingTest` / `FullProject1000EmployeeFlowTest` / `PayrollTaxOverrideTest`
  stayed green untouched). While on, `PayrollSalarySheetService::dispatch()` refuses to queue a *new* sheet
  until that month's cutlist is approved — and then **links the approved cutlist onto the sheet**, so the
  batch pays the reviewed rows verbatim instead of regenerating its own. Retrying an existing sheet is never
  blocked. The toggle lives in Payroll → Settings → "Cutlist review".
- **Permissions**: `payroll.cutlists.review` opens the whole review desk except the HRM rebuild trigger
  (`generate` stays `payroll.sheets.manage`). Spatie's `PermissionMiddleware` takes a single permission and
  reads a comma as a *guard* (the route file proved that with "Auth guard [payroll.cutlists.review] is not
  defined"), so the any-of pair is a tiny `EnsureCutlistReviewer` middleware.
- **Error visibility**: `SalarySheets/Create.vue` gained the catch-all loose-errors list (same class as §14:
  the gate throws on key `payroll_cutlist_id`, which no field binds), so the refusal tells the officer what
  to do instead of the button silently failing.

### 15.3 Verification

- `tests/Feature/PAYROLL` → **33 passed (330 assertions)**, including the ten new cases: gap→continuation
  (9 fallback days, full month paid), punishable→still-stops, no-rows-at-all→stops, slip note, zeroed vs
  charged discipline deductions, engine ignoring stale deductions, full lifecycle
  review→adjust→approve→reopen→adjust with every refusal asserted, flag-on refuses queueing until approved
  and then **pays exactly the reviewed 500.00 deduction (net 29,500.00)**, flag-off behaves as before, and
  reviewer-permission gets the desk but 403s on generate.
- `tests/Feature/CORE` → all pass; pins moved honestly: `.vue` census 431→432 (Cutlists/Index), permission
  vocabulary 195→196 (`payroll.cutlists.review`), down()-data allowlist +1 with reason. NativeDialog 45,
  models/money counts unchanged.
- Vitest **371 passed** (menu hygiene scan covers the new sidebar entry + keywords), ESLint clean over
  `modules/PAYROLL/Resources/js` + `menu.ts` + `TreeSidebar.vue`, Pint **PASS 1027 files**.
- Mirror dev DB migrated (`2026_11_10_000001` DONE); deployment = `git apply erp-all-changes.patch` + `php artisan migrate`.

### 15.4 Deliberate edges

- The fallback pays the **nearest known rate**, not a mean of neighbours, and never fabricates a rate when no
  history exists. Every day it happens is counted on the slip so the correction is forced into HRM, not
  hidden in payroll.
- The review gate is opt-in because the audit finding was *visibility*, not enforcement; switching it on is a
  policy decision for the payroll desk, made in one checkbox.
- `generated`-status cutlists still block attendance regularization (`RegularizationService`) — unchanged
  behaviour, and 'approved' was already in its list; this slice makes that list meaningful.

## 16. Fix pairing stopped eating off days; the AMZ demo got its October 2026 calendar

Reported 2026-11-10: *"hrm pipeline nicely handles off day, but 'Fix pair' removes the day which is off
day, so please fix the 'Fix pair' Section"* — plus seed requests for October 2026 (roster for L402003,
two sick leaves, Durga-puja holidays).

### 16.1 The defect and the fix

*(Superseded in part by §18: the repair now runs as a queued job, clamps its range to what the pipeline can
finalise, and restores calendar rows the rebuild cannot derive. The Stage 3 half below stands.)*

Attendance → **Fix pairing** (`AttendanceFixController::fix`) is a scoped rebuild: it deletes every
`attendance_pairs` + `attendance_dailies` row in the scope/range, resets the logs, and replays
`processPending()`. Replaying logs can only rebuild days **with punches**. Off Day / Holiday / On Leave
rows are punch-less by nature — the nightly pipeline creates them in Stage 3
(`AttendancePipeline::stage3PrepareAttendance` = `recalcForDateRange()` +
`AttendanceDayReconciliationService::reconcile()`, which classifies every employee×date from roster
status, holiday calendar and approved leave). Fix never invoked Stage 3, so repairing a range deleted
those rows and left a hole where the pipeline had correctly stamped "Off Day". Fixing Friday made Friday
disappear.

The repair now ends with exactly the pipeline's Stage 3, so it is impossible for the two to disagree:
after `processPending()` it runs `recalcForDateRange()` + `reconcile()`, reports the reclassification
counts in the flash ("Reclassified from the roster: N off days, M holidays, …"), notes that today
finalises with the nightly run (the reconciler's own end-cap, deliberately unchanged), and the dry run
now counts `roster_derived_rows` up front — "N of these daily rows are Off Day/Holiday/On Leave rows
that the rebuild reclassifies from the roster, not from punches" — so the promise is visible *before*
anything is deleted. The page copy says the same.

### 16.2 The seed additions (AMZDemoSeeder)

Requested for the October 2026 payroll/attendance rehearsal:

- **Roster**: `AMZ PM Duty 15-23` (15:00–23:00, punch window 14:00–16:00) assigned to **L402003** for
  **every day 2026-10-01..2026-10-31**, with the five Fridays (2, 9, 16, 23, 30) as roster `status: Off`
  rows, plus `EmployeeFallbackOffDay(Friday)` so the contracted weekly off survives even a roster rebuilt
  without Off rows — both inputs the `OffDayResolver` reads.
- **Approved sick leave**: L402003 on **2026-10-22**, L1009001 on **2026-10-17** (LeaveType `Sick Leave`).
- **Holiday calendar**: **Hindu Durga puja**, Public Holiday, company-wide (`department_id` null), on
  **2026-10-20 and 2026-10-21**.

Running it against this mirror's lived-in dev database also surfaced (and fixed) three *live-data
hostility* bugs in the existing seeder — it used to crash before reaching any of the new sections:

- `Designation::updateOrCreate(['code' => 'SD'], ...)` collided on the **unique slug** when an imported
  designation held `software-developer` under another code. Both demo designations are now
  *reuse-if-exists (untouched), create-if-absent*.
- `Employee::updateOrCreate` rewrote real employees and died on the **unique `biometric_id`** when
  another person held `101`. L1009001/L402001/L402003 are now `firstOrNew`: existing real people are left
  completely alone; policies likewise are created only when missing.
- The `DeviceUser` mapping `updateOrCreate` hijacked live enrolments (`device_users.employee_id` is
  unique; 101/102 are genuinely mapped to other staff here). Mappings are now skipped with a printed
  note when either side already belongs to someone else.

The seeder deliberately does **not** call `reconcile()` itself — reconcile is company-wide (first run
backfilled 30,907 dailies on this DB), which belongs to the nightly pipeline, not to a demo seed. It
prints the exact command instead (`hrm:pipeline --from=2026-10-01 --to=2026-10-31`, or Fix pairing →
apply, which now runs the same Stage 3).

### 16.3 Verification

- `tests/Feature/HRM/FixPairingKeepsOffDaysTest.php` — **5 passed**: a repair rebuilds the punched day
  from logs *and* re-creates the deleted Friday (31 October rows afterwards, one per date); Holiday/On
  Leave/Off Day/Absent statuses all land correctly after wiping the dailies and repairing from raw data;
  the dry run counts `roster_derived_rows` and touches nothing; the seeder lays exactly the requested
  calendar (31 duties, 5 Fridays Off, both leaves Approved, two Public Holidays), is idempotent on a
  second run, and after `pipeline`-style reconciliation shows L402003's Fridays as Off Day and his Oct 22
  as On Leave; and a Fix *applied over the seeded roster* keeps every Friday classified.
- `tests/Feature/HRM` **160 passed**, `tests/Feature/PAYROLL` **33 passed**, `tests/Feature/CORE`
  **252 passed (6,718 assertions)** — one pitfall hit while writing it, worth repeating: a model with
  `casts ['col' => 'date']` **stores** `'Y-m-d 00:00:00'`, so `whereBetween('col', ['2026-10-01',
  '2026-10-31'])` silently drops the upper-bound day and `updateOrCreate(['col' => 'Y-m-d'])` never
  matches its own row (duplicate insert → unique violation on re-run). All date-keyed reads/writes here
  use `whereDate`; the same trap the repo already documents for `AttendanceDaily`.
- `db:seed --class=AMZDemoSeeder --force` on the polluted dev DB: runs clean, prints the left-alone
  notices, and re-runs idempotently. Pint **PASS 1028 files**, ESLint clean on the touched page.

## 17. "and add seeder on main database seeder" — the October calendar moves out of AMZDemoSeeder (2026-11-10)

§16 ended with the October 2026 calendar living inside `AMZDemoSeeder`. That was not what was asked: the
requirement was plain `php artisan db:seed` — the **main** `database/seeders/DatabaseSeeder.php` — and the
two catalogue seeders that already existed but had never been wired up (`RosterShiftSeeder`,
`LeaveTypeSeeder`). Registration, not embedding.

**What changed**

- New `modules/HRM/Database/Seeders/AMZOctober2026CalendarSeeder.php` holds the four requested datasets
  in one standalone-safe class: L402003's roster 2026-10-01..31 with Fridays `status = 'Off'` and the
  shift row `Evening 03PM-11PM` (15:00–23:00, the row `RosterShiftSeeder` already seeds — the ad-hoc
  'AMZ PM Duty 15-23' shift is gone), the Friday fallback off-day, Approved Sick Leave for L402003 on
  2026-10-22 and L1009001 on 2026-10-17 (via the canonical `Sick Leave` type, never by id), and
  company-wide `Hindu Durga puja` public holidays on 2026-10-20/21. People are `firstOrNew` — a live
  employee is left untouched — and no `reconcile()` runs inside a seeder (§16's DBA-incident rule).
- `database/seeders/DatabaseSeeder.php` now calls `RosterShiftSeeder` + `LeaveTypeSeeder` after
  `RosterFallbackShiftSeeder` (reference data) and `AMZOctober2026CalendarSeeder` after
  `RealEmployeeBasicSeeder` (so it enriches imported employees instead of racing them).
  `AMZDemoSeeder`'s inline block was replaced by `$this->call([AMZOctober2026CalendarSeeder::class])`:
  one source of truth, either entry point converges.
- Idempotent by construction: every date-keyed read is `whereDate(...) ?: new Model` (§16's cast pitfall),
  so a second `db:seed` changes nothing.

**Registering those two seeders was a behaviour change, and three things had to follow**

1. `DemoDataSeeder::roster()` asked "does any non-fallback shift exist?" and skipped creating its own
   'General Day' — then looked 'General Day' up by name and inserted duties with `shift_id = 0`, hitting
   the FK (`DemoDataIntegrityTest`). It now probes **its own row by name**. Same class of bug as
   §16's employees/designations: a seeder that assumes an empty table is a seeder that only works once.
2. `DemoDataSeeder::leave()` created leave types blindly → `UNIQUE leave_types.name`. It reuses the
   canonical type when the catalogue already has it (only 'Annual Leave'/'Study Leave' are demo-only) and
   the holiday block became per-name, so Durga puja's presence no longer suppresses Eid/Independence/
   Victory Day. `RosterFallbackShiftSeeder`'s docblock — which recorded *why* `RosterShiftSeeder` had been
   left unwired — was rewritten to record the decision and its consequence.
3. Because the base seed now lays an October roster + real shifts, `syncFromAttendance('Overtime')`
   finally finds overtime to derive (28 rows) and the demo cohort gains an approved extra-duty benefit.
   That exposed a **wrong assertion** in `DemoDataIntegrityTest`: it checked
   `net = gross + benefits − deductions`, but the engine folds benefits *into* `gross_salary`
   (`gross = period_base_gross + sum(benefits)`) and `My/Payslip.vue` shows exactly one Gross line — the
   identity only ever held while demo employees had zero benefits. Fixed to `net = gross − deductions`.
   `roster_overtimes` moved out of `DD_UNCOVERED` into `DD_MINIMUMS` (floor 1), and the twin test
   ("tables were genuinely empty before the demo") now lists the four tables the base seed fills on
   purpose instead of demanding zero.

**Verification**

- `php artisan db:seed --force` (the main seeder, twice) on the dev DB: October rows laid on run 1 and
  byte-identical on run 2 — `duties=31, fridays_off=5, start15=26`, `leave_L402003=1`, `leave_L1009001=1`,
  `durga=2`, one `Evening 03PM-11PM` shift, 6 leave types, L402003 fallback off-day `day_of_week=5`
  (Friday). `db:seed --class=…AMZDemoSeeder` prints the same three lines — same class, no duplication.
- New regression test `the MAIN DatabaseSeeder lays the reference data and the October calendar` in
  `tests/Feature/HRM/FixPairingKeepsOffDaysTest.php` seeds the root `DatabaseSeeder` and asserts shifts,
  leave types, the Friday `Off` duty, the 15:00 snapshot, both approved leaves and both holidays — so
  un-registering any of them fails the build. That file: **6 passed (44 assertions)**.
- `DemoDataIntegrityTest` **9 passed**; then the **whole suite: 900 passed (10,644 assertions)** in
  1,186 s — the first full run of this task, because touching the base seed is exactly the kind of change
  a per-module sweep cannot see. Pint **PASS** on every touched seeder/test file (one `fully_qualified_strict_types`
  fix applied).

## 18. A repair is a job, not a request; and one employee with no salary record must not sink the month (2026-11-10)

Two field reports, one of them a correction of §16.

### 18.1 `RelationNotFoundException: Call to undefined relationship [reviewer] on model [Modules\HRM\Models\AttendanceRegularization]`

`RegularizationController::index()` has eager-loaded `reviewer:id,name` since the review desk was built
(migration `2026_11_03_000072` created the `reviewed_by` FK to `users` in the same breath), but the
relation method was only ever added to *that* migration's mental model, not to the class. The queue page
therefore 500'd on `GET /hrm/regularizations` for everyone — while the six tests written around
regularization all POSTed and never once opened the list. The relation is now declared (`belongsTo(User::class,
'reviewed_by')`, `Modules\CORE\Models\User`), and the new test asserts both shapes: an approved request
carrying the reviewer's name, and a pending one carrying `null`.

### 18.2 "Fix Attendance Pairing will be a job which will run on background — it destroys the other status record"

§16 made the repair end with pipeline Stage 3, which fixed the *classification* half. The report came back
anyway, with the mechanism spelled out: a month is 28–31 daily rows, off day / leave / holiday all have a
row, and after a Fix only the days with punches remain. Two causes, both real, both fixed:

1. **Nobody owns a month-sized rebuild inside an HTTP request.** The clear was committed in its own
   transaction and the rebuild ran after it, synchronously. A department or global scope reconciles
   ~1,000 employees × 31 days; PHP-FPM/nginx cut the request, and the database was left cleared and
   unrebuild — exactly "the other status records are gone". Applying now dispatches
   `Modules\HRM\Jobs\FixAttendancePairingJob` (`tries = 1`, `timeout = 1800`, `ShouldBeUnique` +
   `WithoutOverlapping('attendance-pairing-fix')`) and the page polls `GET /hrm/attendance/fix/status`
   (the route also refuses a second apply while one is `running`, since the uniqueness lock would fail
   silently). Progress is written to `Cache` under `AttendanceRepairService::STATUS_KEY` — the page and the
   worker are different processes, so it could not live in memory. `CACHE_STORE=database` in this app, so it
   survives; `QUEUE_CONNECTION=database` means the work genuinely leaves the request. Closing the tab is now
   harmless, which is the point.
2. **A rebuild can only rebuild what it can re-derive**, and it used to clear more than it could derive:
   * the range is now **clamped** to `min(to, yesterday)`, the reconciler's own finalisation line (it refuses
     to finalise today); previously `to = today` deleted today's and any future row with no way back. A range
     entirely beyond the line reports `nothing_finalisable` and deletes nothing;
   * `AttendanceProcessingService::recalcForDateRange()` compared the date-cast `duty_date` with
     `whereBetween('duty_date', [$from, $to])`, so **the last day of every range was recalculated by nobody**
     — the pair existed, the reconciler steps aside for days that have a pair, and the day ended up with no
     daily at all. Bounds are now widened to whole days (the §16 cast trap, in a place the §16 tests did not
     reach: their punch days were never on the range's last day);
   * and a **snapshot/restore** pass makes loss structurally impossible: before anything is deleted, every
     punch-less row with a calendar status *plus every approved-regularization day* (Stage 3 does not
     reproduce `Present` from a regularization — its vocabulary is Off Day/Holiday/On Leave/Absent) is kept
     column-for-column. Afterwards a snapshotted day that has **no** row is re-inserted verbatim; one whose
     only row is a **synthetic** `Absent` (punch-less, the "No attendance logs …" remark) is put back as it
     was. Anything the rebuild produced from real punch evidence wins — the repair changes days it can see
     and nothing else. The report says how many were kept (`restored` / `replaced`), and the Fix page shows
     it as chips under the result.

All of it lives in `Modules\HRM\Services\Attendance\AttendanceRepairService` (no session, no flash, no HTTP),
so the job, the console and the tests drive one path; the controller keeps only validation, the dry-run
preview and the guards.

### 18.3 "still salary sheet not work — No HRM salary-history record covers M302012 on 2026-10-01"

The message was *correct* and the sheet was still broken, because two selection methods disagreed:
`countEmployeesForPayroll()` counts only employees with a salary row overlapping the period, while
`employeesForPayroll()` — the batch the queued sheet and the cutlist walk — had **no such filter**. Every
active employee without a rate was thus nobody's business in the count and then threw in the batch, marking
the whole month `failed`: 14 such employees in a 998-person import (`M302012` "Dr. Shanawaz Ibne Ambiya",
joined 2018, zero `employee_salaries` rows, zero policy rows), so October could never be run at all. The
same disagreement ran the other way for the two employees payable through §15's rate continuation — paid by
the batch, refused by the count — which is also what `PayrollSalarySheetService::release()`'s slip-count
check compares.

One private scope, `payrollable($periodStart, $periodEnd)`, now feeds both: *a salary row overlaps the
period, OR the employee has a salary record anywhere and no punishable HRM policy* — exactly the condition
`mapEmployee()` uses to decide "pay at the nearest known rate" vs "stop". So an employee with no rate at all
is left out of the sheet rather than failing it, and a punishable policy still stops a *single-employee*
request (an adjustment, a settlement) because there is no batch to protect there.

Being skipped is not the same as being forgotten: `unpayableForPeriod()` returns the complement with an
actionable reason per person ("No HRM salary record at all — add one under HRM → Employees → Salary history",
or "Salary history does not reach this period (2026-11-05 → open)"), exposed on the interface and rendered
on **both** payroll screens — before the run on `SalarySheets/Create`, and after it on `SalarySheets/Show`.
The hard-stop message also names the remedy now, not just the offence.

### 18.4 Verified against the real October data, not only the fixtures

Unit tests prove the rules; the two defects were both about a month-sized dataset, so both were run again
against the mirror copy of the development database (998 employees, seeded October 2026 calendar) end to
end. A stale `ShouldBeUnique` lock in `cache_locks` — left behind by an earlier interrupted run — silently
swallowed the first dispatch attempts, so the numbers below come from runs after `cache_locks` was cleared.

| check | before | after |
| --- | --- | --- |
| `attendance_dailies` rows, 2026-10 | 30 907 | 30 907 |
| Off Day / Holiday / On Leave rows | 5 / 1 994 / 2 | 5 / 1 994 / 2 |
| snapshot rows lost by the repair | — | **0** |
| snapshot calendar rows whose status changed | — | **0** |
| L402003 · 16 / 20 / 22 Oct | Off Day / Holiday / On Leave | Off Day / Holiday / On Leave |

The repair ran as a queued job — `FixAttendancePairingJob … 786.40ms DONE` in the `queue:work` log — and published
`state=done` under the status key with `calendar_rows_at_risk: 2001` and `restored/replaced: 0` — nothing had
to be reinstated because nothing was destroyed. The queue lock is released by the worker afterwards
(`cache_locks` back to 0 rows).

Payroll, same database, October 2026: `countEmployeesForPayroll` **981** = the batch it produced **981** =
slips written **981**; `status=ready`, `failure_message` empty, wall 3.1 s, net total 15 876 589.81, no
null/zero net, deductions 0.00 because that database has no punishable policy (the §15 rule). The 14 people
with no usable rate are the ones named by `unpayableForPeriod()` — including `M302012`, the employee quoted in
the bug report — and they are listed instead of failing the month.

Two consequences of that run were fixed rather than explained away:

1. **count and batch are now equal by construction.** `mapEmployee()` also drops anyone who *joins after* the
   period, so the projection was 983 counted against 981 batched; the release step compares counts and would
   have refused the sheet. `whereNull('joining_date')->orWhereDate('joining_date','<=',$periodEnd)` is now in
   both queries, and the test asserts a December joiner appears in neither the batch nor the warning list.
2. **a dead worker must free the lock.** `ShouldBeUnique` holds its lock until `uniqueFor` (1 h) when the job
   dies, so the next click on *Fix pairing* would be dropped with a success toast and no job — the exact
   silent no-op observed above. `FixAttendancePairingJob::failed()` now calls
   `(new UniqueLock(Cache::store()))->release($this)` before writing `state=failed`, and a test holds the lock,
   calls `failed()`, and re-acquires it.

### 18.5 The hand-written query builder is where the last bug was (2026-11-12)

Asked whether the service reaching for `DB::table(...)` instead of the models was acceptable under SOLID,
the answer came back with a defect attached, so it is worth recording both.

`AttendanceRepairService` mixes two access styles on purpose: bulk set-based work through the model
(`pairScope()`, `dailyScope()`, `AttendanceDaily::query()->insertOrIgnore()` chunks) and two verbatim row
reads through the base builder, because a snapshot has to copy *columns*, not attributes — an `AttendanceDaily`
model would hand back `duty_date` as a `Carbon` and would fire events on 30 000 inserts. What it should not
have was **two syntaxes for one rule**: the model scopes used `whereDate('duty_date','>=',$from)`, while the
raw reads used `whereBetween('duty_date', [$from.' 00:00:00', $to.' 23:59:59'])`.

`attendance_dailies.duty_date` is a `date` column, so on the range's first day the raw predicate evaluates
`'2026-10-01' >= '2026-10-01 00:00:00'` → **false**: the clear (model scope) deleted day 1, the snapshot (raw
builder) had never read it, and every punch-less Off Day / Holiday / On Leave / approved-regularization row on
the 1st of the month was downgraded to a synthetic `Absent` — precisely the complaint the section was written
to answer, surviving in the fix itself for exactly one day of the month. The same wrong predicate was also in
`reinstateCalendarRows()`' comparison read, so the restore could not have seen the row either.

Fixed by deleting the duplication rather than patching one of the copies: `dailyRange($employeeIds, $from, $to)`
is now the only place that decides which daily rows belong to a repair scope, and the snapshot, the comparison
read and (via its model twin `dailyScope()`) the clear all take their bounds from it. `attendance_logs.punch_time`
keeps its `whereBetween(… ' 00:00:00' …)` — that column really is a `dateTime`, which is the whole reason the
mistake was easy to make, and why the rule belongs in one place instead of two.

Pinned red→green by `the safety net covers the first day of the range, not just the middle`
(`AttendanceRepairRunsOnQueueTest`, 9 passed; `tests/Feature/HRM` + `tests/Feature/PAYROLL` 208 passed;
pint PASS over 422 files). The lesson recorded here is not "never use the query builder" — for a set-based
rewrite of a month the builder is sometimes the right tool, and the retry-wrapped
`AttendanceDaily::query()->insertOrIgnore($chunk)` inside `DB::transaction(…, 3)` is deliberately the same
shape {@see AttendanceDayReconciliationService} already uses. What the module does *not* do is read through
`DB::table`, and the two reads that did are where the bug lived: `AttendanceRepairService` was the only file
under `modules/HRM/Services/Attendance` with raw table reads at all. So the rule to take from this is
narrower than "follow SOLID" and stricter than "it is fine": when policy and persistence share a file, the
*scope* predicate must have exactly one owner, and reads should go through the model that owns the cast.

### 18.6 The repair now depends on a port, and the predicate mistake is guarded (2026-11-12)

Follow-up to §18.5, chosen deliberately rather than left as a lesson learned: the repair's persistence was
put behind the module's own repository convention, and the mistake that slipped through it became a test.

**The port.** `Modules\HRM\Interfaces\Attendance\AttendanceRepairRepositoryInterface`, implemented by
`Modules\HRM\Repositories\Attendance\AttendanceRepairRepository` and bound in `HRMServiceProvider` beside
the ten `Attendance*`/`Roster*` bindings that were already there — so this is not a new architecture, it is the
module's existing one applied to the file that had skipped it. The service's constructor is now
`(AttendanceProcessingService, AttendanceDayReconciliationService, AttendanceRepairRepositoryInterface)` and
it writes **no SQL at all**: 4 `DB::table` uses and 2 hand-built range predicates left it, and the only raw
builder reads in the module's attendance layer now sit in the repository, where they are explained — a
snapshot has to carry *columns* (`is_regularized`, `remarks`, the punch datetimes) through a delete and a
re-insert without a cast or a mutator touching them, and a month-sized restore is ~1 000 employees × 31 days
of work that `save()` would turn into tens of thousands of statements and events.

**The single owner.** `Modules\HRM\Support\Attendance\AttendanceRepairScope` (new) is the one place that turns
"from, to, employee list" into bounds: `applyDateRange()` for the `date` columns (`attendance_dailies`,
`attendance_pairs`) and `applyPunchWindow()` for the `dateTime` one (`attendance_logs.punch_time`), plus
`windowStart()/windowEnd()` for collaborators whose signature wants datetimes. The service, its Eloquent
reads and the repository all go through it, which is the actual fix: two files agreeing by luck is what broke.

**The guard.** `tests/Feature/HRM/AttendanceRepairArchitectureTest.php` (4 tests) pins the shape:

- the service contains no `DB::`, no `::query()`, no `whereDate/whereBetween/whereIn`, and does depend on the
  interface — a policy file that starts hand-writing SQL re-breaks this;
- the range predicate appears in exactly one file, with the `duty_date` and `punch_time` forms asserted there;
- **no `date` column anywhere in `modules/`, `app/` or `database/` is compared against a time-suffixed
  bound** (Class A, the bug above), and no `dateTime` column is bounded by bare dates (Class B, the mirror
  mistake that would drop everything after midnight on the last day). The scan tokenizes each file, so the
  comments in this very section — which quote both anti-patterns at length — cannot be mistaken for code.

**The sweep.** A standalone version of that scan ran over the whole tree against 187 tables' column types
(`~/tools/audit-date-predicates.py`): **0 findings**. The single line it flagged during the work,
`AttendanceDayReconciliationService:54`'s `whereBetween('punch_time', [$start->copy()->startOfDay(),
$end->copy()->endOfDay()])`, is correct — Laravel formats those Carbon bounds as `'Y-m-d H:i:s'` — and both
tools now treat `startOfDay()/endOfDay()/setTime()` as time-carrying rather than reporting it. The
detector's own teeth were checked with a canary file: put the old line back and the sweep reports
`CLASS A: first day of the range never matches`; remove it and it is silent. So "0 findings" is a result,
not an empty scan.

---

## 19. `queue:work` died at 300 s inside a device sync — the limit war and the per-punch queries (2026-11-13)

Reported from the operator's own machine (Windows):

```
php artisan queue:work
  12:37:55 Modules\HRM\Jobs\SyncDeviceJob ....... RUNNING
php artisan queue:work
  12:38:16 Modules\HRM\Jobs\SyncDeviceJob ....... RUNNING
PHP Fatal error:  Maximum execution time of 300 seconds exceeded in …\Illuminate\Foundation\Bootstrap\HandleExceptions.php on line 261
```

The job *already* tried to be well behaved — `SyncDeviceJob::handle()` opens with `@set_time_limit(0)`, then
`@ini_set('max_execution_time','0')`, then a redundant `if (function_exists(...)) { @set_time_limit(0); }`.
None of it survived the call it made next: `AttendanceSyncService::syncDevice()` began with

```php
@set_time_limit(300);
@ini_set('max_execution_time', '300');
```

— **re-arming a 300-second limit inside a process that had just removed its own.** On Windows
`max_execution_time` counts wall-clock (on Linux it is CPU time, which is why this never showed up in the
sandbox), so the first device that took more than five minutes killed the worker mid-transfer. The job's
`finally` that set the limit back to `0` never ran, because a timeout fatal is not an exception. The second
`RUNNING` 21 seconds later is the same device: the reserved job went back on the queue.

It was also *designed* to overrun that limit. The ingest loop had no bound at all, and the only guard was
`if (microtime(true) - $start > 250)` in the **processing** loop — 250 s of pairing on top of however long the
device took to send its logs and however long the ingest took, inside a 300 s cap. And the ingest was slow for
a reason of its own: **three queries per punch** (`Employee` by `biometric_id`, `DeviceUser` by
`device_user_id`, and an `AttendanceLog::…->exists()` duplicate check). A first sync of a 50 000-record F18 is
~150 000 statements. Nothing survives that in any language; the log's own comment ("50k+ records can take
60s+") was already optimistic.

### 19.1 Who owns the timeout

One rule, now applied to every call site in the module: **the process that owns the loop owns the limit.**

- queue jobs (`SyncDeviceJob`, `SyncRawLogsJob`, `PrepareAttendanceJob`, `MakePairsJob`,
  `ProcessSalarySheetJob`, `FixAttendancePairingJob`) keep `set_time_limit(0)` and nothing else — `SyncDeviceJob`'s
  three redundant calls collapsed into one, with the reason in a comment;
- `SyncAttendanceCommand` (cron) had `@set_time_limit(120)`, which was *shorter than the default 30 s × 4 of
  the things it calls* and cut large first syncs in half — it is `0` now;
- HTTP entry points (`AttendanceSyncController`, `AttendancePipelineController`) keep their own
  `set_time_limit(300)`: raising a limit for one request is that caller's right, and it is unchanged behaviour;
- **no service or repository may touch it.** All five sites in `AttendanceSyncService`
  (`syncDevice`, `syncDeviceRawOnly`, `fetchViaJmrashed`, `fetchUsers`, and the two `finally { set_time_limit(0) }`
  clean-ups) are gone. `fetchViaJmrashed` keeps `default_socket_timeout = 60` — that is the *socket*, which is
  the thing that actually blocks, and the vendor library needs it.

`tests/Feature/HRM/DeviceSyncBudgetTest.php` pins this with a tokenized source scan over
`modules/HRM/{Services,Repositories,Support}`: `set_time_limit` with any argument other than `0`, or
`ini_set('max_execution_time', …)` with anything but `'0'`, fails the suite. Comments are stripped first, so
this section's code samples can't be mistaken for calls.

### 19.2 A budget that stops between records

`hrm.sync.budget_seconds` (env `HRM_SYNC_BUDGET_SECONDS`, default 240) is now the bound. Both `syncDevice()`
and `syncDeviceRawOnly()` take an optional `$budgetSeconds`, and when the deadline passes they stop **between
records**, which is the part a fatal error cannot do:

- `partial => true` in the result, `PARTIAL: time budget reached…` in the operator's message;
- the sync log row is written with status **`Partial`** — the `attendance_sync_logs.status` enum already
  allowed it, and the page renders an amber `AlertTriangle` for anything that isn't Success/Failed, so the new
  state shows up with no frontend change;
- **`last_sync_at` is not advanced**, and `next_sync_at` is set a minute out. This is the correctness half:
  a partial sync that moved the incremental window forward would skip the punches it never read, forever.
  A `last_sync_at` that only a finished sync updates means "retry from where we actually got to".

### 19.3 Set-based ingest

The ingest used to run three queries per punch — `Employee` by `biometric_id`, `DeviceUser` by `device_user_id`,
and an `exists()` for the duplicate check. It now resolves all three as sets, once per batch: `Employee` keyed by
`biometric_id`, `DeviceUser` keyed by `device_user_id`, and the `(device_user_id, punch_time)` keys the device
already holds. Accepted keys are added to the set as the loop goes, so two punches in the same second *within one
batch* are still caught — the per-row `exists()` got that for free and a set does not.

The dedupe read is deliberately **not** narrowed to the batch's own time span. A first cut did exactly that, and
it was wrong: the device hands back its whole log buffer and the window is applied client-side, so a punch outside
the requested window is still one this sync has seen before — narrowing the read would have quietly
reintroduced duplicates. (`§20` supersedes the *shape* of this read, not that conclusion: the method is now
`ingestDeviceLogs()`, and both the mapping lookup and the dedupe read are chunked, so no statement scales with the
device's total stored volume.)

The dedupe is measured, not asserted vaguely: the test counts SQL *shapes* and requires zero
`select exists(…"attendance_logs"…"punch_time" = ?)` and zero per-row `from "device_users" where
"device_user_id" = ?`, and one `where in` per mapping lookup and per 500 candidate rows.

### 19.4 What to run locally

The `php -d max_execution_time=0` hint that used to be printed in the "Sync queued" flash message was wrong
in two ways — a queue worker has no execution limit by default, and `--timeout=300` needs `ext-pcntl`, which
a stock Windows PHP does not have (so it silently does nothing). The messages now say:

```
php artisan queue:work --sleep=3 --max-time=3600
```

`--max-time` makes the worker exit *cleanly* before anything else kills it, which is what you want on a
machine where a hard kill leaves the reserved job to be retried by the next start. If a device is genuinely
offline, nothing about timeouts matters: `isReachable()` bounds it to a few seconds and the sync logs
`Failed`.

### 19.5 The same arithmetic one level up: a job may not outlive its reservation

While wiring the timeout story, one more mismatch of the same family turned up in the queue config — and it
applies to jobs this session added, not only to the sync:

`queue.connections.database.retry_after` is **400** in this app (raised from Laravel's 90 precisely so long
attendance work is not double-run). A worker reserves a job for that long; if the job is *still running* when
the window closes, the queue treats it as lost and hands it to a second worker. That is not a retry after a
failure — it is two workers doing live work at once. Three jobs had a declared ceiling above the window and no
override of their own:

| job | `$timeout` | reserved for | now |
| --- | --- | --- | --- |
| `FixAttendancePairingJob` | 1800 | 400 | `$retryAfter = 1860` |
| `ProcessSalarySheetJob` | 900 (and `tries = 3`) | 400 | `$retryAfter = 960` |
| `SyncDeviceJob` | 420 | 400 | `$retryAfter = 480` |

Each value is its own timeout plus the 60 s grace the files already use for `WithoutOverlapping(…)->expireAfter($this->timeout + 60)`,
so one number explains both. `MakePairsJob` (300), `SyncRawLogsJob` (300) and `PrepareAttendanceJob` (120) sit
inside the connection window and need nothing.

`tests/Feature/CORE/QueueReserveWindowTest.php` pins it: every `ShouldQueue` job under `modules/` or `app/`
whose class declares a `$timeout` must have a reserve window (its own `$retryAfter`, else the connection's) of
at least `timeout + 60`, and a job whose timeout exceeds the connection window may not rely on `--timeout`
either — that flag needs `ext-pcntl`, which a stock Windows PHP does not have, and it kills the worker instead
of stopping the unit of work. Comments are tokenized away before scanning, because the docblocks quote these
numbers deliberately. The guard was checked by deleting `SyncDeviceJob`'s new property in the mirror and
re-running: both tests fail with `timeout 420s but reserved for 400s — a job still running at 400s is handed
to a second worker`. Restore it and they pass.

---

## 20. `--from=2026` printed "PARTIAL" on every device — the argument was the fault, not the log volume (2026-11-13)

**Symptom.** Right after §19 landed, the operator tried the full pull on Windows and got this for all three F18s,
in about one second each:

```
Device: ZKT-01 (192.168.1.200:4370) - PARTIAL
  Stage 1 Raw  : {"ok":false,"fetched":0,"inserted":0}
  Stage 2 Pairs: {"processed":0,"new":0,"closed":0,"backfilled":0,"orphan_skip":0,"errors":0}
  Stage 3 Daily: {"recalculated":0}
```

with the reading that "the devices have lots of logs, so the sync choked on them".

**Cause — two defects, not one.** Both had to be true for the output to look like a device problem.

*The argument was never a date.* `Carbon::parse('2026')` does **not** throw — measured, not assumed:

```
2026         => 2026-11-13 20:26:00     (a bare 4-digit number is read as a clock time)
2026-11      => 2026-11-01 00:00:00
2026-02-31   => 2026-03-03 00:00:00     (an impossible day rolls over instead of failing)
```

So `--from=2026` quietly became *"from today at 20:26"*. That value is then used as the client-side window filter
in `fetchViaJmrashed` (line ~413, `Carbon::parse($fromDate)`) and as the Stage 3 recalculation range — every punch
the device returned, from January onward, was earlier than that instant and was filtered out. `fetched: 0` was a
correct count of a nonsense window. A silent mis-parse is worse than an exception here: nothing in the output said
the start date had been invented.

*The console hid the rest.* `ok:false` on all three devices did not come from the empty window — the stage either
threw or was refused, and `syncDeviceRawOnly`'s `catch` had already turned that into `['ok' => false, 'message' =>
$e->getMessage()]`. `printResult()` printed none of it, filled the missing counters with `?? 0`, and labelled the
run `PARTIAL`. The text was not lost, only buried: the same `catch` writes it to `attendance_sync_logs.error` (via
`logSync(…, 'Failed', …)`) and to `laravel.log` as `ZK Sync failed`, which is where an operator should not have to
go to read a message the command already had in hand.

**Fix — refuse the argument, not the run.** `Modules\HRM\Support\Attendance\DailyDateArgument` validates
`--from` / `--to` once, for both `hrm:pipeline` and `hrm:sync-attendance`, **before a device is contacted**:

- accepted: `2026-11-01`, `2026-11-01 07:00`, `2026-11-01 07:00:00` (checked with `checkdate`, so
  `2026-02-31` is refused rather than rolled over), and the words `today` / `yesterday` that
  `hrm:sync-attendance --since=` has always taken — the legacy `if ($from === 'today')` branches in
  `SyncAttendanceCommand` moved into `resolve()` and were deleted;
- refused: a bare year or month, deliberately. `Carbon` turns `2026` into *a time of day* and `2026-11` into the
  1st of the month, and neither is what an operator means; with `--to` defaulting to today, a guessed start also
  changes how many months of punches are pulled through three stages. A wrong-but-accepted value is worse than a
  refusal, so the refusal prints the exact command to retype and exits 1.

`RunAttendancePipelineCommand::printResult()` now also prints `Stage N reason: …` for any stage that came back
`ok: false`, taken from the stage's own `message`/`error`. `ok:false` on a terminal is a summary, not a diagnosis,
and the operator should not have to open `storage/logs/laravel.log` to find out that they mistyped a flag.

**Fix — the ingest is bounded in memory as well as in time.** §19 replaced three queries *per punch* with one
prefetch that read **every existing punch for the device** into a PHP array. On the "device has months of logs"
case that is the same outage with a different error message: a 100 000-row collection, every row cast to Carbon,
inside the 240 s it is supposed to fit in. Both call sites — `syncDevice()` and `syncDeviceRawOnly()` had grown
near-duplicate 60-line ingest blocks — now call one `ingestDeviceLogs(BiometricDevice, array $fetched, float $deadline)`
that runs two bounded passes:

1. **normalize, no I/O** — device id + `Y-m-d H:i:s`, unparseable timestamps dropped with a warning, and
   same-second repeats from the device skipped in memory (the old per-row `exists()` used to catch those because
   the earlier row had just been inserted);
2. **persist in chunks of 500** — the mapping lookup is 1 statement per 1 000 distinct device ids, the
   "already stored?" read is 1 statement per 500 candidate rows bounded by `whereIn device_user_id` **and**
   `whereIn punch_time`, and the write is one bulk `insert`. The existence check goes through
   `DB::table('attendance_logs')`, not `AttendanceLog`, because the model casts `punch_time` and this comparison
   wants the string exactly as the database holds it.

The deadline is re-checked at the top of each loop, so a partial sync stops cleanly between records and reports
`partial: true` — which still means `last_sync_at` is not advanced (§19), and because the skip path is now a
500-row set read instead of 500 `exists()` calls, **a re-run costs a fraction of the pass that filled the budget**.
That is what makes a months-long backfill converge instead of restarting.

**Fix — stop the console inventing a diagnosis.** Two more lies were being printed on the way out, both of them
pointing at "device full of logs":

- the header said `PARTIAL` for *any* non-OK run, because `RunAttendancePipelineCommand::printResult()` and
  `AttendancePipeline::buildResult()` both reduced the whole pipeline to `ok ? 'OK' : 'PARTIAL'`. `PARTIAL` is the
  budget state — "some of it landed, come back for the rest" — so it was telling the operator to expect a backlog
  when the run had in fact read nothing. Both now print `FAILED` unless a stage actually reported
  `partial: true`, which is the only case where retrying is the right advice;
- the summary line was built with `?? 0` on every counter, so a stage that threw before counting anything printed
  `{"ok":false,"fetched":0,"inserted":0}`. `fetched: 0` is a measurement — "the device answered and had nothing
  in the window" — and printing it for a device that never answered is how a mistyped flag turned into a
  hardware investigation. The line now prints only the keys the stage returned; the numbers appear when there are
  numbers.

### 20.1 What the *next* run will say, and the pre-flight that makes it shorter

The corrected command was re-run against a real device and Stage 1 still failed — but the output that came back
was from the pre-fix build: no `Stage 1 reason:` line, and a failure still labelled `PARTIAL`. That is worth
recording as the diagnostic itself, because it is how you can tell which build you are looking at.

With the fix in place the same run prints the reason the sync had all along. Three messages account for an
*instant* fleet-wide `ok:false`, and they are the three checks `fetchFromDevice` makes before it reads anything
(`AttendanceSyncService.php:272-301`, `fetchViaJmrashed:306-313`, `getAttendance` fallback `:408`):

| printed reason | what it means | how it is fixed |
| --- | --- | --- |
| `PHP sockets extension not enabled - required for ZKTeCo UDP communication` | the first line of `fetchViaJmrashed`, before any network call — so every device fails in ~0 s. `ext-sockets` is **disabled in a stock Windows `php.ini`** | remove the `;` before `extension=sockets` in the ini `php --ini` names, reopen the terminal |
| `ZKTeCo package not installed. Run: composer require jmrashed/zkteco …` (the pre-flight now prints its own one-line version of this before the loop) | neither `Jmrashed\Zkteco\Lib\ZKTeco` nor `TahaRafi\Zkteco\Lib\ZKTeco` exists, and `APP_ENV` is not local/testing, so the demo fallback is refused | `composer require jmrashed/zkteco` |
| `Device <name> (<ip>:<port>) unreachable - check IP/firewall` | `fsockopen` pre-check (3 s) failed *and* simulation is not allowed | same subnet / UDP 4370 open; the device's own IP is `192.168.10.91` here, not the `192.168.1.x` the earlier test notes assumed |

The first of those is the shape of the reported output — three identical failures, all sub-second — and a
configuration mistake is not something a per-device error line should be left to imply. So `hrm:pipeline` now
**checks the environment before the first device** (`preflightMessage()`): a required extension missing, or no
ZKTeCo client installed, is one error line and exit 1, printed *instead of* three empty device summaries. With
`--queue` it warns and dispatches anyway (the worker's PHP is a different process and may well have the
extension), and `--stage=pairs` / `--stage=daily` are never gated, because those do not open a socket.

The list is configuration — `hrm.sync.required_extensions`, default `['sockets']` — so the guard is testable with
an extension that genuinely is absent, rather than being skipped on machines that happen to have one.

**Tests.** Eight added to `tests/Feature/HRM/DeviceSyncBudgetTest.php`: `--from=2026` exits 1 and says "is not a
day" without writing a single `attendance_sync_logs` row; the accepted/refused shapes above are pinned directly
on `DailyDateArgument`; a stage that fails prints its `message`; and with **6 000 punches already on the server**
for the device, one sync issues exactly one key-read and one bulk insert, and no `select exists(...)` against
`attendance_logs` at all; and the two console labels are pinned with a fake stage-1 result — a bare
`{ok:false, message}` must print `FAILED` with its reason and must not print `fetched`, while
`{ok:false, fetched:51000, partial:true}` must print `PARTIAL` with `"fetched":51000`; and the pre-flight is
pinned by faking a missing extension — exit 1, `is not enabled`, and *no* `Running pipeline for` line, plus
`--stage=daily` still running ungated. Red-checked both ways in the mirror: with the `printResult` label reverted
the failure test fails, and with the pre-flight block deleted the command dies in `ModelNotFoundException`
instead of refusing.

**What the suite caught on the way.** The scripted edit that replaced the two ingest blocks over-matched: in
`syncDevice()` its anchor ran past the ingest into the tail of the method, so the `processPending()` pairing loop
and the `recalcForDateRange()` stage-3 call were deleted along with it — and `$recalculated` was then referenced
by the summary line, which Laravel turns into an `ErrorException` that the method's own `catch` reported as
`{"ok":false,"message":"Undefined variable $recalculated"}`. Two tests in `AttendanceEngineTest` ("can trigger
sync for single device", "attendance sync service validates sockets extension") went red on exactly that, which
is how it was found before it could reach a device. Both blocks are restored verbatim from the §19.5 state, with
`$deadline` in place of the old `250 s` guard. Reconstructing the prior version was a one-liner worth remembering:
the shipped `~/erp-all-changes.patch` is itself a snapshot, so `git show <base>:<file>` plus that file's slice of
the patch rebuilds any earlier state, and `diff -u` against it shows precisely what an in-place edit destroyed.
It also caught a real trap in the shape check: `checkdate` on a *parsed* Carbon date is useless for
`2026-02-31`, because Carbon rolls it over to March 3 — the day is validated from the digits the regex captured.

`DeviceSyncBudgetTest` + `SilentFailureInventoryTest` + `QueueReserveWindowTest` → **13 passed (70 assertions)**;
`AttendanceEngineTest` → **18 passed**; `pint --test modules/HRM modules/PAYROLL tests/Feature` → **PASS 499
files**. The new `--from` test was red-checked by deleting the validation block from
`RunAttendancePipelineCommand` in the mirror: `Failed asserting that 0 matches expected 1` (the command exited 0
and never said "is not a day"); restored, it passes in 0.9 s.

**Operator note for the real backfill.** The devices do have months of logs to pull, and `--from=2026-01-01` is
the right way to say so — but run it one device at a time (`--device=<id>`), and expect several `PARTIAL` passes
of about `hrm.sync.budget_seconds` each with `last_sync_at` unchanged between them. That is the budget working,
not a fault; `Partial` is not an error state, and the UI shows it in amber for exactly that reason. Prefer
walking the range in weeks: `fetchFromDevice` downloads the device's whole log buffer on *every* pass (the ZK
protocol filters nothing server-side), so a year-long window pays that download once per pass. The stages can
also be run separately — `--stage=raw --device=<id>` until it stops reporting new inserts, then `--stage=pairs`,
then `--stage=daily --from=… --to=…`.
