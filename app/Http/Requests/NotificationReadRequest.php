<?php

namespace App\Http\Requests;

class NotificationReadRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return $this->route('notification') !== null ? [] : ['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'uuid', 'distinct']];
    }
}
