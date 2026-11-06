<?php

use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
| The whole app sits behind login — there are no public pages.
*/

Route::middleware('auth')->group(function () {
    // اليوم
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/batches', [BatchController::class, 'store'])->name('batches.store');
    Route::post('/discover', [BatchController::class, 'discover'])->name('discover');
    Route::patch('/batch-items/{item}', [BatchController::class, 'update'])->name('batch-items.update');

    // الأرشيف
    Route::get('/archive', ArchiveController::class)->name('archive.index');

    // الشركات
    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::post('/companies/{company}/enrich', [CompanyController::class, 'enrich'])->name('companies.enrich');
    Route::post('/companies/{company}/analyze', [CompanyController::class, 'analyze'])->name('companies.analyze');

    // البحث
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');
    Route::post('/search/queries', [SearchController::class, 'store'])->name('search.queries.store');
    Route::patch('/search/queries/{searchQuery}', [SearchController::class, 'update'])->name('search.queries.update');
    Route::delete('/search/queries/{searchQuery}', [SearchController::class, 'destroy'])->name('search.queries.destroy');

    // الإعدادات
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.index');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/blocked-domains', [SettingsController::class, 'storeBlockedDomain'])->name('settings.blocked-domains.store');
    Route::delete('/settings/blocked-domains/{blockedDomain}', [SettingsController::class, 'destroyBlockedDomain'])->name('settings.blocked-domains.destroy');
    Route::delete('/settings/sources/{source}', [SettingsController::class, 'enableSource'])->name('settings.sources.enable');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
