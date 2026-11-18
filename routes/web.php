<?php

use App\Models\Order;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/shop', function () {
    return view('shop');
});

Route::get('/my-orders/{number}', function ($number) {
    $order = Order::where('number', $number)->firstOrFail();

    return view('my-orders', compact('order'));
});

Route::get('/login', function () {
    return 'redirect to frontendlogin page';
})->name('login');

Route::get('/password-reset', function () {
    return 'reste password';
})->name('password.reset');

Route::get('/login', function () {
    return 'redirect to frontendlogin page';
})->name('login');
