<?php

declare(strict_types=1);

namespace inventor96\InertiaOffline;

use inventor96\InertiaOffline\Contracts\OfflineRouteListInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;

abstract class AbstractOfflineRouteList implements OfflineRouteListInterface {
    /**
     * Generates a list of routes to be cached offline, based on the routes
     * defined in the application and the presence of the `OfflineCacheable`
     * attribute on their actions.
     *
     * @return array{array{url: string, ttl: int}} An array of route entries,
     *     each containing a 'url' and 'ttl' key.
     */
    public function generateRoutes(): array {
        $output = [];

        foreach ($this->getRoutes() as $route) {
            // get the action for the route and build a reflection to inspect its attributes
            $action = $this->normalizeAction($this->getRouteAction($route));
            $reflection = $this->buildReflection($action);

            // skip if we can't build a reflection (e.g. unsupported action type)
            if ($reflection === null) {
                $this->logWarning('Skipping offline route because action is unsupported.');
                continue;
            }

            // check for the presence of the `OfflineCacheable` attribute
            $attributes = $reflection->getAttributes(OfflineCacheable::class);
            if (count($attributes) === 0) {
                continue;
            }

            // if the attribute is present, instantiate it and check access control
            $attribute = $attributes[0]->newInstance();
            if (!$this->hasAccess($attribute)) {
                continue;
            }

            // build route entries based on the route, attribute configuration,
            // and any parameter generation or pagination expansion
            $routeEntries = $this->buildEntriesForRoute($route, $attribute);
            foreach ($routeEntries as $entry) {
                $output[$entry['url'] . '|' . $entry['ttl']] = $entry;
            }
        }

        return array_values($output);
    }

    /**
     * Provides an iterable of routes to be processed for offline caching. The
     * specific structure of the route objects is left to the implementation,
     * but they should be sufficient to determine the route pattern and action
     * for each route.
     * 
     * @return iterable<mixed> An iterable of route objects.
     */
    abstract protected function getRoutes(): iterable;

    /**
     * Given a route object, return the URL pattern associated with that route.
     * The pattern may include placeholders for params (e.g. `/posts/{id}`).
     * 
     * @param mixed $route The route object from which to extract the pattern.
     * @return string The URL pattern for the route.
     */
    abstract protected function getRoutePattern(mixed $route): string;

    /**
     * Given a route object, returns the action associated with that route. The
     * action can be a callable, a string representing a function name, or a
     * string in the format `ClassName::methodName` or `ClassName@methodName`.
     * 
     * @param mixed $route The route object from which to extract the action.
     * @return mixed The action associated with the route.
     */
    abstract protected function getRouteAction(mixed $route): mixed;

    /**
     * Invokes the given action with the provided parameters.
     * 
     * @param mixed $action The action to invoke, which may be in various
     *     formats (e.g. callable, string, array).
     * @param array $parameters An associative array of parameters to pass to
     *     the action when invoking it.
     * @return mixed The result of invoking the action, which may vary
     *     depending on the specific implementation and the nature of the action itself.
     */
    abstract protected function invokeAction(mixed $action, array $parameters = []): mixed;

    /**
     * Expands pagination URLs for a given route and attribute.
     * 
     * @param string $baseUrl The base URL for the route (e.g. `/posts`).
     * @param mixed $pagination The pagination data returned by the
     *     pagination resolver.
     * @param mixed $route The original route object.
     * @param OfflineCacheable $attribute The attribute instance associated
     *     with the route.
     * @param array $routeParams The parameters for the route.
     * @return string[] An array of pagination URLs to be cached offline, which
     *     may include the base URL as well as additional URLs for paginated
     *     content (e.g. `/posts?page=2`).
     */
    abstract protected function expandPaginationUrls(
        string $baseUrl,
        mixed $pagination,
        mixed $route,
        OfflineCacheable $attribute,
        array $routeParams = [],
    ): array;

    /**
     * Logs a warning message. The specific logging mechanism is left to the
     * implementation, but this method can be used to provide feedback about
     * issues encountered during route generation (e.g. unsupported action
     * types, missing required parameters, etc.).
     * 
     * @param string $message The warning message to log.
     */
    protected function logWarning(string $message): void {}

    /**
     * Normalizes an action into a consistent format for processing. This
     * method handles various formats for defining actions (e.g. string, array)
     * and converts them into a format that can be easily reflected upon and
     * invoked.
     * 
     * @param mixed $action The action to normalize, which may be in various
     *     formats (e.g. callable, string, array).
     * @return string|array The normalized action, which may be an array
     *     representing a class method or a string representing a function
     *     name, depending on the input format.
     */
    protected function normalizeAction(mixed $action): string|array {
        if (is_string($action)) {
            if (str_contains($action, '::')) {
                return explode('::', $action, 2);
            }

            if (str_contains($action, '@')) {
                return explode('@', $action, 2);
            }
        }

        return $action;
    }

