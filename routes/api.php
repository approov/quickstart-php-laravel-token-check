<?php

use App\ApproovApplication;
use App\Http\Middleware\ApproovTokenVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(ApproovApplication::home());
});

Route::get('/approov-state', function () {
    return response()->json(ApproovApplication::approovState());
});

Route::post('/approov/enable', function () {
    return response()->json(ApproovApplication::enableApproovEndpoint());
});

Route::post('/approov/disable', function () {
    return response()->json(ApproovApplication::disableApproovEndpoint());
});

Route::post('/token-binding/enable', function () {
    return response()->json(ApproovApplication::enableTokenBindingEndpoint());
});

Route::post('/token-binding/disable', function () {
    return response()->json(ApproovApplication::disableTokenBindingEndpoint());
});

Route::get('/unprotected', function () {
    return response()->json(ApproovApplication::unprotected());
});

Route::middleware([ApproovTokenVerifier::class])->group(function () {
    Route::get('/token-check', function () {
        return response()->json(ApproovApplication::tokenCheck());
    });

    Route::get('/token-binding', function (Request $request) {
        return response()->json(
            ApproovApplication::tokenBinding($request->header(ApproovApplication::AUTH_HEADER))
        );
    });

    Route::get('/token-double-binding', function (Request $request) {
        return response()->json(
            ApproovApplication::tokenDoubleBinding(
                $request->header(ApproovApplication::AUTH_HEADER),
                $request->header(ApproovApplication::SESSION_ID_HEADER)
            )
        );
    });
});

Route::fallback(function () {
    return response()->json(['message' => 'Not Found'], 404);
});
