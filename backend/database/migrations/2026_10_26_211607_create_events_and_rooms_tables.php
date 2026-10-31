<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An event is one booked party. It has one main room (the gallery its
     * table QR codes open) and, when guest rooms are allowed, rooms that
     * guests create for smaller groups. Every photo belongs to one room.
     *
     * Photos that exist before this migration move into a first event,
     * "Glavna galerija", which never expires.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->boolean('guest_rooms_enabled')->default(false);
            $table->boolean('uploads_open')->default(true);
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('host_key');
            $table->timestamp('deleting_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('code', 48)->unique();
            $table->string('name', 60);
            $table->boolean('is_main')->default(false);
            $table->text('admin_key')->nullable();
            $table->string('created_by_device', 40)->nullable()->index();
            $table->timestamp('deleting_at')->nullable();
            $table->timestamps();
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('upload_id')->constrained();
            $table->index(['room_id', 'status', 'created_at', 'uuid']);
            $table->index(['room_id', 'status', 'updated_at']);
            $table->index(['room_id', 'status', 'id']);
        });

        Schema::table('photo_deletions', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('uuid')->index();
        });

        if (DB::table('photos')->exists()) {
            $this->moveExistingPhotosIntoAFirstEvent();
        }
    }

    private function moveExistingPhotosIntoAFirstEvent(): void
    {
        $now = now();

        $eventId = DB::table('events')->insertGetId([
            'name' => 'Glavna galerija',
            'host_key' => Crypt::encryptString(Str::random(32)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roomId = DB::table('rooms')->insertGetId([
            'event_id' => $eventId,
            'code' => 'galerija-'.Str::lower(Str::random(10)),
            'name' => 'Glavna galerija',
            'is_main' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('photos')->update(['room_id' => $roomId]);
        DB::table('photo_deletions')->update(['room_id' => $roomId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photo_deletions', function (Blueprint $table) {
            $table->dropIndex(['room_id']);
            $table->dropColumn('room_id');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->dropIndex(['room_id', 'status', 'created_at', 'uuid']);
            $table->dropIndex(['room_id', 'status', 'updated_at']);
            $table->dropIndex(['room_id', 'status', 'id']);
            $table->dropColumn('room_id');
        });

        Schema::dropIfExists('rooms');
        Schema::dropIfExists('events');
    }
};
