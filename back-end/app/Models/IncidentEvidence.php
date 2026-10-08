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
    public const REQ_WRONG_PRODUCT_PHOTO   = 'wrong_product_photo';
    public const REQ_CORRECT_PRODUCT_PHOTO = 'correct_product_photo';
    public const REQ_RETURNED_GOODS_PHOTO  = 'returned_goods_photo';
    public const REQ_RECEIPT_OR_DOR        = 'receipt_or_dor';

    public const REQUIREMENTS = [
        self::REQ_DEFECT_PHOTO, self::REQ_MODEL_SERIAL_STICKER,
        self::REQ_BOX_TWO_SIDES, self::REQ_ACCESSORIES_WARRANTY,
        self::REQ_ASC_SERVICE_REPORT,
        self::REQ_WRONG_PRODUCT_PHOTO, self::REQ_CORRECT_PRODUCT_PHOTO,
        self::REQ_RETURNED_GOODS_PHOTO, self::REQ_RECEIPT_OR_DOR,
    ];

    /**
     * What a report is asked to attach.
     *
     * Reason-driven, not type-driven. A wrong-delivery return proves THE
     * DELIVERY WAS WRONG — the pair plus the receipt. A wrong-quantity or
     * unwanted return proves WHETHER THE GOODS ARE STILL SELLABLE — the goods,
     * the sticker, the box, the accessories. Neither needs the other's
     * evidence, and asking for it trains people to skip the checklist.
     *
     * `missing` asks for nothing at all: the item is gone, and demanding a
     * photograph of an absent unit teaches people to fake evidence.
     *
     * @return array<int,string>
     */
    public static function requiredFor(string $reason, ?string $returnReasonCode, bool $checkedByAsc = false): array
    {
        $required = match (true) {
            $reason === 'defective' => [
                self::REQ_DEFECT_PHOTO, self::REQ_MODEL_SERIAL_STICKER,
                self::REQ_BOX_TWO_SIDES, self::REQ_ACCESSORIES_WARRANTY,
            ],
            $reason === 'return' && $returnReasonCode === 'wrong_product' => [
                self::REQ_MODEL_SERIAL_STICKER, self::REQ_WRONG_PRODUCT_PHOTO,
                self::REQ_CORRECT_PRODUCT_PHOTO, self::REQ_RECEIPT_OR_DOR,
            ],
            $reason === 'return' => [
                self::REQ_MODEL_SERIAL_STICKER, self::REQ_BOX_TWO_SIDES,
                self::REQ_ACCESSORIES_WARRANTY, self::REQ_RETURNED_GOODS_PHOTO,
                self::REQ_RECEIPT_OR_DOR,
            ],
            $reason === 'wrong_type' => [self::REQ_MODEL_SERIAL_STICKER],
            default => [],
        };

        // Conditional on a fact, not on the type.
        if ($checkedByAsc && $reason === 'defective') {
            $required[] = self::REQ_ASC_SERVICE_REPORT;
        }

        return $required;
    }

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
