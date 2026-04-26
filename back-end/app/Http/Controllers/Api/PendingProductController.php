<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\PendingProduct;
use App\Models\Product;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PendingProductController extends Controller
{
    use ApiResponse;

    /** @return list<string> */
    private static function supplyTypes(): array
    {
        return [
            'Refrigerant',
            'Copper / tubing',
            'Electrical',
            'Insulation',
            'Fasteners & hardware',
            'Installation supplies',
            'Lubricants & chemicals',
            'Other',
        ];
    }

    /** @return list<array{key: string, label: string}> */
    private static function packagingUnits(): array
    {
        return [
            ['key' => 'piece', 'label' => 'Piece / Each'],
            ['key' => 'box', 'label' => 'Box'],
            ['key' => 'roll', 'label' => 'Roll'],
            ['key' => 'kg', 'label' => 'Kilogram'],
            ['key' => 'meter', 'label' => 'Meter'],
            ['key' => 'set', 'label' => 'Set'],
            ['key' => 'bottle', 'label' => 'Bottle'],
            ['key' => 'carton', 'label' => 'Carton'],
            ['key' => 'bundle', 'label' => 'Bundle'],
            ['key' => 'pair', 'label' => 'Pair'],
        ];
    }

    private static function packagingKeys(): array
    {
        return array_column(self::packagingUnits(), 'key');
    }

    private static function packagingLabel(string $key): string
    {
        foreach (self::packagingUnits() as $row) {
            if ($row['key'] === $key) {
                return $row['label'];
            }
        }

        return $key;
    }

    public function catalog()
    {
        return $this->success([
            'supply_types'    => self::supplyTypes(),
            'packaging_units' => self::packagingUnits(),
        ], 'Consumable catalog');
    }

    /** GET /api/supply-types — canonical list (same validation as consumable registration). */
    public function supplyTypesIndex()
    {
        return $this->success(self::supplyTypes(), 'Supply types');
    }

    public function store(Request $request)
    {
        $kind = $request->input('registration_kind', 'consumable');

        try {
            if ($kind === 'appliance') {
                return $this->storeAppliance($request);
            }

            return $this->storeConsumable($request);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    private function storeConsumable(Request $request)
    {
        $packKeys = self::packagingKeys();

        $validated = $request->validate([
            'barcode'                => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'barcode'),
                Rule::unique('pending_products', 'barcode')->where('status', 'pending'),
            ],
            'category_id'            => 'nullable|exists:category_lookup,category_id',
            'brand_id'               => 'nullable|exists:brand_lookup,brand_id',
            'supply_type'            => ['required', 'string', 'max:120', Rule::in(self::supplyTypes())],
            'packaging_unit'         => ['required', 'string', 'max:64', Rule::in($packKeys)],
            'quantity_per_package'   => 'nullable|integer|min:1',
            'opening_location_id'    => 'required|exists:locations,location_id',
            'unit_price'             => 'nullable|numeric|min:0',
            'cost_price'             => 'nullable|numeric|min:0',
        ]);

        $barcode = trim($validated['barcode']);
        $packLabel = self::packagingLabel($validated['packaging_unit']);
        $generatedName = "{$validated['supply_type']} · {$packLabel} · {$barcode}";

        $row = PendingProduct::create([
            'registration_kind'    => 'consumable',
            'barcode'              => $barcode,
            'supply_type'          => $validated['supply_type'],
            'packaging_unit'       => $validated['packaging_unit'],
            'quantity_per_package' => $validated['quantity_per_package'] ?? null,
            'category_id'          => $validated['category_id'] ?? null,
            'brand_id'             => $validated['brand_id'] ?? null,
            'opening_location_id'  => (int) $validated['opening_location_id'],
            'opening_quantity'     => 1,
            'generated_name'       => $generatedName,
            'status'               => 'pending',
            'unit_price'           => $validated['unit_price'] ?? null,
            'cost_price'           => $validated['cost_price'] ?? null,
            'created_by'           => $request->user()?->user_id,
        ]);

        $row->load(['category', 'brand', 'openingLocation', 'creator']);

        return $this->success($row, 'Registration submitted for approval', 201);
    }

    private function storeAppliance(Request $request)
    {
        $validated = $request->validate([
            'barcode'                                      => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'barcode'),
                Rule::unique('pending_products', 'barcode')->where('status', 'pending'),
            ],
            'unit_price'                                   => 'nullable|numeric|min:0',
            'cost_price'                                   => 'nullable|numeric|min:0',
            'appliance'                                    => 'required|array',
            'appliance.product_name'                       => 'required|string|max:255',
            'appliance.product_code'                       => 'nullable|string|max:50',
            'appliance.capacity_rating'                    => 'nullable|string|max:20',
            'appliance.variant'                            => 'nullable|string|max:255',
            'appliance.category_id'                        => 'required|exists:category_lookup,category_id',
            'appliance.brand_id'                           => 'required|exists:brand_lookup,brand_id',
            'appliance.unit_id'                            => 'required|exists:unit_lookup,unit_id',
            'appliance.warranty_period_months'             => 'nullable|integer|min:0',
            'appliance.initial_location_id'                => 'required|exists:locations,location_id',
        ]);

        $snap = $validated['appliance'];
        $barcode = trim($validated['barcode']);

        $row = PendingProduct::create([
            'registration_kind'    => 'appliance',
            'barcode'              => $barcode,
            'supply_type'          => null,
            'packaging_unit'       => null,
            'quantity_per_package' => null,
            'category_id'          => (int) $snap['category_id'],
            'brand_id'             => (int) $snap['brand_id'],
            'opening_location_id'  => (int) $snap['initial_location_id'],
            'opening_quantity'     => 1,
            'generated_name'       => $snap['product_name'],
            'appliance_snapshot'   => $snap,
            'status'               => 'pending',
            'unit_price'           => $validated['unit_price'] ?? null,
            'cost_price'           => $validated['cost_price'] ?? null,
            'created_by'           => $request->user()?->user_id,
        ]);

        $row->load(['category', 'brand', 'openingLocation', 'creator']);

        return $this->success($row, 'Registration submitted for approval', 201);
    }

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 50), 200);

        $query = PendingProduct::query()
            ->with(['category', 'brand', 'creator', 'openingLocation'])
            ->where('status', 'pending')
            ->orderByDesc('created_at');

        return $this->paginated($query->paginate($perPage), 'Pending registrations');
    }

    public function approve(Request $request, PendingProduct $pendingProduct)
    {
        if ($pendingProduct->status !== 'pending') {
            return $this->error('This registration was already processed.', 400);
        }

        try {
            $validated = $request->validate([
                'final_product_name' => 'required|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $finalName = trim($validated['final_product_name']);
        $uid = $request->user()?->user_id;

        try {
            $product = DB::transaction(function () use ($pendingProduct, $finalName, $uid) {
                if ($pendingProduct->registration_kind === 'appliance') {
                    return $this->approveAppliance($pendingProduct, $finalName, $uid);
                }

                return $this->approveConsumable($pendingProduct, $finalName, $uid);
            });
        } catch (\Throwable $e) {
            return $this->error('Approval failed: '.$e->getMessage(), 500);
        }

        Product::syncQuantityFromInventory((int) $product->product_id);

        $product->load(['category', 'brand', 'model', 'status', 'unit']);

        return $this->success($product, 'Product approved and added to Item Master');
    }

    private function approveConsumable(PendingProduct $pending, string $finalName, ?int $uid): Product
    {
        $productCode = $this->uniqueProductCode();

        $description = sprintf(
            'Consumable — Supply type: %s; Packaging: %s; Barcode: %s',
            $pending->supply_type ?? '—',
            self::packagingLabel((string) $pending->packaging_unit),
            $pending->barcode
        );
        if ($pending->quantity_per_package) {
            $description .= sprintf('; Qty per package: %s', $pending->quantity_per_package);
        }

        $product = Product::create([
            'product_code'           => $productCode,
            'product_name'           => $finalName,
            'product_type'           => 'consumable',
            'barcode'                => $pending->barcode,
            'category_id'            => $pending->category_id,
            'brand_id'               => $pending->brand_id,
            'description'            => $description,
            'pieces_per_package'     => $pending->quantity_per_package,
            'unit_price'             => $pending->unit_price,
            'cost_price'             => $pending->cost_price,
            'capacity_rating'        => null,
            'unit_id'                => null,
            'warranty_period_months' => null,
        ]);

        $this->ensureOpeningStock($product->product_id, (int) $pending->opening_location_id, max(1, (int) $pending->opening_quantity));

        $pending->update([
            'status'             => 'approved',
            'approved_by'        => $uid,
            'approved_at'        => now(),
            'created_product_id' => $product->product_id,
        ]);

        return $product;
    }

    private function approveAppliance(PendingProduct $pending, string $finalName, ?int $uid): Product
    {
        $snap = $pending->appliance_snapshot ?? [];
        $code = ! empty($snap['product_code']) ? trim((string) $snap['product_code']) : $this->uniqueProductCode();

        if (Product::query()->where('product_code', $code)->exists()) {
            $code = $this->uniqueProductCode();
        }

        $product = Product::create([
            'product_code'           => $code,
            'product_name'           => $finalName,
            'product_type'           => 'appliance',
            'barcode'                => $pending->barcode,
            'category_id'            => (int) ($snap['category_id'] ?? $pending->category_id),
            'brand_id'               => (int) ($snap['brand_id'] ?? $pending->brand_id),
            'unit_id'                => (int) ($snap['unit_id'] ?? 0) ?: null,
            'capacity_rating'        => $snap['capacity_rating'] ?? null,
            'description'            => isset($snap['variant']) ? (string) $snap['variant'] : null,
            'warranty_period_months' => isset($snap['warranty_period_months']) ? (int) $snap['warranty_period_months'] : null,
            'unit_price'             => $pending->unit_price,
            'cost_price'             => $pending->cost_price,
        ]);

        $locId = (int) ($snap['initial_location_id'] ?? $pending->opening_location_id);
        $this->ensureOpeningStock($product->product_id, $locId, max(1, (int) $pending->opening_quantity));

        $pending->update([
            'status'             => 'approved',
            'approved_by'        => $uid,
            'approved_at'        => now(),
            'created_product_id' => $product->product_id,
        ]);

        return $product;
    }

    private function ensureOpeningStock(int $productId, int $locationId, int $qty): void
    {
        $inv = Inventory::firstOrCreate(
            [
                'location_id' => $locationId,
                'product_id'  => $productId,
            ],
            [
                'quantity_on_hand'   => 0,
                'available_quantity' => 0,
                'reorder_level'      => 0,
            ]
        );

        $inv->quantity_on_hand = (int) $inv->quantity_on_hand + $qty;
        $inv->available_quantity = (int) $inv->available_quantity + $qty;
        $inv->save();
    }

    private function uniqueProductCode(): string
    {
        do {
            $code = 'PEND-'.strtoupper(bin2hex(random_bytes(4)));
        } while (Product::query()->where('product_code', $code)->exists());

        return $code;
    }
}
