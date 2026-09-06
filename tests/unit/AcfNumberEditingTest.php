<?php
/**
 * Exact decimal field-bound validation.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);
namespace Noteware\AdminTables\Tests;

use InvalidArgumentException;
use Noteware\AdminTables\Editing\AcfNumberBounds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcfNumberEditingTest extends TestCase
{
    #[DataProvider('bounds')]
    public function testBounds(string $value, array $field, bool $allowed): void
    {
        if (! $allowed) {
            $this->expectException(InvalidArgumentException::class);
        }
        AcfNumberBounds::validate($value, $field);
        self::assertTrue($allowed);
    }

    public static function bounds(): iterable
    {
        yield 'zero minimum' => array('0', array('min' => '0'), true);
        yield 'negative zero' => array('-0.000', array('min' => '0'), true);
        yield 'below zero' => array('-0.1', array('min' => '0'), false);
        yield 'decimal boundary' => array('1.20', array('max' => '1.2'), true);
        yield 'precise too large' => array('9999999999999999999999999.0000000000000000002', array('max' => '9999999999999999999999999.0000000000000000001'), false);
        yield 'negative decimal' => array('-10.2', array('min' => '-10.3', 'max' => '-10.1'), true);
        yield 'bad bound fails closed' => array('1', array('max' => 'INF'), false);
        yield 'array bound fails closed' => array('1', array('min' => array()), false);
    }
}
