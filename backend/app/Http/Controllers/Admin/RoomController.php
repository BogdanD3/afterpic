<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\RoomDeleter;
use Illuminate\Http\JsonResponse;

/**
 * Super admin only (RequirePhotoAdmin): rooms of an event.
 */
class RoomController extends Controller
{
    /**
     * Delete a guest room with its photos (in the background). The main
     * room goes only with its event.
     */
    public function destroy(Room $room, RoomDeleter $deleter): JsonResponse
    {
        if ($room->is_main) {
            return response()->json(['message' => 'The main room is deleted together with its event.'], 422);
        }

        $deleter->deleteRoom($room);

        return response()->json(['message' => 'The room is being deleted.'], 202);
    }

    /**
     * Give the room a new link, for when the old one (or its QR code) got
     * into the wrong hands. The old link stops working right away; printed
     * QR codes have to be replaced.
     */
    public function rotateCode(Room $room): JsonResponse
    {
        $room->update(['code' => Room::generateCode($room->name)]);

        return response()->json(['code' => $room->code]);
    }
}
