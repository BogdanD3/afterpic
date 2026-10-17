<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->string('original_path');
            $table->string('web_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('mime_type', 50);
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
