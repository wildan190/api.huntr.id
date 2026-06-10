<?php

use App\Domain\Order\Http\Controllers\ReturnController;
use App\Domain\Order\Http\Controllers\DebitNoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->middleware('auth:sanctum')->group(function () {
    // Returns endpoints
    Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');
    Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('/returns/{id}', [ReturnController::class, 'show'])->name('returns.show');
    Route::put('/returns/{id}/status', [ReturnController::class, 'updateStatus'])->name('returns.update-status');
    Route::post('/returns/{id}/inspect', [ReturnController::class, 'inspect'])->name('returns.inspect');
    Route::post('/returns/{id}/approve', [ReturnController::class, 'approve'])->name('returns.approve');

    // Debit Notes endpoints
    Route::post('/debit-notes', [DebitNoteController::class, 'store'])->name('debit-notes.store');
    Route::post('/debit-notes/from-return/{returnId}', [DebitNoteController::class, 'createFromReturn'])->name('debit-notes.from-return');
    Route::get('/debit-notes', [DebitNoteController::class, 'index'])->name('debit-notes.index');
    Route::get('/debit-notes/{id}', [DebitNoteController::class, 'show'])->name('debit-notes.show');
    Route::post('/debit-notes/{id}/issue', [DebitNoteController::class, 'issue'])->name('debit-notes.issue');
    Route::post('/debit-notes/{id}/acknowledge', [DebitNoteController::class, 'acknowledge'])->name('debit-notes.acknowledge');
    Route::post('/debit-notes/{id}/settle', [DebitNoteController::class, 'settle'])->name('debit-notes.settle');
    Route::post('/debit-notes/{id}/dispute', [DebitNoteController::class, 'dispute'])->name('debit-notes.dispute');
    Route::post('/debit-notes/{id}/resolve-dispute', [DebitNoteController::class, 'resolveDispute'])->name('debit-notes.resolve-dispute');
});
