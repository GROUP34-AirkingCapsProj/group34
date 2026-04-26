<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\ItemSerial;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class SerialController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'nullable|integer',
                'serial_type' => 'nullable|string|max:100',
                'status_id' => 'nullable|integer',
                'search' => 'nullable|string|max:120',
                'per_page' => 'nullable|integer|min:1|max:200',
            ]);

            $query = ItemSerial::with(['product', 'status']);

            if ($request->filled('product_id')) {
                $query->where('product_id', (int) $request->product_id);
            }
            if ($request->filled('serial_type')) {
                $query->where('serial_type', $request->serial_type);
            }
            if ($request->filled('status_id')) {
                $query->where('status_id', (int) $request->status_id);
            }
            if ($request->filled('search')) {
                $term = (string) $request->input('search');
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
                $query->where('serial_number', 'like', '%' . $escaped . '%');
            }

            $perPage = $request->get('per_page', 50);
            return $this->paginated($query->orderBy('serial_number')->paginate($perPage), 'Serial numbers retrieved successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve serial numbers: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $serial = ItemSerial::with(['product', 'status'])->findOrFail($id);
            return $this->success($serial, 'Serial number retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Serial number not found', 404);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id'    => 'required|exists:products,product_id',
                'serial_number' => 'required|string|unique:item_serial,serial_number|max:100',
                'serial_type'   => 'nullable|string|max:100',
                'status_id'     => 'nullable|exists:status_lookup,status_id',
            ]);

            $serial = ItemSerial::create($validated);

            return $this->success($serial->load(['product', 'status']), 'Serial number created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create serial number: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $serial = ItemSerial::findOrFail($id);

            $validated = $request->validate([
                'product_id'    => 'sometimes|exists:products,product_id',
                'serial_number' => 'sometimes|string|unique:item_serial,serial_number,' . $id . ',serial_id|max:100',
                'serial_type'   => 'nullable|string|max:100',
                'status_id'     => 'nullable|exists:status_lookup,status_id',
            ]);

            $serial->update($validated);

            return $this->success($serial->load(['product', 'status']), 'Serial number updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update serial number: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $serial = ItemSerial::findOrFail($id);
            $serial->delete();
            return $this->success(null, 'Serial number deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete serial number: ' . $e->getMessage(), 500);
        }
    }

    public function scan(Request $request)
    {
        try {
            $validated = $request->validate([
                'serial_number' => 'required|string',
            ]);

            $serial = ItemSerial::where('serial_number', $validated['serial_number'])
                ->with(['product', 'status'])
                ->first();

            if (!$serial) {
                return $this->error('Serial number not found', 404);
            }

            return $this->success($serial, 'Serial number scanned successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Scan failed: ' . $e->getMessage(), 500);
        }
    }
}
