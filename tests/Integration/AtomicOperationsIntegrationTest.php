<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Integration;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\Http\ResponseFactory;
use Modufolio\JsonApi\Tests\Fixtures\Controller\JsonApiController;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * JSON:API 1.1 over HTTP: content negotiation with the ext parameter, and an
 * atomic operations request end to end through the reference controller.
 */
class AtomicOperationsIntegrationTest extends TestCase
{
    private const ATOMIC = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    private EntityManager $em;
    private JsonApiController $controller;
    private Account $account;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $this->account = new Account();
        $this->account->setName('Test Account');
        $this->em->persist($this->account);
        $this->em->flush();

        $psr17 = new Psr17Factory();
        $this->controller = new JsonApiController(
            $this->em,
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            new ResponseFactory($psr17, $psr17),
            dirname(__DIR__) . '/Fixtures/config/json_api.php',
        );
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, string $contentType = self::ATOMIC, string $accept = self::ATOMIC): ServerRequest
    {
        return (new ServerRequest('POST', 'http://example.com/api/operations'))
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Accept', $accept)
            ->withBody(Stream::create((string) json_encode($body)));
    }

    public function testOperationsCreateLinkAndUpdateInOneRequest(): void
    {
        $response = $this->controller->handle($this->post([
            'atomic:operations' => [
                [
                    'op' => 'add',
                    'data' => [
                        'type' => 'organization',
                        'lid' => 'org',
                        'attributes' => ['name' => 'Initech', 'email' => 'hq@initech.test'],
                        'relationships' => ['account' => ['data' => ['type' => 'account', 'id' => (string) $this->account->getId()]]],
                    ],
                ],
                [
                    'op' => 'add',
                    'data' => [
                        'type' => 'contact',
                        'lid' => 'peter',
                        'attributes' => ['first_name' => 'Peter', 'last_name' => 'Gibbons', 'email' => 'peter@initech.test'],
                        'relationships' => [
                            'account' => ['data' => ['type' => 'account', 'id' => (string) $this->account->getId()]],
                            'organization' => ['data' => ['type' => 'organization', 'lid' => 'org']],
                        ],
                    ],
                ],
                [
                    'op' => 'update',
                    'ref' => ['type' => 'contact', 'lid' => 'peter'],
                    'data' => ['type' => 'contact', 'lid' => 'peter', 'attributes' => ['city' => 'Austin']],
                ],
            ],
        ]), Contact::class, 'create');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::ATOMIC, $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['version' => '1.1', 'ext' => ['https://jsonapi.org/ext/atomic']], $body['jsonapi']);
        $this->assertArrayNotHasKey('data', $body);

        $results = $body['atomic:results'];
        $this->assertCount(3, $results);
        $orgId = $results[0]['data']['id'];
        $this->assertSame($orgId, $results[1]['data']['relationships']['organization']['data']['id']);
        $this->assertSame('Austin', $results[2]['data']['attributes']['city']);

        $this->em->clear();
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['firstName' => 'Peter']);
        $this->assertNotNull($contact);
        $this->assertSame('Initech', $contact->getOrganization()?->getName());
    }

    public function testRemovalOnlyIsA204(): void
    {
        $contact = new Contact();
        $contact->setFirstName('Gone');
        $contact->setLastName('Soon');
        $contact->setEmail('gone@example.com');
        $contact->setAccount($this->account);
        $this->em->persist($contact);
        $this->em->flush();

        $response = $this->controller->handle($this->post([
            'atomic:operations' => [
                ['op' => 'remove', 'ref' => ['type' => 'contact', 'id' => (string) $contact->getId()]],
            ],
        ]), Contact::class, 'create');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contacts'));
    }

    public function testAFailedOperationRollsBackAndPointsAtItself(): void
    {
        $response = $this->controller->handle($this->post([
            'atomic:operations' => [
                [
                    'op' => 'add',
                    'data' => [
                        'type' => 'contact',
                        'attributes' => ['first_name' => 'First', 'last_name' => 'Kept', 'email' => 'kept@example.com'],
                        'relationships' => ['account' => ['data' => ['type' => 'account', 'id' => (string) $this->account->getId()]]],
                    ],
                ],
                ['op' => 'remove', 'ref' => ['type' => 'contact', 'id' => '424242']],
            ],
        ]), Contact::class, 'create');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayNotHasKey('atomic:results', $body);
        $this->assertSame('RESOURCE_NOT_FOUND', $body['errors'][0]['code']);
        $this->assertSame(['pointer' => '/atomic:operations/1'], $body['errors'][0]['source']);

        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contacts'));
    }

    public function testMalformedOperationsDocumentIsA400(): void
    {
        $response = $this->controller->handle($this->post([
            'atomic:operations' => [['op' => 'teleport']],
        ]), Contact::class, 'create');

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('ATOMIC_OPERATION_MALFORMED', $body['errors'][0]['code']);
        $this->assertSame(['pointer' => '/atomic:operations/0/op'], $body['errors'][0]['source']);
    }

    /**
     * "the server MUST respond with a 415 Unsupported Media Type status code"
     * for an extension it does not support.
     */
    public function testUnsupportedExtensionIs415WithAHeaderSource(): void
    {
        $response = $this->controller->handle(
            $this->post(['data' => []], 'application/vnd.api+json; ext="https://example.com/ext"'),
            Contact::class,
            'create',
        );

        $this->assertSame(415, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('MEDIA_TYPE_UNSUPPORTED', $body['errors'][0]['code']);
        $this->assertSame(['header' => 'Content-Type'], $body['errors'][0]['source']);
    }

    public function testJsonApiTypeWithCharsetIs415(): void
    {
        $response = $this->controller->handle(
            $this->post(['data' => []], 'application/vnd.api+json; charset=utf-8'),
            Contact::class,
            'create',
        );

        $this->assertSame(415, $response->getStatusCode());
    }

    /**
     * "If all instances of that media type are modified with a media type
     * parameter other than ext or profile, servers MUST respond with a 406."
     */
    public function testAcceptWithOnlyForeignParametersIs406(): void
    {
        $request = (new ServerRequest('GET', 'http://example.com/api/contacts'))
            ->withHeader('Accept', 'application/vnd.api+json; charset=utf-8');

        $response = $this->controller->handle($request, Contact::class, 'index');

        $this->assertSame(406, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['header' => 'Accept'], $body['errors'][0]['source']);
    }

    public function testResponseEchoesTheNegotiatedExtensionInHeaderAndJsonApiObject(): void
    {
        $request = (new ServerRequest('GET', 'http://example.com/api/contacts'))
            ->withHeader('Accept', self::ATOMIC . ', application/vnd.api+json;q=0.5');

        $response = $this->controller->handle($request, Contact::class, 'index');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::ATOMIC, $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['https://jsonapi.org/ext/atomic'], $body['jsonapi']['ext']);
    }

    public function testPlainAcceptGetsAPlainResponse(): void
    {
        $request = (new ServerRequest('GET', 'http://example.com/api/contacts'))
            ->withHeader('Accept', 'application/vnd.api+json');

        $response = $this->controller->handle($request, Contact::class, 'index');

        $this->assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['version' => '1.1'], json_decode((string) $response->getBody(), true)['jsonapi']);
    }
}
