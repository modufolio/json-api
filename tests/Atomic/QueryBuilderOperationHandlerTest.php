<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Atomic;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\Atomic\OperationProcessor;
use Modufolio\JsonApi\Atomic\OperationsDocument;
use Modufolio\JsonApi\Atomic\QueryBuilderOperationHandler;
use Modufolio\JsonApi\Atomic\ResultsDocument;
use Modufolio\JsonApi\Exception\LidUnresolved;
use Modufolio\JsonApi\Exception\OperationFailed;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Organization;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * A whole atomic request through the query builder: create an organization
 * under a lid, create a contact pointing at it, update, remove — one
 * transaction, one results document.
 */
class QueryBuilderOperationHandlerTest extends TestCase
{
    private EntityManager $em;
    /** @var array<string, mixed> */
    private array $config;
    private Account $account;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $this->config = [
            Contact::class => [
                'resource_key' => 'contacts',
                'fields' => ['id', 'firstName', 'lastName', 'email'],
                'relationships' => ['account', 'organization'],
                'operations' => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
            ],
            Organization::class => [
                'resource_key' => 'organizations',
                'fields' => ['id', 'name', 'email'],
                'relationships' => ['account'],
                'operations' => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
            ],
        ];

        $this->account = new Account();
        $this->account->setName('Acme');
        $this->em->persist($this->account);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     */
    private function execute(array $operations): ResultsDocument
    {
        $handler = new QueryBuilderOperationHandler(
            $this->config,
            fn (string $entityClass) => (new JsonApiQueryBuilder(
                $this->config,
                $this->em,
                $this->em->getConnection(),
                $entityClass,
            ))->scope(['account' => $this->account->getId()]),
        );

        return (new OperationProcessor($handler, $this->em->getConnection()))
            ->process(OperationsDocument::parse(['atomic:operations' => $operations]));
    }

    public function testCreateLinkThroughLidUpdateAndRemove(): void
    {
        $document = $this->execute([
            [
                'op' => 'add',
                'data' => [
                    'type' => 'organizations',
                    'lid' => 'new-org',
                    'attributes' => ['name' => 'Initech', 'email' => 'hq@initech.test'],
                ],
            ],
            [
                'op' => 'add',
                'data' => [
                    'type' => 'contacts',
                    'lid' => 'new-contact',
                    'attributes' => ['first_name' => 'Peter', 'last_name' => 'Gibbons', 'email' => 'peter@initech.test'],
                    'relationships' => [
                        'organization' => ['data' => ['type' => 'organizations', 'lid' => 'new-org']],
                    ],
                ],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'contacts', 'lid' => 'new-contact'],
                'data' => ['type' => 'contacts', 'lid' => 'new-contact', 'attributes' => ['first_name' => 'Pete']],
            ],
            [
                'op' => 'remove',
                'ref' => ['type' => 'contacts', 'lid' => 'new-contact'],
            ],
        ]);

        $results = json_decode((string) json_encode($document), true)['atomic:results'];
        $this->assertCount(4, $results);

        $orgId = $results[0]['data']['id'];
        $this->assertSame('organizations', $results[0]['data']['type']);
        $this->assertSame('Initech', $results[0]['data']['attributes']['name']);

        $this->assertSame('contacts', $results[1]['data']['type']);
        $this->assertSame($orgId, $results[1]['data']['relationships']['organization']['data']['id']);
        $this->assertSame((string) $this->account->getId(), $results[1]['data']['relationships']['account']['data']['id']);

        $this->assertSame('Pete', $results[2]['data']['attributes']['first_name']);
        $this->assertSame([], $results[3]);

