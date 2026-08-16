<?php

namespace App\Reports;

/**
 * One filter a report declares it honors.
 *
 * The frontend builds its dialog inputs from these, and the runner builds its
 * validation rules from them, so a report cannot be offered a filter it does
 * not implement — nor quietly ignore one that was sent.
 */
class ReportParameter
{
    public const TYPE_DATE_RANGE    = 'date_range';
    public const TYPE_LOCATION      = 'location';
    public const TYPE_LOCATION_TYPE = 'location_type';
    public const TYPE_CATEGORY      = 'category';
    public const TYPE_USER          = 'user';
    public const TYPE_SELECT        = 'select';

    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly array $options = [],
        public readonly mixed $default = null,
    ) {
    }

    /**
     * The request keys this parameter consumes. A date range is two fields on
     * the wire (date_from / date_to) but one control in the dialog.
     */
    public function requestKeys(): array
    {
        return $this->type === self::TYPE_DATE_RANGE
            ? ['date_from', 'date_to']
            : [$this->key];
    }

    /** Laravel validation rules, keyed by request field. */
    public function rules(): array
    {
        $required = $this->required ? 'required' : 'nullable';

        return match ($this->type) {
            self::TYPE_DATE_RANGE => [
                'date_from' => "{$required}|date",
                'date_to'   => "{$required}|date|after_or_equal:date_from",
            ],
            self::TYPE_LOCATION => [
                $this->key => "{$required}|integer|exists:locations,location_id",
            ],
            self::TYPE_LOCATION_TYPE => [
                $this->key => "{$required}|string|in:warehouse,showroom,branch_office",
            ],
            self::TYPE_CATEGORY => [
                $this->key => "{$required}|integer|exists:category_lookup,category_id",
            ],
            self::TYPE_USER => [
                $this->key => "{$required}|integer|exists:users,user_id",
            ],
            self::TYPE_SELECT => [
                $this->key => $this->options === []
                    ? "{$required}|string"
                    : "{$required}|string|in:" . implode(',', array_keys($this->options)),
            ],
            default => throw new \InvalidArgumentException("Unknown report parameter type: {$this->type}"),
        };
    }

    public function toArray(): array
    {
        return [
            'key'      => $this->key,
            'type'     => $this->type,
            'label'    => $this->label,
            'required' => $this->required,
            'options'  => $this->options,
            'default'  => $this->default,
        ];
    }
}
