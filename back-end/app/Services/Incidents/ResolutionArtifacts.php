<?php

namespace App\Services\Incidents;

/**
 * What a resolution posted, if anything. Named constructors keep the null
 * cases self-documenting — `ResolutionArtifacts::none()` says what it means
 * where a two-null array did not.
 */
final class ResolutionArtifacts
{
    private function __construct(
        public readonly ?int $adjustmentId = null,
        public readonly ?int $correctionId = null,
    ) {}

    public static function adjustment(int $id): self { return new self(adjustmentId: $id); }
    public static function correction(int $id): self { return new self(correctionId: $id); }
    public static function none(): self { return new self(); }
}
