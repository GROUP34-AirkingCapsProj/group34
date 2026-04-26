<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ActivityLogController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = ActivityLog::with(['user', 'status']);

            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->has('module')) {
                $query->where('module', $request->module);
            }
            if ($request->has('activity_type')) {
                $query->where('activity_type', $request->activity_type);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }

            $perPage = $request->get('per_page', 50);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Activity logs retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve activity logs: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id'       => 'nullable|exists:users,user_id',
                'activity_type' => 'required|string|max:100',
                'module'        => 'nullable|string|max:100',
                'description'   => 'nullable|string',
                'status_id'     => 'nullable|exists:status_lookup,status_id',
            ]);

            $log = ActivityLog::create($validated);

            return $this->success($log->load(['user', 'status']), 'Activity log created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create activity log: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $log = ActivityLog::with(['user', 'status'])->findOrFail($id);
            return $this->success($log, 'Activity log retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Activity log not found', 404);
        }
    }

    public function destroy($id)
    {
        try {
            $log = ActivityLog::findOrFail($id);
            $log->delete();
            return $this->success(null, 'Activity log deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete activity log: ' . $e->getMessage(), 500);
        }
    }
}
