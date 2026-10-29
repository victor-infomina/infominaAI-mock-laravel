<?php

use App\Http\Controllers\ApiClientController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\SyncCasesController;
use App\Http\Controllers\WebhookInbox\ReceiveController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Partner-side callback landing: the FE redirects the browser here after a
// partner/account purchase. Public by design (see README "Webhook inbox").
Route::get('/webhook-inbox/{slug}/{token}/callback', [ReceiveController::class, 'callback'])
    ->middleware('throttle:120,1')
    ->where(['slug' => '[a-z0-9][a-z0-9-]{0,63}', 'token' => '[a-f0-9]{32}']);

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/tokens', [ApiClientController::class, 'index'])->name('tokens.index');
    Route::post('/tokens', [ApiClientController::class, 'store'])->name('tokens.store');
    Route::post('/tokens/{apiClient}/revoke', [ApiClientController::class, 'revoke'])->name('tokens.revoke');
});

Route::middleware(['auth', 'local.only'])->group(function () {
    Route::get('/admin/sync-cases', [SyncCasesController::class, 'index']);
    Route::get('/admin/sync-cases/{requestId}', [SyncCasesController::class, 'show']);
    Route::post('/admin/sync-cases/{requestId}/sync', [SyncCasesController::class, 'sync']);
});
