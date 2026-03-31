<?php

use App\Approov\ApproovService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (ApproovService $approov) {
    return response()->json($approov->home());
});

Route::get('/approov-state', function (ApproovService $approov) {
    return response()->json($approov->approovState());
});

Route::post('/approov/enable', function (ApproovService $approov) {
    return response()->json($approov->enableApproov());
});

Route::post('/approov/disable', function (ApproovService $approov) {
    return response()->json($approov->disableApproov());
});

Route::post('/token-binding/enable', function (ApproovService $approov) {
    return response()->json($approov->enableTokenBinding());
});

Route::post('/token-binding/disable', function (ApproovService $approov) {
    return response()->json($approov->disableTokenBinding());
});

Route::get('/unprotected', function (ApproovService $approov) {
    return response()->json($approov->unprotected());
});

Route::get('/token-check', function (ApproovService $approov) {
    return response()->json($approov->tokenCheck());
})->middleware('approov');

Route::get('/token-binding', function (Request $request, ApproovService $approov) {
    return response()->json(
        $approov->tokenBinding($request->header(ApproovService::AUTH_HEADER))
    );
})->middleware('approov:' . ApproovService::AUTH_HEADER);

Route::get('/token-double-binding', function (Request $request, ApproovService $approov) {
    return response()->json(
        $approov->tokenDoubleBinding(
            $request->header(ApproovService::AUTH_HEADER),
            $request->header(ApproovService::SESSION_ID_HEADER)
        )
    );
})->middleware('approov:' . ApproovService::AUTH_HEADER . ',' . ApproovService::SESSION_ID_HEADER);

Route::fallback(function () {
    return response()->json(['message' => 'Not Found'], 404);
});
