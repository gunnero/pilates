<?php

namespace App\Http\Requests;

class SelfProfileRequest extends StudioMemberRequest
{
    public function rules(): array
    {
        return array_intersect_key(parent::rules(), array_flip(['first_name', 'last_name', 'phone', 'email', 'preferred_locale']));
    }
}
