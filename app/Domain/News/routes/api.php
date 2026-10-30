<?php

use App\Domain\News\Http\Controllers\NewsController;
use Illuminate\Support\Facades\Route;

Route::get('api/news', [NewsController::class, 'index'])->middleware(['api', 'auth:api']);
