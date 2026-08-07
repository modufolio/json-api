<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Modufolio\JsonApi\AttributeCaster;
use Modufolio\JsonApi\Exception\InvalidAttributeValueException;
use Modufolio\JsonApi\Tests\Fixtures\Entity\CastSample;
use Modufolio\JsonApi\Tests\Fixtures\Enum\SampleStatus;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttributeCasterTest extends TestCase
{
    private EntityManager $em;
    private AttributeCaster $caster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = TestDatabaseSetup::createEntityManager();
        $this->caster = new AttributeCaster($this->em);
    }

    private function cast(string $field, mixed $value): mixed
    {
        return $this->caster->cast(CastSample::class, $field, $value);
    }

    public function testABackedEnumIsBuiltFromItsBackingValue(): void
    {
        $this->assertSame(SampleStatus::ACTIVE, $this->cast('status', 'active'));
        $this->assertSame(SampleStatus::ARCHIVED, $this->cast('status', 'archived'));
    }

    public function testAnEnumInstanceIsLeftAlone(): void
    {
        $this->assertSame(SampleStatus::ACTIVE, $this->cast('status', SampleStatus::ACTIVE));
    }

    /**
     * The error has to name the accepted values — a bare ValueError leaves the
     * client guessing what the API would have taken.
     */
    public function testAnUnknownEnumValueIsRejectedWithTheAcceptedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Expected one of: active, archived.");

        $this->cast('status', 'bogus');
    }

    /**
     * The caller needs the field and the accepted values as data, not prose, to
     * build a JSON:API error object with a source pointer.
     */
    public function testTheExceptionCarriesTheFieldAndAcceptedValues(): void
    {
        try {
            $this->cast('status', 'bogus');
            $this->fail('Expected the cast to be rejected.');
        } catch (InvalidAttributeValueException $e) {
            $this->assertSame('status', $e->getField());
            $this->assertSame(['active', 'archived'], $e->getAccepted());
        }
    }

    /**
     * Open-ended types have no list to offer, and inventing one would be worse
     * than saying nothing.
     */
    public function testAnOpenEndedTypeReportsNoAcceptedValues(): void
    {
        try {
            $this->cast('publishedOn', 'not-a-date');
            $this->fail('Expected the cast to be rejected.');
        } catch (InvalidAttributeValueException $e) {
            $this->assertSame('publishedOn', $e->getField());
            $this->assertSame([], $e->getAccepted());
        }
    }

    /**
     * Existing callers catch InvalidArgumentException; the new type has to keep
     * landing in those blocks.
     */
    public function testTheExceptionStaysAnInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cast('status', 'bogus');
    }

    public function testDateStringsBecomeDateTimeImmutable(): void
    {
        $on = $this->cast('publishedOn', '1990-01-01');
        $this->assertInstanceOf(DateTimeImmutable::class, $on);
        $this->assertSame('1990-01-01', $on->format('Y-m-d'));

        $at = $this->cast('publishedAt', '2026-08-07 13:45:00');
        $this->assertInstanceOf(DateTimeImmutable::class, $at);
        $this->assertSame('2026-08-07 13:45:00', $at->format('Y-m-d H:i:s'));
    }

    public function testAnUnparseableDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('publishedOn');

        $this->cast('publishedOn', 'not-a-date');
    }

    public function testNullPassesThroughSoNullableSettersStillWork(): void
    {
        $this->assertNull($this->cast('status', null));
        $this->assertNull($this->cast('publishedOn', null));
    }

    /**
     * JSON literals arrive as real PHP types; re-casting them can only lose
     * information, so they are handed straight back.
     */
    public function testRealJsonScalarsArePassedThroughUntouched(): void
    {
        $this->assertTrue($this->cast('active', true));
        $this->assertFalse($this->cast('active', false));
        $this->assertSame(42, $this->cast('score', 42));
        $this->assertSame('hello', $this->cast('title', 'hello'));
    }

    /**
     * DBAL's boolean type is a plain (bool) cast, which reads the string
     * "false" as true and would silently switch a flag back on.
     */
    #[DataProvider('booleanStrings')]
    public function testBooleanStringsUseFilterVarRulesRatherThanACast(string $raw, bool $expected): void
    {
        $this->assertSame($expected, $this->cast('active', $raw));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleanStrings(): iterable
    {
        yield 'false' => ['false', false];
        yield 'zero' => ['0', false];
        yield 'off' => ['off', false];
        yield 'true' => ['true', true];
        yield 'one' => ['1', true];
        yield 'on' => ['on', true];
    }

    public function testANonBooleanStringOnABooleanFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('active');

        $this->cast('active', 'maybe');
    }

    public function testNumericStringsAreConverted(): void
    {
        $this->assertSame(42, $this->cast('score', '42'));
    }

    /**
     * Associations are the caller's job — it has to load the related entity —
     * and unmapped names are not ours to interpret.
     */
    public function testUnmappedFieldsAreLeftAlone(): void
    {
        $this->assertSame('anything', $this->cast('notAField', 'anything'));
    }

    public function testAnEntityInstanceResolvesTheSameMappingAsAClassName(): void
    {
        $this->assertSame(
            SampleStatus::ACTIVE,
            $this->caster->cast(new CastSample(), 'status', 'active'),
        );
    }

    /**
     * The cast result has to satisfy the real setter, which is the whole point:
     * the caller keeps calling setters so domain logic survives.
     */
    public function testCastValuesSatisfyTheEntitySetters(): void
    {
        $entity = new CastSample();

        $entity->setStatus($this->cast('status', 'archived'));
        $entity->setPublishedOn($this->cast('publishedOn', '2001-02-03'));
        $entity->setActive($this->cast('active', 'false'));
        $entity->setScore($this->cast('score', '7'));

        $this->assertSame(SampleStatus::ARCHIVED, $entity->getStatus());
        $this->assertSame('2001-02-03', $entity->getPublishedOn()?->format('Y-m-d'));
        $this->assertFalse($entity->isActive());
        $this->assertSame(7, $entity->getScore());
    }
}
