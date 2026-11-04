<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Whistleblower\Http\Controllers\ManageReportController;
use Whistleblower\Http\Controllers\PublicReportController;

$prefix = (string) config('whistleblower.routes.prefix');

Route::prefix($prefix)->name('whistleblower.')->group(function (): void {
    Route::middleware((array) config('whistleblower.routes.public_middleware'))->group(function (): void {
        Route::post('reports', [PublicReportController::class, 'store'])->name('reports.store');
        Route::post('reports/status', [PublicReportController::class, 'status'])->name('reports.status');
        Route::post('reports/messages', [PublicReportController::class, 'message'])->name('reports.message');
    });

    Route::middleware((array) config('whistleblower.routes.manage_middleware'))->prefix('manage')->name('manage.')->group(function (): void {
        Route::get('reports', [ManageReportController::class, 'index'])->name('index');
        Route::get('reports/{report}', [ManageReportController::class, 'show'])->name('show');
        Route::post('reports/{report}/acknowledge', [ManageReportController::class, 'acknowledge'])->name('acknowledge');
        Route::post('reports/{report}/investigate', [ManageReportController::class, 'investigate'])->name('investigate');
        Route::post('reports/{report}/extend', [ManageReportController::class, 'extend'])->name('extend');
        Route::post('reports/{report}/resolve', [ManageReportController::class, 'resolve'])->name('resolve');
        Route::post('reports/{report}/archive', [ManageReportController::class, 'archive'])->name('archive');
        Route::post('reports/{report}/messages', [ManageReportController::class, 'message'])->name('message');
    });
});
