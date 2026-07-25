<?php

namespace App\Support;

class ScanResult
{
    public const ACCEPTED        = 'ACCEPTED';
    public const UNKNOWN_BARCODE = 'UNKNOWN_BARCODE';
    public const WRONG_PRODUCT   = 'WRONG_PRODUCT';
    public const OVER_PICK       = 'OVER_PICK';
    public const FIFO_VIOLATION  = 'FIFO_VIOLATION';

    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?int $productId = null,
        public readonly ?string $productName = null,
        public readonly ?int $serialId = null,
        public readonly ?string $serialNumber = null,
        public readonly ?int $batchId = null,
        public readonly ?string $zoneLabel = null,
        public readonly ?int $expectedBatchId = null,
        public readonly ?string $expectedZoneLabel = null,
        public readonly ?string $expectedReceivedAt = null,
    ) {
    }

    public function accepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    /** Only a FIFO violation may be overridden, and only by filing an incident. */
    public function overridable(): bool
    {
        return $this->status === self::FIFO_VIOLATION;
    }

    public function toArray(): array
    {
        return [
            'status'               => $this->status,
            'accepted'             => $this->accepted(),
            'overridable'          => $this->overridable(),
            'message'              => $this->message,
            'product_id'           => $this->productId,
            'product_name'         => $this->productName,
            'serial_id'            => $this->serialId,
            'serial_number'        => $this->serialNumber,
            'batch_id'             => $this->batchId,
            'zone_label'           => $this->zoneLabel,
            'expected_batch_id'    => $this->expectedBatchId,
            'expected_zone_label'  => $this->expectedZoneLabel,
            'expected_received_at' => $this->expectedReceivedAt,
        ];
    }
}
