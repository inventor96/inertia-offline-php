<?php

declare(strict_types=1);

namespace inventor96\InertiaOffline;

use inventor96\InertiaOffline\Contracts\PaginationUrlExpanderInterface;

class QueryPaginationUrlExpander implements PaginationUrlExpanderInterface {
    public function __construct(private readonly string $pageKey = 'page') {}

    public function expand(
        string $baseUrl,
        mixed $pagination,
        mixed $route,
        OfflineCacheable $attribute,
        array $routeParams = [],
    ): array {
        // get number of pages
        $pageCount = $this->resolvePageCount($pagination);
        if ($pageCount === null) {
            return [];
        }

        // ensure at least 1 page
        $pageCount = max($pageCount, 1);
        $urls = [];

        // generate URLs for each page number
        for ($page = 1; $page <= $pageCount; $page++) {
            $urls[] = $this->appendQueryParam($baseUrl, $this->pageKey, (string) $page);
        }

        return $urls;
    }

    /**
     * Resolve the total number of pages from the pagination object.
     *
     * @param mixed $pagination An integer, an object with a numberOfPages()
     *     method, or an array with a 'pages' key.
     * @return int|null
     */
    private function resolvePageCount(mixed $pagination): ?int {
        // plain integer
        if (is_int($pagination)) {
            return $pagination;
        }

        // object with numberOfPages() method
        if (is_object($pagination) && method_exists($pagination, 'numberOfPages')) {
            return (int) $pagination->numberOfPages();
        }

        // array with 'pages' key
        if (is_array($pagination) && isset($pagination['pages']) && is_numeric($pagination['pages'])) {
            return (int) $pagination['pages'];
        }

        // who knows
        return null;
    }

    /**
     * Append a query parameter to the URL, correctly handling existing query parameters.
     *
     * @param string $url
     * @param string $key
     * @param string $value
     * @return string
     */
    private function appendQueryParam(string $url, string $key, string $value): string {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query([$key => $value]);
    }
}
