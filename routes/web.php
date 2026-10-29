<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/password-reset', function () {
    return 'reste password';
})->name('password.reset');
