<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

class SelfServiceListRequest extends ReservationListRequest
{
    public function rules(): array
    {
        return parent::rules() + ['from' => ['sometimes', 'date_format:Y-m-d'], 'until' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'], 'group_id' => ['sometimes', 'uuid'], 'relationship' => ['sometimes', 'in:all,reserved,waiting']];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $v): void {
            if (! $v->errors()->any() && $this->filled('from') && CarbonImmutable::parse($this->string('from')->toString())->diffInDays(CarbonImmutable::parse($this->string('until')->toString())) > 55) {
                $v->errors()->add('until', __('selfservice.range'));
            }if ($this->filled('until') && ! $this->filled('from')) {
                $v->errors()->add('from', __('selfservice.range'));
            }
        }];
    }
}
