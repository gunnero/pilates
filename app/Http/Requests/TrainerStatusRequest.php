<?php

namespace App\Http\Requests;

use App\Support\TrainerStatus;
use Illuminate\Validation\Rule;

class TrainerStatusRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(TrainerStatus::class)]];
    }
}
