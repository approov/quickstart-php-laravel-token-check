# Approov QuickStart - PHP Laravel Token Check

[Approov](https://approov.io) is an API security solution used to verify that requests received by your backend services originate from trusted versions of your mobile apps.

This repo implements the Approov server-side request verification code in PHP, which performs the verification check before allowing valid traffic to be processed by the API endpoint.

This is an Approov integration quickstart example for the PHP Laravel framework. If you are looking for another PHP integration you can check our list of [quickstarts](https://approov.io/docs/latest/approov-integration-examples/backend-api/), and if you don't find what you are looking for, then please let us know [here](https://approov.io/contact). Meanwhile, you can always use the framework agnostic [quickstart example](https://github.com/approov/quickstart-php-token-check) for PHP, and you may find that's easily adaptable to your framework of choice.


## Approov Integration Quickstart

The quickstart was tested with the following Operating Systems:

* Ubuntu 20.04
* MacOS Big Sur
* Windows 10 WSL2 - Ubuntu 20.04

First, setup the [Appoov CLI](https://approov.io/docs/latest/approov-installation/index.html#initializing-the-approov-cli).

Now, register the API domain for which Approov will issues tokens:

```bash
approov api -add api.example.com
```

Next, enable your Approov `admin` role with:

```bash
eval `approov role admin`
```

Now, get your Approov Secret with the [Appoov CLI](https://approov.io/docs/latest/approov-installation/index.html#initializing-the-approov-cli):

```bash
approov secret -get base64
```

> **@IMPORTANT:**
> Don't set an Approov key id for the secret, because the JWT library doesn't support to pass the symmetric key for the Approov secret in a JWKs.

Next, add the [Approov secret](https://approov.io/docs/latest/approov-usage-documentation/#account-secret-key-export) to your project `.env` file:

```env
APPROOV_BASE64_SECRET=approov_base64_secret_here
```

Now, let your Laravel app load it into the config, by creating a configuration file for Approov at `config/approov.php`:

```php
<?php

return [
    'secret' => base64_decode(env('APPROOV_BASE64_SECRET'), true),
];
```

Next, add to your project the [firebase/php-jwt](https://github.com/firebase/php-jwt) package to check the JWT token:

```text
composer require firebase/php-jwt
```

Now, add the [Approov Middleware](/src/approov-protected-server/token-check/hello/app/Http/Middleware/Approov.php) class to your project at `app/Http/Middleware/Approov.php`:

```php
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
                //\Log::debug("MISSIG APPROOV SECRET");
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
}
```

Next, add the [Approov Middleware](/src/approov-protected-server/token-check/hello/app/Http/Middleware/Approov.php) to your Laravel application route middleware array at [app/Http/Kernel.php](/src/approov-protected-server/token-check/hello/app/Http/Kernel.php):

```php
protected $routeMiddleware = [
    'approov' => \App\Http\Middleware\Approov::class,
    'auth' => \App\Http\Middleware\Authenticate::class,
    // omitted lines for brevity
];
```

In the same file, you need to activate the Approov Middleware by including it as the first one in the array for the `api` route middleware group:

```php
protected $middlewareGroups = [
    'web' => [
        // omitted lines for brevity
    ],

    'api' => [
        'approov',
        'throttle:60,1',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ],
];
```

> **NOTE:** The Approov middleware is included as the first one in the array because you don't want to waste your server resources in processing requests that don't have a valid Approov token. This approach will help your server to handle more load under a Denial of Service(DoS) attack.

Adding the Approov Middleware class to the `api` middleware group means that any incoming request to an API route needs to have a valid Approov token to be further processed. So, no need to explicitly add Approov as a middleware into any route in the file `routes/api.php`.

You can skip the Approov Middleware execution for any given route by using:

```php
Route::get('/some-route', function () {
    // your code here
})->withoutMiddleware(['approov']);
```

Not enough details in the bare bones quickstart? No worries, check the [detailed quickstarts](QUICKSTARTS.md) that contain a more comprehensive set of instructions, including how to test the Approov integration.


## More Information

* [Approov Overview](OVERVIEW.md)
* [Detailed Quickstarts](QUICKSTARTS.md)
* [Examples](EXAMPLES.md)
* [Testing](TESTING.md)


## Issues

If you find any issue while following our instructions then just report it [here](https://github.com/approov/quickstart-php-laravel-token-check/issues), with the steps to reproduce it, and we will sort it out and/or guide you to the correct path.


## Useful Links

If you wish to explore the Approov solution in more depth, then why not try one of the following links as a jumping off point:

* [Approov Free Trial](https://approov.io/signup)(no credit card needed)
* [Approov Get Started](https://approov.io/product/demo)
* [Approov QuickStarts](https://approov.io/docs/latest/approov-integration-examples/)
* [Approov Docs](https://approov.io/docs)
* [Approov Blog](https://approov.io/blog/)
* [Approov Resources](https://approov.io/resource/)
* [Approov Customer Stories](https://approov.io/customer)
* [Approov Support](https://approov.zendesk.com/hc/en-gb/requests/new)
* [About Us](https://approov.io/company)
* [Contact Us](https://approov.io/contact)
