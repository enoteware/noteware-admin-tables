<?php
/**
 * View configuration overlay and admin form endpoints.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);

namespace Noteware\AdminTables\View;

use Noteware\AdminTables\Admin\ViewPage;
use Throwable;

final class ViewController
{
    /** @var array<string, array<string, mixed>> */
    private array $catalog = array();

    public function register(): void
    {
        add_filter('noteware_admin_tables_config', array($this, 'overlay'), 999);
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
        add_action('admin_post_nat_save_view', array($this, 'submit'));
    }

    /** @param mixed $raw @return mixed */
    public function overlay(mixed $raw): mixed
    {
        if (! is_array($raw)) {
            return $raw;
        }
        $this->catalog = $raw;
        $repository = new ViewRepository();
        foreach ($raw as $postType => $screen) {
            try {
                $active = get_user_option('nat_active_view_' . $postType);
                $available = $repository->available($postType);
                if (is_string($active) && isset($available[$active]) && is_array($screen)) {
                    $raw[$postType] = ViewLayout::apply($screen, $available[$active]);
                }
            } catch (Throwable) {
                // Stale views fail closed to site-owned configuration, never a broken list.
            }
        }
        return $raw;
    }

    public function menu(): void
    {
        add_submenu_page('tools.php', __('Table views', 'noteware-admin-tables'), __('Table views', 'noteware-admin-tables'), 'read', 'nat-views', array($this, 'page'));
    }

    public function enqueueAssets(string $hook): void
    {
        if ('tools_page_nat-views' !== $hook) {
            return;
        }
        wp_enqueue_script('nat-views', plugins_url('assets/views.js', NAT_PLUGIN_FILE), array(), NAT_VERSION, true);
        wp_enqueue_style('nat-views', plugins_url('assets/views.css', NAT_PLUGIN_FILE), array(), NAT_VERSION);
    }

    public function page(): void
    {
        apply_filters('noteware_admin_tables_config', array());
        (new ViewPage())->render($this->catalog, new ViewRepository());
    }

    public function submit(): void
    {
        check_admin_referer('nat_save_view');
        try {
            $postType = isset($_POST['post_type']) && is_string($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';
            $repository = new ViewRepository();
            $repository->authorize($postType);
            apply_filters('noteware_admin_tables_config', array());
            if (! isset($this->catalog[$postType])) {
                throw new \InvalidArgumentException('This screen has no configured columns.');
            }
            $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
            $id = isset($_POST['view_id']) && is_string($_POST['view_id']) ? sanitize_key(wp_unslash($_POST['view_id'])) : 'default';
            if ('select' === $operation) {
                $repository->select($postType, $id);
            } elseif ('save' === $operation) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Raw byte limit precedes JSON decoding and strict schema validation.
                if (! isset($_POST['view']) || ! is_string($_POST['view']) || strlen($_POST['view']) > 100000) {
                    throw new \InvalidArgumentException('The view payload is invalid.');
                }
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and strictly validated before storage; labels escape at output.
                $view = json_decode(wp_unslash($_POST['view']), true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($view)) {
                    throw new \InvalidArgumentException('The view payload must be an object.');
                }
                $repository->save($postType, $this->catalog[$postType], $view);
                $repository->select($postType, $view['id']);
            } else {
                throw new \InvalidArgumentException('Unknown view operation.');
            }
            wp_safe_redirect(add_query_arg(array('page' => 'nat-views', 'nat_saved' => '1'), admin_url('tools.php')));
            exit;
        } catch (Throwable $error) {
            wp_die(esc_html($error->getMessage()), esc_html__('View not saved', 'noteware-admin-tables'), array('response' => 400, 'back_link' => true));
        }
    }
}
