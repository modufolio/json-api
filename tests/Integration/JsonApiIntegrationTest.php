<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Integration;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\JsonApiController;
use Modufolio\JsonApi\Http\ResponseFactory;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Organization;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class JsonApiIntegrationTest extends TestCase
{
    private JsonApiController $controller;
    private EntityManager $em;
    private Account $testAccount;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        // Create test account
        $this->testAccount = new Account();
        $this->testAccount->setName('Test Account');
        $this->em->persist($this->testAccount);
        $this->em->flush();

        // Setup response factory
        $psr17Factory = new Psr17Factory();
        $responseFactory = new ResponseFactory($psr17Factory, $psr17Factory);

        // Setup validator
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $jsonApiConfig = dirname(__DIR__) . '/Fixtures/config/json_api.php';

        $this->controller = new JsonApiController(
            $this->em,
            $validator,
            $responseFactory,
            $jsonApiConfig
        );
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    public function testIndexReturnsEmptyCollection(): void
    {
        $request = new ServerRequest('GET', 'http://example.com/api/contacts');
        $request = $request->withHeader('Accept', 'application/vnd.api+json');

        $response = $this->controller->handle($request, Contact::class, 'index');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/vnd.api+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($body['data']);
        $this->assertCount(0, $body['data']);
        $this->assertArrayHasKey('meta', $body);
        $this->assertEquals(0, $body['meta']['total']);
    }

    public function testCreateContact(): void
    {
        $contactData = [
            'data' => [
                'type' => 'contact',
                'attributes' => [
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'email' => 'john@example.com',
                    'phone' => '555-1234',
                    'city' => 'New York',
                ],
                'relationships' => [
                    'account' => [
                        'data' => [
                            'type' => 'account',
                            'id' => (string)$this->testAccount->getId(),
                        ],
                    ],
                ],
            ],
        ];

        $request = new ServerRequest('POST', 'http://example.com/api/contacts');
        $request = $request->withHeader('Content-Type', 'application/vnd.api+json');
        $request = $request->withHeader('Accept', 'application/vnd.api+json');

        $body = Stream::create(json_encode($contactData));
        $request = $request->withBody($body);

        $response = $this->controller->handle($request, Contact::class, 'create');

        $this->assertEquals(201, $response->getStatusCode());

        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals('contact', $responseData['data']['type']);
        $this->assertEquals('John', $responseData['data']['attributes']['first_name']);
        $this->assertEquals('Doe', $responseData['data']['attributes']['last_name']);
        $this->assertEquals('john@example.com', $responseData['data']['attributes']['email']);
    }

    public function testShowContact(): void
    {
        // Create a contact
        $contact = new Contact();
        $contact->setFirstName('Jane');
        $contact->setLastName('Smith');
        $contact->setEmail('jane@example.com');
        $contact->setAccount($this->testAccount);
        $this->em->persist($contact);
        $this->em->flush();

        $request = new ServerRequest('GET', 'http://example.com/api/contacts/' . $contact->getId());
        $request = $request->withHeader('Accept', 'application/vnd.api+json');

        $response = $this->controller->handle($request, Contact::class, 'show', $contact->getId());

        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals('contact', $responseData['data']['type']);
        $this->assertEquals((string)$contact->getId(), $responseData['data']['id']);
        $this->assertEquals('Jane', $responseData['data']['attributes']['first_name']);
    }

    public function testUpdateContact(): void
    {
        // Create a contact
        $contact = new Contact();
        $contact->setFirstName('Bob');
        $contact->setLastName('Johnson');
        $contact->setEmail('bob@example.com');
        $contact->setAccount($this->testAccount);
        $this->em->persist($contact);
        $this->em->flush();

        $updateData = [
            'data' => [
                'type' => 'contact',
                'id' => (string)$contact->getId(),
                'attributes' => [
                    'first_name' => 'Robert',
                    'email' => 'robert@example.com',
                ],
            ],
        ];

        $request = new ServerRequest('PATCH', 'http://example.com/api/contacts/' . $contact->getId());
        $request = $request->withHeader('Content-Type', 'application/vnd.api+json');
        $request = $request->withHeader('Accept', 'application/vnd.api+json');

        $body = Stream::create(json_encode($updateData));
        $request = $request->withBody($body);

        $response = $this->controller->handle($request, Contact::class, 'update', $contact->getId());

        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals('Robert', $responseData['data']['attributes']['first_name']);
        $this->assertEquals('robert@example.com', $responseData['data']['attributes']['email']);
    }

    public function testDeleteContact(): void
    {
        // Create a contact
        $contact = new Contact();
        $contact->setFirstName('Delete');
        $contact->setLastName('Me');
        $contact->setEmail('delete@example.com');
        $contact->setAccount($this->testAccount);
        $this->em->persist($contact);
        $this->em->flush();

        $contactId = $contact->getId();

        $request = new ServerRequest('DELETE', 'http://example.com/api/contacts/' . $contactId);

        $response = $this->controller->handle($request, Contact::class, 'delete', $contactId);

        $this->assertEquals(204, $response->getStatusCode());

        // Verify contact was soft deleted
        $this->em->clear();
        $deletedContact = $this->em->getRepository(Contact::class)->find($contactId);
        $this->assertNotNull($deletedContact->getDeletedAt());
    }

    public function testIndexWithPagination(): void
    {
        // Create 30 contacts
        for ($i = 1; $i <= 30; $i++) {
            $contact = new Contact();
            $contact->setFirstName('Contact');
            $contact->setLastName('Number ' . $i);
            $contact->setEmail("contact{$i}@example.com");
            $contact->setAccount($this->testAccount);
            $this->em->persist($contact);
        }
        $this->em->flush();

        $request = new ServerRequest('GET', 'http://example.com/api/contacts?page[number]=2&page[size]=10');
        $request = $request->withHeader('Accept', 'application/vnd.api+json');

        $response = $this->controller->handle($request, Contact::class, 'index');

        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertCount(10, $responseData['data']);
        $this->assertEquals(30, $responseData['meta']['total']);
        $this->assertEquals(2, $responseData['meta']['current_page']);
        $this->assertEquals(3, $responseData['meta']['last_page']);
    }

    public function testIndexWithSorting(): void
    {
        // Create contacts with different names
        $names = ['Charlie', 'Alice', 'Bob'];
        foreach ($names as $name) {
            $contact = new Contact();
            $contact->setFirstName($name);
            $contact->setLastName('Test');
            $contact->setEmail(strtolower($name) . '@example.com');
            $contact->setAccount($this->testAccount);
            $this->em->persist($contact);
        }
        $this->em->flush();

        $request = new ServerRequest('GET', 'http://example.com/api/contacts?sort=first_name');
        $request = $request->withHeader('Accept', 'application/vnd.api+json');

        $response = $this->controller->handle($request, Contact::class, 'index');

        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals('Alice', $responseData['data'][0]['attributes']['first_name']);
        $this->assertEquals('Bob', $responseData['data'][1]['attributes']['first_name']);
        $this->assertEquals('Charlie', $responseData['data'][2]['attributes']['first_name']);
    }

    public function testIndexWithFiltering(): void
    {
        // Create contacts with different cities
        $contact1 = new Contact();
        $contact1->setFirstName('John');
        $contact1->setLastName('NYC');
        $contact1->setEmail('john@example.com');
        $contact1->setCity('New York');
        $contact1->setAccount($this->testAccount);
        $this->em->persist($contact1);

        $contact2 = new Contact();
        $contact2->setFirstName('Jane');
        $contact2->setLastName('LA');
        $contact2->setEmail('jane@example.com');
        $contact2->setCity('Los Angeles');
        $contact2->setAccount($this->testAccount);
        $this->em->persist($contact2);

        $this->em->flush();

        $request = new ServerRequest('GET', 'http://example.com/api/contacts?filter[city]=New York');
        $request = $request->withHeader('Accept', 'application/vnd.api+json');

        $response = $this->controller->handle($request, Contact::class, 'index');

        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertCount(1, $responseData['data']);
        $this->assertEquals('New York', $responseData['data'][0]['attributes']['city']);
    }

    public function testRelationships(): void
    {
        // Create organization
        $org = new Organization();
        $org->setName('Test Org');
        $org->setEmail('org@example.com');
        $org->setAccount($this->testAccount);
        $this->em->persist($org);

        // Create contact with organization
        $contact = new Contact();
        $contact->setFirstName('Employee');
        $contact->setLastName('One');
        $contact->setEmail('employee@example.com');
        $contact->setAccount($this->testAccount);
        $contact->setOrganization($org);
        $this->em->persist($contact);

        $this->em->flush();

        $request = new ServerRequest('GET', 'http://example.com/api/contacts/' . $contact->getId());
        $request = $request->withHeader('Accept', 'application/vnd.api+json');

        $response = $this->controller->handle($request, Contact::class, 'show', $contact->getId());

        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('relationships', $responseData['data']);
        $this->assertArrayHasKey('organization', $responseData['data']['relationships']);
        $this->assertEquals((string)$org->getId(), $responseData['data']['relationships']['organization']['data']['id']);
    }
}
