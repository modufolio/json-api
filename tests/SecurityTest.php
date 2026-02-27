<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\SafeExpressionBuilder;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Security tests for the JSON API Query Builder
 */
class SecurityTest extends TestCase
{
    private SafeExpressionBuilder $safeExpr;

    protected function setUp(): void
    {
        $connection = $this->createMock(Connection::class);
        $expr = new ExpressionBuilder($connection);
        $this->safeExpr = new SafeExpressionBuilder($expr);
    }

    public function testValidColumnNamesAreAccepted(): void
    {
        // These should all work
        $validColumns = [
            'user_id',
            'email', 
            'created_at',
            'first_name',
            'user.email',
            'table1.column_name'
        ];

        foreach ($validColumns as $column) {
            $result = $this->safeExpr->eq($column, ':value');
            $this->assertStringContainsString($column, $result);
        }
    }

    public function testDangerousColumnNamesAreRejected(): void
    {
        // These should all be rejected
        $dangerousColumns = [
            'email; DROP TABLE users',
            'name OR 1=1',
            '1; DELETE FROM users',
            'SELECT * FROM users',
            'EXEC xp_cmdshell',
            'INFORMATION_SCHEMA.tables',
            'user.email--',
            'column/*comment*/',
            'DROP',
            'UPDATE',
            'DELETE'
        ];

        foreach ($dangerousColumns as $column) {
            $this->expectException(InvalidArgumentException::class);
            $this->safeExpr->eq($column, ':value');
        }
    }

    public function testInClauseLimitsAreEnforced(): void
    {
        // Small IN clause should work
        $smallValues = range(1, 10);
        $result = $this->safeExpr->in('user.id', array_map(fn($i) => ":param$i", $smallValues));
        $this->assertStringContainsString('user.id', $result);

        // Large IN clause should be rejected
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many values in IN clause');
        
        $largeValues = range(1, 2000);
        $this->safeExpr->in('user.id', array_map(fn($i) => ":param$i", $largeValues));
    }

    public function testSafeExpressionsAreGenerated(): void
    {
        // Test various safe expressions
        $expressions = [
            $this->safeExpr->eq('user.email', ':email'),
            $this->safeExpr->gt('user.age', ':min_age'),
            $this->safeExpr->like('user.name', ':pattern'),
            $this->safeExpr->isNull('user.deleted_at'),
            $this->safeExpr->in('user.status', [':active', ':pending'])
        ];

        foreach ($expressions as $expr) {
            $this->assertIsString($expr);
            $this->assertNotEmpty($expr);
            // Ensure no dangerous SQL keywords at word boundaries
            $this->assertDoesNotMatchRegularExpression('/\\bDROP\\b/i', $expr);
            $this->assertDoesNotMatchRegularExpression('/\\bDELETE\\s+FROM\\b/i', $expr);
            $this->assertDoesNotMatchRegularExpression('/\\bUNION\\s+SELECT\\b/i', $expr);
        }
    }

    public function testCompositeExpressionsWork(): void
    {
        $expr1 = $this->safeExpr->eq('user.active', ':active');
        $expr2 = $this->safeExpr->gt('user.created_at', ':date');
        
        $andExpr = $this->safeExpr->and($expr1, $expr2);
        $orExpr = $this->safeExpr->or($expr1, $expr2);
        
        $this->assertStringContainsString('AND', $andExpr);
        $this->assertStringContainsString('OR', $orExpr);
        $this->assertStringContainsString('user.active', $andExpr);
        $this->assertStringContainsString('user.created_at', $orExpr);
    }
}