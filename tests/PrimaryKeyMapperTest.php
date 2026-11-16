<?php
declare(strict_types=1);

namespace JtlMedusa\Tests;

use JtlMedusa\PrimaryKeyMapper;
use PDO;
use PHPUnit\Framework\TestCase;

final class PrimaryKeyMapperTest extends TestCase
{
    public function testMappingsSurviveReopeningAndRemainScopedByType(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'jtl-map-');
        try {
            $mapper = new PrimaryKeyMapper(new PDO('sqlite:' . $path));
            $mapper->save(1, 'prod_1', 42);
            $mapper->save(2, 'cat_1', 42);
            $mapper->save(1, 'prod_1', 42);
            $reopened = new PrimaryKeyMapper(new PDO('sqlite:' . $path));
            self::assertSame('prod_1', $reopened->getEndpointId(1, 42));
            self::assertSame(42, $reopened->getHostId(2, 'cat_1'));
            $reopened->clear(2);
            self::assertNull($reopened->getHostId(2, 'cat_1'));
            self::assertSame('prod_1', $reopened->getEndpointId(1, 42));
            $reopened->delete(1, 'prod_1', 42);
            self::assertNull($reopened->getEndpointId(1, 42));
        } finally { unlink($path); }
    }

    public function testCannotReassignAnExistingEndpoint(): void
    {
        $mapper = new PrimaryKeyMapper(new PDO('sqlite::memory:'));
        $mapper->save(1, 'prod_1', 42);
        try {
            $mapper->save(1, 'prod_1', 43);
            self::fail('Conflicting mapping was accepted.');
        } catch (\RuntimeException) {
            self::assertSame(42, $mapper->getHostId(1, 'prod_1'));
            self::assertNull($mapper->getEndpointId(1, 43));
        }
    }

    public function testCannotLinkTwoEndpointsToOneWawiId(): void
    {
        $mapper = new PrimaryKeyMapper(new PDO('sqlite::memory:'));
        $mapper->save(1, 'prod_1', 42);
        $this->expectException(\PDOException::class);
        $mapper->save(1, 'prod_2', 42);
    }
}
