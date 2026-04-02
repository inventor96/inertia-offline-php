<?php

declare(strict_types=1);

namespace inventor96\InertiaOffline\Contracts;

use inventor96\InertiaOffline\OfflineCacheable;

interface PaginationUrlExpanderInterface {
    /**
     * @return string[]
     */
    public function expand(
        string $baseUrl,
        mixed $pagination,
        mixed $route,
        OfflineCacheable $attribute,
        array $routeParams = [],
    ): array;
}
