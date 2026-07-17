<?php

use App\Http\Controllers\SsmMockController;
use Illuminate\Support\Facades\Route;

Route::middleware('verify.gateway')->group(function () {
    Route::post('/get-search-entity', [SsmMockController::class, 'searchEntity']);
});
