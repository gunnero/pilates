<?php

namespace App\Http\Requests;

class SessionCancelRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['reason' => ['sometimes', 'nullable', 'string', 'max:300', 'not_regex:/[\x00-\x1F\x7F]/u']];
    }
}
