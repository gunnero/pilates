<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

final class OperationsRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return ['from' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'], 'until' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'], 'location_id' => ['sometimes', 'uuid'], 'trainer_id' => ['sometimes', 'uuid'], 'group_id' => ['sometimes', 'uuid'], 'status' => ['sometimes', 'in:scheduled,cancelled,all'], 'page' => ['sometimes', 'integer', 'between:1,10000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $v): void {
            if ($v->errors()->isEmpty() && $this->filled('from') && CarbonImmutable::parse($this->string('from')->toString())->diffInDays(CarbonImmutable::parse($this->string('until')->toString())) >= 31) {
                $v->errors()->add('until', __('operations.range'));
            }
            if ($this->filled('until') && ! $this->filled('from')) {
                $v->errors()->add('from', __('operations.range'));
            }
        }];
    }
}
