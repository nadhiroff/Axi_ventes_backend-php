<?php
declare(strict_types=1);

namespace Axi\Core;

/** Pagination : ?page=1&per_page=20 */
final class Page
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public static function from(Request $request, int $default = 20, int $max = 50): self
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min($max, max(1, (int) $request->query('per_page', $default)));
        return new self($page, $perPage);
    }

    /** Fragment SQL sûr : les valeurs sont des entiers */
    public function limitSql(): string
    {
        return ' LIMIT ' . $this->perPage . ' OFFSET ' . (($this->page - 1) * $this->perPage);
    }

    public function meta(int $total, array $extra = []): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $total] + $extra;
    }
}
