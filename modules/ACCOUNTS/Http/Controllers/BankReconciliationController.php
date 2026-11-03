<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Models\BankStatement;
use Modules\ACCOUNTS\Models\BankTransaction;

class BankReconciliationController extends Controller
{
    public function index(Request $r): Response
    {
        $bankId = $r->query('bank_id');
        $bank = $bankId ? Bank::find($bankId) : Bank::first();
        $statements = $bank ? BankStatement::where('bank_id', $bank->id)->orderBy('txn_date')->orderBy('id')->limit(200)->get()->map(fn($s)=>[
            'id'=>$s->id,'txn_date'=>$s->txn_date->format('Y-m-d'),'description'=>$s->description,'reference_no'=>$s->reference_no,'amount'=>$s->amount,'balance'=>$s->balance,'is_reconciled'=>$s->is_reconciled,'matched_bank_txn_id'=>$s->matched_bank_txn_id,
        ]) : collect();
        $unreconciledTxns = $bank ? BankTransaction::where('bank_id',$bank->id)->where('status','pending')->orderBy('transaction_date')->limit(100)->get(['id','transaction_date','amount','type','reference_no','description']) : collect();
        $banks = Bank::orderBy('name')->get(['id','name','bank_name','account_no']);
        return Inertia::render('ACCOUNTS::Banks/Reconcile', ['banks'=>$banks,'bank'=>$bank, 'statements'=>$statements, 'unreconciledTxns'=>$unreconciledTxns]);
    }

    public function import(Request $r)
    {
        $r->validate(['bank_id'=>'required|exists:acc_banks,id','file'=>'required|file|mimes:csv,txt|max:2048']);
        $bank = Bank::findOrFail($r->input('bank_id'));
        $path = $r->file('file')->getRealPath();
        $batch = 'IMP-'.now()->format('YmdHis').'-'.Str::random(4);
        $count=0;
        if (($h=fopen($path,'r'))!==false) {
            $header = fgetcsv($h);
            $header = array_map(fn($v)=>strtolower(trim($v)), $header ?? []);
            // expected: date, description, reference, amount, balance (flexible)
            while (($row=fgetcsv($h))!==false) {
                if (count($row)<2) continue;
                $row = array_combine($header, array_pad($row, count($header), null)) ?? [];
                // fallback positional: 0=date,1=desc,2=ref,3=amount,4=balance
                $date = $row['date'] ?? $row['txn_date'] ?? $row[0] ?? null;
                $desc = $row['description'] ?? $row['desc'] ?? $row['narration'] ?? $row[1] ?? null;
                $ref = $row['reference_no'] ?? $row['reference'] ?? $row['ref'] ?? $row[2] ?? null;
                $amount = $row['amount'] ?? $row['debit'] ?? $row[3] ?? null;
                // handle credit/debit split columns
                if (isset($row['credit']) && isset($row['debit'])) {
                    $amount = ((float)($row['credit']??0)) - ((float)($row['debit']??0));
                    if (abs($amount)<0.005) $amount = $row['amount'] ?? 0;
                } elseif (isset($row['withdrawal']) || isset($row['deposit'])) {
                    $amount = ((float)($row['deposit']??0)) - ((float)($row['withdrawal']??0));
                }
                $balance = $row['balance'] ?? $row[4] ?? null;
                if (!$date) continue;
                try { $d = \Carbon\Carbon::parse(trim($date))->format('Y-m-d'); } catch (\Throwable $e) { continue; }
                $amt = (float)preg_replace('/[^0-9\-\.]/','', (string)$amount);
                if (abs($amt)<0.005 && trim((string)$desc)==='') continue;
                BankStatement::create(['bank_id'=>$bank->id,'txn_date'=>$d,'description'=>trim((string)$desc),'reference_no'=>trim((string)$ref),'amount'=>$amt,'balance'=> $balance!==null ? (float)preg_replace('/[^0-9\-\.]/','', (string)$balance) : null,'import_batch'=>$batch]);
                $count++;
            }
            fclose($h);
        }
        return back()->with('success', "Imported {$count} statement lines (batch {$batch}).");
    }

    public function reconcile(Request $r)
    {
        $r->validate(['statement_id'=>'required|exists:acc_bank_statements,id','bank_txn_id'=>'required|exists:acc_bank_transactions,id']);
        $st = BankStatement::findOrFail($r->input('statement_id'));
        $txn = BankTransaction::findOrFail($r->input('bank_txn_id'));
        if ($st->is_reconciled) return back()->with('error','Statement line already reconciled.');
        if ($st->bank_id !== $txn->bank_id) return back()->with('error','Bank mismatch.');
        // amount tolerance 0.01
        $stmtAmt = abs((float)$st->amount);
        $txnAmt = abs((float)$txn->amount);
        if (abs($stmtAmt - $txnAmt) > 0.01) return back()->with('error',"Amount mismatch: statement {$st->amount} vs ledger {$txn->amount}.");
        $st->update(['matched_bank_txn_id'=>$txn->id,'is_reconciled'=>true,'reconciled_at'=>now()]);
        $txn->update(['status'=>'cleared']);
        return back()->with('success','Reconciled: '.$st->reference_no.' ↔ '.$txn->reference_no);
    }

    public function unreconcile(BankStatement $statement)
    {
        if (!$statement->is_reconciled) return back()->with('error','Not reconciled.');
        if ($statement->matched_bank_txn_id) BankTransaction::where('id',$statement->matched_bank_txn_id)->update(['status'=>'pending']);
        $statement->update(['is_reconciled'=>false,'matched_bank_txn_id'=>null,'reconciled_at'=>null]);
        return back()->with('success','Unreconciled.');
    }
}
