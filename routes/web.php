<?php

use App\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;

// Link posted to Facebook: Open Graph preview, then redirect to the article.
Route::get('/share/{post}', ShareController::class)->name('share');

Route::get('/', function () {
    return view('welcome');
});
