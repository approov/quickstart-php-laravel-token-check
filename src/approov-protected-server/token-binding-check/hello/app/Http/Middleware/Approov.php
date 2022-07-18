<?php declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Symfony\Component\HttpFoundation\HeaderBag;

class Approov
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $approov_token_claims = $this->verifyApproovToken($request->headers);

        if (!$approov_token_claims) {
            return response()->json(new \stdClass(), 401);
        }

        if (!$this->verifyApproovTokenBinding($request->headers, $approov_token_claims)) {
            return response()->json(new \stdClass(), 401);
        }

        return $next($request);
    }

    /**
     * Verifies the Approov token in the incoming request.
     *
     * Returns the Approov token claims on success or null on failure.
     *
     * @param  Symfony\Component\HttpFoundation\HeaderBag  $headers
     * @return ?\stdClass
     */
    private function verifyApproovToken(HeaderBag $headers): ?\stdClass {
        try {
            $approov_token = $headers->get('Approov-Token');

            if (empty($approov_token)) {
                // You may want to add some logging here
                // \Log::debug("MISSING APPROOV TOKEN");
                return null;
            }

            $approov_secret = config('approov.secret');

            if (empty($approov_secret)) {
                // You may want to add some logging here
                // \Log::debug("MISSING APPROOV SECRET");
                return null;
            }

            // The Approov secret cannot be given as part of a JWKS key set,
            // therefore you cannot use the Approov CLI to set a key id for it.
            //
            // If you set the key id then the token check will fail due to the
            // presence of a `kid` key in the header of the Approov token, that
            // will not be found in the `$approov_secret` variable, because this
            // variable contains the secret as a binary string, not as a JWKs
            // key set.
            $approov_token_claims = JWT::decode($approov_token, $approov_secret, ['HS256']);
            return $approov_token_claims;

        } catch(\UnexpectedValueException $exception) {
            // You may want to add some logging here
            // \Log::debug($exception->getMessage());
            return null;
        } catch(\InvalidArgumentException $exception) {
            // You may want to add some logging here
            // \Log::debug($exception->getMessage());
            return null;
        } catch(\DomainException $exception) {
            // You may want to add some logging here
            // \Log::debug($exception->getMessage());
            return null;
        }

        // You may want to add some logging here
        return null;
    }

    /**
     * Verifies the Approov token binding for the Approov token in the incoming
     * request.
     *
     * The value in the `pay` claim of the Approov token must match the token
     * binding header being used, the `Authorization` header.
     *
     * @param  Symfony\Component\HttpFoundation\HeaderBag  $headers
     * @param  \stdClass  $approov_token_claims
     * @return bool
     */
    private function verifyApproovTokenBinding(HeaderBag $headers, \stdClass $approov_token_claims): bool {
        if (empty($approov_token_claims->pay)) {
            // You may want to add some logging here
            // \Log::debug("MISSING APPROOV TOKEN BINDING CLAIM");
            return false;
        }

        // We use the Authorization token, but feel free to use another header in
        // the request. Bear in mind that it needs to be the same header used in the
        // mobile app to bind the request with the Approov token.
        $token_binding_header = $headers->get('Authorization');

        if (empty($token_binding_header)) {
            // You may want to add some logging here
            // \Log::debug("MISSING APPROOV TOKEN BINDING HEADER");
            return false;
        }

        // We need to hash and base64 encode the token binding header, because that's
        // how it was included in the Approov token on the mobile app.
        $token_binding_header_encoded = base64_encode(hash("sha256", $token_binding_header, true));

        if($approov_token_claims->pay !== $token_binding_header_encoded) {
            // You may want to add some logging here
            // \Log::debug("APPROOV TOKEN BINDING MISMATCH");
            return false;
        }

        return true;
    }
}
