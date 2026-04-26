<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\User;
use App\Models\Role;
use App\Models\Branch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\CategoryLookup;
use App\Models\BrandLookup;
use App\Models\UnitLookup;
use App\Http\Controllers\Api\CategoryLookupController;
use App\Http\Controllers\Api\BrandLookupController;
use Illuminate\Http\Request;

/**
 * Returns multiple resources in one request to reduce round-trips.
 * GET /api/batch?include=users,roles,branches,locations,items,inventory,transactions,sales,dashboard,categories,brands,units
 */
class BatchController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $include = $request->get('include', '');
        $parts   = array_filter(array_map('trim', explode(',', $include)));
        $data    = [];
        $user    = $request->user();

        if ($user && !$user->relationLoaded('role')) {
            $user->loadMissing('role');
        }
        $isAdmin = $user && $user->role?->role_name === 'admin';

        if (in_array('roles', $parts)) {
            $data['roles'] = ['success' => true, 'data' => Role::all()];
        }

        if (in_array('locations', $parts)) {
            $locations = Location::with('status')->paginate(200);
            $data['locations'] = [
                'success'    => true,
                'data'       => $locations->items(),
                'pagination' => [
                    'current_page' => $locations->currentPage(),
                    'last_page'    => $locations->lastPage(),
                    'per_page'     => $locations->perPage(),
                    'total'        => $locations->total(),
                ],
            ];
        }

        if (in_array('users', $parts) && $isAdmin) {
            $users = User::with(['role', 'status'])->paginate(500);
            $data['users'] = [
                'success'    => true,
                'data'       => $users->items(),
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page'    => $users->lastPage(),
                    'per_page'     => $users->perPage(),
                    'total'        => $users->total(),
                ],
            ];
        }

        if (in_array('items', $parts)) {
            $items = Product::with(['category', 'brand', 'unit'])->paginate(500);
            $data['items'] = [
                'success'    => true,
                'data'       => $items->items(),
                'pagination' => [
                    'current_page' => $items->currentPage(),
                    'last_page'    => $items->lastPage(),
                    'per_page'     => $items->perPage(),
                    'total'        => $items->total(),
                ],
            ];
        }

        if (in_array('branches', $parts)) {
            $data['branches'] = [
                'success' => true,
                'data'    => Branch::orderBy('name')->get()->map(function ($b) {
                    return [
                        'id'             => $b->location_id,
                        'location_id'    => $b->location_id,
                        'code'           => $b->location_code,
                        'location_code'  => $b->location_code,
                        'name'           => $b->location_name,
                        'location_name'  => $b->location_name,
                        'region'         => $b->region,
                        'city'           => $b->city,
                        'address'        => $b->address,
                        'contact_number' => $b->contact_number,
                        'email'          => $b->email,
                        'opening_date'   => $b->opening_date,
                        'capacity'       => $b->capacity,
                        'is_active'      => $b->is_active,
                        'status_id'      => $b->status_id,
                        'created_at'     => $b->created_at,
                        'updated_at'     => $b->updated_at,
                    ];
                }),
            ];
        }

        if (in_array('transactions', $parts)) {
            $details = SaleDetail::query()
                ->with([
                    'product.category',
                    'product.brand',
                    'sale' => static function ($q) {
                        $q->with(['location']);
                    },
                ])
                ->whereHas('sale')
                ->join('sales', 'sales_details.sales_id', '=', 'sales.sales_id')
                ->orderByDesc('sales.created_at')
                ->select('sales_details.*')
                ->limit(4000)
                ->get();

            $mapped = $details->map(static function (SaleDetail $d) {
                $sale   = $d->sale;
                $loc    = $sale?->location;
                $prod   = $d->product;

                return [
                    'product_id'  => $d->product_id,
                    'item_id'     => $d->product_id,
                    'location_id' => $loc?->location_id,
                    'quantity'    => (int) $d->quantity,
                    'amount'      => (float) $d->unit_price,
                    'created_at'  => $sale?->created_at?->toIso8601String(),
                    'date'        => $sale?->sale_date?->format('Y-m-d'),
                    'type'        => 'sale',
                    'notes'       => $sale?->invoice_number
                        ? ('Invoice ' . $sale->invoice_number)
                        : 'Sale',
                    'product'     => $prod,
                    'location'    => $loc,
                ];
            })->filter(static fn ($row) => $row['product'] !== null)->values()->all();

            $data['transactions'] = [
                'success' => true,
                'data'    => $mapped,
            ];
        }

        if (in_array('inventory', $parts)) {
            $inventory = Inventory::with(['product.category', 'product.unit', 'location'])->paginate(500);
            $data['inventory'] = [
                'success'    => true,
                'data'       => $inventory->items(),
                'pagination' => [
                    'current_page' => $inventory->currentPage(),
                    'last_page'    => $inventory->lastPage(),
                    'per_page'     => $inventory->perPage(),
                    'total'        => $inventory->total(),
                ],
            ];
        }

        if (in_array('sales', $parts)) {
            $sales = Sale::with(['customer', 'createdBy', 'status'])->paginate(200);
            $data['sales'] = [
                'success'    => true,
                'data'       => $sales->items(),
                'pagination' => [
                    'current_page' => $sales->currentPage(),
                    'last_page'    => $sales->lastPage(),
                    'per_page'     => $sales->perPage(),
                    'total'        => $sales->total(),
                ],
            ];
        }

        if (in_array('dashboard', $parts)) {
            try {
                $dashboard = app(DashboardController::class)->index();
                $data['dashboard'] = json_decode($dashboard->getContent(), true);
            } catch (\Throwable $e) {
                $data['dashboard'] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        if (in_array('categories', $parts)) {
            try {
                $data['categories'] = json_decode(
                    app(CategoryLookupController::class)->index()->getContent(), true
                );
            } catch (\Throwable $e) {
                $data['categories'] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        if (in_array('brands', $parts)) {
            try {
                $data['brands'] = json_decode(
                    app(BrandLookupController::class)->index()->getContent(), true
                );
            } catch (\Throwable $e) {
                $data['brands'] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        if (in_array('units', $parts)) {
            $data['units'] = [
                'success' => true,
                'data'    => UnitLookup::orderBy('unit_name')->get(),
            ];
        }

        return response()->json(['success' => true, 'message' => 'Batch retrieved', 'data' => $data]);
    }
}
