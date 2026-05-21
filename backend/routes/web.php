<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/api/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'OpenPersona API is running',
    ]);
});
