<?php

use App\Http\Controllers\AsiaverifyMockController;
use App\Http\Controllers\CaseUploadController;
use App\Http\Controllers\DnbMockController;
use App\Http\Controllers\LlmMockController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SsmMockController;
use Illuminate\Support\Facades\Route;

Route::middleware('verify.dnb')->post('/dnb', [DnbMockController::class, 'handle']);

Route::post('/token/create', [AsiaverifyMockController::class, 'createToken']);

Route::middleware('verify.asiaverify.token')->group(function () {
    Route::get('/{country}/search', [AsiaverifyMockController::class, 'search'])->where('country', '[A-Za-z]{3}');
    Route::post('/{country}/basic', [AsiaverifyMockController::class, 'basicProfile'])->where('country', '[A-Za-z]{3}');
});

Route::middleware('verify.gateway')->group(function () {
    Route::post('/get-search-entity', [SsmMockController::class, 'searchEntity']);
    Route::post('/v2/get-company-profile-document', [SsmMockController::class, 'companyProfile']);
    Route::post('/v2/get-bizprofile-document', [SsmMockController::class, 'businessProfile']);
    Route::post('/v2/get-llp-current-profile', [SsmMockController::class, 'llpProfile']);
    Route::post('/get-order-document', [SsmMockController::class, 'orderDocument']);
    Route::post('/get-image-list', [SsmMockController::class, 'imageList']);
    Route::post('/get-image', [SsmMockController::class, 'image']);
});

Route::middleware('verify.gateway:admin_sync')->group(function () {
    Route::post('/admin-api/cases', [CaseUploadController::class, 'store']);
});

Route::get('/reports/{caseKey}.pdf', [ReportController::class, 'show'])
    ->where('caseKey', '[A-Za-z0-9_-]+')
    ->name('ssm-mock.report');

Route::post('/{modelOp}/invoke', [LlmMockController::class, 'invoke'])
    ->where('modelOp', '[A-Za-z0-9_]+');
