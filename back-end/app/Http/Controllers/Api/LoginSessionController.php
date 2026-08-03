<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\LoginActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LoginSessionController extends Controller
{
    /**
     * Successful logins paired with the next logout (when present).
     * Structure matches an admin "Login sessions" audit view.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 50), 5), 200);
        $page = max((int) $request->input('page', 1), 1);
        $statusFilter = strtolower(trim((string) $request->input('status', 'all')));

        $query = LoginActivity::with(['user:user_id,first_name,last_name,username,email'])
            ->where('success', true)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('email')) {
            $email = '%' . strtolower(trim((string) $request->input('email'))) . '%';
            $query->where(function ($q) use ($email) {
                $q->whereRaw('LOWER(username_attempt) LIKE ?', [$email])
                    ->orWhereHas('user', function ($uq) use ($email) {
                        $uq->whereRaw('LOWER(email) LIKE ?', [$email]);
                    });
            });
        }

        if ($request->filled('ip')) {
            $query->where('ip_address', 'like', '%' . trim((string) $request->input('ip')) . '%');
        }

        // Cap for in-memory status pairing / filtering (status is derived).
        $rows = $query->limit(500)->get();

        $userIds = $rows->pluck('user_id')->filter()->unique()->values();
        $logoutsByUser = [];
        if ($userIds->isNotEmpty()) {
            $logouts = AuditTrail::query()
                ->where('action', 'logout')
                ->whereIn('user_id', $userIds)
                ->orderBy('created_at')
                ->get(['user_id', 'created_at', 'ip_address']);

            foreach ($logouts as $logout) {
                $logoutsByUser[$logout->user_id][] = $logout;
            }
        }

        $nextLoginById = [];
        if ($userIds->isNotEmpty()) {
            $allLogins = LoginActivity::query()
                ->where('success', true)
                ->whereIn('user_id', $userIds)
                ->orderBy('created_at')
                ->get(['id', 'user_id', 'created_at']);

            foreach ($allLogins->groupBy('user_id') as $list) {
                $ordered = $list->values();
                foreach ($ordered as $i => $login) {
                    $nextLoginById[$login->id] = $ordered[$i + 1] ?? null;
                }
            }
        }

        $items = $rows->map(function (LoginActivity $row) use ($logoutsByUser, $nextLoginById) {
            $loginAt = $row->created_at ? Carbon::parse($row->created_at) : null;
            $nextLogin = $nextLoginById[$row->id] ?? null;
            $nextLoginAt = $nextLogin?->created_at
                ? Carbon::parse($nextLogin->created_at)
                : null;

            $logoutAt = null;
            $notes = null;

            if ($loginAt && $row->user_id && ! empty($logoutsByUser[$row->user_id])) {
                foreach ($logoutsByUser[$row->user_id] as $logout) {
                    $candidate = Carbon::parse($logout->created_at);
                    if ($candidate->lte($loginAt)) {
                        continue;
                    }
                    if ($nextLoginAt && $candidate->gte($nextLoginAt)) {
                        continue;
                    }
                    $logoutAt = $candidate;
                    $notes = 'manual logout';
                    break;
                }
            }

            if (! $logoutAt && $nextLoginAt) {
                $logoutAt = $nextLoginAt;
                $notes = 'session replaced';
            }

            $active = $logoutAt === null;
            $durationSeconds = ($loginAt && $logoutAt)
                ? max(0, (int) $loginAt->diffInSeconds($logoutAt, false))
                : null;
            if ($durationSeconds !== null && $durationSeconds < 0) {
                $durationSeconds = abs($durationSeconds);
            }

            $user = $row->user;
            $displayName = $user
                ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                    ?: ($user->username ?? null))
                : null;
            $email = $user?->email ?: $row->username_attempt;

            $device = $this->parseUserAgent((string) ($row->user_agent ?? ''));

            return [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'email' => $email,
                'display_name' => $displayName,
                'status' => $active ? 'active' : 'logged_out',
                'status_label' => $active ? 'Active' : 'Logged out',
                'login_at' => $loginAt?->toIso8601String(),
                'logout_at' => $logoutAt?->toIso8601String(),
                'duration_seconds' => $durationSeconds,
                'duration_label' => $this->formatDuration($durationSeconds),
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'device_type' => $device['device_type'],
                'browser' => $device['browser'],
                'platform' => $device['platform'],
                'device_label' => $device['device_label'],
                'notes' => $notes,
            ];
        })->values();

        if (in_array($statusFilter, ['active', 'logged_out'], true)) {
            $items = $items->filter(fn ($row) => $row['status'] === $statusFilter)->values();
        }

        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'success' => true,
            'data' => $slice,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
            ],
        ]);
    }

    private function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $minutes = intdiv($seconds, 60);
        $rem = $seconds % 60;
        if ($minutes < 60) {
            return $rem > 0 ? "{$minutes}m {$rem}s" : "{$minutes}m";
        }
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
    }

    /** @return array{device_type: string, browser: string, platform: string, device_label: string} */
    private function parseUserAgent(string $ua): array
    {
        $uaLower = strtolower($ua);
        $deviceType = 'Desktop';
        if (str_contains($uaLower, 'mobile') || str_contains($uaLower, 'android') || str_contains($uaLower, 'iphone')) {
            $deviceType = 'Mobile';
        } elseif (str_contains($uaLower, 'ipad') || str_contains($uaLower, 'tablet')) {
            $deviceType = 'Tablet';
        }

        $browser = 'Unknown browser';
        if (str_contains($uaLower, 'edg/')) {
            $browser = 'Microsoft Edge';
        } elseif (str_contains($uaLower, 'chrome/') && ! str_contains($uaLower, 'edg/')) {
            $browser = 'Chrome';
        } elseif (str_contains($uaLower, 'firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($uaLower, 'safari/') && ! str_contains($uaLower, 'chrome/')) {
            $browser = 'Safari';
        }

        $platform = 'Unknown';
        if (str_contains($uaLower, 'windows')) {
            $platform = 'Windows';
        } elseif (str_contains($uaLower, 'mac os') || str_contains($uaLower, 'macintosh')) {
            $platform = 'macOS';
        } elseif (str_contains($uaLower, 'android')) {
            $platform = 'Android';
        } elseif (str_contains($uaLower, 'iphone') || str_contains($uaLower, 'ipad') || str_contains($uaLower, 'ios')) {
            $platform = 'iOS';
        } elseif (str_contains($uaLower, 'linux')) {
            $platform = 'Linux';
        }

        return [
            'device_type' => $deviceType,
            'browser' => $browser,
            'platform' => $platform,
            'device_label' => "{$deviceType} - {$browser}",
        ];
    }
}
