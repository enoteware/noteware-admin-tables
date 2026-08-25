<?php
/**
 * Authenticated inline edit and conditional undo endpoints.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Editing;

use InvalidArgumentException;
use RuntimeException;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Audit\AuditRepository;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Contract\EditableFieldAdapter;
use Noteware\AdminTables\Model\StoredValue;
use Noteware\AdminTables\Screen\ColumnRenderer;
use Throwable;

final class EditController
{
    private readonly ColumnRenderer $renderer;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly AdapterRegistry $adapters,
        private readonly AuditRepository $audit
    ) {
        $this->renderer = new ColumnRenderer();
    }

    public function register(): void
    {
        add_action('wp_ajax_nat_inline_edit', array($this, 'edit'));
        add_action('wp_ajax_nat_undo_edit', array($this, 'undo'));
    }

    public function edit(): void
    {
        try {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- processEdit verifies the request-specific nonce before any write.
            wp_send_json_success($this->processEdit($_POST));
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $this->safeError($error)), 400);
        }
    }

    public function undo(): void
    {
        try {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- processUndo verifies the request-specific nonce before any write.
            wp_send_json_success($this->processUndo($_POST));
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $this->safeError($error)), 400);
        }
    }

    /**
     * Execute a validated edit request without terminating the PHP process.
     *
     * @param  array<string, mixed> $request Raw request data.
     * @return array<string, mixed>
     */
    public function processEdit(array $request): array
    {
        $postId   = $this->positiveInt($this->requestValue($request, 'post_id'));
        $key      = $this->requestValue($request, 'column');
        $nonce    = $this->requestValue($request, 'nonce');
        $snapshot = $this->requestValue($request, 'snapshot');
        $postType = get_post_type($postId);
        if (! is_string($postType)) {
            throw new InvalidArgumentException('The post does not exist.');
        }
        $column = $this->configuration->column($postType, $key);
        if (! $column || ! $column->editable) {
            throw new InvalidArgumentException('This field is not editable.');
        }
        $adapter = $this->adapters->get($column->source);
        if (! $adapter instanceof EditableFieldAdapter) {
            throw new InvalidArgumentException('This field adapter is read-only.');
        }
        if (! wp_verify_nonce($nonce, $adapter->nonceAction('edit', $postId, $column))) {
            throw new InvalidArgumentException('The edit link expired. Refresh the page and try again.');
        }
        $adapter->authorize($postId, $column);
        $remove = '1' === $this->requestValue($request, 'remove', true);

        $this->audit->begin();
        try {
            $adapter->lock($postId, $column);
            $before = $adapter->read($postId, $column);
            if (! preg_match('/^[a-f0-9]{64}$/', $snapshot) || ! hash_equals($before->hash(), $snapshot)) {
                throw new InvalidArgumentException('The value changed after this editor opened. Refresh the page and try again.');
            }
            if ($remove) {
                $adapter->remove($postId, $column, $before);
            } else {
                $validated = $adapter->validate($column, $this->requestValue($request, 'value'));
                $value     = $adapter->sanitize($column, $validated);
                $adapter->write($postId, $column, $value, $before);
            }
            $after   = $adapter->read($postId, $column);
            $auditId = $this->audit->record($postId, $postType, $adapter->auditDescriptor($column), $before, $after);
            $this->audit->commit();
        } catch (Throwable $error) {
            $this->rollbackAfterFailure($postId, $error);
        }

        return array(
            'text'       => $this->renderer->text($column, $after),
            'auditId'    => $auditId,
            'undoNonce'  => wp_create_nonce($adapter->nonceAction('undo', $auditId, $column)),
            'undoLabel'  => __('Undo', 'noteware-admin-tables'),
            'savedLabel' => __('Saved.', 'noteware-admin-tables'),
            'snapshot'   => $after->hash(),
            'value'      => $this->editableValue($after),
            'exists'     => $after->exists,
        );
    }

    /**
     * Execute a validated undo request without terminating the PHP process.
     *
     * @param  array<string, mixed> $request Raw request data.
     * @return array<string, mixed>
     */
    public function processUndo(array $request): array
    {
        $auditId = $this->positiveInt($this->requestValue($request, 'audit_id'));
        $nonce   = $this->requestValue($request, 'nonce');
        $undoAction = 'nat_undo_' . $auditId;
        if (! wp_verify_nonce($nonce, $undoAction)) {
            throw new InvalidArgumentException('The undo link expired. Refresh the page and try again.');
        }
        $row = $this->audit->find($auditId);
        if (! $row || ! empty($row['undone_at'])) {
            throw new InvalidArgumentException('This edit cannot be undone.');
        }

        $postId   = (int) $row['post_id'];
        $postType = get_post_type($postId);
        if (! is_string($postType) || $postType !== $row['post_type']) {
            throw new InvalidArgumentException('The edited post no longer exists.');
        }
        $column = $this->configuration->column($postType, (string) $row['column_key']);
        if (! $column || ! $column->editable || $column->source !== $row['source'] || $column->field !== $row['field_name']) {
            throw new InvalidArgumentException('The field configuration changed, so this edit cannot be undone.');
        }
        $adapter = $this->adapters->get($column->source);
        if (! $adapter instanceof EditableFieldAdapter) {
            throw new InvalidArgumentException('This field adapter is read-only.');
        }
        if ($undoAction !== $adapter->nonceAction('undo', $auditId, $column)) {
            throw new InvalidArgumentException('The field adapter returned an invalid undo action.');
        }
        $adapter->authorize($postId, $column);
        $expected = $this->decodeStored((string) $row['after_value']);
        $target   = $this->decodeStored((string) $row['before_value']);

        $this->audit->begin();
        try {
            $adapter->lock($postId, $column);
            $current = $adapter->read($postId, $column);
            if (! $current->equals($expected)) {
                throw new InvalidArgumentException('The value changed after this edit. Undo stopped to protect the newer value.');
            }
            $adapter->restore($postId, $column, $current, $target);
            $restored    = $adapter->read($postId, $column);
            $undoAuditId = $this->audit->record($postId, $postType, $adapter->auditDescriptor($column), $current, $restored);
            if (! $this->audit->markUndone($auditId, $undoAuditId)) {
                throw new InvalidArgumentException('This edit was already undone.');
            }
            $this->audit->commit();
        } catch (Throwable $error) {
            $this->rollbackAfterFailure($postId, $error);
        }

        return array(
            'text'     => $this->renderer->text($column, $restored),
            'message'  => __('Edit undone.', 'noteware-admin-tables'),
            'snapshot' => $restored->hash(),
            'value'    => $this->editableValue($restored),
            'exists'   => $restored->exists,
        );
    }

    private function positiveInt(mixed $value): int
    {
        if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value < 1) {
            throw new InvalidArgumentException('A valid numeric identifier is required.');
        }
        return (int) $value;
    }

    /** @param array<string, mixed> $request Raw request data. */
    private function requestValue(array $request, string $key, bool $optional = false): string
    {
        if (! isset($request[$key]) || ! is_string($request[$key])) {
            if ($optional) {
                return '';
            }
            throw new InvalidArgumentException('The request contains an invalid value.');
        }
        return sanitize_text_field(wp_unslash($request[$key]));
    }

    private function decodeStored(string $json): StoredValue
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_key_exists('exists', $decoded) || ! array_key_exists('value', $decoded)) {
            throw new InvalidArgumentException('The audit snapshot is invalid.');
        }
        return new StoredValue((bool) $decoded['exists'], $decoded['value']);
    }

    private function editableValue(StoredValue $stored): string
    {
        return $stored->exists && is_scalar($stored->value) ? (string) $stored->value : '';
    }

    private function safeError(Throwable $error): string
    {
        if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
            return $error->getMessage();
        }
        return __('The edit could not be completed. Refresh the page and try again.', 'noteware-admin-tables');
    }

    private function rollbackAfterFailure(int $postId, Throwable $original): never
    {
        $rollback = null;
        try {
            $this->audit->rollback();
        } catch (Throwable $error) {
            $rollback = $error;
        } finally {
            wp_cache_delete($postId, 'post_meta');
        }
        if ($rollback) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception is chained, not rendered.
            throw new RuntimeException('The failed edit could not be rolled back safely.', 0, $rollback);
        }
        throw $original;
    }
}
