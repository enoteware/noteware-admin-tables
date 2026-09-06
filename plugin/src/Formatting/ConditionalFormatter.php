<?php
/**
 * Deterministic formatting resolution for current-user rules and cell previews.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Formatting;

use InvalidArgumentException;
use Noteware\AdminTables\Model\StoredValue;

final class ConditionalFormatter
{
    /**
     * No arbitrary CSS or HTML is accepted. The renderer must keep its text labels.
     *
     * @param list<FormattingRule> $rules Authorized rule objects.
     * @return array{rule: string, tone: string, foreground: string, background: string}|null
     */
    public static function resolve(string $column, string $type, StoredValue $value, array $rules, bool $dark = false): ?array
    {
        if (count($rules) > 100) {
            throw new InvalidArgumentException('At most one hundred formatting rules may run together.');
        }
        usort($rules, static fn (FormattingRule $left, FormattingRule $right): int => ($right->priority <=> $left->priority) ?: strcmp($left->id, $right->id));
        $userId = get_current_user_id();
        foreach ($rules as $rule) {
            if ($rule->column !== $column || $rule->type !== $type || (null !== $rule->owner && $rule->owner !== $userId) || ! $rule->matches($value)) {
                continue;
            }
            return array_merge(array('rule' => $rule->id, 'tone' => $rule->tone), self::palette($rule->tone, $dark));
        }
        return null;
    }

    /** @return array{foreground: string, background: string} */
    public static function palette(string $tone, bool $dark): array
    {
        $light = array('notice' => '#fef3c7', 'success' => '#dcfce7', 'danger' => '#fee2e2');
        $night = array('notice' => '#422006', 'success' => '#052e16', 'danger' => '#450a0a');
        if (! isset($light[$tone])) {
            throw new InvalidArgumentException('Unknown formatting tone.');
        }
        return array('foreground' => $dark ? '#ffffff' : '#111827', 'background' => $dark ? $night[$tone] : $light[$tone]);
    }
}
