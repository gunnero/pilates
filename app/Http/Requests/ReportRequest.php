<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

final class ReportRequest extends LifecycleRequest
{
    public function rules(): array
    {
        return [
            'report' => ['required', 'in:revenue,settlement,activity,expiry,attendance,occupancy,cancellations,popularity'],
            'from' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:2001-01-01', 'before_or_equal:2100-12-31'],
            'until' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
            'granularity' => ['sometimes', 'in:daily,weekly,monthly'],
            'comparison' => ['sometimes', 'in:none,previous,custom'],
            'compare_from' => ['required_if:comparison,custom', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'compare_until' => ['required_if:comparison,custom', 'date_format:Y-m-d', 'after_or_equal:compare_from', 'before_or_equal:2100-12-31'],
            'group_id' => ['sometimes', 'uuid'], 'trainer_id' => ['sometimes', 'uuid'], 'plan_id' => ['sometimes', 'uuid'],
            'currency' => ['sometimes', 'in:MKD'],
            'state' => ['sometimes', 'in:active,upcoming,expired,cancelled'],
            'payment_state' => ['sometimes', 'in:unpaid,partially_paid,paid,overpaid'],
            'expiry' => ['sometimes', 'in:expired,7,14,30,later'],
            'rank' => ['sometimes', 'in:present,reserved,waiting'],
            'page' => ['sometimes', 'integer', 'between:1,10000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'top' => ['sometimes', 'integer', 'between:1,50'], 'view' => ['sometimes', 'in:summary,details'],
            'format' => ['sometimes', 'in:csv,xlsx'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            foreach ([['from', 'until'], ['compare_from', 'compare_until']] as [$a, $b]) {
                if ($this->filled($a) !== $this->filled($b) || ($this->filled($a) && CarbonImmutable::parse($this->string($a)->toString())->diffInDays(CarbonImmutable::parse($this->string($b)->toString())) >= 366)) {
                    $v->errors()->add($b, __('reporting.range'));
                }
            }
            if ($this->input('comparison') !== 'custom' && ($this->has('compare_from') || $this->has('compare_until'))) {
                $v->errors()->add('comparison', __('reporting.filters'));
            }
            $finance = in_array($this->input('report'), ['revenue', 'settlement', 'expiry', 'activity'], true);
            foreach (['group_id', 'trainer_id', 'plan_id', 'state', 'payment_state', 'expiry', 'rank'] as $key) {
                $allowed = match ($key) {
                    'group_id', 'trainer_id' => ! $finance,
                    'plan_id' => $finance && $this->input('report') !== 'activity',
                    'state', 'payment_state' => $this->input('report') === 'settlement',
                    'expiry' => $this->input('report') === 'expiry',
                    'rank' => $this->input('report') === 'popularity',
                };
                if ($this->has($key) && ! $allowed) {
                    $v->errors()->add($key, __('reporting.filters'));
                }
            }
        }];
    }
}
