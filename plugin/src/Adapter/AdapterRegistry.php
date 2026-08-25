<?php
/**
 * Field adapter lookup.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Adapter;

use InvalidArgumentException;
use Noteware\AdminTables\Contract\FieldAdapter;

final class AdapterRegistry
{
    /** @var array<string, FieldAdapter> */
    private array $adapters = array();

    /** @param list<FieldAdapter> $adapters Field adapters. */
    public function __construct(array $adapters)
    {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->source()] = $adapter;
        }
    }

    public function get(string $source): FieldAdapter
    {
        if (! isset($this->adapters[$source])) {
            throw new InvalidArgumentException('No field adapter is registered for this source.');
        }
        return $this->adapters[$source];
    }
}
