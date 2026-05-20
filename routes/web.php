<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SatisfactionReviewController;

Route::inertia('/', 'welcome')->name('home');

Route::view('/app', 'spa')->name('spa');
Route::view('/app/{any}', 'spa')
    ->where('any', '.*')
    ->name('spa.catchall');

Route::get('/review/satisfaction', [SatisfactionReviewController::class, 'show'])
    ->middleware('signed')
    ->name('review.satisfaction');

Route::post('/review/satisfaction', [SatisfactionReviewController::class, 'submit'])
    ->middleware('signed')
    ->name('review.satisfaction.submit');
