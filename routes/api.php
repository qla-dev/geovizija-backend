<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PublishController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Middleware\EnsureAdminToken;
use Illuminate\Support\Facades\Route;

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show']);
Route::get('/posts/{post}/preview', [PostController::class, 'preview']);
Route::get('/posts/{post}/comments', [CommentController::class, 'index']);
Route::get('/quizzes', [QuizController::class, 'index']);
Route::get('/quizzes/today', [QuizController::class, 'today']);
Route::get('/quizzes/{date}', [QuizController::class, 'show']);

// Paste-and-publish for Claude-written JSON; guarded by the secret inside the JSON, rate limited.
Route::post('/publish', PublishController::class)->middleware('throttle:10,1');

// Anonymous comments; rate limited per IP.
Route::post('/posts/{post}/comments', [CommentController::class, 'store'])->middleware('throttle:5,1');
Route::post('/comments/{comment}/like', [CommentController::class, 'like'])->middleware('throttle:30,1');
Route::delete('/comments/{comment}/like', [CommentController::class, 'unlike'])->middleware('throttle:30,1');

Route::middleware(EnsureAdminToken::class)->group(function () {
    Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
    Route::apiResource('posts', PostController::class)->except(['index', 'show']);
    Route::post('/posts/{post}/generate-image', [PostController::class, 'generateImage']);
    Route::post('/posts/{post}/generate-content', [PostController::class, 'generateContent']);
    Route::post('/posts/{post}/generate-inline-image', [PostController::class, 'generateInlineImage']);
    Route::post('/posts/{post}/share-meta', [PostController::class, 'shareToMeta']);
    Route::post('/quizzes', [QuizController::class, 'store']);
    Route::post('/quizzes/generate', [QuizController::class, 'generate']);
    Route::get('/agent/context', [AgentController::class, 'context']);
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);
});
