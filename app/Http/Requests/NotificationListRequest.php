<?php

namespace App\Http\Requests;

class NotificationListRequest extends ReservationListRequest
{
    public function rules(): array
    {
        return parent::rules() + ['unread' => ['sometimes', 'in:0,1']];
    }
}
