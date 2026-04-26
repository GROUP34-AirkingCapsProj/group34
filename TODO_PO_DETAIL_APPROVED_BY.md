# TODO: Add approved_by to purchase_order_details

## Steps
- [x] 1. Update airking_clean.sql - add approved_by column to purchase_order_details
- [x] 2. Create Laravel migration for approved_by on purchase_order_details
- [x] 3. Update PurchaseOrderDetail model - add approved_by fillable + relationship
- [x] 4. Update PurchaseOrderController - validate and store approved_by per detail
- [x] 5. Update ApprovalQueue.jsx - display detail-level approved_by
- [x] 6. Update DraftPOCreator.jsx - display detail-level approved_by
- [x] 7. Run migration

## Summary of Changes

### Backend
1. **airking_clean.sql** — Added `approved_by` column and FK to `purchase_order_details` table
2. **Migration** `2025_01_20_000000_add_approved_by_to_purchase_order_details.php` — Created migration to add `approved_by` column with FK to `users(user_id)`
3. **PurchaseOrderDetail.php** — Added `approved_by` to `$fillable` and added `approvedBy()` BelongsTo relationship
4. **PurchaseOrderController.php** — Added validation for `details.*.approved_by`, included `details.approvedBy` in eager-load relations, and ensured approved_by flows through on create/update

### Frontend
5. **ApprovalQueue.jsx** — Mapped `approved_by` from API response (`approvedBy` or `approved_by`) into line item rows; added green "✓ Approved by: Name" indicator under each product name in the line item table
6. **DraftPOCreator.jsx** — Updated detail rendering to check both `approvedBy` and `approved_by` properties; displays green "✓ Approved by: Name" indicator when populated

