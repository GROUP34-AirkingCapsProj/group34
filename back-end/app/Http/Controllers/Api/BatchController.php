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
use App\Models\IssuanceDetail;
use App\Models\CategoryLookup;
use App\Models\BrandLookup;
use App\Http\Controllers\Api\CategoryLookupController;
use App\Http\Controllers\Api\BrandLookupController;
use App\Http\Controllers\Api\UnitLookupController;
use App\Http\Controllers\Api\CapacityLookupController;
use App\Http\Controllers\Api\VariantLookupController;
use Illuminate\Http\Request;

/**
 * Returns multiple resources in one request to reduce round-trips.
 * GET /api/batch?include=users,roles,branches,locations,items,inventory,transactions,sales,dashboard,categories,brands,units,capacities,variants
 *
 * NOTE: this endpoint carries no permission: route middleware, and every
 * include below calls its controller's index()/show() method directly in
 * PHP rather than dispatching through the router — so route-level
 * permission: middleware never runs for anything included here. That is
 * safe today only because all five item-master lookups (units, categories,
 * brands, capacities, variants) have deliberately
 * ungated GETs (see routes/api.php). Do NOT add an include for a resource
 * whose GET route IS permission-gated without first adding an equivalent
 * check inside this method — the route gate will not protect it here.
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
                ->whereHas('sale', function ($q) use ($user) {
                    if ($user && ! $user->isCentralized()) {
                        $q->where('location_id', $user->scopedLocationId());
                    }
                })
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

            $issued = IssuanceDetail::query()
                ->with(['product.category', 'issuance.location'])
                ->where('quantity_issued', '>', 0)
                ->whereHas('issuance', function ($q) use ($user) {
                    if ($user && ! $user->isCentralized()) {
                        $q->where('location_id', $user->scopedLocationId());
                    }
                })
                ->orderByDesc('created_at')
                ->limit(4000)
                ->get()
                ->map(static function (IssuanceDetail $d) {
                    $issuance = $d->issuance;
                    return [
                        'product_id'  => $d->product_id,
                        'item_id'     => $d->product_id,
                        'location_id' => $issuance?->location_id,
                        'quantity'    => (int) ($d->quantity_in_pieces ?: $d->quantity_issued),
                        'amount'      => (float) ($d->unit_price_per_piece ?: $d->unit_price),
                        'created_at'  => $d->created_at?->toIso8601String(),
                        'date'        => $issuance?->issuance_date?->format('Y-m-d'),
                        'type'        => 'issuance',
                        'notes'       => $issuance?->dr_number
                            ? ('Issue Out ' . $issuance->dr_number)
                            : 'Issue Out',
                        'product'     => $d->product,
                        'location'    => $issuance?->location,
                    ];
                })
                ->filter(static fn ($row) => $row['product'] !== null)
                ->values()
                ->all();

            $mapped = collect(array_merge($mapped, $issued))
                ->sortByDesc('created_at')
                ->values()
                ->all();

            $data['transactions'] = [
                'success' => true,
                'data'    => $mapped,
            ];
        }

        if (in_array('inventory', $parts)) {
            $inventory = Inventory::forUser($user)
                ->with(['product.category', 'product.unit', 'location'])
                ->paginate(500);
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
                $dashboard = app(DashboardController::class)->index(request());
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
            try {
                $data['units'] = json_decode(
                    app(UnitLookupController::class)->index(new Request())->getContent(), true
                );
            } catch (\Throwable $e) {
                $data['units'] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        // capacities/variants deliberately get a fresh,
        // empty Request rather than the live $request: capacity and variant
        // inherit ScopesLookupsToCategory::lookupIndexFilters(), which
        // filters on a `category_id` query param. If the live $request were
        // passed through, a caller hitting /api/batch?...&category_id=5 for
        // some unrelated reason would silently narrow these two includes to
        // one category's rows (plus globals) while every other include on
        // this endpoint ignored that same param — an inconsistency a
        // frontend bootstrapping its full lookup set from batch would not
        // expect. A batch include always means "everything"; per-category
        // narrowing belongs on the dedicated /api/capacities and
        // /api/variants endpoints, not here.
        if (in_array('capacities', $parts)) {
            try {
                $data['capacities'] = json_decode(
                    app(CapacityLookupController::class)->index(new Request())->getContent(), true
                );
            } catch (\Throwable $e) {
                $data['capacities'] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        if (in_array('variants', $parts)) {
            try {
                $data['variants'] = json_decode(
                    app(VariantLookupController::class)->index(new Request())->getContent(), true
                );
            } catch (\Throwable $e) {
                $data['variants'] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        return response()->json(['success' => true, 'message' => 'Batch retrieved', 'data' => $data]);
    }
}
