<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotoRequest;
use App\Http\Resources\PhotoResource;
use App\Jobs\ProcessPhotoJob;
use App\Models\Photo;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PhotoController extends Controller
{
    /**
     * File extension for each accepted mime type (originals have none on disk).
     *
     * @var array<string, string>
     */
    private const DOWNLOAD_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Store an uploaded photo and queue it for processing.
     */
    public function store(StorePhotoRequest $request): JsonResponse
    {
        $uuid = (string) Str::uuid();
        $file = $request->file('photo');

        try {
            $originalPath = Storage::disk('local')->putFileAs("photos/{$uuid}", $file, 'original');
        } catch (Throwable $exception) {
            // Most likely a full disk or a permissions problem on the storage volume.
            Log::error('Could not store uploaded photo', ['exception' => $exception->getMessage()]);

            return response()->json([
                'message' => 'The server could not store the photo.',
            ], 503);
        }

        if ($originalPath === false) {
            Log::error('Could not store uploaded photo', ['exception' => 'putFileAs returned false']);

            return response()->json([
                'message' => 'The server could not store the photo.',
            ], 503);
        }

        $photo = Photo::create([
            'uuid' => $uuid,
            'status' => Photo::STATUS_PENDING,
            'original_path' => $originalPath,
            'mime_type' => $file->getMimeType(),
        ]);

        ProcessPhotoJob::dispatch($photo);

        return response()->json([
            'uuid' => $photo->uuid,
            'status' => $photo->status,
        ], 202);
    }

    /**
     * List processed photos, newest first, with cursor pagination.
     *
     * Pages are ordered by created_at and uuid, so the page cursor contains
     * no internal id. The `next` link is a path without host, so it is right
     * behind any proxy. `updates_cursor` starts the polling for new photos.
     */
    public function index(): AnonymousResourceCollection
    {
        $photos = Photo::processed()
            ->orderByDesc('created_at')
            ->orderByDesc('uuid')
            ->cursorPaginate(config('photos.per_page'))
            ->withPath(route('photos.index', absolute: false));

        return PhotoResource::collection($photos)->additional([
            'updates_cursor' => $this->encodeUpdatesCursor(
                (int) Photo::processed()->max('id'),
                now()->timestamp - (int) config('photos.updates_since_overlap_seconds'),
            ),
        ]);
    }

    /**
     * Return processed photos the client has not seen yet, for polling.
     *
     * The client sends back the `cursor` of its previous answer (the first
     * one comes from the gallery page). It is encrypted, so guests never see
     * internal ids. Inside are `after_id` (highest id already sent) and
     * `since` (unix time).
     *
     * Two workers can finish photos out of order, so a photo with a LOWER id
     * can become ready after one with a higher id. Asking only for
     * "id greater than after_id" would miss it forever. So every photo that
     * finished processing at or after `since` is returned too, whatever its
     * id. `since` trails the last poll by a small overlap to cover clock
     * rounding; the client skips photos it already has.
     *
     * `has_more` is true when the limit cut the list short: the next cursor
     * then keeps the old `since`, so nothing is skipped.
     */
    public function updates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['required', 'string', 'max:1000'],
        ]);

        $cursor = $this->decodeUpdatesCursor($validated['cursor']);

        if ($cursor === null) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        ['after_id' => $afterId, 'since' => $since] = $cursor;
        $limit = (int) config('photos.updates_limit');

        $latestId = (int) Photo::processed()->max('id');
        $latestChange = (string) Photo::processed()->max('updated_at');

        $photos = Photo::processed()
            ->where(function (Builder $query) use ($afterId, $since): void {
                $query->where('id', '>', $afterId)
                    ->orWhere('updated_at', '>=', Carbon::createFromTimestamp($since));
            })
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $photos->count() > $limit;
        $photos = $photos->take($limit);

        $nextCursor = $this->encodeUpdatesCursor(
            max($afterId, (int) $photos->max('id')),
            $hasMore ? $since : now()->timestamp - (int) config('photos.updates_since_overlap_seconds'),
        );

        $response = PhotoResource::collection($photos)
            ->additional([
                'cursor' => $nextCursor,
                'has_more' => $hasMore,
            ])
            ->response($request);

        $response->setEtag(md5("{$afterId}:{$since}:{$latestId}:{$latestChange}"));
        $response->isNotModified($request);

        return $response;
    }

    private function encodeUpdatesCursor(int $afterId, int $since): string
    {
        return Crypt::encryptString(json_encode(['after_id' => $afterId, 'since' => max(0, $since)]));
    }

    /**
     * @return array{after_id: int, since: int}|null
     */
    private function decodeUpdatesCursor(string $cursor): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($cursor), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_int($data['after_id'] ?? null) || ! is_int($data['since'] ?? null)) {
            return null;
        }

        return ['after_id' => $data['after_id'], 'since' => $data['since']];
    }

    /**
     * Show a single processed photo by its public uuid.
     */
    public function show(string $uuid): PhotoResource
    {
        $photo = Photo::processed()->where('uuid', $uuid)->firstOrFail();

        return new PhotoResource($photo);
    }

    /**
     * Report the processing status of a photo ("pending", "processed" or "failed").
     *
     * The uploader polls this to learn about a failed photo right away. The
     * uuid is unguessable and nothing else about the photo is returned.
     */
    public function status(string $uuid): JsonResponse
    {
        $photo = Photo::where('uuid', $uuid)->firstOrFail();

        return response()->json(['status' => $photo->status]);
    }

    /**
     * Delete a photo and its files. Admin only (see RequirePhotoAdmin).
     *
     * Any status can be deleted, so pending and failed photos can be removed too.
     */
    public function destroy(string $uuid): Response
    {
        $photo = Photo::where('uuid', $uuid)->firstOrFail();

        if (! $photo->deleteWithFiles()) {
            return response()->json([
                'message' => 'The server could not delete the photo. Try again.',
            ], 500);
        }

        return response()->noContent();
    }

    /**
     * Download the high quality copy of a processed photo.
     *
     * New photos have a JPEG download copy (see config photos.download). Photos
     * processed before that existed, or when originals are kept, fall back to
     * the raw original, which keeps its metadata (including GPS) and has no
     * file extension on disk, so its name is built from the mime type.
     */
    public function download(string $uuid): Response
    {
        $photo = Photo::processed()->where('uuid', $uuid)->firstOrFail();

        $disk = Storage::disk('local');
        $downloadPath = Photo::downloadPathFor($photo->original_path);

        if ($disk->exists($downloadPath) && ! config('photos.download.keep_originals')) {
            $path = $downloadPath;
            $mimeType = 'image/jpeg';
        } else {
            abort_unless($disk->exists($photo->original_path), 404);

            $path = $photo->original_path;
            $mimeType = $photo->mime_type;
        }

        $extension = self::DOWNLOAD_EXTENSIONS[$mimeType] ?? 'jpg';
        $fileName = sprintf(
            'foto-%s-%s.%s',
            $photo->created_at->format('Ymd-His'),
            Str::substr($photo->uuid, 0, 8),
            $extension,
        );

        return $disk->download($path, $fileName, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
