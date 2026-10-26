<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Event;
use App\Models\ModerationRequest;
use App\Models\Photo;
use Illuminate\Http\JsonResponse;

/**
 * Super admin only (RequirePhotoAdmin): the numbers on the overview tab.
 */
class StatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $storage = storage_path('app');
        $total = @disk_total_space($storage) ?: 0;
        $free = @disk_free_space($storage) ?: 0;

        return response()->json([
            'events_active' => Event::active()->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'events_expired' => Event::active()->where('expires_at', '<=', now())->count(),
            'photos' => Photo::processed()->count(),
            'photos_today' => Photo::processed()->where('created_at', '>=', now()->startOfDay())->count(),
            'devices' => Device::count(),
            'open_requests' => ModerationRequest::where('status', ModerationRequest::STATUS_OPEN)->count(),
            'disk' => [
                'total_bytes' => (int) $total,
                'free_bytes' => (int) $free,
                'used_percent' => $total > 0 ? (int) round(($total - $free) / $total * 100) : null,
            ],
        ]);
    }
}
