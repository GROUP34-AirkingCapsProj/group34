<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Supplier::with(['status']);

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('supplier_name', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        }

        if ($request->has('origin') && $request->origin) {
            $query->where('origin', $request->origin);
        }

        if ($request->has('region') && $request->region) {
            $query->where('region', $request->region);
        }

        if ($request->has('status_id') && $request->status_id) {
            $query->where('status_id', $request->status_id);
        }

        $perPage = $request->get('per_page', 50);

        if ($request->get('paginate', 'true') === 'false') {
            $suppliers = $query->orderBy('supplier_name')->get();
            return $this->success($suppliers, 'Suppliers retrieved successfully');
        }

        $suppliers = $query->orderBy('supplier_name')->paginate($perPage);
        return $this->paginated($suppliers, 'Suppliers retrieved successfully');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'supplier_name'   => 'required|string|max:255',
                'contact_person'  => 'nullable|string|max:255',
                'contact_number'  => 'required|string|max:20',
                'email'           => 'nullable|email|max:100',
                'address'         => 'nullable|string',
                'origin'          => 'required|in:Local,International',
                'region'          => 'nullable|string|max:100',
                'tin'             => 'nullable|string|max:50',
                'status_id'       => 'nullable|exists:status_lookup,status_id',
                'notes'           => 'nullable|string',
            ]);

            $supplier = Supplier::create($validated);
            $supplier->load('status');

            return $this->success($supplier, 'Supplier created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function show($id)
    {
        $supplier = Supplier::with(['status', 'products.category', 'products.brand'])->find($id);

        if (!$supplier) {
            return $this->notFound('Supplier not found');
        }

        return $this->success($supplier, 'Supplier retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::find($id);
        if (!$supplier) {
            return $this->notFound('Supplier not found');
        }

        try {
            $validated = $request->validate([
                'supplier_name'   => 'sometimes|string|max:255',
                'contact_person'  => 'nullable|string|max:255',
                'contact_number'  => 'sometimes|string|max:20',
                'email'           => 'nullable|email|max:100',
                'address'         => 'nullable|string',
                'origin'          => 'sometimes|in:Local,International',
                'region'          => 'nullable|string|max:100',
                'tin'             => 'nullable|string|max:50',
                'status_id'       => 'nullable|exists:status_lookup,status_id',
                'notes'           => 'nullable|string',
            ]);

            $supplier->update($validated);
            $supplier->load('status');

            return $this->success($supplier, 'Supplier updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function destroy($id)
    {
        $supplier = Supplier::find($id);
        if (!$supplier) {
            return $this->notFound('Supplier not found');
        }

        $supplier->delete();
        return $this->success(null, 'Supplier deleted successfully');
    }

    /**
     * List products linked to a supplier.
     */
    public function products($id)
    {
        $supplier = Supplier::find($id);
        if (!$supplier) {
            return $this->notFound('Supplier not found');
        }

        $products = $supplier->products()->with(['category', 'brand'])->get()->map(function ($p) {
            $statusName = null;
            if ($p->pivot->status_id) {
                $status = \App\Models\StatusLookup::find($p->pivot->status_id);
                $statusName = $status?->status_name;
            }
            return [
                'supplier_prod_id' => $p->pivot->supplier_prod_id,
                'product_id'       => $p->product_id,
                'product_name'     => $p->product_name,
                'product_code'     => $p->product_code,
                'category'         => $p->category?->category_name,
                'brand'            => $p->brand?->brand_name,
                'product_price'    => $p->pivot->product_price,
                'currency'         => $p->pivot->currency,
                'status_id'        => $p->pivot->status_id,
                'status_name'      => $statusName,
            ];
        });

        return $this->success($products, 'Supplier products retrieved successfully');
    }

    /**
     * Link a product to a supplier.
     */
    public function addProduct(Request $request, $id)
    {
        $supplier = Supplier::find($id);
        if (!$supplier) {
            return $this->notFound('Supplier not found');
        }

        try {
            $validated = $request->validate([
                'product_id'    => 'required|exists:products,product_id',
                'product_price' => 'nullable|numeric|min:0',
                'currency'      => 'nullable|string|max:10',
                'status_id'     => 'nullable|exists:status_lookup,status_id',
            ]);

            $exists = SupplierProduct::where('supplier_id', $supplier->supplier_id)
                ->where('product_id', $validated['product_id'])
                ->exists();

            if ($exists) {
                return $this->error('Product is already linked to this supplier', 422);
            }

            $record = SupplierProduct::create([
                'supplier_id'   => $supplier->supplier_id,
                'product_id'    => $validated['product_id'],
                'product_price' => $validated['product_price'] ?? null,
                'currency'      => $validated['currency'] ?? 'PHP',
                'status_id'     => $validated['status_id'] ?? null,
            ]);

            $record->load('status');

            return $this->success($record, 'Product linked to supplier successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    /**
     * Update an existing supplier-product link.
     */
    public function updateProduct(Request $request, $id, $supplierProdId)
    {
        $record = SupplierProduct::where('supplier_prod_id', $supplierProdId)
            ->where('supplier_id', $id)
            ->first();

        if (!$record) {
            return $this->notFound('Supplier product link not found');
        }

        try {
            $validated = $request->validate([
                'product_price' => 'nullable|numeric|min:0',
                'currency'      => 'nullable|string|max:10',
                'status_id'     => 'nullable|exists:status_lookup,status_id',
            ]);

            $record->update($validated);
            $record->load('status');

            return $this->success($record, 'Supplier product updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    /**
     * Unlink a product from a supplier.
     */
    public function removeProduct($id, $productId)
    {
        $deleted = SupplierProduct::where('supplier_id', $id)
            ->where('product_id', $productId)
            ->delete();

        if (!$deleted) {
            return $this->notFound('Product link not found');
        }

        return $this->success(null, 'Product unlinked from supplier successfully');
    }
}
