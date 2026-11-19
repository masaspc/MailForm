<?php

use App\Http\Controllers\Admin\FormController;
use App\Http\Controllers\Admin\HistoryController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\PublicFormController;
use App\Http\Middleware\AdminAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::get('login', [SessionController::class, 'create'])->name('login');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:30,1')->name('admin.login');
    Route::middleware('auth')->name('admin.')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
        Route::middleware(AdminAccess::class.':admin,editor,viewer')->group(function () {
            Route::get('/', [FormController::class, 'index'])->name('forms');
            Route::get('deliveries', [HistoryController::class, 'deliveries'])->name('deliveries');
            Route::get('history', [HistoryController::class, 'index'])->name('history');
            Route::get('forms/{form}/csv', [HistoryController::class, 'csv'])->name('csv');
            Route::get('history/{submission}', [HistoryController::class, 'show'])->name('submission');
            Route::get('history/{submission}/files/{attachment}', [HistoryController::class, 'download'])->name('attachment');
        });
        Route::middleware(AdminAccess::class.':admin,editor')->group(function () {
            Route::get('forms/new', [FormController::class, 'create'])->name('create');
            Route::get('forms/{form}/preview', [PublicFormController::class, 'preview'])->name('preview');
            Route::post('forms', [FormController::class, 'store'])->name('store');
            Route::get('forms/{form}/edit', [FormController::class, 'edit'])->name('edit');
            Route::put('forms/{form}', [FormController::class, 'update'])->name('update');
            Route::post('forms/{form}/copy', [FormController::class, 'copy'])->name('copy');
            Route::post('forms/{form}/publish', [FormController::class, 'publish'])->name('publish');
            Route::post('forms/{form}/unpublish', [FormController::class, 'unpublish'])->name('unpublish');
        });
        Route::middleware(AdminAccess::class.':admin')->group(function () {
            Route::post('forms/{form}/archive', [FormController::class, 'archive'])->name('archive');
            Route::post('forms/{form}/restore', [FormController::class, 'restore'])->name('restore');
            Route::delete('history/{submission}', [HistoryController::class, 'destroy'])->name('history.destroy');
            Route::get('users', [UserController::class, 'index'])->name('users');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        });
    });
});
