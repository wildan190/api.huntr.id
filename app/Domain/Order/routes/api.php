<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Order\Http\Controllers\OrderController;
use App\Domain\Order\Http\Controllers\ReturnController;
use App\Domain\Order\Http\Controllers\DebitNoteController;
use App\Domain\Order\Http\Controllers\BastController;

Route::prefix('api/orders')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('', [OrderController::class, 'index']);
    Route::post('award', [OrderController::class, 'award']);
    Route::post('{po}/confirm', [OrderController::class, 'confirm']);
    Route::get('{po}/print', [OrderController::class, 'printPo'])->withoutMiddleware('auth:api');
    
    // Negotiation routes (Legacy endpoints redirecting to new Domain)
    Route::get('negotiations', [\App\Domain\Negotiation\Http\Controllers\NegotiationController::class, 'index']);
    Route::post('negotiate', [\App\Domain\Negotiation\Http\Controllers\NegotiationController::class, 'store']);
    Route::post('negotiate/{negotiation}/respond', [\App\Domain\Negotiation\Http\Controllers\NegotiationController::class, 'respond']);
    Route::post('{po}/arrange-delivery', [OrderController::class, 'arrangeDelivery']);
    // Vendor tracking status update (Packing / In Transit / Delivered)
    Route::post('{po}/update-tracking-status', [OrderController::class, 'updateTrackingStatus']);
});

// Public tracking — no auth required
Route::get('api/track', [OrderController::class, 'publicTrack'])->middleware('api');

Route::prefix('api/do')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('{deliveryOrder}/print', [OrderController::class, 'printDo'])->withoutMiddleware('auth:api');
    Route::post('{deliveryOrder}/sign-handed-by', [OrderController::class, 'signDoHandedBy']);
    Route::post('{deliveryOrder}/sign-received-by', [OrderController::class, 'signDoReceivedBy']);
});

Route::prefix('api/invoices')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('{invoice}/print', [OrderController::class, 'printInvoice'])->withoutMiddleware('auth:api');
    Route::post('{invoice}/publish', [OrderController::class, 'publishInvoice']);
    Route::post('{invoice}/approve', [OrderController::class, 'approveInvoice']);
});

// Returns routes
Route::prefix('api/returns')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('', [ReturnController::class, 'index'])->name('returns.index');
    Route::post('', [ReturnController::class, 'store'])->name('returns.store');
    Route::get('{id}', [ReturnController::class, 'show'])->name('returns.show');
    Route::patch('{id}/status', [ReturnController::class, 'updateStatus'])->name('returns.update-status');
    Route::post('{id}/inspect', [ReturnController::class, 'inspect'])->name('returns.inspect');
    Route::post('{id}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
    
    // New resolution workflow endpoints
    Route::post('{id}/propose-resolution', [\App\Domain\Order\Http\Controllers\GoodsReturnController::class, 'proposeResolution'])->name('returns.propose-resolution');
    Route::post('{id}/approve-resolution', [\App\Domain\Order\Http\Controllers\GoodsReturnController::class, 'approveResolution'])->name('returns.approve-resolution');
    Route::patch('{id}/complete', [\App\Domain\Order\Http\Controllers\GoodsReturnController::class, 'complete'])->name('returns.complete');
});

// Debit Notes routes
Route::prefix('api/debit-notes')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('', [DebitNoteController::class, 'index'])->name('debit-notes.index');
    Route::post('', [DebitNoteController::class, 'store'])->name('debit-notes.store');
    Route::post('from-return/{returnId}', [DebitNoteController::class, 'createFromReturn'])->name('debit-notes.from-return');
    Route::get('{id}', [DebitNoteController::class, 'show'])->name('debit-notes.show');
    Route::post('{id}/issue', [DebitNoteController::class, 'issue'])->name('debit-notes.issue');
    Route::post('{id}/acknowledge', [DebitNoteController::class, 'acknowledge'])->name('debit-notes.acknowledge');
    Route::post('{id}/settle', [DebitNoteController::class, 'settle'])->name('debit-notes.settle');
    Route::post('{id}/dispute', [DebitNoteController::class, 'dispute'])->name('debit-notes.dispute');
    Route::post('{id}/resolve-dispute', [DebitNoteController::class, 'resolveDispute'])->name('debit-notes.resolve-dispute');
});

// BAST routes (moved to Order domain as part of PO lifecycle)
Route::prefix('api/basts')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('', [BastController::class, 'index'])->name('basts.index');
    Route::post('', [BastController::class, 'store'])->name('basts.store');
    Route::get('{id}', [BastController::class, 'show'])->name('basts.show');
    Route::post('{id}/sign-handed-by', [BastController::class, 'signHandedBy'])->name('basts.sign-handed-by');
    Route::post('{id}/sign-received-by', [BastController::class, 'signReceivedBy'])->name('basts.sign-received-by');
});

// BAST PDF endpoint - no auth middleware (HTML view for printing)
Route::prefix('api/basts')->middleware(['api'])->group(function () {
    Route::get('{id}/pdf', [BastController::class, 'showPdf'])->name('basts.pdf')->withoutMiddleware('auth:sanctum');
});

