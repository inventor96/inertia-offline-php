<?php

declare(strict_types=1);

namespace inventor96\InertiaOffline;

use Attribute;
use Closure;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class OfflineCacheable {
    public mixed $param_generator;
    public mixed $pagination_resolver;
    public mixed $access_control;

    public function __construct(
        public int $ttl = 86400,
        callable|array|string|Closure|null $param_generator = null,
        mixed $pagination_resolver = null,
        callable|array|string|Closure|null $access_control = null,
    ) {
        if ($param_generator !== null) {
            $this->param_generator = $param_generator;
        }

        if ($pagination_resolver !== null) {
            $this->pagination_resolver = $pagination_resolver;
        }

        if ($access_control !== null) {
            $this->access_control = $access_control;
        }
    }
}
