<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The row of a deleted photo is gone, so the polling feed reads this log
     * to tell open pages which photos to remove.
     */
    public function up(): void
    {
        Schema::create('photo_deletions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamp('deleted_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_deletions');
    }
};
