<?php

use App\Http\Controllers\SearchController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

$stateless = [
    PreventRequestForgery::class,
    StartSession::class,
    ShareErrorsFromSession::class,
];

Route::get('/', function () {
    return view('index');
})->withoutMiddleware($stateless);

Route::get('/api/search', SearchController::class)->withoutMiddleware($stateless);
