<?php

use App\Http\Controllers\SsmMockController;
use Illuminate\Support\Facades\Route;

Route::middleware('verify.gateway')->group(function () {
    Route::post('/get-search-entity', [SsmMockController::class, 'searchEntity']);
    Route::post('/v2/get-company-profile-document', [SsmMockController::class, 'companyProfile']);
    Route::post('/v2/get-bizprofile-document', [SsmMockController::class, 'businessProfile']);
    Route::post('/v2/get-llp-current-profile', [SsmMockController::class, 'llpProfile']);
});
