<?php

namespace App\Http\Middleware;

use App\Models\PurchaseOrder;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceivingDraftMutationLock
{
    public function handle(Request $request, Closure $next): mixed
    {
        return DB::transaction(function () use ($request, $next) {
            $draft = $request->route('draft');
            if ($draft === null) {
                // Serialize opening a delivery against other opens/submissions.
                if ($request->filled('po_id')) {
                    PurchaseOrder::whereKey($request->input('po_id'))->lockForUpdate()->first();
                }

                return $next($request);
            }
            $draftId = $draft instanceof ReceivingDraft ? $draft->draft_id : $draft;
            $locked = ReceivingDraft::whereKey($draftId)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                return response()->json(['success' => false, 'message' => 'This delivery has already been submitted or discarded.'], 422);
            }
            $request->route()->setParameter('draft', $locked);
            $item = $request->route('item');
            if ($item !== null) {
                $itemId = $item instanceof ReceivingDraftItem ? $item->draft_item_id : $item;
                $request->route()->setParameter('item', ReceivingDraftItem::whereKey($itemId)->lockForUpdate()->firstOrFail());
            }

            return $next($request);
        });
    }
}
