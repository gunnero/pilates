<?php

namespace App\Http\Requests;

class FinanceListRequest extends ReservationListRequest
{
    public function rules(): array
    {
        return parent::rules() + ['member_id' => ['sometimes', 'uuid'], 'membership_id' => ['sometimes', 'uuid'], 'state' => ['sometimes', 'in:all,active,upcoming,expired,cancelled,expiring'], 'payment_state' => ['sometimes', 'in:all,unpaid,partially_paid,paid,overpaid'], 'status' => ['sometimes', 'in:all,active,inactive,archived']];
    }
}
