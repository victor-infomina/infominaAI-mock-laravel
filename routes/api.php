<?php

use App\Http\Controllers\ReportController;
use App\Http\Controllers\SsmMockController;
use Illuminate\Support\Facades\Route;

Route::middleware('verify.gateway')->group(function () {
    Route::post('/get-search-entity', [SsmMockController::class, 'searchEntity']);
    Route::post('/v2/get-company-profile-document', [SsmMockController::class, 'companyProfile']);
    Route::post('/v2/get-bizprofile-document', [SsmMockController::class, 'businessProfile']);
    Route::post('/v2/get-llp-current-profile', [SsmMockController::class, 'llpProfile']);
    Route::post('/get-order-document', [SsmMockController::class, 'orderDocument']);
    Route::post('/get-image-list', [SsmMockController::class, 'imageList']);
    Route::post('/get-image', [SsmMockController::class, 'image']);
});

Route::get('/reports/{caseKey}.pdf', [ReportController::class, 'show'])
    ->where('caseKey', '[A-Za-z0-9_-]+')
    ->name('ssm-mock.report');
