<?php

use App\Http\Controllers\Access\SecretKey;
use App\Http\Controllers\Payment\Create;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::post('/pay/create', [Create::class, 'createPaymentLink']);
Route::post('/pay/authorize/one', [Create::class, 'authorizePayment']);
Route::post('/pay/authorize/two', [Create::class, 'paymentTypeTwo']);
Route::post('/create/app/key', [SecretKey::class, 'createSecretKey']);
Route::get('/pay/get/{id}', [Create::class, 'getPaymentLink']);
Route::get('/pay/links/{id}', [Create::class, 'getPaymentLinks']);
