<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Filter;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\Filter\DateFilter;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\JsonApiUrlParser;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Drives DateFilter end-to-end: an HTTP request flows through JsonApiUrlParser
 * (which must keep the `after` / `before` operators) and on into the
 * JsonApiQueryBuilder + DateFilter, which actually filter the rows.
 *
 * Before the parser whitelisted DateFilter's operators, `filter[createdAt][after]`
 * was stripped during parsing and DateFilter never fired.
 */
class DateFilterRequestIntegrationTest extends TestCase
{
    private EntityManager $em;
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $this->config = [
            Contact::class => [
                'resource_key' => 'contact',
                'fields' => ['id', 'firstName', 'lastName', 'email', 'createdAt', 'updatedAt'],
                'relationships' => ['account'],
                'operations' => ['index' => true, 'show' => true],
            ],
        ];

        $account = new Account();
        $account->setName('Test Account');
        $this->em->persist($account);
        $this->em->flush();

        $now = date('Y-m-d H:i:s');
        $conn = $this->em->getConnection();

        $contacts = [
            ['first_name' => 'John',  'last_name' => 'Doe',     'email' => 'john@example.com',  'account_id' => $account->getId(), 'created_at' => date('Y-m-d H:i:s', strtotime('-10 days')), 'updated_at' => $now],
            ['first_name' => 'Jane',  'last_name' => 'Smith',   'email' => 'jane@test.org',     'account_id' => $account->getId(), 'created_at' => date('Y-m-d H:i:s', strtotime('-5 days')),  'updated_at' => $now],
            ['first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'alice@company.com', 'account_id' => $account->getId(), 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),   'updated_at' => $now],
        ];

        foreach ($contacts as $contact) {
            $conn->insert('contacts', $contact);
        }
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    private function parser(): JsonApiUrlParser
    {
        return new JsonApiUrlParser($this->config);
    }

    private function queryBuilder(): JsonApiQueryBuilder
    {
        $registry = new FilterRegistry();
        $registry->register(Contact::class, new DateFilter(['createdAt', 'updatedAt']));

        return new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );
    }

    public function testAfterOperatorSurvivesParsing(): void
    {
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        $request = (new ServerRequest('GET', '/contacts'))
            ->withQueryParams(['filter' => ['createdAt' => ['after' => $sevenDaysAgo]]]);

        $params = $this->parser()->parse($request, Contact::class);

        // The operator must NOT be stripped during parsing.
        $this->assertSame(['createdAt' => ['after' => $sevenDaysAgo]], $params->filter);
    }

    public function testAfterOperatorFiltersEndToEnd(): void
    {
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        $request = (new ServerRequest('GET', '/contacts'))
            ->withQueryParams(['filter' => ['createdAt' => ['after' => $sevenDaysAgo]]]);

        $params = $this->parser()->parse($request, Contact::class);

        $result = $this->queryBuilder()
            ->applyParams($params)
            ->operation('index')
            ->get();

        // after -7d matches the -5d and -1d contacts.
        $this->assertCount(2, $result['data']);
        $names = array_map(fn ($c) => $c['attributes']['first_name'], $result['data']);
        $this->assertContains('Jane', $names);
        $this->assertContains('Alice', $names);
        $this->assertNotContains('John', $names);
    }

    public function testBeforeOperatorFiltersEndToEnd(): void
    {
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        $request = (new ServerRequest('GET', '/contacts'))
            ->withQueryParams(['filter' => ['createdAt' => ['before' => $sevenDaysAgo]]]);

        $params = $this->parser()->parse($request, Contact::class);

        $result = $this->queryBuilder()
            ->applyParams($params)
            ->operation('index')
            ->get();

        // before -7d matches only the -10d contact.
        $this->assertCount(1, $result['data']);
        $this->assertSame('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testDateRangeFiltersEndToEnd(): void
    {
        $request = (new ServerRequest('GET', '/contacts'))
            ->withQueryParams([
                'filter' => [
                    'createdAt' => [
                        'after'  => date('Y-m-d H:i:s', strtotime('-7 days')),
                        'before' => date('Y-m-d H:i:s'),
                    ],
                ],
            ]);

        $params = $this->parser()->parse($request, Contact::class);

        // Both operators survive parsing.
        $this->assertArrayHasKey('after', $params->filter['createdAt']);
        $this->assertArrayHasKey('before', $params->filter['createdAt']);

        $result = $this->queryBuilder()
            ->applyParams($params)
            ->operation('index')
            ->get();

        // Range (-7d, now] matches the -5d and -1d contacts.
        $this->assertCount(2, $result['data']);
    }

    public function testStrictlyOperatorsSurviveParsing(): void
    {
        $request = (new ServerRequest('GET', '/contacts'))
            ->withQueryParams([
                'filter' => [
                    'createdAt' => ['strictly_after' => '2020-01-01'],
                    'updatedAt' => ['strictly_before' => '2030-01-01'],
                ],
            ]);

        $params = $this->parser()->parse($request, Contact::class);

        $this->assertSame(['strictly_after' => '2020-01-01'], $params->filter['createdAt']);
        $this->assertSame(['strictly_before' => '2030-01-01'], $params->filter['updatedAt']);

        $result = $this->queryBuilder()
            ->applyParams($params)
            ->operation('index')
            ->get();

        // Wide bounds on both fields: all 3 contacts match.
        $this->assertCount(3, $result['data']);
    }
}
