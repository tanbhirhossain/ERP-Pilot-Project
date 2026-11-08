<?php

namespace Modules\ACCOUNTS\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ACCOUNTS\Support\JournalBalance;

class StoreJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accounts.journals.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'journal_date' => ['required', 'date'],
            'type' => ['required', 'in:JV,PV,RV,CV,BV,SV,PV'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'partner_id' => ['nullable', 'integer'],
            'partner_type' => ['nullable', 'in:vendor,customer'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'exists:acc_accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.partner_id' => ['nullable', 'integer'],
            'lines.*.cost_center' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator($v): void
    {
        $v->after(function ($validator) {
            // Same rule the service enforces, from the same place: JournalBalance. This used to be a
            // second copy of a one-cent tolerance, which disagreed with the exact 2dp comparison post()
            // makes through Journal::isBalanced().
            try {
                JournalBalance::assertBalanced($this->input('lines', []));
            } catch (\DomainException $e) {
                $validator->errors()->add('lines', $e->getMessage());
            }
        });
    }
}
