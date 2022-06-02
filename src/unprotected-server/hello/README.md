# Unprotected Server Example

The unprotected example is the base reference to build the [Approov protected servers](/src/approov-protected-server/). This a very basic Hello World API server.


## TOC - Table of Contents

* [Why?](#why)
* [How it Works?](#how-it-works)
* [Requirements](#requirements)
* [Try It](#try-it)


## Why?

To be the starting building block for the [Approov protected servers](/src/approov-protected-server/), that will show you how to lock down your API server to your mobile app. Please read the brief summary in the [Approov Overview](/OVERVIEW.md#why) at the root of this repo or visit our [website](https://approov.io/product) for more details.

[TOC](#toc---table-of-contents)


## How it works?

The PHP Laravel API server is very simple and is defined in the project located at [src/unprotected-server/hello](/src/unprotected-server/hello).

The server only replies to the endpoint `/` with the message:

```json
{"message": "Hello, World!"}
```

[TOC](#toc---table-of-contents)


## Requirements

To run this example you will need to have at least PHP `7.2.5` installed. If you don't have then please follow the official installation instructions from [here](https://www.php.net/manual/en/install.php) to download and install it.

[TOC](#toc---table-of-contents)


## Try It

First, you need to create the `.env` file. From the `src/unprotected-server/hello` folder execute:

```
cp .env.example .env
```

Next, you need to install the dependencies. From the `src/unprotected-server/hello` folder execute:

```text
composer install
```

Now, you can run this example from the `src/unprotected-server/hello` folder with:

```text
php artisan serve --port 8002
```

> **NOTE:** If running the server inside a docker container add `--host 0.0.0.0.`, otherwise the Laravel server will not answer requests from outside the container, like the ones you may want to do from cURL or Postman to test the API.

Finally, you can test that it works with:

```text
curl -iX GET 'http://localhost:8002'
```

The response will be:

```text
HTTP/1.1 200 OK
Host: localhost:8002
Date: Thu, 01 Oct 2020 13:53:58 GMT
Connection: close
X-Powered-By: PHP/7.2.18
Cache-Control: no-cache, private
Date: Thu, 01 Oct 2020 13:53:58 GMT
Content-Type: application/json

{"message":"Hello, World!"}
```

[TOC](#toc---table-of-contents)


## Issues

If you find any issue while following our instructions then just report it [here](https://github.com/approov/quickstart-php-laravel-token-check/issues), with the steps to reproduce it, and we will sort it out and/or guide you to the correct path.

[TOC](#toc---table-of-contents)


## Useful Links

If you wish to explore the Approov solution in more depth, then why not try one of the following links as a jumping off point:

* [Approov Free Trial](https://approov.io/signup)(no credit card needed)
* [Approov Get Started](https://approov.io/product/demo)
* [Approov QuickStarts](https://approov.io/docs/latest/approov-integration-examples/)
* [Approov Docs](https://approov.io/docs)
* [Approov Blog](https://approov.io/blog/)
* [Approov Resources](https://approov.io/resource/)
* [Approov Customer Stories](https://approov.io/customer)
* [Approov Support](https://approov.io/contact)
* [About Us](https://approov.io/company)
* [Contact Us](https://approov.io/contact)


[TOC](#toc---table-of-contents)
