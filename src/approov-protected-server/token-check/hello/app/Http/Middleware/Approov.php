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
                // \Log::debug("MISSIG APPROOV SECRET");
                return null;
            }

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
}
