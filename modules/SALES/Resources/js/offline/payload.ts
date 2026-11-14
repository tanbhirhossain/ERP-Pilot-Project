/**
 * F22 — the one definition of "what the till sends".
 *
 * Extracted from `Terminal.vue` because the offline path has to produce *exactly* the row that
 * `PosSaleRequest` validates: a second builder for queued tickets is a second idea of what a sale is,
 * and the first time someone adds a field to the live path the offline path would start 422-ing on
 * every replay — quietly, days later, in the middle of a rush.
 *
 * So the page computes its totals for the screen and hands them over; this builds the body; and
 * `Phase3OfflinePosTest` asserts the field list still matches the request's rules.
 */

import type { SalePayload } from './queue';

export interface CartLine {
  product_id: number;
  variant_id?: number | null;
  qty: number;
  unit_price: number;
  discount_pct?: number;
  vat_rate?: number;
}

export interface TillSaleInput {
  warehouseId: number | null;
  customerId: number | null;
  customerName?: string | null;
  paymentMethod: string;
  bankId: number | null;
  sessionId: number | null;
  lines: CartLine[];
  /** Split tender, in currency. Only read when `paymentMethod` is `split`. */
  cashAmount?: number | null;
  bankAmount?: number | null;
  /**
   * Tender, already in *cents* and already clamped, because the page clamps it for the figures it
   * shows (change due, balance due) and the body must carry the same number the cashier saw — a
   * second clamp here with different rounding is how a receipt and a ledger row disagree by a poisha.
   */
  paidNowCents?: number;
  tenderedCents?: number;
}

/** One line of a cart, in the units the server expects. */
function line(l: CartLine) {
  return {
    product_id: Number(l.product_id),
    variant_id: l.variant_id ?? null,
    qty: Number(l.qty),
    unit_price: Number(l.unit_price),
    discount_pct: Number(l.discount_pct) || 0,
    vat_rate: Number(l.vat_rate) || 0,
  };
}

export function buildSalePayload(input: TillSaleInput): SalePayload {
  const payload: SalePayload = {
    warehouse_id: input.warehouseId,
    customer_id: input.customerId,
    // Trimmed, not merely defaulted: a blank-but-not-null name would print an empty header on the
    // receipt and put an empty string in the day-end report's customer column.
    customer_name: input.customerName?.trim() || 'Walk-in Customer',
    payment_method: input.paymentMethod,
    bank_id: input.bankId,
    session_id: input.sessionId,
    items: input.lines.map(line),
  };

  if (input.paymentMethod === 'split') {
    payload.cash_amount = Number(input.cashAmount) || 0;
    payload.bank_amount = Number(input.bankAmount) || 0;
  }

  // A credit sale carries what was taken now; anything else carries what was handed over, so the
  // ledger can record the change. Same two fields the live till has always sent.
  if (input.paymentMethod === 'due') payload.paid_amount = (Number(input.paidNowCents) || 0) / 100;
  if (input.paymentMethod !== 'due' && input.paymentMethod !== 'split') {
    payload.tendered_amount = (Number(input.tenderedCents) || 0) / 100;
  }

  return payload;
}

/** Line total in cents: gross, less discount, plus VAT — the server's order of operations, exactly. */
export function lineTotalCents(l: CartLine): number {
  const gross = Math.round((Number(l.qty) || 0) * (Number(l.unit_price) || 0) * 100);
  const discount = Math.round(gross * ((Number(l.discount_pct) || 0) / 100));
  const net = gross - discount;

  return net + Math.round(net * ((Number(l.vat_rate) || 0) / 100));
}

export function cartTotalCents(lines: CartLine[]): number {
  return lines.reduce((sum, l) => sum + lineTotalCents(l), 0);
}

export function cartTotal(lines: CartLine[]): number {
  return cartTotalCents(lines) / 100;
}

/** The queue's one-line description of a ticket, built from what the till already knows. */
export function cartLabel(lines: CartLine[], customerName?: string | null): string {
  const units = lines.reduce((sum, l) => sum + (Number(l.qty) || 0), 0);
  const plural = (n: number, word: string) => `${n % 1 === 0 ? n : n.toFixed(2)} ${n === 1 ? word : `${word}s`}`;

  return `${plural(lines.length, 'line')} · ${plural(units, 'unit')} · ${customerName?.trim() || 'Walk-in Customer'}`;
}
