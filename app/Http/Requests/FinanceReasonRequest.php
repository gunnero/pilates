<?php

namespace App\Http\Requests;

class FinanceReasonRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', $this->routeIs('cash.reverse') ? 'in:wrong_member,wrong_amount,wrong_date,duplicate,other' : 'in:mistake,member_request,other']];
    }
}
