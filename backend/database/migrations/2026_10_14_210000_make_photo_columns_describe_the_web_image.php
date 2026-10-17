<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Long edge of the web image when the existing photos were processed.
     */
    private const WEB_MAX_EDGE = 1600;

    /**
     * Run the migrations.
     *
     * - size_bytes always held the size of web.webp, so it is renamed.
     * - width and height held the (upright) upload size. They now describe
     *   the web image, so existing rows are scaled the same way the image was
     *   (Intervention scaleDown: proportional, rounded, never enlarged).
     * - The gallery pages by created_at and uuid instead of the internal id.
     */
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->renameColumn('size_bytes', 'web_size_bytes');
            $table->index(['status', 'created_at', 'uuid']);
        });

        DB::table('photos')
            ->where(fn ($query) => $query
                ->where('width', '>', self::WEB_MAX_EDGE)
                ->orWhere('height', '>', self::WEB_MAX_EDGE))
            ->orderBy('id')
            ->chunkById(500, function ($photos): void {
                foreach ($photos as $photo) {
                    [$width, $height] = $this->scaledDown((int) $photo->width, (int) $photo->height);

                    DB::table('photos')->where('id', $photo->id)->update(['width' => $width, 'height' => $height]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * The original upload size cannot be recovered, so width and height stay
     * at the web image size.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at', 'uuid']);
            $table->renameColumn('web_size_bytes', 'size_bytes');
        });
    }

    /**
     * @return array{int, int}
     */
    private function scaledDown(int $width, int $height): array
    {
        $edge = self::WEB_MAX_EDGE;

        return [
            min(max(1, (int) round($edge * $width / $height)), $edge, $width),
            min(max(1, (int) round($edge * $height / $width)), $edge, $height),
        ];
    }
};
