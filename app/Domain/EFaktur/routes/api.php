<?php

use Illuminate\Support\Facades\Route;
use App\Domain\EFaktur\Http\Controllers\EFakturController;

Route::prefix('api/efaktur')->middleware(['api', 'auth:sanctum'])->group(function () {

    /* ── VAT IN (static paths — must come before {id} wildcard) ──── */
    Route::get('vat-in',                  [EFakturController::class, 'vatInList']);
    Route::post('vat-in/prepopulated',    [EFakturController::class, 'vatInPrepopulated']);
    Route::post('vat-in/upload',          [EFakturController::class, 'vatInUpload']);
    Route::post('vat-in/verify',          [EFakturController::class, 'vatInVerify']);

    /* ── VAT OUT — direct PajakExpress list (static path) ────────── */
    Route::get('vat-out/list',            [EFakturController::class, 'vatOutList']);

    /* ── Reference data ───────────────────────────────────────────── */
    Route::get('references',              [EFakturController::class, 'references']);
    Route::get('bast/{bastId}/items',     [EFakturController::class, 'bastItems']);

    /* ── VAT OUT — local CRUD ─────────────────────────────────────── */
    Route::post('',                       [EFakturController::class, 'store']);   // Terbitkan dari BAST
    Route::get('',                        [EFakturController::class, 'index']);   // List lokal per company
    Route::get('{id}',                    [EFakturController::class, 'show']);    // Detail + refresh status
    Route::post('{id}/upload',            [EFakturController::class, 'upload']); // Upload DRAFT → DJP
    Route::post('{id}/cancel',            [EFakturController::class, 'cancel']); // Cancel approved faktur
    Route::delete('{id}',                 [EFakturController::class, 'destroy']); // Hapus draft
});
