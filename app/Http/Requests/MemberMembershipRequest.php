<?php

namespace App\Http\Requests;

class MemberMembershipRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['member_id' => ['required', 'uuid'], 'plan_id' => ['required', 'uuid'], 'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31']];
    }
}
