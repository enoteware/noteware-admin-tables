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
    private const SOURCES = array('native', 'meta', 'acf', 'taxonomy');

    private const TYPES = array('text', 'number', 'boolean', 'select', 'date', 'image', 'url');

    private const OPERATORS = array('is', 'empty', 'not_empty');

    /** @var array<string, list<string>> */
    private const NATIVE_TYPES = array(
        'id'             => array('number', 'text'),
        'title'          => array('text'),
        'slug'           => array('text'),
        'author'         => array('text', 'number'),
        'date'           => array('date', 'text'),
        'status'         => array('text', 'select'),
        'word_count'     => array('number', 'text'),
        'featured_image' => array('image'),
        'permalink'      => array('url', 'text'),
    );

    private const NATIVE_SORTABLE = array('id', 'title', 'slug', 'author', 'date');

    private const NATIVE_FILTERABLE = array('author', 'status');

    private const NATIVE_EDITABLE = array('title', 'slug', 'featured_image');

    private const ACF_EDITABLE_TYPES = array('text', 'url', 'select');

    private const META_EDITABLE_TYPES = array('text', 'number', 'boolean', 'select', 'date', 'url');

    /**
     * @param array<string, string> $choices   Choice value to label map.
     * @param list<string>          $operators Enabled filter operators.
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
        public readonly string $emptyLabel,
        public readonly ?string $width = null,
        public readonly bool $bulkEditable = false,
        public readonly array $operators = array('is'),
        public readonly ?string $replaces = null
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
        if ('acf' !== $source && null !== $fieldKey) {
            throw new InvalidArgumentException('Only ACF columns may declare a field_key.');
        }
        if (null !== $width && ! preg_match('/^[1-9][0-9]{0,3}(?:px|%|em|rem|ch)$/', $width)) {
            throw new InvalidArgumentException('Column widths must be a bounded CSS length such as 120px or 12%.');
        }
        if (null !== $replaces && (! preg_match('/^[a-z][a-z0-9_-]*$/', $replaces) || 'cb' === $replaces)) {
            throw new InvalidArgumentException('A replaced built-in column must be a normal column key and cannot be the checkbox column.');
        }

        $this->assertOperators();
        $this->assertSourceRules();

        if ($editable && $bulkEditable && 'image' === $type) {
            throw new InvalidArgumentException('Image columns cannot be bulk edited.');
        }
        if ($bulkEditable && ! $editable) {
            throw new InvalidArgumentException('Only editable columns can be bulk edited.');
        }
        if ('image' === $type && $filterable) {
            throw new InvalidArgumentException('Image columns do not support filtering.');
        }
        if ('select' === $type && ($filterable || $editable) && ! $choices && 'taxonomy' !== $source) {
            throw new InvalidArgumentException('Filterable or editable select columns require at least one configured choice.');
        }
        if ('select' === $type && $filterable && array_key_exists('', $choices)) {
            throw new InvalidArgumentException('Filterable select columns cannot use an empty choice value.');
        }
    }

    public function supportsOperator(string $operator): bool
    {
        return $this->filterable && in_array($operator, $this->operators, true);
    }

    public function defaultOperator(): string
    {
        return $this->operators[0] ?? 'is';
    }

    private function assertOperators(): void
    {
        if (! $this->operators) {
            throw new InvalidArgumentException('Filterable columns must enable at least one filter operator.');
        }
        if (count($this->operators) !== count(array_unique($this->operators))) {
            throw new InvalidArgumentException('Filter operators must be unique.');
        }
        foreach ($this->operators as $operator) {
            if (! in_array($operator, self::OPERATORS, true)) {
                throw new InvalidArgumentException('Unsupported filter operator.');
            }
        }
        if (! $this->filterable && array('is') !== $this->operators) {
            throw new InvalidArgumentException('Filter operators only apply to filterable columns.');
        }
        if ('native' === $this->source && $this->filterable && array('is') !== $this->operators) {
            throw new InvalidArgumentException('Native columns only support the exact filter operator.');
        }
    }

    private function assertSourceRules(): void
    {
        if ('native' === $this->source) {
            $this->assertNativeRules();
            return;
        }
        if ('taxonomy' === $this->source) {
            $this->assertTaxonomyRules();
            return;
        }
        if ('acf' === $this->source && $this->editable && ! in_array($this->type, self::ACF_EDITABLE_TYPES, true)) {
            throw new InvalidArgumentException('This ACF field type is not editable.');
        }
        if ('meta' === $this->source && $this->editable && ! in_array($this->type, self::META_EDITABLE_TYPES, true)) {
            throw new InvalidArgumentException('Only allowlisted scalar WordPress metadata fields are editable.');
        }
    }

    private function assertNativeRules(): void
    {
        if (! isset(self::NATIVE_TYPES[$this->field])) {
            throw new InvalidArgumentException('Unsupported native field.');
        }
        if (! in_array($this->type, self::NATIVE_TYPES[$this->field], true)) {
            throw new InvalidArgumentException('This native field does not support the configured column type.');
        }
        if ($this->sortable && ! in_array($this->field, self::NATIVE_SORTABLE, true)) {
            throw new InvalidArgumentException('This native field does not support sorting.');
        }
        if ($this->filterable && ! in_array($this->field, self::NATIVE_FILTERABLE, true)) {
            throw new InvalidArgumentException('This native field does not support filtering.');
        }
        if ($this->editable && ! in_array($this->field, self::NATIVE_EDITABLE, true)) {
            throw new InvalidArgumentException('This native field is not editable.');
        }
    }

    private function assertTaxonomyRules(): void
    {
        if ('select' !== $this->type) {
            throw new InvalidArgumentException('Taxonomy columns must use the select column type.');
        }
        if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $this->field)) {
            throw new InvalidArgumentException('Taxonomy columns must name a registered taxonomy.');
        }
        if ($this->sortable) {
            throw new InvalidArgumentException('Taxonomy columns do not support sorting.');
        }
    }

    /**
     * @param array<string, mixed> $data Raw site configuration.
     */
    public static function fromArray(array $data): self
    {
        $allowed = array(
            'key',
            'label',
            'source',
            'type',
            'field',
            'field_key',
            'sortable',
            'filterable',
            'editable',
            'choices',
            'empty_label',
            'width',
            'bulk_editable',
            'operators',
            'replaces',
        );
        if (array_diff(array_keys($data), $allowed)) {
            throw new InvalidArgumentException('Column definitions contain an unknown option.');
        }
        foreach (array('key', 'label', 'source', 'type', 'field') as $required) {
            if (! isset($data[$required]) || ! is_string($data[$required])) {
                throw new InvalidArgumentException('Column definitions require scalar string identifiers.');
            }
        }
        foreach (array('sortable', 'filterable', 'editable', 'bulk_editable') as $flag) {
            if (isset($data[$flag]) && ! is_bool($data[$flag])) {
                throw new InvalidArgumentException('Column behavior flags must be boolean values.');
            }
        }
        if (isset($data['choices']) && ! is_array($data['choices'])) {
            throw new InvalidArgumentException('Column choices must be an array.');
        }
        foreach (array('field_key', 'empty_label', 'width', 'replaces') as $text) {
            if (isset($data[$text]) && ! is_string($data[$text])) {
                throw new InvalidArgumentException('Column text options must be strings.');
            }
        }
        if (isset($data['operators']) && ! is_array($data['operators'])) {
            throw new InvalidArgumentException('Column operators must be an array.');
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

        $operators = array();
        foreach ((array) ($data['operators'] ?? array('is')) as $operator) {
            if (! is_string($operator)) {
                throw new InvalidArgumentException('Filter operators must be strings.');
            }
            $operators[] = $operator;
        }

        return new self(
            (string) $data['key'],
            (string) $data['label'],
            (string) $data['source'],
            (string) $data['type'],
            (string) $data['field'],
            isset($data['field_key']) && is_string($data['field_key']) ? $data['field_key'] : null,
            (bool) ($data['sortable'] ?? false),
            (bool) ($data['filterable'] ?? false),
            (bool) ($data['editable'] ?? false),
            $choices,
            isset($data['empty_label']) && is_string($data['empty_label']) ? $data['empty_label'] : 'Not set',
            isset($data['width']) && is_string($data['width']) ? $data['width'] : null,
            (bool) ($data['bulk_editable'] ?? false),
            $operators,
            isset($data['replaces']) && is_string($data['replaces']) ? $data['replaces'] : null
        );
    }
}
