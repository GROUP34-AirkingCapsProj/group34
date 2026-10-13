# Reusable transfer requests and partial fulfillment

Cancelled pending requests and undispatched IA-demand transfers become Draft records rather than being deleted. Drafts remain in the requester's My Requests tab. Cancelling IA-demand releases dispatch scans and serial assignments without moving stock.

Draft and Rejected requests allow editing and explicit resubmission. Edited inputs are automatically preserved as a Draft. Submitting again sends pull/offload requests to Pending analyst review; IA-demand returns to Approved only after validating current source availability. Rejection reasons remain in the history even if request notes are edited.

Automatic draft saving supersedes the manual Save changes button. Changed inputs are backed up immediately in the browser, scoped to the current user and request, then saved to the server after 600 ms of inactivity. Closing, navigation, and page hiding flush the latest snapshot; page hiding uses authenticated keepalive requests. Offline drafts are recovered from the browser and retried when connectivity returns. Saving a changed Pending or Rejected request returns it to Draft so incomplete edits are not reviewed. A completely untouched form creates no record. Submission remains explicit and waits for pending autosaves before reusing the same draft record; successful submission clears browser recovery data and the server snapshot.

Draft snapshots preserve incomplete product lines, package/piece entries, location selections, date and notes. They do not reserve or move stock. A new draft is identified by a stable unique client key so retries cannot create duplicate requests. Only an editable request may receive a draft snapshot; submitted IA-demand and dispatched records cannot be overwritten by an old draft save.

Rejected and Partial have separate tabs. Done contains Received records only. The original request stays Partial until the full requested quantity has been posted into destination inventory, including all linked continuation shipments.

Each request retains its original requested products. A partial approval may reduce the shipment quantities or omit unavailable lines while preserving the overall demand. Continuing a Partial request creates one linked Pending transfer containing only quantities that have not yet shipped. Analysts can select another source warehouse and approve only available quantities. An existing unfinished or rejected continuation must be completed or resubmitted before creating another.

Short receipts stay Partial and can accept the missing shipped units later. Confirming receipt posts only quantities not previously credited, with incremental FIFO batch allocation and distinct ledger posting keys. Confirmed receipt scans cannot be removed. Items shipped but not received are kept separate from quantities not yet shipped, preventing replacement shipments from duplicating goods already in transit.

Schema migration: `2026_10_13_000003_transfer_reusable_requests.php` adds request snapshots, continuation links, posted receipt quantities and receipt-scan posting flags. It marks existing completed receipt quantities as posted and moves existing short receipts to Partial. Historical demand already overwritten by old approvals cannot be reconstructed; legacy requests fall back to their retained detail quantities.

API additions and changes:

- `PATCH /api/transfers/{id}/cancel`: keeps an editable Draft. Only Pending or undispatched Approved IA-demand is cancellable.
- `PATCH /api/transfers/{id}/request`: accepts `resubmit: true`; supports Pending, Draft and Rejected. Ownership or an admin/analyst role is required.
- `POST /api/transfers/drafts` and `PUT /api/transfers/{id}/draft`: automatically save `{ draft_key, form }`, including incomplete inputs, without submitting or moving stock.
- `POST /api/transfers`: accepts `draft_id` to submit an owned Draft in place. The ordinary stock and location validation still applies.
- `POST /api/transfers/{id}/continue`: creates the outstanding shipment for analyst review; returns 422 if another continuation is unfinished or if all outstanding quantities have already shipped.
- `GET /api/transfers/{id}`: includes `fulfillment` with requested, shipped, posted received, remaining-to-send and remaining-to-receive quantities, plus continuation links.
- Receipt scanning and confirmation support In Transit and Partial records. Confirmation reports its actual resulting status.

Original transfer records with linked shipments are retained together and cannot be archived individually through the legacy deletion endpoint.
