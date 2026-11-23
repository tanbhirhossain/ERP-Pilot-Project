<?php

/** Isolated SQLite test: PO -> GRN -> bill -> payment -> POS -> day-end, reconciling stock and journals. */
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Services\PurchaseOrderService;
use Modules\PURCHASE\Services\GoodsReceiptService;
use Modules\PURCHASE\Services\VendorBillService;
use Modules\SALES\DTO\InvoiceLineData;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Services\PosService;

uses(RefreshDatabase::class);

function flowLeg(int $id, string $code): array {
    $r = DB::table('acc_journal_lines as l')->join('acc_accounts as a','a.id','=','l.account_id')
        ->where('l.journal_id',$id)->where('a.code',$code)
        ->selectRaw('COALESCE(SUM(l.debit),0) AS dr, COALESCE(SUM(l.credit),0) AS cr')->first();
    return [(float)$r->dr,(float)$r->cr];
}
function flowBalance(int $id): void {
    $j = DB::table('acc_journals')->where('id',$id)->first();
    expect($j)->not->toBeNull()->and((float)$j->total_debit)->toBe((float)$j->total_credit);
}

test('a purchased delivery reconciles through stock, vendor payment and POS day-end', function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::where('email',config('core.super_admin.email','admin@example.com'))->first() ?? User::first());
    $warehouse=Warehouse::create(['name'=>'Integrated Flow Store','code'=>'FLOW-WH','type'=>'main','is_active'=>true,'allow_negative_stock'=>false]);
    $product=Product::create(['name'=>'Integrated Flow Product','sku'=>'FLOW-SKU','type'=>'stockable','status'=>'active','is_active'=>true,'valuation_method'=>'weighted','cost_price'=>40,'selling_price'=>100,'vat_rate'=>0]);
    $vendor=Partner::create(['partner_code'=>'FLOW-VENDOR','type'=>'vendor','name'=>'Flow Supplier','is_active'=>true]);
    $line=['product_id'=>$product->id,'product_name'=>$product->name,'sku'=>$product->sku,'qty'=>10,'unit_price'=>40,'discount_pct'=>0,'vat_rate'=>0];
    $order=app(PurchaseOrderService::class)->create(['vendor_id'=>$vendor->id,'warehouse_id'=>$warehouse->id,'order_date'=>now()->toDateString(),'items'=>[$line]]);
    $order=app(PurchaseOrderService::class)->confirm($order->fresh());
    $order->load('items');
    $receipt=app(GoodsReceiptService::class)->create(['order_id'=>$order->id,'vendor_id'=>$vendor->id,'warehouse_id'=>$warehouse->id,'receipt_date'=>now()->toDateString(),'items'=>[array_merge($line,['order_item_id'=>$order->items->first()->id])]]);
    $receipt=app(GoodsReceiptService::class)->post($receipt->fresh());
    expect((float)Stock::where('product_id',$product->id)->where('warehouse_id',$warehouse->id)->value('qty_on_hand'))->toBe(10.0)
        ->and($order->fresh()->status)->toBe('received');
    flowBalance($receipt->grni_journal_id);
    expect(flowLeg($receipt->grni_journal_id,config('accounts.defaults.inventory_code','1200')))->toBe([400.0,0.0])
        ->and(flowLeg($receipt->grni_journal_id,config('accounts.defaults.grni_code','2115')))->toBe([0.0,400.0]);
    $receipt->load('items');
    $bill=app(VendorBillService::class)->create(['order_id'=>$order->id,'receipt_id'=>$receipt->id,'vendor_id'=>$vendor->id,'warehouse_id'=>$warehouse->id,'bill_date'=>now()->toDateString(),'items'=>[array_merge($line,['receipt_item_id'=>$receipt->items->first()->id])]]);
    $bill=app(VendorBillService::class)->post($bill->fresh());
    flowBalance($bill->journal_id);
    expect(flowLeg($bill->journal_id,config('accounts.defaults.grni_code','2115')))->toBe([400.0,0.0])
        ->and(flowLeg($bill->journal_id,config('accounts.defaults.payable_code','2100')))->toBe([0.0,400.0]);
    $payment=app(VendorBillService::class)->recordPayment($bill,['amount'=>400,'payment_date'=>now()->toDateString(),'method'=>'cash']);
    flowBalance($payment->journal_id);
    expect(flowLeg($payment->journal_id,config('accounts.defaults.payable_code','2100')))->toBe([400.0,0.0])
        ->and(flowLeg($payment->journal_id,config('accounts.defaults.cash_code','1001')))->toBe([0.0,400.0])
        ->and($bill->fresh()->payment_status)->toBe('paid');
    $session=app(PosService::class)->openSession(['warehouse_id'=>$warehouse->id,'opening_cash'=>0]);
    $sale=app(PosService::class)->sell(new PosSaleData(warehouseId:$warehouse->id,lines:[new InvoiceLineData(productId:$product->id,qty:3,unitPrice:100)],paymentMethod:'cash',sessionId:$session->id));
    expect((float)Stock::where('product_id',$product->id)->where('warehouse_id',$warehouse->id)->value('qty_on_hand'))->toBe(7.0)
        ->and((float)$sale->cost_total)->toBe(120.0);
    $closed=app(PosService::class)->closeSession($session->fresh(),['closing_cash'=>300]);
    flowBalance($closed->journal_id);
    expect(flowLeg($closed->journal_id,config('accounts.defaults.cash_code','1001')))->toBe([300.0,0.0])
        ->and(flowLeg($closed->journal_id,config('accounts.defaults.sales_code','4001')))->toBe([0.0,300.0])
        ->and(flowLeg($closed->journal_id,config('accounts.defaults.cogs_code','5001')))->toBe([120.0,0.0])
        ->and(flowLeg($closed->journal_id,config('accounts.defaults.inventory_code','1200')))->toBe([0.0,120.0]);
});
