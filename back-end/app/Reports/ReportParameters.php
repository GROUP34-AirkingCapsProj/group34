<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * Validated parameter values, handed to a definition's build().
 *
 * Must only be constructed from request data that has already passed the
 * validation rules built from each ReportParameter (see
 * ReportParameter::rules()) — this class does not validate anything itself.
 * On garbage input, `dateFrom()`/`dateTo()` let CarbonImmutable::parse()
 * throw rather than silently producing a wrong date.
 */
class ReportParameters
{
    public function __construct(private readonly array $values)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '';
    }

    public function int(string $key): ?int
    {
        return $this->has($key) ? (int) $this->values[$key] : null;
    }

    /** Start of the from-day, so a same-day range includes that whole day. */
    public function dateFrom(): ?CarbonImmutable
    {
        return $this->has('date_from')
            ? CarbonImmutable::parse($this->values['date_from'])->startOfDay()
            : null;
    }

    /** End of the to-day — a range of 1st..1st must not be an empty window. */
    public function dateTo(): ?CarbonImmutable
    {
        return $this->has('date_to')
            ? CarbonImmutable::parse($this->values['date_to'])->endOfDay()
            : null;
    }

    public function all(): array
    {
        return $this->values;
    }
}
