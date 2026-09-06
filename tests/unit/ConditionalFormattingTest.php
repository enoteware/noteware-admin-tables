<?php
/**
 * Formatting permission, priority, typed state and contrast tests.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Formatting {
    function get_current_user_id(): int
    {
        return $GLOBALS['nat_formatting_user'];
    }
    function current_user_can(string $capability): bool
    {
        return in_array($capability, $GLOBALS['nat_formatting_caps'], true);
    }
}

namespace Noteware\AdminTables\Tests {
    use Noteware\AdminTables\Formatting\ConditionalFormatter;
    use Noteware\AdminTables\Formatting\FormattingRule;
    use Noteware\AdminTables\Model\StoredValue;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class ConditionalFormattingTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['nat_formatting_user'] = 1;
            $GLOBALS['nat_formatting_caps'] = array('read');
        }

        private function rule(string $id = 'one', int $priority = 0, bool $shared = false): FormattingRule
        {
            return FormattingRule::create(array('id' => $id, 'column' => 'amount', 'type' => 'number', 'operator' => 'is', 'value' => '0', 'tone' => 'notice', 'priority' => $priority), $shared);
        }

        public function testPrivateRulesAreHiddenFromOtherUsers(): void
        {
            $rule = $this->rule();
            $GLOBALS['nat_formatting_user'] = 2;
            self::assertNull(ConditionalFormatter::resolve('amount', 'number', new StoredValue(true, '0'), array($rule)));
        }

        public function testSharedRulesRequirePermission(): void
        {
            $this->expectException(RuntimeException::class);
            $this->rule('shared', 0, true);
        }

        public function testPriorityAndTieAreDeterministic(): void
        {
            $result = ConditionalFormatter::resolve('amount', 'number', new StoredValue(true, '0'), array($this->rule('z', 10), $this->rule('a', 10), $this->rule('b', 0)));
            self::assertSame('a', $result['rule']);
        }

        public function testZeroDoesNotMatchAbsentOrEmpty(): void
        {
            $rule = $this->rule();
            self::assertTrue($rule->matches(new StoredValue(true, '0')));
            self::assertFalse($rule->matches(new StoredValue(false, null)));
            self::assertFalse($rule->matches(new StoredValue(true, '')));
            self::assertFalse($rule->matches(new StoredValue(true, false)));
        }

        public function testAllPalettesExceedNormalTextContrastInBothModes(): void
        {
            foreach (array(false, true) as $dark) {
                foreach (array('notice', 'success', 'danger') as $tone) {
                    $palette = ConditionalFormatter::palette($tone, $dark);
                    $first = $this->luminance($palette['foreground']);
                    $second = $this->luminance($palette['background']);
                    self::assertGreaterThanOrEqual(4.5, (max($first, $second) + 0.05) / (min($first, $second) + 0.05));
                }
            }
        }

        private function luminance(string $hex): float
        {
            $parts = array();
            foreach (array(1, 3, 5) as $offset) {
                $value = hexdec(substr($hex, $offset, 2)) / 255;
                $parts[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }
            return 0.2126 * $parts[0] + 0.7152 * $parts[1] + 0.0722 * $parts[2];
        }
    }
}
