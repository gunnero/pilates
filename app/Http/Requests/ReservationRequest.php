<?php

namespace App\Http\Requests;

class ReservationRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['member_id' => ['required', 'uuid'], 'allow_waiting' => ['sometimes', 'boolean']];
    }
}
