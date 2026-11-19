<?php

use App\Http\Controllers\ThemeAssetController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/admin.php';
require __DIR__.'/fields.php';
Route::get('/_theme/{slug}/file/{path}', ThemeAssetController::class)->where('path', '.+')->name('theme.asset');
require __DIR__.'/public.php';
