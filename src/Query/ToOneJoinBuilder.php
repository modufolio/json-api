<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use Modufolio\JsonApi\Exception\InclusionUnrecognized;

/**
 * LEFT JOINs for the to-one segments of `?include=` paths.
 *
 * A to-one can be joined without multiplying rows, so its columns ride along
 * on the main SELECT as `<segment>_<field>`. A path stops at its first
 * to-many segment: joining one would repeat root rows and make LIMIT slice
 * joined rows rather than records, so those are fetched afterwards by
 * {@see ToManyIncludeLoader}.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class ToOneJoinBuilder
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly ResourceSchema $root,
    ) {
    }

    /**
     * @param list<string>                $includes
     * @param array<string, list<string>> $sparseFields
     *
     * @return list<array{alias: string, table: string, joinAlias: string, condition: string, select: list<string>}>
     */
    public function build(array $includes, array $sparseFields, string $rootAlias): array
    {
        $joins = [];
        $aliasCounter = 1;
        $usedAliases = [$rootAlias];

        foreach ($includes as $path) {
            $currentAlias = $rootAlias;
            $currentMeta = $this->root->metadata();

            foreach (explode('.', $path) as $segment) {
                if (!$currentMeta->hasAssociation($segment)) {
                    throw new InclusionUnrecognized($path, "Unknown include: $segment in path $path");
                }

                $mapping = $currentMeta->getAssociationMapping($segment);

                if (!($mapping['type'] & ClassMetadata::TO_ONE)) {
                    break;
                }

                while (in_array('t' . $aliasCounter, $usedAliases)) {
                    $aliasCounter++;
                }
                $joinAlias = 't' . $aliasCounter;
                $usedAliases[] = $joinAlias;

                $target = $this->schemas->of($mapping['targetEntity']);
                $targetMeta = $target->metadata();
                $fields = $target->selectableFields($sparseFields);

                if ($fields === []) {
                    throw new InvalidArgumentException("No fields defined for target entity {$target->class()} in path $path");
                }

                // Every allowed field, not just the first: a caller needing two
                // columns off a joined record (a person's first and last name)
                // otherwise had no way to ask for the second.
                $select = [];
                foreach ($fields as $field) {
                    $select[] = "$joinAlias.{$target->columnName($field)} AS {$segment}_$field";
                }

                // ManyToOne or OneToOne — to-many never reaches here.
                $joinColumn = $mapping['joinColumns'][0]['name'] ?? 'id';
                $referencedColumn = $mapping['joinColumns'][0]['referencedColumnName'] ?? 'id';

                $joins[] = [
                    'alias' => $currentAlias,
                    'table' => $target->tableName(),
                    'joinAlias' => $joinAlias,
                    'condition' => "$currentAlias.$joinColumn = $joinAlias.$referencedColumn",
                    'select' => $select,
                ];

                $currentAlias = $joinAlias;
                $currentMeta = $targetMeta;
                $aliasCounter++;
            }
        }

        return $joins;
    }
}
