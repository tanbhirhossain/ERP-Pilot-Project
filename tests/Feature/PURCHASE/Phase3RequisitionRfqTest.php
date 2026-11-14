<?php

/**
 * Phase 3 — F18 purchase requisition + RFQ.
 *
 * What this file is really testing is the *separation* the two documents exist to create, because the
 * arithmetic on a requisition is trivial and would be a thin excuse for the slice:
 *
 *   - a requisition decides nothing financially: no journal, no stock move, ever, at any status;
 *   - the answers to "who may ask" and "who may decide" are different people, enforced in code rather
 *     than left to a policy an admin might forget to switch on;
 *   - a budget breach is a refusal with a named override held by the approver, not a warning a
 *     requester can walk past;
 *   - a quote is a price with an expiry, and an award that cannot honour the number it was compared on
 *     is refused rather than accepted quietly;
 *   - and the award produces a **draft** purchase order through `PurchaseOrderService`, so the approval
 *     matrix and the confirm permission still stand between "cheapest bid" and "we bought it".
 *
 * Helpers are prefixed `p3rq`; Pest loads every Feature file into one process.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Budget;
use Modules\ACCOUNTS\Models\BudgetLine;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\ApprovalPolicy;
use Modules\CORE\Models\ApprovalRequest;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Models\PurchaseRequisition;
use Modules\PURCHASE\Models\PurchaseRfq;
use Modules\PURCHASE\Models\PurchaseRfqAward;
use Modules\PURCHASE\Models\PurchaseRfqQuote;
use Modules\PURCHASE\Services\PurchaseRequisitionService;
use Modules\PURCHASE\Services\PurchaseRfqService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Permissions come from the module seeder, so a grant renamed in config is a red test here rather
    // than a page that 403s in production.
    $this->seed(SuperAdminSeeder::class);

    foreach (config('purchase.permissions') as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/* ───────────────────────────── fixtures ───────────────────────────── */

/** A user holding exactly these grants — the shape of a real, narrow role. */
/**
 * A user holding exactly these grants — the shape of a real, narrow role.
 *
 * The role name is salted per call rather than per label: several tests mint two "Approver"s (one to
 * approve, one to be refused), and a shared Spatie role would quietly merge their permissions and make
 * the refusal test pass for the wrong reason.
 */
