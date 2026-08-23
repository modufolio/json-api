<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\JsonApiConfigurator;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Organization;
use PHPUnit\Framework\TestCase;

/**
 * Roles restrict who may reach a resource's generated routes.
 *
 * The configurator only carries the declaration; the route loader turns it into
 * the `_is_granted_roles` route default that the host framework enforces. These
 * tests therefore assert the shape reaching `buildConfig()`.
 */
class JsonApiConfiguratorRolesTest extends TestCase
{
    public function testEntitiesHaveNoRolesByDefault(): void
    {
        $api = new JsonApiConfigurator();
        $api->entities([Contact::class]);

        $this->assertSame([], $api->buildConfig()[Contact::class]['roles']);
    }

    public function testRolesAreDeclaredPerEntity(): void
    {
        $api = new JsonApiConfigurator();
        $api->entities([Contact::class, Organization::class]);
        $api->roles(Contact::class, ['ROLE_ADMIN']);

        $config = $api->buildConfig();

        $this->assertSame(['ROLE_ADMIN'], $config[Contact::class]['roles']);
        $this->assertSame([], $config[Organization::class]['roles'], 'Roles must not leak between entities.');
    }

    public function testSeveralRolesAreKeptAsAlternatives(): void
    {
        $api = new JsonApiConfigurator();
        $api->entities([Contact::class]);
        $api->roles(Contact::class, ['ROLE_ADMIN', 'ROLE_EDITOR']);

        // Both survive; the loader decides they are alternatives (one OR-group).
        $this->assertSame(['ROLE_ADMIN', 'ROLE_EDITOR'], $api->buildConfig()[Contact::class]['roles']);
    }

    public function testEmptyRoleNamesAreDropped(): void
    {
        $api = new JsonApiConfigurator();
        $api->entities([Contact::class]);
        $api->roles(Contact::class, ['ROLE_ADMIN', '']);

        // An empty string would otherwise become a role nobody can hold, and
        // would silently lock the resource for everyone.
        $this->assertSame(['ROLE_ADMIN'], $api->buildConfig()[Contact::class]['roles']);
    }

    public function testRolesDeclaredAfterBuildAreStillPickedUp(): void
    {
        $api = new JsonApiConfigurator();
        $api->entities([Contact::class]);

        $api->buildConfig(); // warms the internal cache
        $api->roles(Contact::class, ['ROLE_ADMIN']);

        $this->assertSame(
            ['ROLE_ADMIN'],
            $api->buildConfig()[Contact::class]['roles'],
            'roles() must invalidate the cached config, like entities() does.'
        );
    }

    public function testFluentResourceBuilderCarriesRoles(): void
    {
        $api = new JsonApiConfigurator();
        $api->resource(Contact::class)
            ->key('contact')
            ->fields(['id', 'firstName'])
            ->operations(['index' => true])
            ->roles(['ROLE_ADMIN']);

        $config = $api->buildConfig()[Contact::class];

        $this->assertSame(['ROLE_ADMIN'], $config['roles']);
        // The other fluent settings must survive the extra save().
        $this->assertSame('contact', $config['resource_key']);
        $this->assertSame(['index' => true], $config['operations']);
    }

    public function testGetRolesExposesEveryDeclaration(): void
    {
        $api = new JsonApiConfigurator();
        $api->roles(Contact::class, ['ROLE_ADMIN']);
        $api->roles(Organization::class, ['ROLE_EDITOR']);

        $this->assertSame(
            [Contact::class => ['ROLE_ADMIN'], Organization::class => ['ROLE_EDITOR']],
            $api->getRoles()
        );
    }
}
