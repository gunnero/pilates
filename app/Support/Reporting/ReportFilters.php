<?php

namespace App\Support\Reporting;

use Carbon\CarbonImmutable;

final readonly class ReportFilters
{
    public CarbonImmutable $from;

    public CarbonImmutable $until;

    public CarbonImmutable $clock;

    public string $granularity;

    /** @param array<string,mixed> $input */
    public function __construct(public array $input, public string $zone, ?CarbonImmutable $clock = null)
    {
        $this->clock = $clock ?? CarbonImmutable::now($zone);
        $this->from = isset($input['from']) ? CarbonImmutable::parse($input['from'], $zone)->startOfDay() : $this->clock->startOfMonth();
        $this->until = isset($input['until']) ? CarbonImmutable::parse($input['until'], $zone)->startOfDay() : $this->clock->startOfDay();
        $this->granularity = $input['granularity'] ?? 'daily';
    }

    public function start(): string
    {
        return $this->from->utc()->format('Y-m-d H:i:sP');
    }

    public function end(): string
    {
        return $this->until->addDay()->utc()->format('Y-m-d H:i:sP');
    }

    public function today(): string
    {
        return $this->clock->toDateString();
    }

    public function previous(): ?self
    {
        if (($this->input['comparison'] ?? 'previous') === 'none') {
            return null;
        }
        $custom = ($this->input['comparison'] ?? '') === 'custom';
        $days = (int) $this->from->diffInDays($this->until) + 1;

        return new self(array_replace($this->input, ['from' => $custom ? $this->input['compare_from'] : $this->from->subDays($days)->toDateString(), 'until' => $custom ? $this->input['compare_until'] : $this->from->subDay()->toDateString(), 'comparison' => 'none']), $this->zone, $this->clock);
    }

    /** @param literal-string $column
     * @return literal-string
     */
    public function bucket(string $column): string
    {
        $unit = match ($this->granularity) {
            'weekly' => 'week', 'monthly' => 'month', default => 'day'
        };

        return "to_char(date_trunc('$unit', $column AT TIME ZONE ?), 'YYYY-MM-DD')";
    }

    /** @return list<array{period:string,partial:bool}> */
    public function buckets(): array
    {
        $cursor = match ($this->granularity) {
            'weekly' => $this->from->startOfWeek(), 'monthly' => $this->from->startOfMonth(), default => $this->from
        };
        $rows = [];
        while ($cursor <= $this->until) {
            $next = match ($this->granularity) {
                'weekly' => $cursor->addWeek(), 'monthly' => $cursor->addMonth(), default => $cursor->addDay()
            };
            $rows[] = ['period' => $cursor->toDateString(), 'partial' => $cursor < $this->from || $next > $this->until->addDay() || $next > $this->clock];
            $cursor = $next;
        }

        return $rows;
    }
}
