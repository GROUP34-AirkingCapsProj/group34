<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ArchiveController extends Controller
{
    use ApiResponse;

    private const MODULES = [
        'products' => [
            'model'    => \App\Models\Product::class,
            'label'    => 'Product',
            'name_col' => 'product_name',
            'code_col' => 'product_code',
            'with'     => ['category', 'applianceDetail.brand', 'installationMaterialDetail.unit'],
        ],
        'locations' => [
            'model'    => \App\Models\Location::class,
            'label'    => 'Location',
            'name_col' => 'location_name',
            'code_col' => 'location_code',
            'with'     => [],
        ],
        'users' => [
            'model'    => \App\Models\User::class,
            'label'    => 'User',
            'name_col' => 'first_name',
            'code_col' => 'username',
            'with'     => ['role'],
        ],
        'brands' => [
            'model'    => \App\Models\BrandLookup::class,
            'label'    => 'Brand',
            'name_col' => 'brand_name',
            'code_col' => null,
            'with'     => [],
        ],
        'categories' => [
            'model'    => \App\Models\CategoryLookup::class,
            'label'    => 'Category',
            'name_col' => 'category_name',
            'code_col' => null,
            'with'     => [],
        ],
        'customers' => [
            'model'    => \App\Models\Customer::class,
            'label'    => 'Customer',
            'name_col' => 'customer_name',
            'code_col' => 'customer_code',
            'with'     => [],
        ],
        'suppliers' => [
            'model'    => \App\Models\Supplier::class,
            'label'    => 'Supplier',
            'name_col' => 'supplier_name',
            'code_col' => null,
            'with'     => [],
        ],
        'purchase_orders' => [
            'model'    => \App\Models\PurchaseOrder::class,
            'label'    => 'Purchase Order',
            'name_col' => 'po_number',
            'code_col' => 'po_number',
            'with'     => ['supplier', 'location'],
        ],
        'purchase_requests' => [
            'model'    => \App\Models\PurchaseRequest::class,
            'label'    => 'Purchase Request',
            'name_col' => 'pr_number',
            'code_col' => 'pr_number',
            'with'     => ['location', 'requester'],
        ],
        'transfers' => [
            'model'    => \App\Models\Transfer::class,
            'label'    => 'Transfer',
            'name_col' => 'transfer_number',
            'code_col' => 'transfer_number',
            'with'     => [],
        ],
        'adjustments' => [
            'model'    => \App\Models\Adjustment::class,
            'label'    => 'Adjustment',
            'name_col' => 'adjustment_number',
            'code_col' => 'adjustment_number',
            'with'     => ['location'],
        ],
        'issuances' => [
            'model'    => \App\Models\Issuance::class,
            'label'    => 'Issuance',
            'name_col' => 'dr_number',
            'code_col' => 'dr_number',
            'with'     => ['customer', 'location'],
        ],
        'units' => [
            'model'    => \App\Models\UnitLookup::class,
            'label'    => 'Unit',
            'name_col' => 'unit_name',
            'code_col' => 'unit_abbreviation',
            'with'     => [],
        ],
        'budget_allocations' => [
            'model'    => \App\Models\BudgetAllocation::class,
            'label'    => 'Budget Allocation',
            'name_col' => 'period_label',
            'code_col' => null,
            'with'     => ['location'],
        ],
    ];

    // GET /api/archive/summary
    public function summary()
    {
        $counts = [];
        foreach (self::MODULES as $key => $config) {
            try {
                $counts[$key] = [
                    'label' => $config['label'],
                    'count' => $config['model']::onlyTrashed()->count(),
                ];
            } catch (\Throwable $e) {
                $counts[$key] = ['label' => $config['label'], 'count' => 0];
            }
        }

        return $this->success([
            'modules'        => $counts,
            'total_archived' => array_sum(array_column($counts, 'count')),
        ], 'Archive summary retrieved');
    }

    // GET /api/archive/{module}
    public function index(Request $request, string $module)
    {
        if (! isset(self::MODULES[$module])) {
            return $this->error("Unknown archive module: {$module}", 422);
        }

        $config = self::MODULES[$module];
        $model  = $config['model'];
        $query  = $model::onlyTrashed();

        if (! empty($config['with'])) {
            $query->with($config['with']);
        }

        if ($request->filled('search') && $config['name_col']) {
            $s = $request->string('search');
            $query->where(function ($q) use ($config, $s) {
                $q->where($config['name_col'], 'like', "%{$s}%");
                if ($config['code_col'] && $config['code_col'] !== $config['name_col']) {
                    $q->orWhere($config['code_col'], 'like', "%{$s}%");
                }
            });
        }

        if ($request->filled('deleted_from')) {
            $query->whereDate('deleted_at', '>=', $request->string('deleted_from'));
        }
        if ($request->filled('deleted_to')) {
            $query->whereDate('deleted_at', '<=', $request->string('deleted_to'));
        }

        $results = $query->orderBy('deleted_at', 'desc')
                         ->paginate($request->integer('per_page', 15));

        $results->getCollection()->transform(
            fn ($record) => $this->transformRecord($record, $module, $config)
        );

        return $this->paginated($results, ucfirst($config['label']) . ' archive retrieved');
    }

    // POST /api/archive/{module}/{id}/restore
    public function restore(string $module, $id)
    {
        if (! isset(self::MODULES[$module])) {
            return $this->error("Unknown archive module: {$module}", 422);
        }

        $config = self::MODULES[$module];
        $record = $config['model']::onlyTrashed()->find($id);

        if (! $record) {
            return $this->notFound('Archived record not found');
        }

        $record->restore();

        try {
            \App\Models\AuditTrail::create([
                'user_id'    => auth()->id(),
                'action'     => 'restore',
                'table_name' => $record->getTable(),
                'record_id'  => $record->getKey(),
                'old_values' => ['deleted_at' => $record->getOriginal('deleted_at')],
                'new_values' => ['deleted_at' => null, 'status' => 'restored'],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->success(
            $this->transformRecord($record->fresh(), $module, $config),
            ucfirst($config['label']) . ' restored successfully'
        );
    }

    // DELETE /api/archive/{module}/{id}/force
    public function forceDestroy(string $module, $id)
    {
        if (! isset(self::MODULES[$module])) {
            return $this->error("Unknown archive module: {$module}", 422);
        }

        $config = self::MODULES[$module];
        $record = $config['model']::onlyTrashed()->find($id);

        if (! $record) {
            return $this->notFound('Archived record not found');
        }

        try {
            \App\Models\AuditTrail::create([
                'user_id'    => auth()->id(),
                'action'     => 'force_delete',
                'table_name' => $record->getTable(),
                'record_id'  => $record->getKey(),
                'old_values' => ['name' => $record->{$config['name_col']}],
                'new_values' => ['permanently_deleted' => true],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        $record->forceDelete();

        return $this->success(null, ucfirst($config['label']) . ' permanently deleted');
    }

    // POST /api/archive/{module}/restore-all
    public function restoreAll(string $module)
    {
        if (! isset(self::MODULES[$module])) {
            return $this->error("Unknown archive module: {$module}", 422);
        }

        $model = self::MODULES[$module]['model'];
        $count = $model::onlyTrashed()->count();
        $model::onlyTrashed()->restore();

        return $this->success(
            ['restored_count' => $count],
            "{$count} " . self::MODULES[$module]['label'] . ' record(s) restored'
        );
    }

    private function transformRecord($record, string $module, array $config): array
    {
        $displayName = $module === 'users'
            ? trim(($record->first_name ?? '') . ' ' . ($record->last_name ?? ''))
            : ($record->{$config['name_col']} ?? '—');

        $codeCol = $config['code_col'];

        return [
            'id'           => $record->getKey(),
            'module'       => $module,
            'module_label' => $config['label'],
            'display_name' => $displayName,
            'display_code' => ($codeCol && $codeCol !== $config['name_col'])
                                ? ($record->{$codeCol} ?? null)
                                : null,
            'deleted_at'   => $record->deleted_at?->format('Y-m-d H:i:s'),
            'created_at'   => $record->created_at?->format('Y-m-d'),
            'record'       => $record,
        ];
    }
}
