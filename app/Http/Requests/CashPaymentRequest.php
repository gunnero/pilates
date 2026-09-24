<?php

namespace App\Http\Requests;

class CashPaymentRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['membership_id' => ['required', 'uuid'], 'amount_minor' => ['required', 'integer:strict', 'min:1', 'max:100000000'], 'paid_at' => ['required', 'date', 'regex:/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/'], 'note' => ['nullable', 'string', 'max:500'], 'request_id' => ['required', 'uuid']];
    }
}
