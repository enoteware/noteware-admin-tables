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
    public function value(ColumnDefinition $column, StoredValue $stored): string
    {
        if (! $stored->exists) {
            return '<span class="nat-empty">' . esc_html($column->emptyLabel) . '</span>';
        }

        if ('image' === $column->type) {
            $attachmentId = is_array($stored->value) ? (int) ($stored->value['ID'] ?? $stored->value['id'] ?? 0) : (int) $stored->value;
            if ($attachmentId > 0) {
                $alt = sprintf('%s: %s', $column->label, get_the_title($attachmentId));
                return wp_get_attachment_image($attachmentId, array(48, 48), false, array('class' => 'nat-thumbnail', 'alt' => $alt)) ?: '<span class="nat-empty">' . esc_html($column->emptyLabel) . '</span>';
            }
            if (is_string($stored->value) && filter_var($stored->value, FILTER_VALIDATE_URL)) {
                return '<img class="nat-thumbnail" src="' . esc_url($stored->value) . '" alt="' . esc_attr($column->label) . '">';
            }
        }

        $display = match ($column->type) {
            'boolean' => in_array($stored->value, array(true, 1, '1'), true) ? __('Yes', 'noteware-admin-tables') : __('No', 'noteware-admin-tables'),
            'select'  => $stored->displayLabel ?? $column->choices[(string) $stored->value] ?? (string) $stored->value,
            default   => is_scalar($stored->value) ? (string) $stored->value : (wp_json_encode($stored->value) ?: ''),
        };

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
        return match ($column->type) {
            'boolean' => in_array($stored->value, array(true, 1, '1'), true) ? __('Yes', 'noteware-admin-tables') : __('No', 'noteware-admin-tables'),
            'select'  => $stored->displayLabel ?? $column->choices[(string) $stored->value] ?? (string) $stored->value,
            default   => is_scalar($stored->value) ? (string) $stored->value : '',
        };
    }

    public function editor(int $postId, ColumnDefinition $column, StoredValue $stored): string
    {
        if (! $column->editable || ! current_user_can('edit_post', $postId) || ! current_user_can('edit_post_meta', $postId, $column->field)) {
            return '';
        }

        $value = $stored->exists && is_scalar($stored->value) ? (string) $stored->value : '';
        $id    = 'nat-edit-' . $postId . '-' . $column->key;
        $html  = '<button type="button" class="button-link nat-edit-button" aria-expanded="false" aria-controls="' . esc_attr($id) . '">';
        $html .= esc_html__('Edit', 'noteware-admin-tables') . '</button>';
        $html .= '<div id="' . esc_attr($id) . '" class="nat-inline-editor" hidden>';
        $html .= '<label class="screen-reader-text" for="' . esc_attr($id . '-value') . '">' . esc_html(sprintf(__('Edit %s', 'noteware-admin-tables'), $column->label)) . '</label>';

        if ('boolean' === $column->type || 'select' === $column->type) {
            $choices = 'boolean' === $column->type ? array('1' => __('Yes', 'noteware-admin-tables'), '0' => __('No', 'noteware-admin-tables')) : $column->choices;
            $html   .= '<select id="' . esc_attr($id . '-value') . '" name="value">';
            foreach ($choices as $choiceValue => $choiceLabel) {
                $html .= '<option value="' . esc_attr((string) $choiceValue) . '"' . selected($value, (string) $choiceValue, false) . '>' . esc_html($choiceLabel) . '</option>';
            }
            $html .= '</select>';
        } else {
            $type  = 'number' === $column->type ? 'number' : ('date' === $column->type ? 'date' : 'text');
            $step  = 'number' === $column->type ? ' step="any"' : '';
            $html .= '<input id="' . esc_attr($id . '-value') . '" name="value" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '"' . $step . '>';
        }

        $html .= '<label class="nat-remove"><input type="checkbox" name="remove" value="1"> ' . esc_html__('Remove stored value', 'noteware-admin-tables') . '</label>';
        $html .= '<input type="hidden" name="post_id" value="' . esc_attr((string) $postId) . '">';
        $html .= '<input type="hidden" name="column" value="' . esc_attr($column->key) . '">';
        $html .= '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('nat_edit_' . $postId . '_' . $column->key)) . '">';
        $html .= '<input type="hidden" name="snapshot" value="' . esc_attr($stored->hash()) . '">';
        $html .= '<button type="button" class="button button-primary button-small nat-save">' . esc_html__('Save', 'noteware-admin-tables') . '</button> ';
        $html .= '<button type="button" class="button button-small nat-cancel">' . esc_html__('Cancel', 'noteware-admin-tables') . '</button>';
        $html .= '</div><span class="nat-status" role="status" aria-live="polite"></span>';
        return $html;
    }
}
