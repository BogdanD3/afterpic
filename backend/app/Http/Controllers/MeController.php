<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\DeviceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The guest's own phone: the optional name shown under their photos.
 */
class MeController extends Controller
{
    public function show(Request $request, DeviceIdentity $devices): JsonResponse
    {
        $id = $devices->fromRequest($request);

        return response()->json(['name' => $id === null ? null : Device::find($id)?->name]);
    }

    /**
     * Set or clear the name. It applies to every photo of this phone, also
     * ones uploaded before. A phone without an id gets one here.
     */
    public function updateName(Request $request, DeviceIdentity $devices): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:40'],
        ]);

        $id = $devices->fromRequest($request);
        $isNewDevice = $id === null;

        if ($isNewDevice) {
            $waitSeconds = $devices->secondsUntilNewIdAllowed($request);

            if ($waitSeconds > 0) {
                return response()->json(['message' => 'Too many requests.'], 429, ['Retry-After' => (string) $waitSeconds]);
            }

            $id = $devices->issue($request);
        }

        $limitKey = "device-name:{$id}";

        if (RateLimiter::tooManyAttempts($limitKey, 20)) {
            return response()->json(['message' => 'Too many requests.'], 429, ['Retry-After' => (string) RateLimiter::availableIn($limitKey)]);
        }

        RateLimiter::hit($limitKey, 3600);

        $name = trim((string) ($validated['name'] ?? ''));
        $device = Device::seen($id, $request);
        $device->update(['name' => $name === '' ? null : $name]);

        $response = response()->json(['name' => $device->name]);

        if ($isNewDevice) {
            $response->headers->setCookie($devices->cookieFor($id, $request));
        }

        return $response;
    }
}
