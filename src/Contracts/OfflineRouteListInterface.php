<?php

declare(strict_types=1);

namespace inventor96\InertiaOffline\Contracts;

interface OfflineRouteListInterface {
    public function generateRoutes(): array;
}
