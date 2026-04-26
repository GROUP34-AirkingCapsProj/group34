<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BarcodeScan;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BarcodeScanController extends Controller
{
    use ApiResponse;

    /**
     * POST /barcode/scan — Receive PO smart lookup (FOUND | NOT_FOUND | PENDING_CONSUMABLE).
     */
    public function scanLookup(Request $request)
    {
        try {
            $validated = $request->validate([
                'barcode' => 'required|string|max:191',
            ]);
            $raw = trim($validated['barcode']);
            if ($raw === '') {
                return $this->success(['status' => 'NOT_FOUND'], 'No barcode');
            }

            $candidates = $this->barcodeLookupCandidates($raw);

            if (Schema::hasTable('pending_products')) {
                foreach ($candidates as $c) {
                    $norm = strtolower($c);
                    $pending = DB::table('pending_products')
                        ->where('status', 'pending')
                        ->where('registration_kind', 'consumable')
                        ->whereRaw('LOWER(TRIM(barcode)) = ?', [$norm])
                        ->first();
                    if ($pending) {
                        return $this->success([
                            'status'               => 'PENDING_CONSUMABLE',
                            'pending_product_id'   => $pending->pending_product_id ?? null,
                        ], 'Pending consumable registration');
                    }
                }
            }

            $product = $this->findProductByBarcodeCandidates($candidates);
            if ($product) {
                $product->load(['category', 'brand', 'model', 'unit', 'status']);

                return $this->success([
                    'status'  => 'FOUND',
                    'product' => $product,
                ], 'Product found');
            }

            return $this->success(['status' => 'NOT_FOUND'], 'Barcode not registered');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Scan lookup failed: '.$e->getMessage(), 500);
        }
    }

    /**
     * @return list<string>
     */
    private function barcodeLookupCandidates(string $raw): array
    {
        $s = trim($raw);
        $set = [];

        $add = function (string $v) use (&$set) {
            $v = trim($v);
            if ($v !== '') {
                $set[$v] = true;
            }
        };

        $add($s);
        $add(strtoupper($s));
        $add(strtolower($s));
        $add(preg_replace('/\s+/', '', $s) ?? '');
        $alnum = preg_replace('/[^0-9A-Za-z]/', '', $s);
        if ($alnum !== null && $alnum !== '') {
            $add($alnum);
            $add(strtoupper($alnum));
        }
        $digits = preg_replace('/\D/', '', $s) ?? '';
        if ($digits !== '') {
            $add($digits);
        }
        if (strlen($digits) === 12) {
            $ean13 = $this->ean13From12($digits);
            if ($ean13 !== null) {
                $add($ean13);
            }
        }

        return array_keys($set);
    }

    private function ean13From12(string $twelve): ?string
    {
        if (! preg_match('/^\d{12}$/', $twelve)) {
            return null;
        }
        $sum = 0;
        foreach (str_split($twelve) as $i => $ch) {
            $d = (int) $ch;
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $twelve.$check;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function findProductByBarcodeCandidates(array $candidates): ?Product
    {
        foreach ($candidates as $c) {
            $norm = strtolower(trim($c));
            if ($norm === '') {
                continue;
            }

            $product = Product::query()
                ->where(function ($q) use ($norm) {
                    $q->whereRaw('LOWER(TRIM(COALESCE(barcode, \'\'))) = ?', [$norm])
                        ->orWhereRaw('LOWER(TRIM(COALESCE(product_code, \'\'))) = ?', [$norm]);
                })
                ->first();

            if ($product) {
                return $product;
            }
        }

        foreach ($candidates as $c) {
            $serial = ItemSerial::query()
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($c))])
                ->first();
            if ($serial && $serial->product_id) {
                $p = Product::query()->where('product_id', $serial->product_id)->first();
                if ($p) {
                    return $p;
                }
            }
        }

        return null;
    }

    public function index(Request $request)
    {
        try {
            $query = BarcodeScan::with(['user', 'product']);

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->filled('scan_mode')) {
                $query->where('scan_mode', $request->scan_mode);
            }

            $perPage = (int) $request->get('per_page', 50);
            $perPage = $perPage > 0 ? min($perPage, 200) : 50;

            return $this->paginated(
                $query->orderByDesc('scanned_at')->orderByDesc('id')->paginate($perPage),
                'Barcode scans retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve barcode scans: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'barcode'     => 'required|string|max:191',
                'product_id'  => 'required|integer|exists:products,product_id',
                'scan_mode'   => 'required|string|max:64',
                'scanned_at'  => 'nullable|date',
            ]);

            $foundProduct = $this->findProductByBarcodeCandidates(
                $this->barcodeLookupCandidates(trim($validated['barcode']))
            );
            if (! $foundProduct || (int) $foundProduct->product_id !== (int) $validated['product_id']) {
                throw ValidationException::withMessages([
                    'barcode' => ['Barcode does not match the selected product.'],
                ]);
            }

            $scan = BarcodeScan::create([
                'user_id'     => $request->user()->user_id,
                'barcode'     => $validated['barcode'],
                'product_id'  => $validated['product_id'],
                'scan_mode'   => $validated['scan_mode'],
                'scanned_at'  => $validated['scanned_at'] ?? now(),
            ]);

            return $this->success(
                $scan->load(['user', 'product']),
                'Barcode scan recorded successfully',
                201
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to record barcode scan: ' . $e->getMessage(), 500);
        }
    }
}
