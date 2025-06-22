`# bintelx/doc/endpoint.md`
---
# Guide to Creating API Endpoint Files

**File Structure Example:** `custom/[app_name]/[module_name]/endpoint.php`

## Purpose

An `endpoint.php` file is responsible for defining all the API routes for a specific module. It uses `bX\Router::register()` to map an HTTP method and a URI pattern to a specific controller method (the callback). It acts purely as a declaration map, keeping routing definitions separate from business logic.

## Key Principles

* **RESTful Design:** Structure endpoints around resources (nouns), not actions (verbs). Use plural nouns (e.g., `/products`, `/orders`).
* **Correct HTTP Methods:** Use `GET` for retrieval, `POST` for creation, `PUT`/`PATCH` for updates, and `DELETE` for removal.
* **Clear Naming:** Route patterns should be intuitive. Controller methods should clearly describe their action (e.g., `createProduct`, `getAllOrders`).
* **Delegate Logic:** The callback in the route definition should always point to a method in a Controller or Handler class. **Do not** place business logic directly inside the `endpoint.php` file.

## `Router::register()` in Detail

The core of every endpoint file is a series of calls to this method:

```php
Router::register(
    ['HTTP_METHOD'],
    'uri-pattern-relative-to-module',
    [Controller::class, 'methodName'],
    ROUTER_SCOPE_REQUIRED
);
```

## Example `*.endpoint.php` Structure
```php
<?php // custom/orders/some.endpoint.php

use bX\Router;
use order\OrderHandler; // The controller class for this module

// --- Order Management Endpoints ---

// GET /api/order/search
Router::register(
    ['GET'],
    'search', // The path is relative to the 'order' module
    [OrderHandler::class, 'searchOrders'],
    ROUTER_SCOPE_READ
);

// GET /api/order/123
Router::register(
    ['GET'],
    '(?P<id>\d+)', // Captures a numeric ID
    [OrderHandler::class, 'getOrderById'],
    ROUTER_SCOPE_READ
);

// POST /api/order
Router::register(
    ['POST'],
    '', // An empty pattern matches the module root (e.g., /api/order)
    [OrderHandler::class, 'createOrder'],
    ROUTER_SCOPE_WRITE
);
```


## Caveats & Developer Notes

**Insight** : Decoupling Route Definition from Permission Logic: When you define a route with `Router::register()`, you are only specifying the minimum required scope (e.g., `ROUTER_SCOPE_READ`).

* The `Router` then performs a much more complex, dynamic check against the user's permission map (`$currentUserPermissions`).
* Your endpoint definition is blissfully unaware of the user's roles; it just declares its own security requirement. 
* Path Patterns are RELATIVE: The URI pattern you provide is relative to the module.


    Never include the API base path (e.g., `/api/`) in your `register()` call.The Router handles this automatically.

**- Correct** : `products/(?P<id>\d+)`

**- Incorrect**: `/api/products/(?P<id>\d+)`

**Payloads & Arguments** : Data from `POST`/`PUT` bodies (JSON or form-data) is automatically parsed by `bX\Args` and is typically available in your controller method via `\bX\Args::$OPT`. 
URL parameters captured by named regex groups (like `(?P<id>\d+)`) are passed as arguments to your controller method.