        $conn = $this->em->getConnection();
        $this->assertSame('Initech', $conn->fetchOne('SELECT name FROM organizations'));
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM contacts'));
    }

    public function testToOneRelationshipUpdateAndClear(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $org->setAccount($this->account);
        $contact = new Contact();
        $contact->setFirstName('A');
        $contact->setLastName('B');
        $contact->setEmail('a@b.test');
        $contact->setAccount($this->account);
        $this->em->persist($org);
        $this->em->persist($contact);
        $this->em->flush();

        $document = $this->execute([
            [
                'op' => 'update',
                'ref' => ['type' => 'contacts', 'id' => (string) $contact->getId(), 'relationship' => 'organization'],
                'data' => ['type' => 'organizations', 'id' => (string) $org->getId()],
            ],
        ]);
        $this->assertTrue($document->isEmpty());
        $this->assertSame(
            $org->getId(),
            (int) $this->em->getConnection()->fetchOne('SELECT organization_id FROM contacts WHERE id = ?', [$contact->getId()]),
        );

        $this->execute([
            [
                'op' => 'update',
                'ref' => ['type' => 'contacts', 'id' => (string) $contact->getId(), 'relationship' => 'organization'],
                'data' => null,
            ],
        ]);
        $this->assertNull($this->em->getConnection()->fetchOne('SELECT organization_id FROM contacts WHERE id = ?', [$contact->getId()]));
    }

    public function testFailureRollsBackTheWholeRequest(): void
    {
        try {
            $this->execute([
                ['op' => 'add', 'data' => ['type' => 'organizations', 'attributes' => ['name' => 'Kept?']]],
                ['op' => 'remove', 'ref' => ['type' => 'organizations', 'id' => '99999']],
            ]);
            $this->fail('Expected OperationFailed');
        } catch (OperationFailed $e) {
            $this->assertSame(404, $e->getStatus());
            $this->assertSame(['pointer' => '/atomic:operations/1'], $e->getSource());
        }

        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM organizations'));
    }

    public function testUnknownTypeIsA403AtTheType(): void
    {
        try {
            $this->execute([['op' => 'add', 'data' => ['type' => 'planets']]]);
            $this->fail('Expected OperationFailed');
        } catch (OperationFailed $e) {
            $this->assertSame(403, $e->getStatus());
            $this->assertSame('ATOMIC_OPERATION_UNSUPPORTED', $e->getErrorCode());
            $this->assertSame(['pointer' => '/atomic:operations/0/data/type'], $e->getSource());
        }
    }

    public function testTypeMismatchBetweenRefAndDataIsA409(): void
    {
        try {
            $this->execute([[
                'op' => 'update',
                'ref' => ['type' => 'contacts', 'id' => '1'],
                'data' => ['type' => 'organizations', 'id' => '1'],
            ]]);
            $this->fail('Expected OperationFailed');
        } catch (OperationFailed $e) {
            $this->assertSame(409, $e->getStatus());
            $this->assertSame(['pointer' => '/atomic:operations/0/data/type'], $e->getSource());
        }
    }

    /**
     * Refused by the pre-flight check, before the transaction and before the
     * builder runs anything — nothing is written.
     */
    public function testUnresolvedLidIsA400AtTheIdentifier(): void
    {
        try {
            $this->execute([
                ['op' => 'add', 'data' => ['type' => 'organizations', 'attributes' => ['name' => 'Never']]],
                [
                    'op' => 'add',
                    'data' => [
                        'type' => 'contacts',
                        'attributes' => ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.test'],
                        'relationships' => ['organization' => ['data' => ['type' => 'organizations', 'lid' => 'ghost']]],
                    ],
                ],
            ]);
            $this->fail('Expected LidUnresolved');
        } catch (LidUnresolved $e) {
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('LID_UNRESOLVED', $e->getErrorCode());
            $this->assertSame(['pointer' => '/atomic:operations/1/data/relationships/organization/data'], $e->getSource());
        }

        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM organizations'));
    }

    public function testToManyRelationshipOperationsAreDeclined(): void
    {
        try {
            $this->execute([[
                'op' => 'add',
                'ref' => ['type' => 'contacts', 'id' => '1', 'relationship' => 'tags'],
                'data' => [['type' => 'tags', 'id' => '1']],
            ]]);
            $this->fail('Expected OperationFailed');
        } catch (OperationFailed $e) {
            $this->assertSame(403, $e->getStatus());
        }
    }

    public function testHrefTargetsAreDeclined(): void
    {
        try {
            $this->execute([['op' => 'add', 'href' => '/contacts', 'data' => ['type' => 'contacts']]]);
            $this->fail('Expected OperationFailed');
        } catch (OperationFailed $e) {
            $this->assertSame(403, $e->getStatus());
            $this->assertSame(['pointer' => '/atomic:operations/0/href'], $e->getSource());
        }
    }
}
