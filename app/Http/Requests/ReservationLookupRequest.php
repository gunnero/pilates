<?php

namespace App\Http\Requests;

class ReservationLookupRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['search' => ['required', 'string', 'min:2', 'max:100']];
    }
}
