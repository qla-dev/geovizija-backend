<?php

use App\Http\Controllers\LegacyPostImageController;
use App\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;

// Link posted to Facebook: Open Graph preview, then redirect to the article.
Route::get('/share/{post}', ShareController::class)->name('share');

// Existing files are served directly; only missing historical cover URLs reach Laravel.
Route::get('/media/posts/{filename}', LegacyPostImageController::class)
    ->where('filename', '[a-z0-9-]+\.jpg')->withoutMiddleware('web');

Route::get('/', function () {
    return view('welcome');
});