    /**
     * Builds a reflection instance for the given action, which can be used to
     * inspect attributes and other metadata about the action. This method
     * supports various formats for defining actions and returns null if the
     * action type is unsupported or if any errors occur during reflection.
     * 
     * @param mixed $action The action for which to build a reflection, which
     *     may be in various formats (e.g. callable, string, array).
     * @return ReflectionFunctionAbstract|null A reflection instance for the
     *     action, or null if the action type is unsupported or if an error
     *     occurs during reflection.
     */
    protected function buildReflection(mixed $action): ?ReflectionFunctionAbstract {
        try {
            return match (ActionTypeEnum::from($action)) {
                ActionTypeEnum::METHOD => new ReflectionMethod($action[0], $action[1]),
                ActionTypeEnum::FUNCTION => new ReflectionFunction($action),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Invokes the given action with the provided parameters and returns the
     * result. This method handles various formats for defining actions and
     * uses the `invokeAction` method to perform the actual invocation.
     * 
     * @param mixed $action The action to invoke, which may be in various
     *     formats (e.g. callable, string, array).
     * @param array $parameters An associative array of parameters to pass to
     *     the action when invoking it.
     * @return mixed The result of invoking the action, which may vary
     *     depending on the specific implementation and the nature of the action itself.
     */
    protected function callAction(mixed $action, array $parameters = []): mixed {
        $action = $this->normalizeAction($action);
        if (!ActionTypeEnum::isSupported($action)) {
            return null;
        }

        return $this->invokeAction($action, $parameters);
    }

    /**
     * Determines whether the given attribute grants access to cache the route
     * offline. This method checks for the presence of an `access_control`
     * configuration on the attribute and, if present, invokes the specified
     * access control action to determine whether access is granted.
     * 
     * @param OfflineCacheable $attribute The attribute instance associated with
     *     the route, which may contain access control configuration.
     * @return bool True if access is granted (i.e. the route should be cached
     *     offline), or false if access is denied.
     */
    protected function hasAccess(OfflineCacheable $attribute): bool {
        if (!isset($attribute->access_control)) {
            return true;
        }

        $hasAccess = $this->callAction($attribute->access_control);
        return (bool) $hasAccess;
    }

    /**
     * Builds route entries for a given route and attribute, including any
     * parameter generation and pagination expansion as configured on the
     * attribute.
     * 
     * @param mixed $route The route object for which to build entries.
     * @param OfflineCacheable $attribute The attribute instance associated
     *     with the route, which may contain configuration for parameter
     *     generation and pagination.
     * @return array<int, array{url: string, ttl: int}>
     */
    protected function buildEntriesForRoute(mixed $route, OfflineCacheable $attribute): array {
        $entries = [];

        // if a parameter generator is configured, use it to generate multiple
        // sets of parameters and build cacheable URL entries for each set
        if (isset($attribute->param_generator)) {
            $params = $this->callAction($attribute->param_generator);
            if (!is_iterable($params)) {
                $this->logWarning('Skipping route expansion because parameter generator did not return iterable data.');
                return [];
            }

            foreach ($params as $paramSet) {
                // skip non-array parameter sets
                if (!is_array($paramSet)) {
                    continue;
                }

                // build route entries for this set of parameters, including
                // pagination expansion if configured on the attribute
                $entrySet = $this->buildEntrySet($route, $attribute, $paramSet);
                foreach ($entrySet as $entry) {
                    // deduplicate entries
                    $entries[$entry['url'] . '|' . $entry['ttl']] = $entry;
                }
            }

            return array_values($entries);
        }

        // if no parameter generator is configured, just build a single route entry
        return $this->buildEntrySet($route, $attribute, []);
    }

    /**
     * Builds a set of route entries for a given route, attribute, and
     * parameter set. This method generates a URL for the route based on the
     * provided parameters and then creates a route entry with the URL and TTL
     * from the attribute. If a pagination resolver is configured on the
     * attribute, this method also expands pagination URLs and creates entries
     * for those as well.
     * 
     * @param mixed $route The route object for which to build entries.
     * @param OfflineCacheable $attribute The attribute instance associated
     *     with the route, which may contain configuration for pagination.
     * @param array $routeParams An associative array of parameters to use when
     *     building the route URL.
     * @return array{array{url: string, ttl: int}} An array of route entries
     *     generated for the given route, attribute, and parameter set, which
     *     may include entries for pagination URLs if a pagination resolver is
     *     configured.
     */
    protected function buildEntrySet(mixed $route, OfflineCacheable $attribute, array $routeParams): array {
        // build the URL for this route based on the route pattern and provided parameters
        $url = $this->buildRouteUrl($this->getRoutePattern($route), $routeParams);
        if ($url === null) {
            return [];
        }

        // start the entry set with a single entry for the base URL
        $entries = [$this->makeRouteEntry($url, $attribute->ttl)];

        // skip pagination expansion if no pagination resolver is configured
        if (!isset($attribute->pagination_resolver)) {
            return $entries;
        }

        // if a pagination resolver is configured, call it to get pagination data
        $pagination = $this->callAction($attribute->pagination_resolver, [
            'route_params' => $routeParams,
            'url' => $url,
            'route' => $route,
        ]);

        // if the pagination resolver does not return pagination data, just return the base entry
        if ($pagination === null) {
            return $entries;
        }

        // expand pagination URLs using the pagination data and build entries
        // for each pagination URL returned by the expander
        $urls = $this->expandPaginationUrls($url, $pagination, $route, $attribute, $routeParams);
        if (!is_iterable($urls)) {
            $this->logWarning('Skipping pagination expansion because expander did not return iterable URLs.');
            return $entries;
        }

        foreach ($urls as $paginationUrl) {
            // skip invalid pagination URLs and URLs that are the same as the base URL
            if (!is_string($paginationUrl) || $paginationUrl === '' || $paginationUrl === $url) {
                continue;
            }

            // build a route entry for this pagination URL and add it to the entry set
            $entries[] = $this->makeRouteEntry($paginationUrl, $attribute->ttl);
        }

        // deduplicate entries in case the pagination expander returned
        // duplicate URLs or URLs that are the same as the base URL
        $deduped = [];
        foreach ($entries as $entry) {
            $deduped[$entry['url'] . '|' . $entry['ttl']] = $entry;
        }

        return array_values($deduped);
    }

    /**
     * Builds a URL for a route based on the given pattern and parameters. This
     * method replaces placeholders in the pattern with corresponding values from
     * the parameter set and appends any extra parameters as query parameters.
     * If required placeholders are missing from the parameter set, this method
     * returns null to indicate that a valid URL cannot be generated.
     * 
     * @param string $pattern The URL pattern for the route, which may include
     *     placeholders (e.g. `/posts/{id}`).
     * @param array $paramSet An associative array of parameters to use when
     *     building the URL, where keys correspond to placeholder names in the
     *     pattern.
     * @return string|null The generated URL with placeholders replaced by
     *     parameter values and extra parameters appended as query parameters,
     *     or null if required placeholders are missing from the parameter set.
     */
    protected function buildRouteUrl(string $pattern, array $paramSet = []): ?string {
        $url = $pattern;
        $queryParams = [];

        // replace placeholders in the pattern with corresponding values from the parameter set
        foreach ($paramSet as $key => $value) {
            $url = str_replace('{' . $key . '}', (string) $value, $url, $count);
            if ($count === 0) {
                $queryParams[$key] = $value;
            }
        }

        // check for any remaining placeholders in the URL
        if (preg_match_all('/\{([^}]+)\}(\??)/', $url, $matches, PREG_SET_ORDER)) {
            $requiredPlaceholders = [];
            foreach ($matches as $match) {
                // remove optional placeholders
                if (($match[2] ?? '') === '?') {
                    $url = str_replace($match[0], '', $url);
                    continue;
                }

                // note required placeholder for later
                $requiredPlaceholders[] = $match[1];
            }

            // warn and skip if there are any remaining required placeholders
            if (count($requiredPlaceholders) > 0) {
                $this->logWarning('Skipping route because required placeholders are missing: ' . implode(', ', $requiredPlaceholders));
                return null;
            }
        }

        // skip query param processing if there are no params left
        if (count($queryParams) === 0) {
            return $url;
        }

        // append any extra parameters as query parameters
        return $url . '?' . http_build_query($queryParams);
    }

    /**
     * Creates a route entry array with the given URL and TTL. This method
     * provides a consistent format for route entries and can be used to
     * centralize any logic related to creating entries (e.g. adding prefixes,
     * handling special cases, etc.) in one place.
     *
     * @param string $url The URL for the route entry, which should be a valid
     *     URL pattern that can be cached offline (e.g. `/posts/1`,
     *     `/posts?page=2`, etc.).
     * @param integer $ttl The time-to-live (TTL) for the route entry in
     *     seconds, which indicates how long the route should be cached offline
     *     before it is considered stale and needs to be refreshed.
     * @return array{url: string, ttl: int} The route entry array containing
     *     the URL and TTL.
     */
    protected function makeRouteEntry(string $url, int $ttl): array {
        return ['url' => $url, 'ttl' => $ttl];
    }
}
