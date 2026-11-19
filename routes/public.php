<?php

use App\Http\Controllers\PublicFormController;
use Illuminate\Support\Facades\Route;

// Register root actions before the single-segment form route.
foreach (['' => 'public.root.', '{slug}' => 'public.'] as $prefix => $name) {
    Route::prefix($prefix)->name($name)->controller(PublicFormController::class)->group(function () {
        Route::post('/confirm', 'confirm')->middleware('throttle:30,1,public-post')->block(30, 10)->name('confirm');
        Route::post('/back', 'back')->middleware('throttle:30,1,public-post')->block(30, 10)->name('back');
        Route::post('/send', 'submit')->middleware('throttle:30,1,public-post')->block(30, 10)->name('send');
        Route::match(['get', 'post'], '/thanks', 'thanks')->middleware('throttle:60,1,public-thanks')->name('thanks');
        Route::get('/', 'show')->block(30, 10)->name('input');
        Route::post('/', 'confirm')->middleware('throttle:30,1,public-post')->block(30, 10)->name('confirm.legacy');
    });
}
