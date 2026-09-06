<?php
/**
 * Registered data traversal and complete-dataset metrics tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\DataSource\ReadOnlySource;
use Noteware\AdminTables\DataSource\SourceRegistry;
use Noteware\AdminTables\Metrics\DatasetMetrics;
use Noteware\AdminTables\Model\StoredValue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegisteredDataSourceTest extends TestCase
{
    private function source(): ReadOnlySource
    {
        return new class implements ReadOnlySource {
            public bool $allowed = true;
            public bool $duplicate = false;
            public int $calls = 0;
            public function id(): string
            {
                return 'example';
            }
            public function fields(): array
            {
                return array('amount' => 'number');
            }
            public function canRead(): bool
            {
                return $this->allowed;
            }
            public function page(array $equals, int $limit, ?string $cursor): array
            {
                ++$this->calls;
                $index = null === $cursor ? 0 : (int) $cursor;
                $rows = array();
                for ($row = $index; $row < min(450, $index + $limit); ++$row) {
                    $rows[] = array('id' => $this->duplicate ? 'same' : (string) $row, 'values' => array('amount' => new StoredValue(true, (string) $row)));
                }
                return array('rows' => $rows, 'next' => $index + $limit < 450 ? (string) ($index + $limit) : null);
            }
        };
    }

    public function testMetricsReconcileAcrossAllThreePages(): void
    {
        $registry = new SourceRegistry();
        $source = $this->source();
        $registry->register($source);
        $result = DatasetMetrics::calculate($registry->values('example', 'amount'), 'number');
        self::assertSame(450, $result['rows']);
        self::assertSame(101025.0, $result['sum']);
        self::assertSame(224.5, $result['mean']);
        self::assertSame(3, $source->calls);
    }

    public function testUnknownRegistrationDenied(): void
    {
        $this->expectException(RuntimeException::class);
        iterator_to_array((new SourceRegistry())->values('unknown', 'amount'));
    }

    public function testReadCapabilityRequired(): void
    {
        $registry = new SourceRegistry();
        $source = $this->source();
        $source->allowed = false;
        $registry->register($source);
        $this->expectException(RuntimeException::class);
        iterator_to_array($registry->values('example', 'amount'));
    }

    public function testUnknownFilterCannotReachProvider(): void
    {
        $registry = new SourceRegistry();
        $registry->register($this->source());
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array($registry->values('example', 'amount', array('raw_sql' => 'arbitrary')));
    }

    public function testDuplicateRowsCannotInflateMetrics(): void
    {
        $registry = new SourceRegistry();
        $source = $this->source();
        $source->duplicate = true;
        $registry->register($source);
        $this->expectException(RuntimeException::class);
        DatasetMetrics::calculate($registry->values('example', 'amount'), 'number');
    }

    public function testBudgetCannotReturnPageOnlyMetrics(): void
    {
        $registry = new SourceRegistry();
        $registry->register($this->source());
        $this->expectException(RuntimeException::class);
        DatasetMetrics::calculate($registry->values('example', 'amount', array(), 200), 'number');
    }

    public function testPermissionRevocationStopsNextPage(): void
    {
        $registry = new SourceRegistry();
        $source = $this->source();
        $registry->register($source);
        $iterator = $registry->values('example', 'amount');
        $iterator->rewind();
        $source->allowed = false;
        $this->expectException(RuntimeException::class);
        while ($iterator->valid()) {
            $iterator->next();
        }
    }
}
