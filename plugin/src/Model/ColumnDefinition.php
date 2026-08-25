<?php
/**
 * Validated column configuration.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Model;

use InvalidArgumentException;

final class ColumnDefinition
{
    private const SOURCES = array('native', 'meta', 'acf');
    private const TYPES   = array('text', 'number', 'boolean', 'select', 'date', 'image');

    /**
     * @param array<string, string> $choices Choice value to label map.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $source,
        public readonly string $type,
        public readonly string $field,
        public readonly ?string $fieldKey,
        public readonly bool $sortable,
        public readonly bool $filterable,
        public readonly bool $editable,
        public readonly array $choices,
        public readonly string $emptyLabel
    ) {
        if (! preg_match('/^[a-z][a-z0-9_-]*$/', $key)) {
            throw new InvalidArgumentException('Column keys must use lowercase letters, numbers, underscores, or hyphens.');
        }
        if (! in_array($source, self::SOURCES, true) || ! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported column source or type.');
        }
        if ('' === $label || '' === $field) {
            throw new InvalidArgumentException('Column labels and fields cannot be empty.');
        }
        if (strlen($label) > 200 || strlen($field) > 191 || ! preg_match('/^[A-Za-z0-9_:-]+$/', $field)) {
            throw new InvalidArgumentException('Column labels or field identifiers are too long or malformed.');
        }
        if (null !== $fieldKey && ! preg_match('/^field_[A-Za-z0-9_]+$/', $fieldKey)) {
            throw new InvalidArgumentException('ACF field keys must use the documented field_key format.');
        }
        if ('acf' === $source && null === $fieldKey) {
            throw new InvalidArgumentException('ACF display columns require a field_key so the adapter can verify the field type.');
        }
        if ('native' === $source) {
            $nativeFields = array('id', 'title', 'author', 'date', 'status');
            if (! in_array($field, $nativeFields, true)) {
                throw new InvalidArgumentException('Unsupported native field.');
            }
            if ($sortable && ! in_array($field, array('id', 'title', 'author', 'date'), true)) {
                throw new InvalidArgumentException('This native field does not support sorting.');
            }
            if ($filterable && ! in_array($field, array('author', 'status'), true)) {
                throw new InvalidArgumentException('This native field does not support filtering.');
            }
        }
        if ($editable && ('meta' !== $source || ! in_array($type, array('text', 'number', 'boolean', 'select', 'date'), true))) {
            throw new InvalidArgumentException('Only allowlisted scalar WordPress metadata fields are editable.');
        }
        if ('image' === $type && $filterable) {
            throw new InvalidArgumentException('Image columns do not support filtering.');
        }
        if ('select' === $type && ($filterable || $editable) && ! $choices) {
            throw new InvalidArgumentException('Filterable or editable select columns require at least one configured choice.');
        }
    }

    /**
     * @param array<string, mixed> $data Raw site configuration.
     */
    public static function fromArray(array $data): self
    {
        $allowed = array('key', 'label', 'source', 'type', 'field', 'field_key', 'sortable', 'filterable', 'editable', 'choices', 'empty_label');
        if (array_diff(array_keys($data), $allowed)) {
            throw new InvalidArgumentException('Column definitions contain an unknown option.');
        }
        foreach (array('key', 'label', 'source', 'type', 'field') as $required) {
            if (! isset($data[$required]) || ! is_string($data[$required])) {
                throw new InvalidArgumentException('Column definitions require scalar string identifiers.');
            }
        }
        foreach (array('sortable', 'filterable', 'editable') as $flag) {
            if (isset($data[$flag]) && ! is_bool($data[$flag])) {
                throw new InvalidArgumentException('Column behavior flags must be boolean values.');
            }
        }
        if (isset($data['choices']) && ! is_array($data['choices'])) {
            throw new InvalidArgumentException('Column choices must be an array.');
        }
        if (isset($data['field_key']) && ! is_string($data['field_key'])) {
            throw new InvalidArgumentException('ACF field keys must be strings.');
        }
        if (isset($data['empty_label']) && ! is_string($data['empty_label'])) {
            throw new InvalidArgumentException('Empty labels must be strings.');
        }

        $choices = array();
        foreach ((array) ($data['choices'] ?? array()) as $value => $label) {
            if (! is_string($label) || strlen((string) $value) > 191 || strlen($label) > 200) {
                throw new InvalidArgumentException('Choice values and labels must be bounded scalar strings.');
            }
            $choices[(string) $value] = $label;
        }
        if (count($choices) > 200) {
            throw new InvalidArgumentException('A column cannot define more than 200 choices.');
        }

        $filterable = (bool) ($data['filterable'] ?? false);
        $editable   = (bool) ($data['editable'] ?? false);

        return new self(
            (string) ($data['key'] ?? ''),
            (string) ($data['label'] ?? ''),
            (string) ($data['source'] ?? ''),
            (string) ($data['type'] ?? 'text'),
            (string) ($data['field'] ?? ''),
            isset($data['field_key']) && is_string($data['field_key']) ? $data['field_key'] : null,
            (bool) ($data['sortable'] ?? false),
            $filterable,
            $editable,
            $choices,
            (string) ($data['empty_label'] ?? 'Not set')
        );
    }
}
