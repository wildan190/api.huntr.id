<?php

use Illuminate\Support\Facades\Route;

Route::prefix('api/access')->middleware('api')->group(function () {
    // Role & Permission management routes can be added here
});
