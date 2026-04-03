# inertia-offline (PHP)

Framework-agnostic backend primitives for discovering which Inertia routes should be available offline.

> Beta: offline read-only layer for Inertia.js apps, focused on safe cached content and navigation fallback.

This package is intentionally small: it provides the core algorithm and extension points, while each framework (Laravel, Mako, Symfony, Slim, etc.) supplies route inspection, action invocation, and pagination URL expansion.

This PHP backend package is designed to work with [`inertia-offline`](https://github.com/inventor96/inertia-offline-js) (the JS/TS frontend service worker library). Both the frontend and the backend aspects are required for an Inertia.js app.

## Why This Exists

Offline-first Inertia apps usually need a server endpoint that returns a list of URLs to precache, often with TTL metadata. The hard part is generating that list in a reliable and maintainable way.

`inventor96/inertia-offline` solves this by:

- Marking cacheable actions with a PHP attribute.
- Scanning routes and reflecting on their actions.
- Expanding dynamic routes via a parameter generator.
- Optionally expanding pagination URLs.
- Returning normalized entries: `{ url, ttl }`.

## ⚠️ This is (Probably) Not the Package You're Looking For

This package is not a drop-in solution for your framework. Instead, it's a core engine that you can build an adapter on top of for your specific framework. Below is a list of known adapters for popular frameworks.

- [`inventor96/inertia-offline-mako`](https://github.com/inventor96/inertia-offline-mako) (Mako framework)

Don't see yours? The core is designed to be adaptable to a wide range of frameworks with different routing and action paradigms. If you build an adapter for a framework, please consider opening a PR to add it to the list below!

## Core Concepts

### 1. `OfflineCacheable` attribute

The `#[OfflineCacheable(...)]` attribute is used in individual projects on controller methods or functions that should be considered for offline caching.

```php
<?php

use inventor96\InertiaOffline\OfflineCacheable;

final class PostController
{
    #[OfflineCacheable(ttl: 3600)]
    public function index(): mixed
    {
        // ...
    }
}
```

Attribute options:

- `ttl` (`int`, default `86400`): route TTL in seconds.
- `param_generator` (`callable|array|string|Closure|null`): returns iterable parameter sets for dynamic route expansion.
- `pagination_resolver` (`mixed|null`): value or action that indicates pagination metadata.
- `access_control` (`callable|array|string|Closure|null`): action that decides if route should be included for current context/user.

### 2. `AbstractOfflineRouteList`

This abstract class contains the full generation pipeline (`generateRoutes()`):

1. Iterate framework routes (`getRoutes()`).
2. Resolve each route action (`getRouteAction()`), normalize format, and build reflection.
3. Keep routes whose reflected action has `#[OfflineCacheable]`.
4. Evaluate `access_control` when configured.
5. Build URL entries:
   - Single URL from route pattern, or
   - Multiple URLs from `param_generator`.
6. Expand paginated URLs when `pagination_resolver` is configured.
7. Deduplicate and return `array{ array{url: string, ttl: int} }`.

### 3. Action normalization and supported action types

The core supports common action formats:

- Function/closure/callable.
- `Class::method` string.
- `Class@method` string.
- `[ClassName, 'method']` or `[$instance, 'method']`.

If reflection or invocation is not possible for an action, that route is skipped.

### 4. URL building strategy

Route URL creation is done from a route pattern and parameter set:

- Placeholder replacement: `/posts/{id}` + `['id' => 42]` => `/posts/42`
- Optional placeholders are removed when missing: `{slug?}`
- Extra params become query string values.
- If required placeholders are unresolved, the entry is skipped.

## Contracts

- `inventor96\InertiaOffline\Contracts\OfflineRouteListInterface`
  - `generateRoutes(): array`
- `inventor96\InertiaOffline\Contracts\PaginationUrlExpanderInterface`
  - `expand(string $baseUrl, mixed $pagination, mixed $route, OfflineCacheable $attribute, array $routeParams = []): array`

## Built-in Implementations

### `QueryPaginationUrlExpander`

A ready-to-use implementation of `PaginationUrlExpanderInterface` that generates paginated URLs by appending a configurable query parameter (e.g. `?page=2`) to the base URL.

```php
<?php

use inventor96\InertiaOffline\Pagination\QueryPaginationUrlExpander;

$expander = new QueryPaginationUrlExpander(pageKey: 'page');
```

It resolves page count from the value returned by `pagination_resolver` in three ways:

| Resolver return type | Resolution strategy |
|---|---|
| `int` | Used directly as the page count. |
| Object with `numberOfPages()` method | Calls `numberOfPages()` and casts to int. |
| Array with `'pages'` key | Reads `$pagination['pages']` and casts to int. |

Given a base URL and a resolved page count `n`, it produces URLs for pages 1 through `n`.

**Usage in an adapter**

This expander is intentionally framework-agnostic, so it can serve as the default in any adapter. It's recommended that adapters accept an optional `PaginationUrlExpanderInterface` argument and fall back to `QueryPaginationUrlExpander` when none is provided, allowing projects to inject their own expander:

```php
<?php

use inventor96\InertiaOffline\AbstractOfflineRouteList;
use inventor96\InertiaOffline\Contracts\PaginationUrlExpanderInterface;
use inventor96\InertiaOffline\OfflineCacheable;
use inventor96\InertiaOffline\Pagination\QueryPaginationUrlExpander;

final class OfflineRoutes extends AbstractOfflineRouteList
{
    public function __construct(
        // ...
        private readonly ?PaginationUrlExpanderInterface $paginationUrlExpander = null,
    ) {}

    protected function expandPaginationUrls(
        string $baseUrl,
        mixed $pagination,
        mixed $route,
        OfflineCacheable $attribute,
        array $routeParams = [],
    ): array {
        $expander = $this->paginationUrlExpander ?? new QueryPaginationUrlExpander();
        return $expander->expand($baseUrl, $pagination, $route, $attribute, $routeParams);
    }

    // ...
}
```

This pattern lets the adapter provide a sensible default while giving individual projects the flexibility to substitute a custom expander for pagination schemes that use path segments, cursor tokens, or other non-standard formats.

If your framework has a DI container, you can also consider making the expander a dependency of the adapter and letting projects bind their preferred implementation in the container.

## Building a Framework Adapter

To implement this package for your framework, create a class extending `AbstractOfflineRouteList` and implement the abstract methods.

```php
<?php

namespace Acme\InertiaOfflineYourFramework;

use inventor96\InertiaOffline\AbstractOfflineRouteList;
use inventor96\InertiaOffline\OfflineCacheable;

final class OfflineRoutes extends AbstractOfflineRouteList
{
    protected function getRoutes(): iterable
    {
        // Return the entire set of routes from your framework's router.
    }

    protected function getRoutePattern(mixed $route): string
    {
        // Return route pattern, e.g. /posts/{id}.
        // `$route` is a single item from the iterable returned by `getRoutes()`
    }

    protected function getRouteAction(mixed $route): mixed
    {
        // Return route action in any supported format.
        // `$route` is a single item from the iterable returned by `getRoutes()`
    }

    protected function invokeAction(mixed $action, array $parameters = []): mixed
    {
        // Use your framework or DI container/invoker to resolve dependencies
        // and call an action.
        // `$action` is the normalized action from `getRouteAction()`.
    }

    protected function expandPaginationUrls(
        string $baseUrl, // from route pattern + params, e.g. /posts/42
        mixed $pagination, // pagination metadata from `pagination_resolver` action
        mixed $route, // the original route item from `getRoutes()`
        OfflineCacheable $attribute, // the attribute instance from the reflected action
        array $routeParams = [], // the parameter set used to generate the base URL, e.g. ['id' => 42]
    ): array {
        // Convert pagination metadata into concrete URLs
        // e.g. /posts?page=1, /posts?page=2, ...
    }

    protected function logWarning(string $message): void
    {
        // Optional: send warnings to framework logger
    }
}
```

## Recommended Adapter Methodology

1. Route selection
   - Return all routes from your framework's router in `getRoutes()`.
   - Let the core filter down to cacheable routes via the presence of `#[OfflineCacheable]` in individual projects.

2. Stable action invocation
   - Ensure `invokeAction()` can call class methods, closures, and container-resolved callables.

3. Pagination normalization
   - Treat pagination resolver output as adapter-specific and convert to URLs via one expander implementation.
   - Feel free to use `QueryPaginationUrlExpander` as the default inside `expandPaginationUrls()` if it covers the common case for your framework.
   - Accept an optional `PaginationUrlExpanderInterface` in your adapter constructor so projects can supply a custom expander for non-standard pagination (path-segment pages, cursor tokens, etc.).

4. Observability
   - Implement `logWarning()` so unsupported actions or malformed generators are visible in logs.

5. Deterministic output
   - Keep route generation deterministic for the same user/context to improve cache behavior and debugging.

## Example: Dynamic Route + Pagination

```php
<?php

use inventor96\InertiaOffline\OfflineCacheable;

final class PostController
{
    #[OfflineCacheable(
        ttl: 1800,
        param_generator: [PostOfflineParams::class, 'recent'],
        pagination_resolver: [PostOfflinePagination::class, 'resolve'],
        access_control: [PostOfflineAccess::class, 'canUseOffline']
    )]
    public function show(int $id): mixed
    {
        // ...
    }
}
```

Possible flow:

- Route pattern: `/posts/{id}`
- Param generator returns: `[['id' => 10], ['id' => 11]]`
- Base URLs: `/posts/10`, `/posts/11`
- Pagination resolver for each base URL returns 3 pages
- Final entries include:
  - `/posts/10`
  - `/posts/10?page=1`
  - `/posts/10?page=2`
  - `/posts/10?page=3`
  - `/posts/11`
  - etc.

(all with configured TTL of 1800 seconds)

## Package Boundaries

This package does not:

- Register framework routes/controllers.
- Provide auth/session integration.
- Decide your endpoint payload format beyond route entry generation.

Those concerns belong in your framework adapter or host application.
