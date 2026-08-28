<?php
/**
 * Escaped column value and editor rendering.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Screen;

use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Model\StoredValue;

final class ColumnRenderer
{
    /**
     * The cell markup, already reduced to a fixed tag allowlist.
     *
     * Both the rendered page and the edit responses use this, so a cell can
     * never carry markup the screen would not have rendered itself.
     */
    public function safeValue(ColumnDefinition $column, StoredValue $stored): string
    {
        return wp_kses($this->value($column, $stored), self::allowedValueHtml());
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public static function allowedValueHtml(): array
    {
        return array(
            'span' => array('class' => true),
            'a'    => array(
                'class'  => true,
                'href'   => true,
                'rel'    => true,
                'target' => true,
            ),
            'img'  => array(
                'class'    => true,
                'src'      => true,
                'srcset'   => true,
                'sizes'    => true,
                'alt'      => true,
                'width'    => true,
                'height'   => true,
                'loading'  => true,
                'decoding' => true,
                'style'    => true,
            ),
        );
    }

    public function value(ColumnDefinition $column, StoredValue $stored): string
    {
        if (! $stored->exists) {
            return '<span class="nat-empty">' . esc_html($column->emptyLabel) . '</span>';
        }

        if ('image' === $column->type) {
            return $this->imageMarkup($column, $stored);
        }

        if ('url' === $column->type) {
            return $this->linkMarkup($column, $stored);
        }

        $display = $this->plainText($column, $stored);

        if ('' === $display) {
            return '<span class="nat-empty">' . esc_html__('Empty', 'noteware-admin-tables') . '</span>';
        }
        return esc_html($display);
    }

    public function text(ColumnDefinition $column, StoredValue $stored): string
    {
        if (! $stored->exists) {
            return $column->emptyLabel;
        }
        return $this->plainText($column, $stored);
    }

    /**
     * @param array<array-key, string>|null $choices   Allowed editor choices, or null to fall back to the configured list.
     * @param bool                  $removable Whether the adapter can clear the value.
     */
    public function editor(
        int $postId,
        ColumnDefinition $column,
        StoredValue $stored,
        ?array $choices = null,
        bool $removable = true
    ): string {
        if (! $column->editable) {
            return '';
        }

        // The list screen renders inside the WordPress filter form, so editor
        // controls carry data-field instead of name. A name would serialize
        // every open and closed editor into the filter URL.
        $value = $this->editableValue($stored);
        $id    = 'nat-edit-' . $postId . '-' . $column->key;
        $html  = '<button type="button" class="button-link nat-edit-button" aria-expanded="false" aria-controls="' . esc_attr($id) . '">';
        $html .= esc_html__('Edit', 'noteware-admin-tables') . '</button>';
        $html .= '<div id="' . esc_attr($id) . '" class="nat-inline-editor" hidden>';
        $html .= '<label class="screen-reader-text" for="' . esc_attr($id . '-value') . '">' . esc_html(sprintf(__('Edit %s', 'noteware-admin-tables'), $column->label)) . '</label>';
        $html .= $this->control($id . '-value', $column, $value, $choices);

        if ($removable) {
            $html .= '<label class="nat-remove"><input type="checkbox" data-field="remove" value="1"> ' . esc_html__('Remove stored value', 'noteware-admin-tables') . '</label>';
        }
        $html .= '<input type="hidden" data-field="post_id" value="' . esc_attr((string) $postId) . '">';
        $html .= '<input type="hidden" data-field="column" value="' . esc_attr($column->key) . '">';
        $html .= '<input type="hidden" data-field="nonce" value="' . esc_attr(wp_create_nonce('nat_edit_' . $postId . '_' . $column->key)) . '">';
        $html .= '<input type="hidden" data-field="snapshot" value="' . esc_attr($stored->hash()) . '">';
        $html .= '<button type="button" class="button button-primary button-small nat-save">' . esc_html__('Save', 'noteware-admin-tables') . '</button> ';
        $html .= '<button type="button" class="button button-small nat-cancel">' . esc_html__('Cancel', 'noteware-admin-tables') . '</button>';
        $html .= '</div><span class="nat-status" role="status" aria-live="polite"></span>';
        return $html;
    }

    /**
     * The undo control for an edit that is still reversible after a reload.
     */
    public function undoButton(int $auditId, string $nonce): string
    {
        return '<button type="button" class="button-link nat-undo" data-audit-id="' . esc_attr((string) $auditId)
            . '" data-nonce="' . esc_attr($nonce) . '">' . esc_html__('Undo', 'noteware-admin-tables') . '</button>';
    }

    /**
     * A standalone control used by the bulk editor panel.
     *
     * @param array<array-key, string>|null $choices Allowed editor choices, or null to fall back to the configured list.
     */
    public function bulkControl(ColumnDefinition $column, ?array $choices = null): string
    {
        return $this->control('nat-bulk-value-' . $column->key, $column, '', $choices);
    }

    /**
     * The exact value an editor control should start from.
     */
    public function editableValue(StoredValue $stored): string
    {
        if (! $stored->exists) {
            return '';
        }
        if (is_array($stored->value)) {
            $values = array_values($stored->value);
            return isset($values[0]) && is_string($values[0]) ? $values[0] : '';
        }
        return is_scalar($stored->value) ? (string) $stored->value : '';
    }

    /**
     * @param array<array-key, string>|null $choices Allowed editor choices, or null to fall back to the configured list.
     */
    private function control(string $controlId, ColumnDefinition $column, string $value, ?array $choices): string
    {
        if ('boolean' === $column->type) {
            $choices = array('1' => __('Yes', 'noteware-admin-tables'), '0' => __('No', 'noteware-admin-tables'));
        } elseif ('select' === $column->type && null === $choices) {
            // Null means the caller had no opinion. An empty array means there
            // are genuinely no legal values right now, and offering the stale
            // configured list would advertise options every save rejects.
            $choices = $column->choices;
        }
        $choices = $choices ?? array();

        if (in_array($column->type, array('boolean', 'select'), true)) {
            $html = '<select id="' . esc_attr($controlId) . '" data-field="value">';
            foreach ($choices as $choiceValue => $choiceLabel) {
                $html .= '<option value="' . esc_attr((string) $choiceValue) . '"' . selected($value, (string) $choiceValue, false) . '>' . esc_html($choiceLabel) . '</option>';
            }
            return $html . '</select>';
        }

        if ('image' === $column->type) {
            return '<input id="' . esc_attr($controlId) . '" data-field="value" type="number" min="1" step="1" inputmode="numeric" value="' . esc_attr($value) . '" placeholder="' . esc_attr__('Media library ID', 'noteware-admin-tables') . '">';
        }

        $type = match ($column->type) {
            'number' => 'number',
            'date'   => 'date',
            'url'    => 'url',
            default  => 'text',
        };
        $step = 'number' === $column->type ? ' step="any"' : '';
        return '<input id="' . esc_attr($controlId) . '" data-field="value" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '"' . $step . '>';
    }

    private function plainText(ColumnDefinition $column, StoredValue $stored): string
    {
        if ('taxonomy' === $column->source) {
            return $this->termText($stored);
        }
        return match ($column->type) {
            'boolean' => in_array($stored->value, array(true, 1, '1'), true) ? __('Yes', 'noteware-admin-tables') : __('No', 'noteware-admin-tables'),
            'select'  => $this->selectText($column, $stored),
            default   => is_scalar($stored->value) ? (string) $stored->value : '',
        };
    }

    private function imageMarkup(ColumnDefinition $column, StoredValue $stored): string
    {
        $attachmentId = is_array($stored->value) ? (int) ($stored->value['ID'] ?? $stored->value['id'] ?? 0) : (int) $stored->value;
        if ($attachmentId > 0) {
            $alt = sprintf('%s: %s', $column->label, get_the_title($attachmentId));
            $markup = wp_get_attachment_image($attachmentId, array(48, 48), false, array('class' => 'nat-thumbnail', 'alt' => $alt));
            if ($markup) {
                return $markup;
            }
        }
        if (is_string($stored->value) && filter_var($stored->value, FILTER_VALIDATE_URL)) {
            return '<img class="nat-thumbnail" src="' . esc_url($stored->value) . '" alt="' . esc_attr($column->label) . '">';
        }
        return '<span class="nat-empty">' . esc_html($column->emptyLabel) . '</span>';
    }

    private function linkMarkup(ColumnDefinition $column, StoredValue $stored): string
    {
        $raw = is_scalar($stored->value) ? (string) $stored->value : '';
        if ('' === $raw) {
            return '<span class="nat-empty">' . esc_html__('Empty', 'noteware-admin-tables') . '</span>';
        }
        $safe = esc_url($raw, array('http', 'https'));
        if ('' === $safe) {
            return '<span class="nat-empty">' . esc_html__('Blocked link', 'noteware-admin-tables') . '</span>';
        }
        return '<a class="nat-link" href="' . $safe . '" rel="noopener nofollow external" target="_blank">'
            . esc_html($raw)
            . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'noteware-admin-tables') . '</span></a>';
    }

    private function termText(StoredValue $stored): string
    {
        if (null !== $stored->displayLabel) {
            return $stored->displayLabel;
        }
        if (! is_array($stored->value)) {
            return '';
        }
        $slugs = array();
        foreach ($stored->value as $slug) {
            if (is_string($slug)) {
                $slugs[] = $slug;
            }
        }
        return implode(', ', $slugs);
    }

    private function selectText(ColumnDefinition $column, StoredValue $stored): string
    {
        if (! is_scalar($stored->value)) {
            return '';
        }
        return $stored->displayLabel ?? $column->choices[(string) $stored->value] ?? (string) $stored->value;
    }
}
