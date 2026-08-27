<?php
/**
 * Authenticated bulk field editing with one audit record per object.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Editing;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Audit\AuditRepository;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Contract\EditableFieldAdapter;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Screen\ColumnRenderer;

final class BulkEditController
{
    public const MAX_OBJECTS = 100;

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
        add_action('wp_ajax_nat_bulk_edit', array($this, 'edit'));
    }

    public function edit(): void
    {
        try {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- processBulkEdit verifies the screen nonce before any write.
            wp_send_json_success($this->processBulkEdit($_POST));
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $this->safeError($error)), 400);
        }
    }

    /**
     * Apply one validated value to every allowed object, auditing each change separately.
     *
     * @param  array<string, mixed> $request Raw request data.
     * @return array{changed: list<array<string, mixed>>, failed: list<array{postId: int, message: string}>}
     */
    public function processBulkEdit(array $request): array
    {
        $postType = $this->requestValue($request, 'post_type');
        if (! in_array($postType, $this->configuration->postTypes(), true)) {
            throw new InvalidArgumentException('This screen is not configured for bulk editing.');
        }
        if (! wp_verify_nonce($this->requestValue($request, 'nonce'), 'nat_bulk_edit_' . $postType)) {
            throw new InvalidArgumentException('The bulk edit form expired. Refresh the page and try again.');
        }

        $column = $this->configuration->column($postType, $this->requestValue($request, 'column'));
        if (! $column || ! $column->editable || ! $column->bulkEditable) {
            throw new InvalidArgumentException('This field cannot be bulk edited.');
        }
        $adapter = $this->adapters->get($column->source);
        if (! $adapter instanceof EditableFieldAdapter || ! $adapter->supports($column)) {
            throw new InvalidArgumentException('This field adapter cannot bulk edit.');
        }

        $remove = '1' === $this->requestValue($request, 'remove', true);
        if ($remove && ! $adapter->supportsRemoval($column)) {
            throw new InvalidArgumentException('This field cannot be cleared.');
        }

        $value = null;
        if (! $remove) {
            $value = $adapter->sanitize($column, $adapter->validate($column, $this->requestInput($request, 'value')));
        }

        $postIds = $this->postIds($request);

        $changed = array();
        $failed  = array();
        foreach ($postIds as $postId) {
            try {
                $changed[] = $this->applyToPost($adapter, $column, $postType, $postId, $value, $remove);
            } catch (Throwable $error) {
                $failed[] = array(
                    'postId'  => $postId,
                    'message' => $this->safeError($error),
                );
            }
        }

        return array(
            'changed' => $changed,
            'failed'  => $failed,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function applyToPost(
        EditableFieldAdapter $adapter,
        ColumnDefinition $column,
        string $postType,
        int $postId,
        mixed $value,
        bool $remove
    ): array {
        if (get_post_type($postId) !== $postType) {
            throw new InvalidArgumentException('That record is not on this screen.');
        }
        $adapter->authorize($postId, $column);

        $this->audit->begin();
        try {
            $adapter->lock($postId, $column);
            $before = $adapter->read($postId, $column);
            if ($remove) {
                $adapter->remove($postId, $column, $before);
            } else {
                $adapter->write($postId, $column, $value, $before);
            }
            $after   = $adapter->read($postId, $column);
            $auditId = $this->audit->record($postId, $postType, $adapter->auditDescriptor($column), $before, $after);
            $this->audit->commit();
        } catch (Throwable $error) {
            $rollback = null;
            try {
                $this->audit->rollback();
            } catch (Throwable $rollbackError) {
                $rollback = $rollbackError;
            } finally {
                wp_cache_delete($postId, 'post_meta');
                clean_post_cache($postId);
            }
            if ($rollback) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception is chained, not rendered.
                throw new RuntimeException('The failed change could not be rolled back safely.', 0, $rollback);
            }
            throw $error;
        }

        return array(
            'postId'    => $postId,
            'column'    => $column->key,
            'text'      => $this->renderer->text($column, $after),
            'auditId'   => $auditId,
            'undoNonce' => wp_create_nonce($adapter->nonceAction('undo', $auditId, $column)),
            'snapshot'  => $after->hash(),
            'value'     => $this->renderer->editableValue($after),
            'exists'    => $after->exists,
        );
    }

    /**
     * @param  array<string, mixed> $request Raw request data.
     * @return list<int>
     */
    private function postIds(array $request): array
    {
        $raw = $request['post_ids'] ?? null;
        if (! is_array($raw) || ! $raw) {
            throw new InvalidArgumentException('Select at least one record.');
        }
        if (count($raw) > self::MAX_OBJECTS) {
            throw new InvalidArgumentException('Select no more than 100 records at a time.');
        }

        $postIds = array();
        foreach ($raw as $candidate) {
            if (! is_scalar($candidate) || ! ctype_digit((string) $candidate) || (int) $candidate < 1) {
                throw new InvalidArgumentException('The selection contains an invalid record.');
            }
            $postIds[(int) $candidate] = (int) $candidate;
        }
        return array_values($postIds);
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

    /** @param array<string, mixed> $request Raw request data. */
    private function requestInput(array $request, string $key): string
    {
        if (! isset($request[$key]) || ! is_string($request[$key])) {
            throw new InvalidArgumentException('The request contains an invalid value.');
        }
        return wp_unslash($request[$key]);
    }

    private function safeError(Throwable $error): string
    {
        if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
            return $error->getMessage();
        }
        return __('The change could not be completed. Refresh the page and try again.', 'noteware-admin-tables');
    }
}
