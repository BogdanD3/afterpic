<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A device is a phone, known by the id in its signed cookie (see
     * DeviceIdentity). There is no account: the optional name is whatever the
     * guest typed, and `client` is only a short description such as
     * "iPhone · Safari" for the admin. Photos remember which device uploaded
     * them, so the admin can remove everything from one phone and block it.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 40)->nullable();
            $table->string('client', 40)->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->string('device_id', 40)->nullable()->after('room_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex(['device_id']);
            $table->dropColumn('device_id');
        });

        Schema::dropIfExists('devices');
    }
};
