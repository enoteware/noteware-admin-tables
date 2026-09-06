<?php
/**
 * The HTTP mutation boundary must verify a nonce before touching input or storage.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);
namespace Noteware\AdminTables\View;

if (! function_exists(__NAMESPACE__ . '\\check_admin_referer')) {
    function check_admin_referer(string $action): never
    {
        throw new \RuntimeException('Nonce denied for ' . esc_html($action));
    }
}

if (! defined(__NAMESPACE__ . '\\NAT_PLUGIN_FILE')) {
    define(__NAMESPACE__ . '\\NAT_PLUGIN_FILE', __FILE__);
    define(__NAMESPACE__ . '\\NAT_VERSION', 'test');
}
if (! function_exists(__NAMESPACE__ . '\\wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $url, array $dependencies, string $version, bool $footer): void
    {
        $GLOBALS['nat_view_test_assets'][] = array('script', $handle, $url, $version, $footer);
    }
    function wp_enqueue_style(string $handle, string $url, array $dependencies, string $version): void
    {
        $GLOBALS['nat_view_test_assets'][] = array('style', $handle, $url, $version);
    }
    function plugins_url(string $path, string $plugin): string
    {
        return 'https://example.test/plugin/' . $path;
    }
}

namespace Noteware\AdminTables\Tests;

use Noteware\AdminTables\View\ViewController;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewControllerTest extends TestCase
{
    public function testNonceDenialPrecedesEveryMutation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nonce denied for nat_save_view');
        (new ViewController())->submit();
    }
    public function testAssetsAreRegisteredBeforeHeadAndOnlyOnViewScreen(): void
    {
        $controller = new ViewController();
        $controller->register();
        $hooks = $GLOBALS['nat_test_registered_actions']['admin_enqueue_scripts'] ?? array();
        self::assertContains(array(array($controller, 'enqueueAssets'), 10, 1), $hooks);
        $GLOBALS['nat_view_test_assets'] = array();
        $controller->enqueueAssets('edit.php');
        self::assertSame(array(), $GLOBALS['nat_view_test_assets']);
        $controller->enqueueAssets('tools_page_nat-views');
        self::assertSame(array(
            array('script', 'nat-views', 'https://example.test/plugin/assets/views.js', 'test', true),
            array('style', 'nat-views', 'https://example.test/plugin/assets/views.css', 'test'),
        ), $GLOBALS['nat_view_test_assets']);
    }

    public function testRenderingDoesNotEnqueueAssetsAfterAdminHead(): void
    {
        $GLOBALS['nat_view_test_assets'] = array();
        ob_start();
        try {
            (new \Noteware\AdminTables\Admin\ViewPage())->render(array(), new \Noteware\AdminTables\View\ViewRepository());
        } finally {
            ob_end_clean();
        }
        self::assertSame(array(), $GLOBALS['nat_view_test_assets']);
    }
}
