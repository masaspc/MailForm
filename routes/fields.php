<?php

use App\Http\Controllers\Admin\FieldTemplateController;
use App\Http\Middleware\AdminAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/fields')->middleware(['auth', AdminAccess::class.':admin,editor'])->group(function () {
    Route::get('/', [FieldTemplateController::class, 'index'])->name('admin.fields');
    Route::post('/', [FieldTemplateController::class, 'store'])->name('admin.fields.store');
    Route::get('/{template}/edit', [FieldTemplateController::class, 'edit'])->name('admin.fields.edit');
    Route::put('/{template}', [FieldTemplateController::class, 'update'])->name('admin.fields.update');
    Route::delete('/{template}', [FieldTemplateController::class, 'destroy'])->name('admin.fields.destroy');
});

Route::prefix('admin/themes')->middleware(['auth', AdminAccess::class.':admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\ThemeController::class, 'index'])->name('admin.themes');
    Route::get('/{slug}/edit', [\App\Http\Controllers\Admin\ThemeController::class, 'edit'])->name('admin.themes.edit');
    Route::post('/', [\App\Http\Controllers\Admin\ThemeController::class, 'save'])->name('admin.themes.save');
});
