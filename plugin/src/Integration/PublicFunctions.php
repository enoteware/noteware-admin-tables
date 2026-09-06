<?php
/**
 * Small public-function boundary for optional plugin integrations.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Integration;

use InvalidArgumentException;
use RuntimeException;

class PublicFunctions
{
    private const ALLOWED = array('rwmb_get_field_settings', 'rwmb_get_value', 'YoastSEO');

    public function available(string $function): bool
    {
        return in_array($function, self::ALLOWED, true) && function_exists($function);
    }

    /** @param list<mixed> $arguments Typed arguments supplied by an integration reader. */
    public function call(string $function, array $arguments): mixed
    {
        if (! in_array($function, self::ALLOWED, true)) {
            throw new InvalidArgumentException('This public API is not allowlisted.');
        }
        // @phpstan-ignore function.impossibleType (Optional plugin functions are absent from the core-only analysis environment.)
        if (! $this->available($function) || ! is_callable($function)) {
            throw new RuntimeException('The required integration API is unavailable.');
        }
        return $function(...$arguments);
    }
}
