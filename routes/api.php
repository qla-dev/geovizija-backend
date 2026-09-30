<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Middleware\EnsureAdminToken;
use Illuminate\Support\Facades\Route;

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show']);
Route::get('/quizzes', [QuizController::class, 'index']);
Route::get('/quizzes/today', [QuizController::class, 'today']);
Route::get('/quizzes/{date}', [QuizController::class, 'show']);

Route::middleware(EnsureAdminToken::class)->group(function () {
    Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
    Route::apiResource('posts', PostController::class)->except(['index', 'show']);
    Route::post('/posts/{post}/generate-image', [PostController::class, 'generateImage']);
    Route::post('/posts/{post}/generate-content', [PostController::class, 'generateContent']);
    Route::post('/quizzes/generate', [QuizController::class, 'generate']);
});
