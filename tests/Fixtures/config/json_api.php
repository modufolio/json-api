<?php

/**
 * JSON:API configuration for tests
 */

use Modufolio\JsonApi\JsonApiConfigurator;

return function (JsonApiConfigurator $api) {
    // Configure Account entity
    $api->resource('Modufolio\JsonApi\Tests\Fixtures\Entity\Account')
        ->key('account')
        ->fields(['id', 'name', 'createdAt', 'updatedAt'])
        ->relationships(['organizations', 'contacts'])
        ->operations([
            'index' => true,
            'show' => true,
            'create' => true,
            'update' => true,
            'delete' => true
        ]);

    // Configure Contact entity
    $api->resource('Modufolio\JsonApi\Tests\Fixtures\Entity\Contact')
        ->key('contact')
        ->fields([
            'id',
            'firstName',
            'lastName',
            'email',
            'phone',
            'address',
            'city',
            'region',
            'country',
            'postalCode',
            'createdAt',
            'updatedAt',
            'deletedAt'
        ])
        ->relationships(['account', 'organization'])
        ->operations([
            'index' => true,
            'show' => true,
            'create' => true,
            'update' => true,
            'delete' => true
        ]);

    // Configure Organization entity
    $api->resource('Modufolio\JsonApi\Tests\Fixtures\Entity\Organization')
        ->key('organization')
        ->fields([
            'id',
            'name',
            'email',
            'city',
            'createdAt',
            'updatedAt'
        ])
        ->relationships(['account', 'contacts'])
        ->operations([
            'index' => true,
            'show' => true,
            'create' => true,
            'update' => true,
            'delete' => true
        ]);
};
