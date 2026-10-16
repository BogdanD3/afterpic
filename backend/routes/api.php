<?php

use App\Http\Controllers\PhotoController;
use App\Http\Middleware\RequirePhotoAdmin;
use Illuminate\Support\Facades\Route;

Route::post('/photos', [PhotoController::class, 'store'])
    ->middleware('throttle:photo-uploads')
    ->name('photos.store');

Route::get('/photos', [PhotoController::class, 'index'])->name('photos.index');

Route::get('/photos/updates', [PhotoController::class, 'updates'])->name('photos.updates');

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

Route::delete('/photos/{uuid}', [PhotoController::class, 'destroy'])
    ->whereUuid('uuid')
    ->middleware(['throttle:photo-admin', RequirePhotoAdmin::class])
    ->name('photos.destroy');
