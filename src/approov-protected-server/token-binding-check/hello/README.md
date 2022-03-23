# Approov Token Binding Integration Example

This Approov integration example is from where the code example for the [Approov token binding check quickstart](/docs/APPROOV_TOKEN_BINDING_QUICKSTART.md) is extracted, and you can use it as a playground to better understand how simple and easy it is to implement [Approov](https://approov.io) in a PHP Laravel API server.

## TOC - Table of Contents

* [Why?](#why)
* [How it Works?](#how-it-works)
* [Requirements](#requirements)
* [Try the Approov Integration Example](#try-the-approov-integration-example)


## Why?

To lock down your API server to your mobile app. Please read the brief summary in the [README](/README.md#why) at the root of this repo or visit our [website](https://approov.io/product.html) for more details.

[TOC](#toc---table-of-contents)


## How it works?

The PHP Laravel API server is very simple and is defined in the project [src/approov-protected-server/token-binding-check/hello](/src/approov-protected-server/token-binding-check/hello). Take a look at the [Approov Middleware](/src/approov-protected-server/token-binding-check/hello/app/Http/Middleware/Approov.php) class, and search for the `verifyApproovToken()` and `verifyApproovTokenBinding()` functions to see the simple code for the checks.


For more background on Approov, see the overview in the [README](/README.md#how-it-works) at the root of this repo.

[TOC](#toc---table-of-contents)


## Requirements

To run this example you will need to have at least PHP `7.2.5` installed. If you don't have then please follow the official installation instructions from [here](https://www.php.net/manual/en/install.php) to download and install it.

[TOC](#toc---table-of-contents)


## Try the Approov Integration Example

First, you need to create the `.env` file. From the `src/approov-protected-server/token-binding-check/hello` folder execute:

```
cp .env.example .env
```

Second, you need to set the dummy secret in the `src/approov-protected-server/token-binding-check/hello/.env` file as explained [here](/README.md#the-dummy-secret).

Next, you need to install the dependencies. From the `src/approov-protected-server/token-binding-check/hello` folder execute:

```text
composer install
```

Now, you can run this example from the `src/approov-protected-server/token-binding-check/hello` folder with:

```text
php artisan serve --port 8002
```

> **NOTE:** If running the server inside a docker container add `--host 0.0.0.0.`, otherwise the Laravel server will not answer requests from outside the container, like the ones you may want to do from cURL or Postman to test the API.

Next, you can test that it works with:

```bash
curl -iX GET 'http://localhost:8002'
```

The response will be a `401` unauthorized request:

```text
HTTP/1.1 401 Unauthorized
Host: localhost:8002
Date: Wed, 23 Mar 2022 12:24:03 GMT
Connection: close
X-Powered-By: PHP/8.1.4
Cache-Control: no-cache, private
Date: Wed, 23 Mar 2022 12:24:03 GMT
Content-Type: application/json

{}
```

The reason you got a `401` is because no Approoov token isn't provided in the headers of the request.

Finally, you can test that the Approov integration example works as expected with this [Postman collection](/README.md#testing-with-postman) or with some cURL requests [examples](/README.md#testing-with-curl).


[TOC](#toc---table-of-contents)
