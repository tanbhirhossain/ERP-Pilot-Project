<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Models\Order;
use Modules\SALES\Models\Quotation;

class OptionsController extends Controller
{
    public function __invoke(Request $r, string $type): JsonResponse
    {
        $search = $r->query('search');
        $limit = min((int) $r->query('limit', 50), 100);
        $data = match ($type) {
            'quotations' => Quotation::when($search, fn($q) => $q->where('quotation_no', 'like', "%{$search}%"))->orderByDesc('id')->limit($limit)->get(['id', 'quotation_no as label'])->map(fn($o) => ['value' => $o->id, 'label' => $o->label]),
            'orders' => Order::when($search, fn($q) => $q->where('order_no', 'like', "%{$search}%"))->orderByDesc('id')->limit($limit)->get(['id', 'order_no as label'])->map(fn($o) => ['value' => $o->id, 'label' => $o->label]),
            'invoices' => Invoice::when($search, fn($q) => $q->where('invoice_no', 'like', "%{$search}%"))->orderByDesc('id')->limit($limit)->get(['id', 'invoice_no as label'])->map(fn($o) => ['value' => $o->id, 'label' => $o->label]),
            default => collect(),
        };
        return response()->json(['data' => $data]);
    }
}
