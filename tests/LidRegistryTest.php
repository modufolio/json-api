<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\Exception\LidConflict;
use Modufolio\JsonApi\Exception\LidUnresolved;
use Modufolio\JsonApi\LidRegistry;
use PHPUnit\Framework\TestCase;

class LidRegistryTest extends TestCase
{
    public function testResolvesARegisteredLid(): void
    {
        $lids = new LidRegistry();
        $lids->register('articles', 'tmp-1', '42');

        $this->assertTrue($lids->has('articles', 'tmp-1'));
        $this->assertSame('42', $lids->resolve('articles', 'tmp-1'));
        $this->assertSame('42', $lids->resolveIdentifier(['type' => 'articles', 'lid' => 'tmp-1']));
    }

    public function testLidsAreScopedByType(): void
    {
        $lids = new LidRegistry();
        $lids->register('articles', 'tmp-1', '1');
        $lids->register('people', 'tmp-1', '2');

        $this->assertSame('1', $lids->resolve('articles', 'tmp-1'));
        $this->assertSame('2', $lids->resolve('people', 'tmp-1'));
    }

    public function testIdentifierWithAnIdNeedsNoRegistry(): void
    {
        $this->assertSame('7', (new LidRegistry())->resolveIdentifier(['type' => 'articles', 'id' => 7]));
    }

    public function testUnknownLidIsA400WithThePointer(): void
    {
        try {
            (new LidRegistry())->resolveIdentifier(['type' => 'articles', 'lid' => 'nope'], '/data/relationships/article/data');
            $this->fail('Expected LidUnresolved');
        } catch (LidUnresolved $e) {
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('LID_UNRESOLVED', $e->getErrorCode());
            $this->assertSame(['pointer' => '/data/relationships/article/data'], $e->getSource());
        }
    }

    public function testReRegisteringTheSameIdIsHarmless(): void
    {
        $lids = new LidRegistry();
        $lids->register('articles', 'tmp-1', '1');
        $lids->register('articles', 'tmp-1', '1');

        $this->assertSame(['articles/tmp-1' => '1'], $lids->all());
    }

    public function testClaimingALidForADifferentResourceIsA400(): void
    {
        $lids = new LidRegistry();
        $lids->register('articles', 'tmp-1', '1');

        try {
            $lids->register('articles', 'tmp-1', '2');
            $this->fail('Expected LidConflict');
        } catch (LidConflict $e) {
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('LID_CONFLICT', $e->getErrorCode());
        }
    }
}
