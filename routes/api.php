<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PublishController;
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

// Paste-and-publish for Claude-written JSON; guarded by the secret inside the JSON, rate limited.
Route::post('/publish', PublishController::class)->middleware('throttle:10,1');

Route::middleware(EnsureAdminToken::class)->group(function () {
    Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
    Route::apiResource('posts', PostController::class)->except(['index', 'show']);
    Route::post('/posts/{post}/generate-image', [PostController::class, 'generateImage']);
    Route::post('/posts/{post}/generate-content', [PostController::class, 'generateContent']);
    Route::post('/posts/{post}/generate-inline-image', [PostController::class, 'generateInlineImage']);
    Route::post('/quizzes', [QuizController::class, 'store']);
    Route::post('/quizzes/generate', [QuizController::class, 'generate']);
    Route::get('/agent/context', [AgentController::class, 'context']);
});
