<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Document;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\ResourceObject;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * Runs the assembly snippet from docs/installation.md end to end.
 *
 * The documented version used to map only `id` and `attributes`, which quietly
 * dropped relationship linkage and produced no `included` member at all — a
 * client asking for `?include=` got a document that looked well-formed and was
 * missing what it requested. Executing the documented shape here keeps the
 * example honest.
 */
class DocumentedAssemblyTest extends TestCase
{
    private EntityManager $em;
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();
        TestDatabaseSetup::reset();

        $this->config = [
            Account::class => [
                'resource_key'  => 'account',
                'fields'        => ['id', 'name'],
                'relationships' => ['contacts'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Contact::class => [
                'resource_key'  => 'contact',
                'fields'        => ['id', 'firstName', 'lastName', 'email'],
                'relationships' => ['account'],
                'operations'    => ['index' => true, 'show' => true],
            ],
        ];

        $account = new Account();
        $account->setName('Acme Corp');
        $this->em->persist($account);

        foreach (['John', 'Jane'] as $first) {
            $contact = new Contact();
            $contact->setFirstName($first);
            $contact->setLastName('Doe');
            $contact->setEmail(strtolower($first) . '@acme.com');
            $contact->setAccount($account);
            $this->em->persist($contact);
        }

        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    /**
     * @return array<string, mixed>
     */
    private function assemble(array $result): array
    {
        // Verbatim from docs/installation.md.
        $toResource = fn (string $type, array $row) => (new ResourceObject($type, (string) $row['id']))
            ->setAttributes($row['attributes'] ?? [])
            ->setRelationships($row['relationships'] ?? []);

        $document = new JsonApiDocument();
        $document->setData(array_map(
            fn (array $row) => $toResource('account', $row),
            $result['data'],
        ));

        if (!empty($result['included'])) {
            $document->setIncluded(array_map(
                fn (array $row) => $toResource($row['type'], $row),
                $result['included'],
            ));
        }

        $document->setMeta(['total' => $result['total'] ?? 0]);

        return json_decode(json_encode($document->toArray()), true);
    }

    private function queryResult(bool $include): array
    {
        $builder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Account::class
        );

        if ($include) {
            $builder->include(['contacts']);
        }

        return $builder->operation('index')->withTotalCount()->get();
    }

    public function testPrimaryDataCarriesRelationshipLinkage(): void
    {
        $document = $this->assemble($this->queryResult(false));

        self::assertCount(1, $document['data']);
        self::assertSame('account', $document['data'][0]['type']);
        self::assertCount(2, $document['data'][0]['relationships']['contacts']['data']);
    }

    public function testIncludedIsASiblingOfDataAndCarriesItsOwnType(): void
    {
        $document = $this->assemble($this->queryResult(true));

        self::assertArrayHasKey('included', $document);
        self::assertCount(2, $document['included']);

        foreach ($document['included'] as $resource) {
            self::assertSame('contact', $resource['type']);
            self::assertArrayHasKey('first_name', $resource['attributes']);
        }
    }

    public function testIncludedIsOmittedWhenNothingWasIncluded(): void
    {
        $document = $this->assemble($this->queryResult(false));

        self::assertArrayNotHasKey('included', $document);
    }

    public function testTotalReachesTheDocumentMeta(): void
    {
        $document = $this->assemble($this->queryResult(true));

        self::assertSame(1, $document['meta']['total']);
    }
}
