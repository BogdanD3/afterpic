<?php

use App\Http\Controllers\Admin\DeviceController as AdminDeviceController;
use App\Http\Controllers\Admin\EventController as AdminEventController;
use App\Http\Controllers\Admin\RoomController as AdminRoomController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\RoomController;
use App\Http\Middleware\LimitPhotoUploads;
use App\Http\Middleware\RequirePhotoAdmin;
use Illuminate\Support\Facades\Route;

// Guests: whoever has a room link (its code is the key).
Route::get('/rooms/{room}', [RoomController::class, 'show'])->name('rooms.show');

Route::get('/rooms/{room}/access', [RoomController::class, 'access'])->name('rooms.access');

Route::post('/rooms/{room}/rooms', [RoomController::class, 'storeGuestRoom'])->name('rooms.guest-rooms.store');

Route::get('/rooms/{room}/photos', [PhotoController::class, 'index'])->name('rooms.photos.index');

Route::get('/rooms/{room}/photos/updates', [PhotoController::class, 'updates'])->name('rooms.photos.updates');

Route::post('/rooms/{room}/photos', [PhotoController::class, 'store'])
    ->middleware(LimitPhotoUploads::class)
    ->name('rooms.photos.store');

// The guest's own phone (device cookie): the optional name under their photos.
Route::get('/me', [MeController::class, 'show'])->name('me.show');

Route::put('/me/name', [MeController::class, 'updateName'])->name('me.name');

// Single photos by uuid, which is unguessable and only listed inside its room.
Route::get('/photos/{uuid}', [PhotoController::class, 'show'])
    ->whereUuid('uuid')
    ->name('photos.show');

Route::get('/photos/{uuid}/status', [PhotoController::class, 'status'])
    ->whereUuid('uuid')
    ->name('photos.status');

Route::get('/photos/{uuid}/download', [PhotoController::class, 'download'])
    ->whereUuid('uuid')
    ->middleware('throttle:photo-downloads')
    ->name('photos.download');

// Super admin, event host or guest room admin (checked in the controller).
Route::delete('/photos/{uuid}', [PhotoController::class, 'destroy'])
    ->whereUuid('uuid')
    ->name('photos.destroy');

// Super admin only.
Route::prefix('admin')->middleware(RequirePhotoAdmin::class)->name('admin.')->group(function (): void {
    Route::get('/events', [AdminEventController::class, 'index'])->name('events.index');
    Route::post('/events', [AdminEventController::class, 'store'])->name('events.store');
    Route::patch('/events/{event}', [AdminEventController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [AdminEventController::class, 'destroy'])->name('events.destroy');
    Route::delete('/rooms/{room}', [AdminRoomController::class, 'destroy'])->name('rooms.destroy');
    Route::post('/rooms/{room}/rotate-code', [AdminRoomController::class, 'rotateCode'])->name('rooms.rotate-code');
    Route::get('/devices', [AdminDeviceController::class, 'index'])->name('devices.index');
    Route::get('/photos/{uuid}/device', [AdminDeviceController::class, 'forPhoto'])->whereUuid('uuid')->name('photos.device');
    Route::delete('/devices/{device}/photos', [AdminDeviceController::class, 'destroyPhotos'])->name('devices.photos.destroy');
    Route::post('/devices/{device}/block', [AdminDeviceController::class, 'block'])->name('devices.block');
    Route::delete('/devices/{device}/block', [AdminDeviceController::class, 'unblock'])->name('devices.unblock');
});
