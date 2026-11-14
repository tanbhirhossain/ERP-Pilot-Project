<?php

namespace Modules\CORE\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Database\Eloquent\Model;
use Milon\Barcode\DNS2D;
use Modules\CORE\Models\CoreSetting;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PURCHASE\Models\GoodsReceipt;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Models\VendorBill;
use Modules\SALES\Models\Delivery;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Models\Payment;

/**
 * Branded document PDF suite (Phase 2 — U16).
 *
 * One letterhead layout (company identity from core_settings, BIN, signature
 * blocks, verification QR) shared by seven document templates: invoice, purchase
 * order, GRN, vendor bill, delivery challan, payslip and payment receipt. Each
 * type is allow-listed with its model, view permission and eager loads, so the
 * controller stays generic and every document is permission-gated.
 */
class DocumentPdfService
{
    /**
     * @var array<string, array{model: class-string, permission: string, view: string, relations: list<string>, number: string, label: string}>
     */
    private const SPECS = [
        'invoice' => ['model' => Invoice::class, 'permission' => 'sales.invoices.view', 'view' => 'core::documents.invoice', 'relations' => ['customer', 'warehouse', 'items'], 'number' => 'invoice_no', 'label' => 'Invoice'],
        'purchase-order' => ['model' => PurchaseOrder::class, 'permission' => 'purchase.orders.view', 'view' => 'core::documents.purchase-order', 'relations' => ['vendor', 'warehouse', 'items'], 'number' => 'order_no', 'label' => 'PO'],
        'grn' => ['model' => GoodsReceipt::class, 'permission' => 'purchase.receipts.view', 'view' => 'core::documents.grn', 'relations' => ['vendor', 'warehouse', 'order', 'items'], 'number' => 'receipt_no', 'label' => 'GRN'],
        'bill' => ['model' => VendorBill::class, 'permission' => 'purchase.bills.view', 'view' => 'core::documents.bill', 'relations' => ['vendor', 'warehouse', 'order', 'items'], 'number' => 'bill_no', 'label' => 'Bill'],
        'delivery' => ['model' => Delivery::class, 'permission' => 'sales.deliveries.view', 'view' => 'core::documents.delivery', 'relations' => ['customer', 'warehouse', 'order', 'invoice', 'items'], 'number' => 'delivery_no', 'label' => 'Delivery'],
        'payslip' => ['model' => PayrollSlip::class, 'permission' => 'payroll.slips.view', 'view' => 'core::documents.payslip', 'relations' => [], 'number' => 'id', 'label' => 'Payslip'],
        'receipt' => ['model' => Payment::class, 'permission' => 'sales.invoices.view', 'view' => 'core::documents.receipt', 'relations' => ['customer', 'invoice'], 'number' => 'payment_no', 'label' => 'Receipt'],
    ];

    /** @return array{model: class-string, permission: string, view: string, relations: list<string>, number: string, label: string}|null */
    public function spec(string $type): ?array
    {
        return self::SPECS[$type] ?? null;
    }

    public function render(string $type, Model $doc, string $generatedBy): DomPdf
    {
        $spec = $this->spec($type);
        abort_if($spec === null, 404, 'Unknown document type.');

        $company = $this->company();
        $number = (string) ($doc->{$spec['number']} ?? $doc->getKey());
        $qr = (new DNS2D)->getBarcodePNG(
            implode('|', [strtoupper($type), $number, $company['name'], now()->toDateString()]),
            'QRCODE',
            3,
            3,
        );

        return Pdf::loadView($spec['view'], [
            'doc' => $doc,
            'company' => $company,
            'qr' => $qr,
            'generatedAt' => now()->format('d M Y H:i'),
            'generatedBy' => $generatedBy,
        ])->setPaper('a4')->setOptions([
            'isFontSubsettingEnabled' => true,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);
    }

    public function filename(string $type, Model $doc): string
    {
        $spec = $this->spec($type);
        $number = (string) ($doc->{$spec['number']} ?? $doc->getKey());

        return preg_replace('/[^A-Za-z0-9._-]/', '-', $spec['label'].'-'.$number).'.pdf';
    }

    /**
     * Company identity for the letterhead. Stored as core_settings keys so it can
     * be edited without a deploy; falls back to the seeded demo identity.
     *
     * @return array{name: string, address: string, bin: string, phone: string, email: string}
     */
    private function company(): array
    {
        $stored = CoreSetting::whereIn('key', ['company.name', 'company.address', 'company.bin', 'company.phone', 'company.email'])
            ->pluck('value', 'key');

        return [
            'name' => $stored['company.name'] ?? 'ERP Pilot Ltd.',
            'address' => $stored['company.address'] ?? 'Chattogram EPZ, Bangladesh',
            'bin' => $stored['company.bin'] ?? '001234567-0101',
            'phone' => $stored['company.phone'] ?? '+880 31 000 0000',
            'email' => $stored['company.email'] ?? 'accounts@erppilot.example',
        ];
    }
}
