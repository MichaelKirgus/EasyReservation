<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;

Route::get('/', function () {
    return view('welcome');
});
