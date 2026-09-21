<?php

namespace App\Http\Requests;

class ReservationListRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
