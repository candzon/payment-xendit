<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/invoices', [PaymentController::class, 'index']);
Route::post('/create-invoice', [PaymentController::class, 'store']);
Route::post('/webhook/notification', [PaymentController::class, 'notification']);