function p3rqUser(array $perms, ?string $name = null): User
{
    $slug = Str::slug((string) $name.'-'.Str::random(8));
    $role = Role::create(['name' => "p3rq {$slug}", 'guard_name' => 'web']);
    $role->givePermissionTo($perms);

    $user = User::create([
        'name' => $name ?? 'P3rq User',
        'email' => 'p3rq-'.Str::lower(Str::slug((string) $name, '')).'-'.Str::random(8).'@example.test',
        'password' => bcrypt('not-the-default'),
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function p3rqActor(): User
{
    return p3rqUser([
        'purchase.requisitions.view', 'purchase.requisitions.manage',
        'purchase.rfq.view', 'purchase.rfq.manage',
        'purchase.orders.view', 'purchase.orders.manage',
    ], 'Store Keeper');
}

/** Warehouse + a priced stockable product + a vendor. */
function p3rqFixtures(): array
{
    static $n = 0;
    $n++;

    $warehouse = Warehouse::create([
        'name' => "P3rq Store {$n}",
        'code' => 'P3RQ-W'.$n,
        'type' => 'main',
        'is_active' => true,
        'allow_negative_stock' => false,
    ]);

    $product = Product::create([
        'name' => "P3rq Widget {$n}",
        'sku' => 'P3RQ-W'.$n,
        'type' => 'stockable',
        'status' => 'active',
        'is_active' => true,
        'valuation_method' => 'weighted',
        'cost_price' => 120.00,
        'selling_price' => 200.00,
        'vat_rate' => 0,
    ]);

    $vendor = Partner::create(['partner_code' => 'P3RQ-V'.$n, 'type' => 'vendor', 'name' => "P3rq Supplier {$n}"]);

    return [$warehouse, $product, $vendor];
}

/**
 * @param  list<array{product_id?:int|null, description:string, qty:float, expected_unit_price?:float}>  $lines
 * @return array<string,mixed>
 */
function p3rqPayload(array $lines, int $warehouseId, array $overrides = []): array
{
    return array_merge([
        'title' => 'Replenish the godown',
        'warehouse_id' => $warehouseId,
        'need_by' => now()->addDays(10)->toDateString(),
        'justification' => 'Stock cover down to 6 days',
        'items' => $lines,
    ], $overrides);
}

function p3rqSvc(): PurchaseRequisitionService
{
    return app(PurchaseRequisitionService::class);
}

function p3rqRfqSvc(): PurchaseRfqService
{
    return app(PurchaseRfqService::class);
}

/** An approved requisition with one product line of $qty at $price, ready for a quote round. */
function p3rqApprovedRequisition(float $qty = 40.0, float $price = 150.00, array $lineOverrides = []): array
{
    [$warehouse, $product] = p3rqFixtures();
    $keeper = p3rqActor();

    actingAs($keeper);

    $req = p3rqSvc()->create(p3rqPayload([
        array_merge(['product_id' => $product->id, 'description' => $product->name, 'qty' => $qty, 'expected_unit_price' => $price], $lineOverrides),
    ], $warehouse->id));

    $approver = p3rqUser(['purchase.requisitions.approve', 'purchase.requisitions.view'], 'Approver');
    actingAs($approver);
    $req = p3rqSvc()->submit($req->fresh());
    $req = p3rqSvc()->approve($req->fresh());

    return [$req, $warehouse, $product, $keeper, $approver];
}

/**
 * An approved requisition with the given lines, for tests that need more than one thing to compare.
 *
 * @param  list<array{description:string,qty:float,expected_unit_price:float}>  $lines
 * @return array{0:PurchaseRequisition,1:Warehouse,2:Product,3:User,4:User}
 */
function p3rqApprovedRequisitionWithLines(array $lines): array
{
    [$warehouse, $product] = p3rqFixtures();
    actingAs(p3rqActor());

    $req = p3rqSvc()->create(p3rqPayload(
        array_map(fn (array $l): array => array_merge(['product_id' => $product->id], $l), $lines),
        $warehouse->id,
    ));

    actingAs(p3rqUser(['purchase.requisitions.approve', 'purchase.requisitions.view'], 'Approver'));
    $req = p3rqSvc()->approve(p3rqSvc()->submit($req->fresh()));

    return [$req, $warehouse, $product, p3rqActor(), p3rqUser(['purchase.requisitions.approve'], 'Approver 2')];
}

/**
 * Open a round against a requisition and drop two quotes in: $cheap undercuts $dear on every line.
 *
 * @return array{0:PurchaseRfq,1:PurchaseRfqQuote,2:PurchaseRfqQuote} the round, the cheap quote, the dear one
 */
function p3rqRoundWithQuotes(PurchaseRequisition $req, float $cheapPrice = 130.00, float $dearPrice = 145.00, ?string $cheapValidUntil = null): array
{
    // A second fixture run, for its vendor: a round worth comparing needs two answers.
    p3rqFixtures();

    $rfq = p3rqRfqSvc()->create([
        'requisition_id' => $req->id,
        'subject' => 'Quote for '.$req->req_no,
        'due_on' => now()->addDays(5)->toDateString(),
        'lines' => $req->items->map(fn ($i): array => [
            'requisition_item_id' => $i->id,
            'description' => $i->description,
            'qty' => (float) $i->qty,
        ])->all(),
    ]);

    actingAs(p3rqUser(['purchase.rfq.manage', 'purchase.rfq.view'], 'Buyer'));
    $rfq = p3rqRfqSvc()->send($rfq);

    $vendors = Partner::where('type', 'vendor')->orderBy('id')->limit(2)->get();
    $lineIds = $rfq->lines()->pluck('id')->all();

    // Only the first answer carries $cheapValidUntil: an expiry that hits both bidders leaves a round
    // with nothing to recommend, and the test would pass for the wrong reason.
    $mk = function (Partner $vendor, float $price, ?string $validUntil) use ($rfq, $lineIds): PurchaseRfqQuote {
        return p3rqRfqSvc()->recordQuote($rfq, [
            'vendor_id' => $vendor->id,
            'quote_ref' => 'Q-'.$vendor->id.'-'.Str::random(4),
            'quoted_on' => now()->toDateString(),
            'valid_until' => $validUntil,
            'lead_time_days' => 7,
            'items' => array_map(fn (int $id): array => ['rfq_line_id' => $id, 'unit_price' => $price], $lineIds),
        ]);
    };

    return [$rfq->fresh(), $mk($vendors[0], $cheapPrice, $cheapValidUntil), $mk($vendors[1], $dearPrice, null)];
}

/* ───────────────────────────── the schema ───────────────────────────── */

test('the tables exist and the purchase order can point back at a requisition', function () {
    foreach ([
        'purchase_requisitions', 'purchase_requisition_items',
        'purchase_rfqs', 'purchase_rfq_lines',
        'purchase_rfq_quotes', 'purchase_rfq_quote_items', 'purchase_rfq_awards',
    ] as $table) {
        expect(DB::getSchemaBuilder()->hasTable($table))->toBeTrue("missing table {$table}");
    }

    expect(DB::getSchemaBuilder()->hasColumn('purchase_orders', 'requisition_id'))->toBeTrue();
});

test('one vendor cannot quote the same round twice, and one line cannot be awarded twice', function () {
    [$req] = p3rqApprovedRequisition();
    [$rfq] = p3rqRoundWithQuotes($req);

    $lineId = $rfq->lines()->value('id');

    // The unique keys, not just the service's firstOrNew: this is what stops a race writing two answers.
    $dupe = null;

    try {
        DB::table('purchase_rfq_quotes')->insert([
            'rfq_id' => $rfq->id,
            'vendor_id' => $rfq->quotes()->first()->vendor_id,
            'status' => 'received',
            'total_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $dupe = $e->getMessage();
    }

    expect($dupe)->not->toBeNull('a second quote row for the same vendor was accepted');

    $violated = false;

    try {
        DB::table('purchase_rfq_awards')->insert([
            'rfq_id' => $rfq->id,
            'quote_id' => $rfq->quotes()->value('id'),
            'rfq_line_id' => $lineId,
            'unit_price' => 10,
            'qty' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_rfq_awards')->insert([
            'rfq_id' => $rfq->id,
            'quote_id' => $rfq->quotes()->value('id'),
            'rfq_line_id' => $lineId,
            'unit_price' => 11,
            'qty' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException) {
        $violated = true;
    }

    expect($violated)->toBeTrue('a line could be awarded to two quotes at once');
});

/* ───────────────────────────── the estimates ───────────────────────────── */

test('the header estimate is the sum of the line estimates, in poisha', function () {
    [$warehouse, $product] = p3rqFixtures();
    actingAs(p3rqActor());

    $req = p3rqSvc()->create(p3rqPayload([
        ['product_id' => $product->id, 'description' => 'A', 'qty' => 3, 'expected_unit_price' => 10.10],
        ['product_id' => $product->id, 'description' => 'B', 'qty' => 0.333, 'expected_unit_price' => 99.99],
    ], $warehouse->id));

    // 3 × 10.10 = 30.30; 0.333 × 99.99 = 33.2967 → 33.30. Summed as stored, so no drift between the
    // lines and the header.
    expect((float) $req->estimated_amount)->toBe(63.60)
        ->and((float) $req->items[0]->line_estimate)->toBe(30.30)
        ->and((float) $req->items[1]->line_estimate)->toBe(33.30)
        ->and($req->items[1]->line_estimate)->toBe('33.30');
});

test('a blank line posted by the form is dropped rather than counted', function () {
    [$warehouse, $product] = p3rqFixtures();
    $user = p3rqActor();
    actingAs($user);

    $r = $this->post(route('purchase.requisitions.store'), [
        'title' => 'Blank rows',
        'warehouse_id' => $warehouse->id,
        'items' => [
            ['product_id' => $product->id, 'description' => 'Real', 'qty' => 1, 'expected_unit_price' => 5],
            ['product_id' => null, 'description' => '   ', 'qty' => 4, 'expected_unit_price' => 5],
        ],
    ]);

    $r->assertSessionHasNoErrors();

    $req = PurchaseRequisition::latest('id')->first();

    expect($req->items)->toHaveCount(1)->and((float) $req->estimated_amount)->toBe(5.00);
});

test('a requisition is numbered REQ-, and re-editing cannot change the number', function () {
    [$warehouse, $product] = p3rqFixtures();
    actingAs(p3rqActor());

    $req = p3rqSvc()->create(p3rqPayload([['product_id' => $product->id, 'description' => 'A', 'qty' => 1]], $warehouse->id));

    expect($req->req_no)->toStartWith('REQ-');

    $again = p3rqSvc()->create(p3rqPayload([['product_id' => $product->id, 'description' => 'B', 'qty' => 1]], $warehouse->id));

    expect($again->req_no)->not->toBe($req->req_no);

    // A form that posts its own req_no (or a forged status) cannot move the record's identity.
    p3rqSvc()->update($again, ['title' => 'Renamed', 'req_no' => 'REQ-00001', 'status' => 'approved', 'items' => null]);

    expect($again->fresh()->req_no)->toBe($again->req_no)
        ->and($again->fresh()->status)->toBe('draft')
        ->and($again->fresh()->title)->toBe('Renamed');
});

/* ───────────────────────────── the state machine ───────────────────────────── */

test('only a draft can be edited or deleted', function () {
    [$req] = p3rqApprovedRequisition();

    expect($req->status)->toBe('approved');

    try {
        p3rqSvc()->update($req, ['title' => 'Too late']);
        $this->fail('an approved requisition was edited');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('Only a draft requisition can be edited');
    }

    try {
        p3rqSvc()->delete($req);
        $this->fail('an approved requisition was deleted');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('Only a draft requisition can be deleted');
    }

    // A draft, by contrast, takes its lines with it.
    [$warehouse, $product] = p3rqFixtures();
    actingAs(p3rqActor());
    $draft = p3rqSvc()->create(p3rqPayload([['product_id' => $product->id, 'description' => 'A', 'qty' => 1]], $warehouse->id));
    p3rqSvc()->delete($draft);

    expect(PurchaseRequisition::find($draft->id))->toBeNull()
        ->and(DB::table('purchase_requisition_items')->where('requisition_id', $draft->id)->count())->toBe(0);
});

test('submit needs a line with a quantity', function () {
    [$warehouse] = p3rqFixtures();
    actingAs(p3rqActor());

    $req = p3rqSvc()->create(p3rqPayload([['description' => 'Zero qty', 'qty' => 0.0]], $warehouse->id));

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('at least one line with a quantity');

    p3rqSvc()->submit($req);
});

test('nothing a requisition does writes the ledger', function () {
    $ledgerBefore = DB::table('acc_account_ledger')->count();
    $stockBefore = Stock::count();
    $ordersBefore = PurchaseOrder::count();

    [$req] = p3rqApprovedRequisition();
    [$rfq] = p3rqRoundWithQuotes($req);
    $lineId = $rfq->lines()->value('id');
    $quoteId = $rfq->quotes()->orderBy('total_amount')->value('id');

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view', 'purchase.orders.manage'], 'Awarder'));
    p3rqRfqSvc()->award($rfq->fresh(), [['rfq_line_id' => $lineId, 'quote_id' => $quoteId]]);

    // The PO exists, but a draft order posts nothing: the ledger still does not know about it.
    expect(DB::table('acc_account_ledger')->count())->toBe($ledgerBefore)
        ->and(Stock::count())->toBe($stockBefore)
        ->and(PurchaseOrder::count())->toBe($ordersBefore + 1)
        ->and($req->fresh()->status)->toBe('converted');
});

test('an approver cannot approve the requisition they raised', function () {
    [$warehouse, $product] = p3rqFixtures();
    $keeper = p3rqActor(['purchase.requisitions.view', 'purchase.requisitions.manage', 'purchase.requisitions.approve'], 'Wearing Two Hats');
    actingAs($keeper);

    $req = p3rqSvc()->create(p3rqPayload([['product_id' => $product->id, 'description' => 'A', 'qty' => 1]], $warehouse->id));
    $req = p3rqSvc()->submit($req);

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('someone else has to approve it');

    p3rqSvc()->approve($req->fresh());
});

test('a draft cannot be approved, and a decided requisition cannot be approved again', function () {
    [$warehouse, $product] = p3rqFixtures();
    actingAs(p3rqActor());
    $req = p3rqSvc()->create(p3rqPayload([['product_id' => $product->id, 'description' => 'A', 'qty' => 1]], $warehouse->id));

    try {
        actingAs(p3rqUser(['purchase.requisitions.approve'], 'Approver'));
        p3rqSvc()->approve($req);
        $this->fail('a draft was approved without being submitted');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('Submit the requisition before deciding');
    }

    $req = p3rqSvc()->submit($req->fresh());
    expect(p3rqSvc()->approve($req)->status)->toBe('approved');

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('cannot be approved again');
    p3rqSvc()->approve($req->fresh());
});

test('a refusal needs a reason, and stores it', function () {
    [$warehouse, $product] = p3rqFixtures();
    actingAs(p3rqActor());
    $req = p3rqSvc()->submit(p3rqSvc()->create(p3rqPayload([['product_id' => $product->id, 'description' => 'A', 'qty' => 1]], $warehouse->id)));

    actingAs(p3rqUser(['purchase.requisitions.approve'], 'Approver'));

    try {
        p3rqSvc()->reject($req, '   ');
        $this->fail('an empty refusal was accepted');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('refusing in writing');
    }

    $rejected = p3rqSvc()->reject($req->fresh(), 'Already on order');

    expect($rejected->status)->toBe('rejected')->and($rejected->rejection_reason)->toBe('Already on order');
});

test('a converted requisition is cancelled by its order, not by itself', function () {
    [$req, $warehouse, $product, $keeper, $approver] = p3rqApprovedRequisition();
    [$rfq] = p3rqRoundWithQuotes($req);

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view', 'purchase.orders.manage'], 'Awarder'));
    p3rqRfqSvc()->award($rfq->fresh(), [['rfq_line_id' => $rfq->lines()->value('id'), 'quote_id' => $rfq->quotes()->orderBy('total_amount')->value('id')]]);

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('cancel the order instead');

    p3rqSvc()->cancel($req->fresh());
});

/* ───────────────────────────── the budget control ───────────────────────────── */

/** An approved budget line for $account in $period, plus the fiscal year it hangs off. */
function p3rqBudget(Account $account, string $period, float $amount): Budget
{
    $fy = FiscalYear::create([
        'name' => 'P3rq FY', 'code' => 'P3RQ-FY-'.Str::random(5),
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open',
    ]);
    $budget = Budget::create(['name' => 'P3rq B'.Str::random(5), 'code' => 'P3RQB'.Str::random(5), 'fiscal_year_id' => $fy->id, 'status' => 'approved']);
    BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'period' => $period, 'amount' => $amount]);

    return $budget;
}

test('no budget at all is not a violation', function () {
    [$warehouse, $product] = p3rqFixtures();
    $account = p3rqAccount('P3rq Consumables');
    actingAs(p3rqActor());

    $req = p3rqSvc()->create(p3rqPayload([['product_id' => $product->id, 'description' => 'A', 'qty' => 1, 'expected_unit_price' => 10]], $warehouse->id, ['account_id' => $account->id]));

    expect(p3rqSvc()->budgetPosition($req))->toBeNull();
    expect(p3rqSvc()->submit($req)->status)->toBe('submitted');
});

test('submitting past an approved budget is refused with the numbers, and the record stays draft', function () {
    [$warehouse, $product] = p3rqFixtures();
    $account = p3rqAccount('P3rq Fuel');

    $period = now()->addDays(10)->format('Y-m');
    p3rqBudget($account, $period, 1000.00);

    actingAs(p3rqActor());
    // need_by is inside the budget month, and the request is 4 × 400 = 1,600 against 1,000 of plan.
    $req = p3rqSvc()->create(p3rqPayload(
        [['product_id' => $product->id, 'description' => 'A', 'qty' => 4, 'expected_unit_price' => 400]],
        $warehouse->id,
        ['account_id' => $account->id, 'need_by' => now()->addDays(10)->toDateString()],
    ));

    try {
        p3rqSvc()->submit($req);
        $this->fail('an over-budget requisition was submitted');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('Over budget')
            ->and($e->getMessage())->toContain('1,000.00')
            ->and($e->getMessage())->toContain('1,600.00')
            ->and($e->getMessage())->toContain('Only an approver can push it through');
    }

    expect($req->fresh()->status)->toBe('draft');
});

test('a budget breach cannot be pushed through by the person asking for the goods', function () {
    [$warehouse, $product] = p3rqFixtures();
    $account = p3rqAccount('P3rq Fuel 2');
    p3rqBudget($account, now()->addDays(10)->format('Y-m'), 100.00);

    $keeper = p3rqActor();
    actingAs($keeper);
    $req = p3rqSvc()->create(p3rqPayload(
        [['product_id' => $product->id, 'description' => 'A', 'qty' => 1, 'expected_unit_price' => 900]],
        $warehouse->id,
        ['account_id' => $account->id, 'need_by' => now()->addDays(10)->toDateString()],
    ));

    // (a) the submit route takes no override at all, so a forged field changes nothing;
    $this->actingAs($keeper)->post(route('purchase.requisitions.submit', $req), ['override_budget' => 1])
        ->assertSessionHas('error');

    expect($req->fresh()->status)->toBe('draft');

    // (b) and the service refuses an override from anyone without the approve grant — the flag is checked
    // against the permission, never trusted from a controller.
    actingAs(p3rqUser(['purchase.requisitions.view'], 'Reader, no approve grant'));

    try {
        p3rqSvc()->approve($req->fresh(), true);
        $this->fail('an override was honoured without the approve grant');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('Only an approver can override');
    }

    expect($req->fresh()->status)->toBe('draft');
});

test('an approver may push a blocked requisition through, and that is recorded as their decision', function () {
    [$warehouse, $product] = p3rqFixtures();
    $account = p3rqAccount('P3rq Fuel 3');
    p3rqBudget($account, now()->addDays(10)->format('Y-m'), 500.00);

    actingAs(p3rqActor());
    $req = p3rqSvc()->create(p3rqPayload(
        [['product_id' => $product->id, 'description' => 'A', 'qty' => 1, 'expected_unit_price' => 800]],
        $warehouse->id,
        ['account_id' => $account->id, 'need_by' => now()->addDays(10)->toDateString()],
    ));

    // The requester cannot even submit it, so there is no "submitted" state to approve from — which is
    // precisely why approve() takes the override and skips that step rather than hiding behind it.
    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('Over budget');

    p3rqSvc()->submit($req);
});

test('the override lands on the approval, not on the submission', function () {
    [$warehouse, $product] = p3rqFixtures();
    $account = p3rqAccount('P3rq Fuel 4');
    p3rqBudget($account, now()->addDays(10)->format('Y-m'), 500.00);

    actingAs(p3rqActor());
    $req = p3rqSvc()->create(p3rqPayload(
        [['product_id' => $product->id, 'description' => 'A', 'qty' => 1, 'expected_unit_price' => 800]],
        $warehouse->id,
        ['account_id' => $account->id, 'need_by' => now()->addDays(10)->toDateString()],
    ));

    $approver = p3rqUser(['purchase.requisitions.approve', 'purchase.requisitions.view'], 'Approver');
    actingAs($approver);

    $approved = p3rqSvc()->approve($req->fresh(), true);

    expect($approved->status)->toBe('approved')
        ->and((int) $approved->reviewed_by)->toBe((int) $approver->id)
        ->and($approved->reviewed_at)->not->toBeNull();

    // and the same call without the flag is still refused while it is a draft
    $second = p3rqSvc()->create(p3rqPayload(
        [['product_id' => $product->id, 'description' => 'B', 'qty' => 1, 'expected_unit_price' => 10]],
        $warehouse->id,
    ));

    try {
        p3rqSvc()->approve($second);
        $this->fail('a draft was approved without the override or a submission');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('Submit the requisition before deciding');
    }
});

/** A posting expense account with a unique code. */
function p3rqAccount(string $name): Account
{
    return Account::create([
        'code' => '5'.Str::random(4), 'name' => $name, 'account_type' => 'expense', 'nature' => 'debit',
        'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true, 'currency' => 'BDT', 'opening_balance' => 0,
    ]);
}

test('a requisition under the plan sails through', function () {
    [$warehouse, $product] = p3rqFixtures();
    $account = p3rqAccount('P3rq Fuel 4');
    p3rqBudget($account, now()->addDays(10)->format('Y-m'), 5000.00);

    actingAs(p3rqActor());
    $req = p3rqSvc()->create(p3rqPayload(
        [['product_id' => $product->id, 'description' => 'A', 'qty' => 2, 'expected_unit_price' => 300]],
        $warehouse->id,
        ['account_id' => $account->id, 'need_by' => now()->addDays(10)->toDateString()],
    ));

    $position = p3rqSvc()->budgetPosition($req);

    expect($position['over'])->toBeFalse()
        ->and($position['plan'])->toBe(5000.0)
        ->and($position['headroom'])->toBe(5000.0)
        ->and($position['requested'])->toBe(600.0)
        ->and(p3rqSvc()->submit($req)->status)->toBe('submitted');
});

/* ───────────────────────────── the approval matrix (F3) ───────────────────────────── */

test('an active approval policy holds the requisition at draft and queues the approver', function () {
    [$warehouse, $product] = p3rqFixtures();
    actingAs(p3rqActor());

    $policy = ApprovalPolicy::create([
        'name' => 'Big requisitions',
        'document_type' => 'purchase_requisition',
        'min_amount' => 1000,
        'approver_permission' => 'purchase.requisitions.approve',
        'require_segregation' => true,
        'priority' => 5,
        'is_active' => true,
    ]);

    $req = p3rqSvc()->create(p3rqPayload([['product_id' => $product->id, 'description' => 'A', 'qty' => 10, 'expected_unit_price' => 400]], $warehouse->id));

    try {
        p3rqSvc()->submit($req);
        $this->fail('the requisition went through past an active policy');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('requires approval under "Big requisitions"');
    }

    expect($req->fresh()->status)->toBe('draft')
        ->and(ApprovalRequest::where('document_type', 'purchase_requisition')
            ->where('document_id', $req->id)->where('status', 'pending')->count())->toBe(1);

    // A second click must not spam a second request (the PO behaviour this deliberately mirrors).
    try {
        p3rqSvc()->submit($req->fresh());
    } catch (DomainException) {
        // expected
    }

    expect(ApprovalRequest::where('policy_id', $policy->id)->count())->toBe(1);
});

/* ───────────────────────────── the round ───────────────────────────── */

test('a round opened from a requisition asks for exactly what is still outstanding', function () {
    [$req] = p3rqApprovedRequisition(qty: 40.0);

    actingAs(p3rqActor());
    $rfq = p3rqSvc()->openRfq($req);

    expect($rfq->rfq_no)->toStartWith('RFQ-')
        ->and($rfq->lines)->toHaveCount(1)
        ->and((float) $rfq->lines[0]->qty)->toBe(40.0)
        ->and($rfq->lines[0]->requisition_item_id)->toBe($req->items[0]->id);

    // A second round after nothing was ordered asks again; after everything was ordered it refuses.
    $req->items[0]->update(['qty_ordered' => 40.0]);

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('already been ordered');

    p3rqSvc()->openRfq($req->fresh());
});

test('only an approved requisition can be put out to quote', function () {
    [$warehouse, $product] = p3rqFixtures();
    actingAs(p3rqActor());
    $req = p3rqSvc()->create(p3rqPayload([['product_id' => $product->id, 'description' => 'A', 'qty' => 1]], $warehouse->id));

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('approved requisition can be put out to quote');

    p3rqSvc()->openRfq($req);
});

test('a vendor cannot quote a line that is not in the round', function () {
    [$req] = p3rqApprovedRequisition();
    [$rfq] = p3rqRoundWithQuotes($req);

    // A real line from a different round — the honest version of "that is not yours to price".
    $elsewhere = p3rqRfqSvc()->create([
        'subject' => 'Another round entirely',
        'lines' => [['description' => 'elsewhere', 'qty' => 1]],
    ]);
    $foreign = (int) $elsewhere->lines()->value('id');

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('must belong to this round');

    p3rqRfqSvc()->recordQuote($rfq->fresh(), [
        'vendor_id' => Partner::where('type', 'vendor')->value('id'),
        'items' => [['rfq_line_id' => $foreign, 'unit_price' => 10]],
    ]);
});

test('re-entering a vendor\'s quote replaces it whole, and the total follows the lines', function () {
    [$req] = p3rqApprovedRequisition(qty: 10.0);
    [$rfq, $cheap] = p3rqRoundWithQuotes($req, cheapPrice: 100.00);
    $lineId = $rfq->lines()->value('id');

    $again = p3rqRfqSvc()->recordQuote($rfq->fresh(), [
        'vendor_id' => $cheap->vendor_id,
        'quote_ref' => 'REVISED',
        'items' => [['rfq_line_id' => $lineId, 'unit_price' => 88.25, 'qty' => 10]],
    ]);

    expect($again->id)->toBe($cheap->id)
        ->and(PurchaseRfqQuote::where('rfq_id', $rfq->id)->where('vendor_id', $cheap->vendor_id)->count())->toBe(1)
        ->and($again->quote_ref)->toBe('REVISED')
        ->and((float) $again->total_amount)->toBe(882.50)
        ->and($again->items)->toHaveCount(1);
});

test('a quote with no lines is refused, because a blank answer is not a bid', function () {
    [$req] = p3rqApprovedRequisition();
    [$rfq] = p3rqRoundWithQuotes($req);

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('at least one priced line');

    p3rqRfqSvc()->recordQuote($rfq->fresh(), ['vendor_id' => Partner::where('type', 'vendor')->value('id'), 'items' => []]);
});

/* ───────────────────────────── the comparison ───────────────────────────── */

test('the comparison finds the cheapest line, the cheapest complete round, and refuses to mix them', function () {
    // Two lines, so "complete" has a meaning: a vendor who quotes one of them is not an answer.
    [$req] = p3rqApprovedRequisitionWithLines([
        ['description' => 'A', 'qty' => 10.0, 'expected_unit_price' => 100.0],
        ['description' => 'B', 'qty' => 4.0, 'expected_unit_price' => 250.0],
    ]);
    [$rfq, $cheap, $dear] = p3rqRoundWithQuotes($req, cheapPrice: 100.00, dearPrice: 120.00);

    // A third vendor quotes one line at a giveaway and skips the rest: cheapest *total*, but not an
    // answer to the requisition. The recommendation must not pick it.
    $partial = Partner::create(['partner_code' => 'P3RQ-PARTIAL', 'type' => 'vendor', 'name' => 'One Line Traders']);
    actingAs(p3rqUser(['purchase.rfq.manage'], 'Buyer'));
    $oneLine = $rfq->lines()->skip(1)->first() ?? $rfq->lines()->first();
    $partialQuote = p3rqRfqSvc()->recordQuote($rfq->fresh(), [
        'vendor_id' => $partial->id,
        'items' => [['rfq_line_id' => $oneLine->id, 'unit_price' => 1.00]],
    ]);

    $compare = p3rqRfqSvc()->compare($rfq->fresh());

    expect($compare['recommendation']['quote_id'])->toBe($cheap->id)
        ->and($compare['recommendation']['reason'])->toContain('Lowest complete');

    $row = collect($compare['quotes'])->firstWhere('id', $partialQuote->id);

    expect($row)->not->toBeNull('a live quote should still appear in the table')
        ->and($row['complete'])->toBeFalse()
        ->and($row['lines_quoted'])->toBe(1)
        ->and($row['lines_requested'])->toBe(2);

    // Per line, the *cheap* vendor is not automatically best: B is dearer with them, and the grid must
    // say so, because that is the argument a purchasing manager has to be able to see.
    $lines = collect($compare['lines']);
    $first = $lines->firstWhere('id', $rfq->lines()->orderBy('id')->value('id'));

    expect($first['best_quote_id'])->toBe($cheap->id);
});

test('an expired quote is never recommended, even when it is cheapest', function () {
    [$req] = p3rqApprovedRequisition(qty: 10.0);
    [$rfq, $cheap] = p3rqRoundWithQuotes($req, cheapPrice: 100.00, dearPrice: 120.00, cheapValidUntil: now()->subDay()->toDateString());

    expect($cheap->fresh()->isExpired())->toBeTrue();

    $compare = p3rqRfqSvc()->compare($rfq->fresh(['quotes']));

    $expiredRow = collect($compare['quotes'])->firstWhere('id', $cheap->id);

    expect($compare['recommendation'])->not->toBeNull()
        ->and($compare['recommendation']['quote_id'])->not->toBe($cheap->id)
        ->and($expiredRow)->not->toBeNull()
        ->and($expiredRow['expired'])->toBeTrue()
        // …and per line the expired price is not "best" either, or the screen would point at it.
        ->and($compare['lines'][0]['best_quote_id'])->not->toBe($cheap->id);
});

test('a withdrawn quote is out of the comparison entirely', function () {
    [$req] = p3rqApprovedRequisition();
    [$rfq, $cheap, $dear] = p3rqRoundWithQuotes($req);

    actingAs(p3rqUser(['purchase.rfq.manage'], 'Buyer'));
    p3rqRfqSvc()->withdrawQuote($dear->fresh());

    $compare = p3rqRfqSvc()->compare($rfq->fresh());

    expect(collect($compare['quotes'])->firstWhere('id', $dear->id))->toBeNull()
        ->and($compare['lines'][0]['cells'])->toHaveCount(1);
});

/* ───────────────────────────── the award ───────────────────────────── */

test('awarding creates a draft purchase order at the quoted price and closes the loop', function () {
    [$req, $warehouse, $product] = p3rqApprovedRequisition(qty: 10.0);
    [$rfq, $cheap, $dear] = p3rqRoundWithQuotes($req, cheapPrice: 130.00, dearPrice: 145.00);

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view', 'purchase.orders.view', 'purchase.orders.manage'], 'Awarder'));

    $orders = p3rqRfqService()->award($rfq->fresh(), [
        ['rfq_line_id' => $rfq->lines()->value('id'), 'quote_id' => $cheap->id],
    ]);

    $order = PurchaseOrder::findOrFail($orders[0]);

    expect($order->status)->toBe('draft')
        ->and((int) $order->vendor_id)->toBe((int) $cheap->vendor_id)
        ->and((int) $order->requisition_id)->toBe((int) $req->id)
        ->and((float) $order->items[0]->unit_price)->toBe(130.00)
        ->and((float) $order->items[0]->qty)->toBe(10.0)
        ->and($req->fresh()->status)->toBe('converted')
        ->and((int) $req->fresh()->converted_order_id)->toBe((int) $order->id)
        ->and((float) $req->items()->value('qty_ordered'))->toBe(10.0)
        ->and($rfq->fresh()->awarded_quote_id)->toBe((int) $cheap->id)
        ->and(PurchaseRfqAward::where('rfq_line_id', $rfq->lines()->value('id'))->count())->toBe(1);
});

function p3rqRfqService(): PurchaseRfqService
{
    return app(PurchaseRfqService::class);
}

test('the awarded quantity never exceeds what is still outstanding', function () {
    [$req] = p3rqApprovedRequisition(qty: 10.0);
    [$rfq, $cheap] = p3rqRoundWithQuotes($req, cheapPrice: 130.00, dearPrice: 145.00);

    // The vendor offers 500; the requisition asked for 10 and nothing has been ordered yet.
    $lineId = $rfq->lines()->value('id');
    DB::table('purchase_rfq_quote_items')->where('rfq_line_id', $lineId)->update(['qty' => 500]);

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view', 'purchase.orders.manage'], 'Awarder'));
    $orders = p3rqRfqSvc()->award($rfq->fresh(), [['rfq_line_id' => $lineId, 'quote_id' => $cheap->id]]);

    expect((float) PurchaseOrder::findOrFail($orders[0])->items[0]->qty)->toBe(10.0);

    // And the requisition has moved on: it is `converted`, so there is no second round to raise at all
    // — refused one step earlier than "every line is ordered", which is the better error to read.
    expect($req->fresh()->status)->toBe('converted');

    actingAs(p3rqUser(['purchase.rfq.manage'], 'Buyer'));

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('Only an approved requisition can be put out to quote');

    p3rqSvc()->openRfq($req->fresh());
});

test('a round can only be awarded once', function () {
    [$req] = p3rqApprovedRequisition();
    [$rfq, $cheap] = p3rqRoundWithQuotes($req);
    $lineId = $rfq->lines()->value('id');

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view', 'purchase.orders.manage'], 'Awarder'));
    p3rqRfqSvc()->award($rfq->fresh(), [['rfq_line_id' => $lineId, 'quote_id' => $cheap->id]]);

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('already been awarded');

    p3rqRfqSvc()->award($rfq->fresh(), [['rfq_line_id' => $lineId, 'quote_id' => $cheap->id]]);
});

test('a line cannot be awarded twice in the same call', function () {
    [$req] = p3rqApprovedRequisition();
    [$rfq, $cheap, $dear] = p3rqRoundWithQuotes($req);
    $lineId = $rfq->lines()->value('id');

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view', 'purchase.orders.manage'], 'Awarder'));

    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('awarded once in the same call');

    p3rqRfqSvc()->award($rfq->fresh(), [
        ['rfq_line_id' => $lineId, 'quote_id' => $cheap->id],
        ['rfq_line_id' => $lineId, 'quote_id' => $dear->id],
    ]);
});

test('an expired quote cannot be awarded, and the refusal says so', function () {
    [$req] = p3rqApprovedRequisition();
    [$rfq, $cheap] = p3rqRoundWithQuotes($req, cheapValidUntil: now()->subDay()->toDateString());

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view', 'purchase.orders.manage'], 'Awarder'));

    try {
        p3rqRfqSvc()->award($rfq->fresh(), [['rfq_line_id' => $rfq->lines()->value('id'), 'quote_id' => $cheap->id]]);
        $this->fail('an expired quote was awarded');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('expired on '.now()->subDay()->toDateString());
    }
});

test('a requisition line with no product stops the award rather than making a broken order', function () {
    [$warehouse, $product] = p3rqFixtures();
    $keeper = p3rqActor();
    actingAs($keeper);

    // A description-only request: legitimate here, unorderable on a purchase order line.
    $req = p3rqSvc()->create(p3rqPayload([['description' => 'Floor scale, 5t', 'qty' => 1, 'expected_unit_price' => 45000]], $warehouse->id));
    actingAs(p3rqUser(['purchase.requisitions.approve'], 'Approver'));
    $req = p3rqSvc()->approve(p3rqSvc()->submit($req->fresh()));

    actingAs(p3rqUser(['purchase.rfq.manage', 'purchase.rfq.view'], 'Buyer'));
    $rfq = p3rqSvc()->openRfq($req->fresh());
    $rfq = p3rqRfqSvc()->send($rfq);
    $vendor = Partner::where('type', 'vendor')->value('id');
    $quote = p3rqRfqSvc()->recordQuote($rfq, ['vendor_id' => $vendor, 'items' => [['rfq_line_id' => $rfq->lines()->value('id'), 'unit_price' => 42000]]]);

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view', 'purchase.orders.manage'], 'Awarder'));

    try {
        p3rqRfqSvc()->award($rfq->fresh(), [['rfq_line_id' => $rfq->lines()->value('id'), 'quote_id' => $quote->id]]);
        $this->fail('an unstocked description became a purchase order line');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('no product on it')
            ->and($e->getMessage())->toContain('Floor scale, 5t');
    }

    expect(PurchaseOrder::where('requisition_id', $req->id)->count())->toBe(0)
        ->and($req->fresh()->status)->toBe('approved');
});

test('a split award makes one purchase order per vendor, not one order with two vendors', function () {
    [$req, $warehouse, $product] = p3rqApprovedRequisition(qty: 10.0);

    // A second line, so there is something to split.
    $second = Product::create([
        'name' => 'P3rq Second Item', 'sku' => 'P3RQ-2'.Str::random(4), 'type' => 'stockable', 'status' => 'active',
        'is_active' => true, 'valuation_method' => 'weighted', 'cost_price' => 10.0, 'selling_price' => 20.0, 'vat_rate' => 0,
    ]);
    $reqItem = DB::table('purchase_requisition_items')->insertGetId([
        'requisition_id' => $req->id, 'product_id' => $second->id, 'description' => $second->name,
        'qty' => 5, 'expected_unit_price' => 40, 'line_estimate' => 200, 'qty_ordered' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs(p3rqUser(['purchase.rfq.manage', 'purchase.rfq.view'], 'Buyer'));
    $rfq = p3rqRfqSvc()->create([
        'requisition_id' => $req->id,
        'subject' => 'Split round',
        'lines' => [
            ['requisition_item_id' => $req->items[0]->id, 'description' => $req->items[0]->description, 'qty' => 10],
            ['requisition_item_id' => $reqItem, 'description' => $second->name, 'qty' => 5],
        ],
    ]);
    $rfq = p3rqRfqSvc()->send($rfq);

    $other = Partner::create(['partner_code' => 'P3RQ-SPLIT'.Str::random(4), 'type' => 'vendor', 'name' => 'P3rq Second Supplier']);
    $vendorIds = Partner::where('type', 'vendor')->orderBy('id')->pluck('id')->all();
    $a = $vendorIds[0];
    $b = $other->id;
    $lines = $rfq->lines()->pluck('id')->all();

    // Vendor A is cheap on line 1, vendor B cheap on line 2.
    $qa = p3rqRfqSvc()->recordQuote($rfq, ['vendor_id' => $a, 'items' => [
        ['rfq_line_id' => $lines[0], 'unit_price' => 90],
        ['rfq_line_id' => $lines[1], 'unit_price' => 45],
    ]]);
    $qb = p3rqRfqSvc()->recordQuote($rfq, ['vendor_id' => $b, 'items' => [
        ['rfq_line_id' => $lines[0], 'unit_price' => 95],
        ['rfq_line_id' => $lines[1], 'unit_price' => 30],
    ]]);

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view', 'purchase.orders.manage'], 'Awarder'));
    $orders = p3rqRfqSvc()->award($rfq->fresh(), [
        ['rfq_line_id' => $lines[0], 'quote_id' => $qa->id],
        ['rfq_line_id' => $lines[1], 'quote_id' => $qb->id],
    ]);

    expect($orders)->toHaveCount(2);

    $byVendor = PurchaseOrder::whereIn('id', $orders)->get()->keyBy('vendor_id');

    expect((float) $byVendor[$a]->items[0]->unit_price)->toBe(90.0)
        ->and((float) $byVendor[$b]->items[0]->unit_price)->toBe(30.0)
        ->and($byVendor[$a]->items)->toHaveCount(1)
        // The requisition points at the first of them; every order points back, which is the query an
        // auditor actually runs.
        ->and((int) $req->fresh()->converted_order_id)->toBe((int) $orders[0])
        ->and(PurchaseOrder::where('requisition_id', $req->id)->count())->toBe(2);
});

test('a round with no requisition needs a warehouse for its order', function () {
    [, , $vendor] = p3rqFixtures();
    actingAs(p3rqUser(['purchase.rfq.manage', 'purchase.rfq.view'], 'Buyer'));

    // A market sounding, raised without a requisition: legal to ask, impossible to award, because an
    // order line has to arrive somewhere and guessing "the first warehouse" is a stock error waiting.
    $rfq = p3rqRfqSvc()->create([
        'subject' => 'Sounding: floor scales',
        'lines' => [['description' => 'Floor scale, 5t', 'qty' => 2]],
    ]);
    $rfq = p3rqRfqSvc()->send($rfq);
    $quote = p3rqRfqSvc()->recordQuote($rfq, [
        'vendor_id' => $vendor->id,
        'items' => [['rfq_line_id' => $rfq->lines()->value('id'), 'unit_price' => 42000]],
    ]);

    actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view'], 'Awarder'));

    try {
        p3rqRfqSvc()->award($rfq->fresh(), [['rfq_line_id' => $rfq->lines()->value('id'), 'quote_id' => $quote->id]]);
        $this->fail('an award was made with nowhere to receive the goods');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('no requisition to inherit a receiving warehouse');
    }
});

/* ───────────────────────────── the doors ───────────────────────────── */

test('the pages answer 403 to a user without the grant and 200 to one with it', function () {
    [$req] = p3rqApprovedRequisition();

    // No grants at all: an authenticated stranger, which is what "the page must 403" actually means.
    $outsider = p3rqUser([], 'Nobody');
    $insider = p3rqUser(['purchase.requisitions.view'], 'Reader');

    foreach (['purchase.requisitions.index' => $req->id, 'purchase.requisitions.show' => $req->id] as $name => $id) {
        $this->actingAs($outsider)->get(route($name, $id))->assertForbidden();
    }

    $this->actingAs($insider)->get(route('purchase.requisitions.show', $req->id))->assertOk();

    // An edit form on a decided document is a dead end, so the route itself is not offered: the page
    // would render a form whose first save is refused.
    $this->actingAs($insider)->get(route('purchase.requisitions.edit', $req->id))->assertForbidden();
});

test('recording a quote and choosing the winner are different permissions', function () {
    [$req] = p3rqApprovedRequisition();
    [$rfq, $cheap] = p3rqRoundWithQuotes($req);

    $clerk = p3rqUser(['purchase.rfq.manage', 'purchase.rfq.view'], 'Clerk');

    $this->actingAs($clerk)->post(route('purchase.rfqs.award', $rfq->id), [
        'picks' => [['rfq_line_id' => $rfq->lines()->value('id'), 'quote_id' => $cheap->id]],
    ])->assertForbidden();

    $this->actingAs(p3rqUser(['purchase.rfq.award', 'purchase.rfq.view'], 'Boss'))
        ->post(route('purchase.rfqs.award', $rfq->id), [])
        ->assertSessionHasErrors('picks');
});

test('a requisition is only editable through the form while it is a draft, enforced on the route too', function () {
    [$req] = p3rqApprovedRequisition();

    $this->actingAs(p3rqUser(['purchase.requisitions.manage'], 'Keeper'))
        ->put(route('purchase.requisitions.update', $req), ['title' => 'ok', 'items' => []])
        ->assertSessionHasErrors('items');
});

test('the module grants all exist in the seeded vocabulary', function () {
    $names = Permission::pluck('name')->all();

    foreach (config('purchase.permissions') as $name) {
        $this->assertContains($name, $names, "{$name} is in config but was never seeded");
    }

    expect($names)->toContain('purchase.requisitions.view', 'purchase.requisitions.manage', 'purchase.requisitions.approve')
        ->and($names)->toContain('purchase.rfq.view', 'purchase.rfq.manage', 'purchase.rfq.award');
});

test('withdrawing an answer goes through the route that names the record', function () {
    [$req] = p3rqApprovedRequisition();
    [$rfq, $cheap] = p3rqRoundWithQuotes($req);

    actingAs(p3rqUser(['purchase.rfq.manage', 'purchase.rfq.view'], 'Buyer'));

    // Implicit binding on `rfqs/quotes/{quote}` — the id in the URL has to resolve to the quote class, so
    // a controller that forgets its import fails here and nowhere else: every other F18 route is bound to
    // the round, not to an answer.
    $this->post(route('purchase.rfqs.quotes.withdraw', $cheap->id))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($cheap->fresh()->status)->toBe('withdrawn')
        ->and($rfq->fresh()->quotes()->where('status', 'withdrawn')->count())->toBe(1);

    // The same post without the manage grant is a 403: the id being guessable is not an authorisation.
    actingAs(p3rqUser(['purchase.rfq.view'], 'Viewer'));

    $this->post(route('purchase.rfqs.quotes.withdraw', $cheap->id))->assertForbidden();
});

test('the seven pages render the components they are named for, with the props their Vue binds to', function () {
    // A prop renamed on the server side is a page that renders "—" forever, and no test that only checks
    // for a 200 will notice. These are the props the .vue files actually read.
    [$req] = p3rqApprovedRequisition();
    [$rfq] = p3rqRoundWithQuotes($req);

    actingAs(p3rqUser([
        'purchase.requisitions.view', 'purchase.requisitions.manage', 'purchase.requisitions.approve',
        'purchase.rfq.view', 'purchase.rfq.manage', 'purchase.rfq.award',
    ], 'Everyone'));

    $this->get(route('purchase.requisitions.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('PURCHASE::Requisitions/Index')
            ->has('requisitions.data')
            ->where('canManage', true)->where('canApprove', true));

    $this->get(route('purchase.requisitions.create'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('PURCHASE::Requisitions/Create'));

    $this->get(route('purchase.requisitions.show', $req->id))->assertOk()
        ->assertInertia(fn ($page) => $page->component('PURCHASE::Requisitions/Show')
            ->where('requisition.req_no', $req->req_no)
            ->has('requisition.items')
            ->where('budget', null)          // no account named, so the panel must say "uncontrolled"
            ->has('activities')->has('attachments')
            ->has('requisition.items')       // the line table the page draws
            ->has('requisition.warehouse')   // "deliver to"
            ->missing('requisition.review_note')     // no such column exists; see the reason field below
            ->where('requisition.rejection_reason', null)
            ->where('canQuote', true));

    $this->get(route('purchase.rfqs.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('PURCHASE::Rfqs/Index')
            ->where('rfqs.data.0.rfq_no', $rfq->rfq_no)
            ->where('rfqs.data.0.quotes', 2)
            ->where('rfqs.data.0.lines', 1));

    $this->get(route('purchase.rfqs.show', $rfq->id))->assertOk()
        ->assertInertia(fn ($page) => $page->component('PURCHASE::Rfqs/Show')
            ->has('compare.lines')->has('compare.quotes')
            // `has($key, $count)` on the cell map: one cell per live answer, no more, no less.
            ->has('compare.lines.0.cells', 2)
            ->has('compare.recommendation.quote_id')
            ->where('canAward', true));

    // The round's create page, opened with a requisition attached, carries the outstanding lines rather
    // than making the buyer retype them — the one prop the prefill depends on.
    $this->get(route('purchase.rfqs.create', ['requisition_id' => $req->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('PURCHASE::Rfqs/Create')
            ->where('requisition.req_no', $req->req_no)
            ->has('requisition.lines', 1)
            ->where('requisition.lines.0.qty', (string) $req->items[0]->remainingQty()));

    // The loop closes visibly: once a round is awarded, the requisition's page has to name the order it
    // became. The casing is the point: `Model::$snakeAttributes` is still on in Laravel 12, so an
    // eager-loaded relation serialises as `converted_order` rather than `convertedOrder`, and a page that
    // reads the camelCase name renders an empty panel forever — quietly, with a 200 and no console error.
    // This is the only place that mistake can be caught.
    $orderIds = p3rqRfqSvc()->award($rfq->fresh(), [[
        'rfq_line_id' => $rfq->lines()->value('id'),
        'quote_id' => $rfq->quotes()->orderBy('total_amount')->value('id'),
    ]]);
    $req->refresh();

    expect($req->status)->toBe('converted');

    $this->get(route('purchase.requisitions.show', $req->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('requisition.converted_order.id', $orderIds[0])
            ->has('requisition.converted_order.order_no'));
});

test('the requisition picker offers only approved demand with something left to buy', function () {
    [$req] = p3rqApprovedRequisition();
    [$warehouse] = p3rqFixtures();
    actingAs(p3rqActor());
    $draft = p3rqSvc()->create(p3rqPayload([['description' => 'Nothing approved yet', 'qty' => 1]], $warehouse->id));

    $acting = actingAs(p3rqUser(['purchase.rfq.manage'], 'Buyer'))
        ->getJson(route('purchase.api.options', 'requisitions'))->assertOk();

    $labels = collect($acting->json('items'))->pluck('value')->all();

    expect($labels)->toContain($req->id)->and($labels)->not->toContain($draft->id);

    // A buyer with only the round grant may read the picker but not the register.
    $this->get(route('purchase.requisitions.index'))->assertForbidden();

    // And someone with neither is refused the door itself, whatever they put in the URL.
    actingAs(p3rqUser(['purchase.orders.view'], 'Unrelated'));

    $this->getJson(route('purchase.api.options', 'requisitions'))->assertForbidden();
});
