<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attachment against the client's checklist — a photo, or an explicit skip
 * with the reason it could not be provided.
 */
class IncidentEvidence extends Model
{
    use Auditable;

    public const REQ_DEFECT_PHOTO         = 'defect_photo';
    public const REQ_MODEL_SERIAL_STICKER = 'model_serial_sticker';
    public const REQ_BOX_TWO_SIDES        = 'box_two_sides';
    public const REQ_ACCESSORIES_WARRANTY = 'accessories_warranty';
    public const REQ_ASC_SERVICE_REPORT   = 'asc_service_report';

    public const REQUIREMENTS = [
        self::REQ_DEFECT_PHOTO, self::REQ_MODEL_SERIAL_STICKER,
        self::REQ_BOX_TWO_SIDES, self::REQ_ACCESSORIES_WARRANTY,
        self::REQ_ASC_SERVICE_REPORT,
    ];

    /**
     * What each incident type is asked for.
     *
     * `missing` asks for NOTHING: the item is gone, so there is nothing to
     * photograph, and demanding a picture of an absent unit is how a checklist
     * teaches people to fake evidence. `wrong_type` needs the sticker, because
     * the sticker not matching the goods IS the incident.
     *
     * asc_service_report is added at runtime when checked_by_asc is set — it is
     * conditional on a fact, not on the type.
     */
    public const REQUIRED_BY_REASON = [
        'defective'     => [self::REQ_DEFECT_PHOTO, self::REQ_MODEL_SERIAL_STICKER,
                            self::REQ_BOX_TWO_SIDES, self::REQ_ACCESSORIES_WARRANTY],
        'return'        => [self::REQ_DEFECT_PHOTO, self::REQ_MODEL_SERIAL_STICKER,
                            self::REQ_BOX_TWO_SIDES, self::REQ_ACCESSORIES_WARRANTY],
        'wrong_type'    => [self::REQ_MODEL_SERIAL_STICKER],
        'missing'       => [],
        'zone_mismatch' => [],
        'other'         => [],
    ];

    protected $table      = 'incident_evidence';
    protected $primaryKey = 'evidence_id';

    protected $fillable = [
        'incident_id', 'requirement', 'photo_path', 'photo_source',
        'skipped_reason', 'uploaded_by',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_id', 'incident_id');
    }
}
