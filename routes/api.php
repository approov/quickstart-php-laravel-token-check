<?php

use App\Approov\ApproovService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get(
    '/',
    /**
     * Returns the API status payload for the root endpoint.
     *
     * @param  ApproovService  $approov  The service that assembles the root response payload.
     * @return \Illuminate\Http\JsonResponse
     */
    function (ApproovService $approov) {
        return response()->json($approov->home());
    }
);

Route::get(
    '/approov-state',
    /**
     * Returns the current Approov and token-binding feature state.
     *
     * @param  ApproovService  $approov  The service that exposes the current feature state.
     * @return \Illuminate\Http\JsonResponse
     */
    function (ApproovService $approov) {
        return response()->json($approov->approovState());
    }
);

Route::post(
    '/approov/enable',
    /**
     * Enables Approov verification and token binding for subsequent requests.
     *
     * @param  ApproovService  $approov  The service that updates the persisted Approov state.
     * @return \Illuminate\Http\JsonResponse
     */
    function (ApproovService $approov) {
        return response()->json($approov->enableApproov());
    }
);

Route::post(
    '/approov/disable',
    /**
     * Disables Approov verification and token binding for subsequent requests.
     *
     * @param  ApproovService  $approov  The service that updates the persisted Approov state.
     * @return \Illuminate\Http\JsonResponse
     */
    function (ApproovService $approov) {
        return response()->json($approov->disableApproov());
    }
);

Route::post(
    '/token-binding/enable',
    /**
     * Enables token binding while leaving Approov verification enabled.
     *
     * @param  ApproovService  $approov  The service that updates the persisted token-binding state.
     * @return \Illuminate\Http\JsonResponse
     */
    function (ApproovService $approov) {
        return response()->json($approov->enableTokenBinding());
    }
);

Route::post(
    '/token-binding/disable',
    /**
     * Disables token binding while leaving the current Approov setting unchanged.
     *
     * @param  ApproovService  $approov  The service that updates the persisted token-binding state.
     * @return \Illuminate\Http\JsonResponse
     */
    function (ApproovService $approov) {
        return response()->json($approov->disableTokenBinding());
    }
);

Route::get(
    '/unprotected',
    /**
     * Returns the unprotected demo payload without running Approov checks.
     *
     * @param  ApproovService  $approov  The service that builds the unprotected response payload.
     * @return \Illuminate\Http\JsonResponse
     */
    function (ApproovService $approov) {
        return response()->json($approov->unprotected());
    }
);

Route::get(
    '/token-check',
    /**
     * Returns the protected token-check payload after Approov middleware verification.
     *
     * @param  ApproovService  $approov  The service that builds the protected response payload.
     * @return \Illuminate\Http\JsonResponse
     */
    function (ApproovService $approov) {
        return response()->json($approov->tokenCheck());
    }
)->middleware('approov');

Route::get(
    '/token-binding',
    /**
     * Returns the token-binding payload using the current Authorization header as the binding input.
     *
     * @param  Request  $request  The request that may include the bound Authorization header.
     * @param  ApproovService  $approov  The service that builds the token-binding response payload.
     * @return \Illuminate\Http\JsonResponse
     */
    function (Request $request, ApproovService $approov) {
        return response()->json(
            $approov->tokenBinding($request->header(ApproovService::AUTH_HEADER))
        );
    }
)->middleware('approov:' . ApproovService::AUTH_HEADER);

Route::get(
    '/token-double-binding',
    /**
     * Returns the dual-binding payload using the Authorization and SessionId headers as binding inputs.
     *
     * @param  Request  $request  The request that may include both bound headers.
     * @param  ApproovService  $approov  The service that builds the dual-binding response payload.
     * @return \Illuminate\Http\JsonResponse
     */
    function (Request $request, ApproovService $approov) {
        return response()->json(
            $approov->tokenDoubleBinding(
                $request->header(ApproovService::AUTH_HEADER),
                $request->header(ApproovService::SESSION_ID_HEADER)
            )
        );
    }
)->middleware('approov:' . ApproovService::AUTH_HEADER . ',' . ApproovService::SESSION_ID_HEADER);

Route::fallback(
    /**
     * Returns a JSON 404 response for unmatched API routes.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    function () {
        return response()->json(['message' => 'Not Found'], 404);
    }
);
