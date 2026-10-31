<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Models\Room;
use App\Services\AdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * "Preuzmi sve": every photo of a room as one ZIP, for the host (and the
 * guest room's admin) before the event is deleted.
 */
class RoomDownloadController extends Controller
{
    private const LINK_MINUTES = 10;

    /**
     * A short-lived download link. The key travels in a header here; the
     * browser then opens the link itself, which cannot send headers, so the
     * link carries a signature instead of the key.
     */
    public function link(Request $request, AdminAccess $access, Room $room): JsonResponse
    {
        $waitSeconds = $access->secondsUntilRetryAllowed($request);

        if ($waitSeconds > 0) {
            return response()->json(['message' => 'Too many attempts.'], 429, ['Retry-After' => (string) $waitSeconds]);
        }

        if ($access->roleFor($request, $room) === null) {
            if ($access->presentedAKey($request)) {
                $access->recordFailure($request);
            }

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        // Relative, because behind the proxies PHP does not see the public https URL.
        $url = URL::temporarySignedRoute('rooms.download-all', now()->addMinutes(self::LINK_MINUTES), $room, absolute: false);

        return response()->json(['url' => $url]);
    }

    /**
     * The ZIP itself (signed link only). JPEGs are stored as they are, not
     * compressed again: that would take time and save almost nothing.
     */
    public function download(Room $room): BinaryFileResponse
    {
        $disk = Storage::disk('local');
        $zipPath = tempnam(sys_get_temp_dir(), 'room-zip-');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);

        $room->photos()->processed()->orderBy('created_at')->chunkById(200, function ($photos) use ($disk, $zip): void {
            foreach ($photos as $photo) {
                $path = $this->bestFileFor($photo);

                if ($path === null) {
                    continue;
                }

                $name = sprintf('foto-%s-%s.jpg', $photo->created_at->format('Ymd-His'), Str::substr($photo->uuid, 0, 8));
                $zip->addFile($disk->path($path), $name);
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
        });

        $zip->close();

        $fileName = (Str::slug($room->name) ?: 'galerija').'-fotografije.zip';

        return response()->download($zipPath, $fileName, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function bestFileFor(Photo $photo): ?string
    {
        $downloadPath = Photo::downloadPathFor($photo->original_path);

        return Storage::disk('local')->exists($downloadPath) ? $downloadPath : null;
    }
}
