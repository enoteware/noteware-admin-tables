<?php
/**
 * Unit-test bootstrap.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var array<string, list<array<int, mixed>>> $nat_test_actions */
$GLOBALS['nat_test_actions'] = array();
$GLOBALS['nat_test_registered_actions'] = array();

if (! function_exists('add_action')) {
    function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $GLOBALS['nat_test_registered_actions'][$hook_name][] = array($callback, $priority, $accepted_args);
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $GLOBALS['nat_test_registered_actions'][$hook_name][] = array($callback, $priority, $accepted_args);
    }
}

if (! function_exists('do_action')) {
    /**
     * Record a WordPress-style action for isolated unit tests.
     *
     * @param string $hook_name Action name.
     * @param mixed  ...$args  Action arguments.
     */
    function do_action(string $hook_name, mixed ...$args): void
    {
        $GLOBALS['nat_test_actions'][$hook_name][] = $args;
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}
