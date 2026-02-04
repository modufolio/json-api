<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Pagination;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Pagination Handler for JSON:API
 *
 * Handles pagination logic and total count calculation
 */
class JsonApiPaginator
{
    private int $defaultPageSize = 25;
    private int $maxPageSize = 100;

    /**
     * Apply pagination to a query builder
     *
     * @param QueryBuilder $qb
     * @param int $page Page number (1-indexed)
     * @param int $size Items per page
     * @return QueryBuilder
     */
    public function paginate(
        QueryBuilder $qb,
        int $page = 1,
        int $size = 25
    ): QueryBuilder {
        // Ensure valid page number
        $page = max(1, $page);

        // Limit page size
        $size = min($this->maxPageSize, max(1, $size));

        $offset = ($page - 1) * $size;

        return $qb
            ->setFirstResult($offset)
            ->setMaxResults($size);
    }

    /**
     * Get total count for a query
     *
     * @param QueryBuilder $qb
     * @return int
     */
    public function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT t0.id) AS total')
            ->setFirstResult(0)
            ->setMaxResults(null);

        $result = $countQb->executeQuery()->fetchOne();
        return $result !== false ? (int)$result : 0;
    }

    /**
     * Calculate pagination metadata
     *
     * @param int $total Total number of items
     * @param int $page Current page
     * @param int $size Items per page
     * @return array
     */
    public function getMetadata(int $total, int $page, int $size): array
    {
        $lastPage = (int)ceil($total / $size);
        $from = $total > 0 ? (($page - 1) * $size) + 1 : 0;
        $to = min($page * $size, $total);

        return [
            'total' => $total,
            'per_page' => $size,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Get page information
     *
     * @param int $total
     * @param int $page
     * @param int $size
     * @return array
     */
    public function getPageInfo(int $total, int $page, int $size): array
    {
        $lastPage = (int)ceil($total / $size);

        return [
            'has_prev' => $page > 1,
            'has_next' => $page < $lastPage,
            'first_page' => 1,
            'last_page' => $lastPage,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $lastPage ? $page + 1 : null,
        ];
    }

    /**
     * Set default page size
     *
     * @param int $size
     * @return self
     */
    public function setDefaultPageSize(int $size): self
    {
        $this->defaultPageSize = max(1, $size);
        return $this;
    }

    /**
     * Set maximum page size
     *
     * @param int $size
     * @return self
     */
    public function setMaxPageSize(int $size): self
    {
        $this->maxPageSize = max(1, $size);
        return $this;
    }

    /**
     * Get default page size
     *
     * @return int
     */
    public function getDefaultPageSize(): int
    {
        return $this->defaultPageSize;
    }

    /**
     * Get maximum page size
     *
     * @return int
     */
    public function getMaxPageSize(): int
    {
        return $this->maxPageSize;
    }
}
