<?php

namespace App\Http\Requests;

class GroupStatusRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['status' => ['required', 'in:active,inactive,archived']];
    }
}
