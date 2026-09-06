<?php
/**
 * Authorized list-screen export download.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Export;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Noteware\AdminTables\Adapter\AdapterRegistry;
use Noteware\AdminTables\Config\Configuration;
use Noteware\AdminTables\Model\ColumnDefinition;
use Noteware\AdminTables\Query\QueryController;

final class ExportController
{
    private const MAX_ROWS = 10000;

    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly AdapterRegistry $adapters,
        private readonly QueryController $query
    ) {
    }

    public function register(): void
    {
        add_action('manage_posts_extra_tablenav', array($this, 'render'), 20);
        add_action('admin_post_nat_export', array($this, 'create'));
        add_action('admin_post_nat_export_download', array($this, 'download'));
        add_action('admin_init', array($this, 'cleanup'));
    }

    public function render(string $which): void
    {
        if ('top' !== $which) {
            return;
        }
        $postType = isset($_GET['post_type']) && is_string($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
        if ('' === $postType || ! in_array($postType, $this->configuration->postTypes(), true)) {
            return;
        }
        $type = get_post_type_object($postType);
        if (! $type || ! current_user_can($type->cap->edit_posts) || ! $this->exportColumns($postType)) {
            return;
        }

        echo '<div class="alignleft actions nat-export" data-url="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('nat_export')) . '">';
        echo '<input type="hidden" name="action" value="nat_export">';
        echo '<input type="hidden" name="post_type" value="' . esc_attr($postType) . '">';
        foreach ($this->frozenFromGet() as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        echo '<button type="button" class="button" name="format" value="csv">' . esc_html__('Export CSV', 'noteware-admin-tables') . '</button> ';
        echo '<button type="button" class="button" name="format" value="json">' . esc_html__('Export JSON', 'noteware-admin-tables') . '</button> ';
        if (class_exists(\ZipArchive::class)) {
            echo '<button type="button" class="button" name="format" value="xlsx">' . esc_html__('Export XLSX', 'noteware-admin-tables') . '</button>';
        }
        echo '</div>';
    }

    public function create(): void
    {
        check_admin_referer('nat_export');
        try {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Allowlisted keys are copied by ExportScope.
            $job = $this->run(get_current_user_id(), wp_unslash($_POST));
            $download = add_query_arg(
                array(
                    'action'   => 'nat_export_download',
                    'job'      => $job['id'],
                    '_wpnonce' => wp_create_nonce('nat_export_download_' . $job['id']),
                ),
                admin_url('admin-post.php')
            );
            wp_safe_redirect($download);
            exit;
        } catch (Throwable $error) {
            wp_die(esc_html($error->getMessage()), esc_html__('Export failed', 'noteware-admin-tables'), array('response' => 400, 'back_link' => true));
        }
    }

    /**
     * Build a private, owner-bound artifact for the frozen request.
     *
     * @param array<array-key, mixed> $request Unslashed POST values.
     * @return array{id: string, format: string, file: string, created: int, expires: int, status: string, rows: int, path: string}
     */
    public function run(int $userId, array $request, ?string $directory = null): array
    {
        $store  = new ExportJobStore($directory ?? ExportJobStore::directory());
        $jobId  = '';
        try {
            $postType = isset($request['post_type']) && is_string($request['post_type']) ? sanitize_key($request['post_type']) : '';
            $format   = isset($request['format']) && is_string($request['format']) ? sanitize_key($request['format']) : '';
            $this->authorizeScreen($postType);
            if (! in_array($format, array('csv', 'json', 'xlsx'), true)) {
                throw new InvalidArgumentException('Choose a supported export format.');
            }
            if ('xlsx' === $format && ! class_exists(\ZipArchive::class)) {
                throw new RuntimeException('Spreadsheet export needs the PHP zip extension.');
            }
            $columns = $this->exportColumns($postType);
            if (! $columns) {
                throw new InvalidArgumentException('This view has no scalar columns to export.');
            }
            $frozen   = ExportScope::freeze($request);
            $selected = ExportScope::selectedIds($request['post'] ?? array());
            $job      = $store->create($userId, $format);
            $jobId    = $job['id'];
            $stream   = fopen($job['path'], 'wb');
            if (false === $stream) {
                throw new RuntimeException('The export file could not be opened.');
            }
            try {
                $rows = ExportRows::iterate(
                    fn (?string $cursor, int $limit): array => $this->page($postType, $frozen, $selected, $columns, $cursor, $limit),
                    fn (?string $id): bool => $this->stillAllowed($postType, $id),
                    static fn (): bool => 1 === connection_aborted(),
                    self::PAGE_SIZE,
                    self::MAX_ROWS
                );
                $written = (new StreamExporter())->write($format, $this->labels($columns), $rows, $stream, self::MAX_ROWS);
            } finally {
                fclose($stream);
            }
            $store->complete($userId, $jobId, $written);
            $ready = $store->ready($userId, $jobId);
            if (null === $ready) {
                throw new RuntimeException('The export file could not be published.');
            }
            return $ready;
        } catch (Throwable $error) {
            if ('' !== $jobId) {
                $store->fail($userId, $jobId);
            }
            throw $error;
        }
    }

    public function download(): void
    {
        $jobId = isset($_GET['job']) && is_string($_GET['job']) ? sanitize_text_field(wp_unslash($_GET['job'])) : '';
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $jobId)) {
            wp_die(esc_html__('This export is unavailable.', 'noteware-admin-tables'), '', array('response' => 400, 'back_link' => true));
        }
        check_admin_referer('nat_export_download_' . $jobId);
        $store = new ExportJobStore(ExportJobStore::directory());
        $job   = $store->ready(get_current_user_id(), $jobId);
        if (null === $job) {
            wp_die(esc_html__('This export expired or is no longer available.', 'noteware-admin-tables'), '', array('response' => 404, 'back_link' => true));
        }
        $types = array(
            'csv'  => 'text/csv; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        nocache_headers();
        header('Content-Type: ' . $types[$job['format']]);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="table-export.' . $job['format'] . '"');
        header('Content-Length: ' . (string) filesize($job['path']));
        readfile($job['path']);
        exit;
    }

    public function cleanup(): void
    {
        $userId = get_current_user_id();
        if ($userId > 0) {
            (new ExportJobStore(ExportJobStore::directory()))->cleanup($userId);
        }
    }

    /**
     * @param array<string, string> $frozen
     * @param list<int>             $selected
     * @param array<string, ColumnDefinition> $columns
     * @return array{rows: list<array{id: string, values: array<string, \Noteware\AdminTables\Model\StoredValue>}>, next: ?string}
     */
    private function page(string $postType, array $frozen, array $selected, array $columns, ?string $cursor, int $limit): array
    {
        $after = 0;
        if (null !== $cursor) {
            if (1 !== preg_match('/^[1-9][0-9]{0,19}$/', $cursor)) {
                throw new RuntimeException('Export cursor did not make bounded progress.');
            }
            $after = (int) $cursor;
        }
        $args = array(
            'post_type'              => $postType,
            'post_status'            => $this->statuses($frozen),
            'posts_per_page'         => $limit,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        );
        if (isset($frozen['s']) && '' !== $frozen['s']) {
            $args['s'] = sanitize_text_field($frozen['s']);
        }
        if ($selected) {
            $args['post__in'] = $selected;
        }
        $query = new \WP_Query();
        $query->parse_query($args);
        $this->query->applyFrozenFilters($query, $postType, $frozen);
        $where = static function (string $where, \WP_Query $current) use ($query, $after): string {
            if ($current !== $query || $after < 1) {
                return $where;
            }
            global $wpdb;
            return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after);
        };
        add_filter('posts_where', $where, 10, 2);
        try {
            $ids = $query->get_posts();
        } finally {
            remove_filter('posts_where', $where, 10);
        }
        if (! is_array($ids)) {
            throw new RuntimeException('Export loader returned an invalid page.');
        }
        $rows = array();
        $last = $cursor;
        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && 1 === preg_match('/^[1-9][0-9]{0,19}$/', $id))) {
                throw new RuntimeException('Export loader returned an invalid row.');
            }
            $postId = (int) $id;
            $values = array();
            foreach ($columns as $key => $column) {
                $values[$key] = ExportProjection::scalar($this->adapters->get($column->source)->read($postId, $column));
            }
            $rows[] = array(
                'id'     => (string) $postId,
                'values' => $values,
            );
            $last = (string) $postId;
        }
        return array(
            'rows' => $rows,
            'next' => count($ids) === $limit ? $last : null,
        );
    }

    private function stillAllowed(string $postType, ?string $id): bool
    {
        $type = get_post_type_object($postType);
        if (! $type || ! current_user_can($type->cap->edit_posts)) {
            return false;
        }
        if (null === $id) {
            return true;
        }
        if (1 !== preg_match('/^[1-9][0-9]{0,19}$/', $id)) {
            return false;
        }
        $postId = (int) $id;
        return $postType === get_post_type($postId) && current_user_can('edit_post', $postId);
    }

    private function authorizeScreen(string $postType): void
    {
        $type = get_post_type_object($postType);
        if ('' === $postType || ! $type || ! current_user_can($type->cap->edit_posts) || ! $this->configuration->screen($postType)) {
            throw new RuntimeException('This screen is not available to the current user.');
        }
    }

    /**
     * @return array<string, ColumnDefinition>
     */
    private function exportColumns(string $postType): array
    {
        $columns = array();
        foreach ($this->configuration->columns($postType) as $column) {
            if (ExportProjection::isExportable($column) && $this->adapters->get($column->source)->supports($column)) {
                $columns[$column->key] = $column;
            }
        }
        return $columns;
    }

    /**
     * @param array<string, ColumnDefinition> $columns
     * @return array<string, string>
     */
    private function labels(array $columns): array
    {
        $labels = array();
        foreach ($columns as $key => $column) {
            $labels[$key] = $column->label;
        }
        return $labels;
    }

    /**
     * @param array<string, string> $frozen
     * @return list<string>
     */
    private function statuses(array $frozen): array
    {
        if (isset($frozen['post_status']) && '' !== $frozen['post_status'] && 'all' !== $frozen['post_status'] && get_post_status_object($frozen['post_status'])) {
            return array($frozen['post_status']);
        }
        return array('publish', 'future', 'draft', 'pending', 'private');
    }

    /**
     * @return array<string, string>
     */
    private function frozenFromGet(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Copied into a nonce-protected POST form and revalidated on submit.
        return ExportScope::freeze(wp_unslash($_GET));
    }
}
