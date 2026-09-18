<?php

namespace App\Http\Requests;

use App\Support\MemberStatus;
use Illuminate\Validation\Rule;

class MemberStatusRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(MemberStatus::class)]];
    }
}
