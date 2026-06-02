<?php

use App\Modules\Workflow\Http\Controllers\ReviewController;
use App\Modules\Workflow\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Reviews
    Route::get('/v1/documents/{documentId}/review',    [ReviewController::class, 'show'])->name('reviews.show');
    Route::post('/v1/documents/{documentId}/review',   [ReviewController::class, 'store'])->name('reviews.store');
    Route::post('/v1/reviews/{reviewId}/advance',      [ReviewController::class, 'advance'])->name('reviews.advance');

    // Tasks
    Route::get('/v1/tasks',         [TaskController::class, 'index'])->name('tasks.index');
    Route::patch('/v1/tasks/{id}',  [TaskController::class, 'update'])->name('tasks.update');
});
