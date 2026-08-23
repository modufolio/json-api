<?php

return [
    'parameters' => [
        'level' => 5,
        'paths' => [__DIR__.'/src', __DIR__.'/tests'],
        'excludePaths' => [
            'analyseAndScan' => [
                __DIR__.'/vendor/*',
            ],
            // Test fixtures are sample entities/controllers/config, not library
            // code — still scanned so tests referencing them resolve, but their
            // Doctrine-reflection-written ids and sample controllers are not
            // analysed.
            'analyse' => [
                __DIR__.'/tests/Fixtures/*',
            ],
        ],
        'ignoreErrors' => [
            // Redundant PHPUnit type assertions (assertIsArray/assertIsString on
            // a value PHPStan already knows the type of). Test-readability
            // noise, never a bug.
            [
                'identifier' => 'method.alreadyNarrowedType',
                'path' => __DIR__.'/tests/*',
                'reportUnmatched' => false,
            ],
            // The QueryBuilder test mocks Doctrine metadata by assigning plain
            // arrays to ClassMetadata::$fieldMappings/$associationMappings. In
            // ORM 3 those are typed as FieldMapping/AssociationMapping objects,
            // but they implement ArrayAccess, so the code under test reads them
            // as arrays and the mock works. Typing them properly would mean
            // building real mapping objects for a test that only needs the
            // array shape.
            [
                'identifier' => 'assign.propertyType',
                'path' => __DIR__.'/tests/JsonApiQueryBuilderTest.php',
                'reportUnmatched' => false,
            ],
        ],
    ],
];
