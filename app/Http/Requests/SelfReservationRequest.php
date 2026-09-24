<?php

namespace App\Http\Requests;

class SelfReservationRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['allow_waiting' => ['sometimes', 'boolean:strict']];
    }
}
