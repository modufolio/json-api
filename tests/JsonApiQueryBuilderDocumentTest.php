<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Document;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

class JsonApiQueryBuilderDocumentTest extends TestCase
{
    private EntityManager $em;
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $this->config = [
            Document::class => [
                'resource_key' => 'document',
                'fields' => ['id', 'title', 'body', 'status'],
                'relationships' => [],
                'operations' => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
            ],
        ];

        foreach ([
            ['Draft Proposal', 'Content of the proposal', 'draft'],
            ['Final Report', 'Content of the report', 'published'],
            ['Meeting Notes', null, 'draft'],
        ] as [$title, $body, $status]) {
            $doc = new Document();
            $doc->setTitle($title);
            $doc->setBody($body);
            $doc->setStatus($status);
            $this->em->persist($doc);
        }

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    private function makeQb(): JsonApiQueryBuilder
    {
        return new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Document::class
        );
    }

    public function testIndexReturnsAllDocuments(): void
    {
        $result = $this->makeQb()->operation('index')->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
        $this->assertArrayNotHasKey('relationships', $result['data'][0]);
    }

    public function testFilterByStatus(): void
    {
        $result = $this->makeQb()->filter(['status' => 'draft'])->operation('index')->get();

        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $item) {
            $this->assertEquals('draft', $item['attributes']['status']);
        }
    }

    public function testFilterByNullBody(): void
    {
        $result = $this->makeQb()->filter(['body' => ['null' => true]])->operation('index')->get();

        $this->assertCount(1, $result['data']);
        $this->assertEquals('Meeting Notes', $result['data'][0]['attributes']['title']);
    }

    public function testSparseFieldset(): void
    {
        $result = $this->makeQb()->fields(['title', 'status'])->operation('index')->get();

        $this->assertCount(3, $result['data']);
        $this->assertArrayHasKey('title', $result['data'][0]['attributes']);
        $this->assertArrayHasKey('status', $result['data'][0]['attributes']);
        $this->assertArrayNotHasKey('body', $result['data'][0]['attributes']);
    }

    public function testShow(): void
    {
        $doc = $this->em->getRepository(Document::class)->findOneBy(['title' => 'Final Report']);

        $result = $this->makeQb()->operation('show')->withId((string) $doc->getId())->get();

        $this->assertCount(1, $result);
        $this->assertEquals('Final Report', $result[0]['attributes']['title']);
        $this->assertEquals('published', $result[0]['attributes']['status']);
    }

    public function testCreate(): void
    {
        $result = $this->makeQb()
            ->withData(['title' => 'New Doc', 'body' => 'Some body', 'status' => 'draft'])
            ->operation('create')
            ->get();

        $this->assertCount(1, $result);
        $this->assertEquals('New Doc', $result[0]['attributes']['title']);
        $this->assertNotEmpty($result[0]['id']);
    }

    public function testUpdate(): void
    {
        $doc = $this->em->getRepository(Document::class)->findOneBy(['title' => 'Draft Proposal']);

        $result = $this->makeQb()
            ->withId((string) $doc->getId())
            ->withData(['status' => 'published'])
            ->operation('update')
            ->get();

        $this->assertCount(1, $result);
        $this->assertEquals('published', $result[0]['attributes']['status']);
    }

    public function testDelete(): void
    {
        $doc = $this->em->getRepository(Document::class)->findOneBy(['title' => 'Meeting Notes']);
        $id = (string) $doc->getId();

        $result = $this->makeQb()->withId($id)->operation('delete')->get();

        $this->assertEquals(['status' => 'deleted', 'id' => $id], $result);
        $this->assertCount(2, $this->makeQb()->operation('index')->get()['data']);
    }

    public function testCountWithNoRelationships(): void
    {
        $this->assertEquals(3, $this->makeQb()->count());
    }

    public function testSortByTitle(): void
    {
        $result = $this->makeQb()->sort(['title'])->operation('index')->get();

        $titles = array_column(array_column($result['data'], 'attributes'), 'title');
        $this->assertEquals(['Draft Proposal', 'Final Report', 'Meeting Notes'], $titles);
    }

    public function testWithTotalCount(): void
    {
        $result = $this->makeQb()->withTotalCount()->filter(['status' => 'draft'])->operation('index')->get();

        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['data']);
    }
}
