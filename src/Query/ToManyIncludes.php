<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

/**
 * What {@see ToManyIncludeLoader} found for a set of parent rows.
 *
 * `linkage` covers every to-many the resource exposes, so relationship
 * identifiers appear in every response regardless of `?include`. `included`
 * holds full resource objects only for the relationships that were asked for.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class ToManyIncludes
{
    /**
     * @param array<string, array<array-key, list<array{type: string, id: string}>>> $linkage  relName => parentId => identifiers
     * @param array<string, array<array-key, list<array<string, mixed>>>>            $included relName => parentId => resource objects
     */
    public function __construct(
        private readonly array $linkage,
        private readonly array $included,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * Add each item's to-many linkage under its `relationships`, keyed by the
     * item's own id. An item with no related rows gets an empty list, which
     * is how JSON:API says "none" for a to-many.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public function attachTo(array $items): array
    {
        foreach ($items as &$item) {
            foreach ($this->linkage as $relName => $byParent) {
                $item['relationships'][$relName] = ['data' => $byParent[$item['id']] ?? []];
            }
        }
        unset($item);

        return $items;
    }

    /**
     * The included resource objects, one per type:id pair.
     *
     * A compound document must not carry two resource objects for the same
     * pair, and two included relationships can legitimately resolve to the
     * same record.
     *
     * @return list<array<string, mixed>>
     */
    public function resources(): array
    {
        $byIdentifier = [];

        foreach ($this->included as $byParent) {
            foreach ($byParent as $resources) {
                foreach ($resources as $resource) {
                    $byIdentifier[$resource['type'] . ':' . $resource['id']] = $resource;
                }
            }
        }

        return array_values($byIdentifier);
    }
}
