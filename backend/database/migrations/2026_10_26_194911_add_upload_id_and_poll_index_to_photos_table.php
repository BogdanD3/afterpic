<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * upload_id: chosen by the phone, the same for every retry of one photo,
     * so a retry after a lost response does not store the photo twice.
     *
     * (status, updated_at): every poll asks for the newest change of a
     * processed photo and for photos changed since the last poll. Without
     * this index each poll reads every processed row.
     */
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->uuid('upload_id')->nullable()->unique()->after('uuid');
            $table->index(['status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
            $table->dropUnique(['upload_id']);
            $table->dropColumn('upload_id');
        });
    }
};
