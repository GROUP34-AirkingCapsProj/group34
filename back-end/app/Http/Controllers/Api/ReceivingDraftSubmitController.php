<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReceivingDraft;
use App\Services\ReceivingDraftSubmitService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Posts a staged delivery to inventory. The one place in the staged-receiving
 * feature where stock moves.
 */
class ReceivingDraftSubmitController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, ReceivingDraft $draft, ReceivingDraftSubmitService $submit)
    {
        $result = $submit->submit(
            $draft,
            $request->user()?->user_id,
            $request->boolean('accept_overage')
        );

        // A failed submit is a normal outcome the clerk acts on, not a fault:
        // it returns 200 with action => 'failed' and the flagged rows, matching
        // how the rest of the receiving endpoints report business rejections.
        return $this->success($result, $result['action'] === 'submitted' ? 'Delivery posted' : 'Submit blocked');
    }
}
