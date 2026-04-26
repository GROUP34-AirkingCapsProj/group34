# Update DraftPOCreator from Branch Fix Changes

## Information Gathered
- Old `branches` table replaced by unified `locations` table with `location_type = 'branch_office'`
- `branchesAPI.getAll()` now calls `locationsAPI.getAll({ location_type: 'branch_office' })`
- Location model uses `location_id` as primary key (not `id`)
- Display names come from `location_name` (accessor provides `name`)

## Issues in DraftPOCreator.jsx
1. `list[0].id` → should use `location_id ?? id` fallback
2. `b.id` branch lookups (×3) → should use `location_id ?? id`
3. `l.branch_id` location matching for PO → should use `location_id ?? id`
4. Error message references obsolete `branch_id` column
5. `b.id` in JSX option keys/values → should use `location_id ?? id`

## Plan
- [x] Edit DraftPOCreator.jsx — fix all branch_id/id references to use location_id
