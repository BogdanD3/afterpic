<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Requests for the super admin's inbox: a guest asks for a photo to be
     * removed, or a host or guest room admin asks for their room (or, from
     * the main room, the whole event) to be deleted. The room and photo stay
     * linked while they exist; after deleting them the request keeps its
     * reason and outcome.
     */
    public function up(): void
    {
        Schema::create('moderation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 10)->default('open')->index();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('photo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('room_name', 60);
            $table->string('event_name', 80);
            $table->text('reason');
            $table->string('device_id', 40)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moderation_requests');
    }
};
