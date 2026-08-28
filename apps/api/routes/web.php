<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'status' => 'ok',
    'name' => config('app.name'),
    'api' => url('/api/ping'),
]));
