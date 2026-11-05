<?php

declare(strict_types=1);

namespace Whistleblower\Domain;

final readonly class Page
{
    /** @param list<Report> $items */
    public function __construct(public array $items, public int $total, public int $page, public int $perPage) {}
}
