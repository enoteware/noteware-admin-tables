<?php
/**
 * Isolated WordPress storage boundary tests.
 *
 * @package NotewareAdminTables
 */
declare(strict_types=1);
namespace Noteware\AdminTables\View;

if (! function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
    function get_current_user_id(): int
    {
        return $GLOBALS['nat_view_test_user'];
    }
    function current_user_can(string $cap): bool
    {
        return 'manage_options' === $cap ? 1 === get_current_user_id() : 9 !== get_current_user_id();
    }
    function get_post_type_object(string $type): ?object
    {
        return 'post' === $type ? (object) array('show_ui' => true, 'cap' => (object) array('edit_posts' => 'edit_posts')) : null;
    }
    function get_user_option(string $key): mixed
    {
        return $GLOBALS['nat_view_test_cache']['personal'] ?? $GLOBALS['nat_view_test_personal'][get_current_user_id()][$key] ?? false;
    }
    function update_user_option(int $id, string $key, mixed $value, bool $global): bool
    {
        if ($global) {
            throw new \RuntimeException('Views must be scoped to the current site.');
        }
        viewTestBeforeWrite();
        if ($GLOBALS['nat_view_test_fail_write']) {
            return false;
        }
        $GLOBALS['nat_view_test_personal'][$id][$key] = $value;
        ++$GLOBALS['nat_view_test_writes'];
        return true;
    }
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['nat_view_test_cache']['shared'] ?? $GLOBALS['nat_view_test_shared'][$key] ?? $default;
    }
    function update_option(string $key, mixed $value, bool $autoload): bool
    {
        viewTestBeforeWrite();
        if ($GLOBALS['nat_view_test_fail_write']) {
            return false;
        }
        $GLOBALS['nat_view_test_shared'][$key] = $value;
        ++$GLOBALS['nat_view_test_writes'];
        return true;
    }
    function viewTestBeforeWrite(): void
    {
        if (isset($GLOBALS['nat_view_test_during_write'])) {
            $callback = $GLOBALS['nat_view_test_during_write'];
            unset($GLOBALS['nat_view_test_during_write']);
            $callback();
        }
    }
    function wp_cache_delete(string|int $key, string $group = ''): bool
    {
        $GLOBALS['nat_view_test_cleared'][] = array($key, $group);
        unset($GLOBALS['nat_view_test_cache']['options' === $group ? 'shared' : 'personal']);
        return true;
    }
    function wp_get_current_user(): object
    {
        return (object) array('roles' => array(2 === get_current_user_id() ? 'editor' : 'author'));
    }
    function wp_roles(): object
    {
        return new class () {
            public function is_role(string $role): bool
            {
                return in_array($role, array('editor', 'author'), true);
            }
        };
    }
}

namespace Noteware\AdminTables\Tests;

use Noteware\AdminTables\View\ViewRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/ViewLockDatabase.php';

final class ViewRepositoryTest extends TestCase
{
    private mixed $previousDatabase = null;
    protected function setUp(): void
    {
        $this->previousDatabase = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new ViewLockDatabase();
        $GLOBALS['nat_view_test_cache'] = array();
        $GLOBALS['nat_view_test_cleared'] = array();
        $GLOBALS['nat_view_test_fail_write'] = false;
        $GLOBALS['nat_view_test_writes'] = 0;
        unset($GLOBALS['nat_view_test_during_write']);
        $GLOBALS['nat_view_test_user'] = 1;
        $GLOBALS['nat_view_test_personal'] = array();
        $GLOBALS['nat_view_test_shared'] = array();
    }

    protected function tearDown(): void
    {
        if (null === $this->previousDatabase) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previousDatabase;
        }
    }

    private function view(string $visibility = 'personal'): array
    {
        return array('version' => 1, 'id' => 'v_test', 'name' => 'Example', 'post_type' => 'post', 'visibility' => $visibility, 'roles' => 'shared' === $visibility ? array('editor') : array(), 'columns' => array());
    }

    public function testPersonalViewsAndSelectionDoNotLeakAcrossUsers(): void
    {
        $repository = new ViewRepository();
        $repository->save('post', array('columns' => array()), $this->view());
        $repository->select('post', 'v_test');
        self::assertSame('v_test', \Noteware\AdminTables\View\get_user_option('nat_active_view_post'));
        self::assertCount(1, $repository->available('post'));
        $GLOBALS['nat_view_test_user'] = 2;
        self::assertSame(array(), $repository->available('post'));
        self::assertFalse(\Noteware\AdminTables\View\get_user_option('nat_active_view_post'));
    }

    public function testRoleRestrictionsAreCheckedOnEveryRead(): void
    {
        $repository = new ViewRepository();
        $repository->save('post', array('columns' => array()), $this->view('shared'));
        $GLOBALS['nat_view_test_user'] = 2;
        self::assertCount(1, $repository->available('post'));
        $GLOBALS['nat_view_test_user'] = 3;
        self::assertSame(array(), $repository->available('post'));
    }

    public function testNonAdministratorCannotWriteSharedView(): void
    {
        $GLOBALS['nat_view_test_user'] = 2;
        $this->expectException(RuntimeException::class);
        (new ViewRepository())->save('post', array('columns' => array()), $this->view('shared'));
    }

    public function testUserWithoutScreenCapabilityCannotWrite(): void
    {
        $GLOBALS['nat_view_test_user'] = 9;
        $this->expectException(RuntimeException::class);
        (new ViewRepository())->save('post', array('columns' => array()), $this->view());
    }

    public function testUnknownViewCannotBeSelected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ViewRepository())->select('post', 'someone_else');
    }
    public function testUnicodeNameLimitUsesCharactersForSaveAndRead(): void
    {
        $repository = new ViewRepository();
        foreach (array('界', '😀') as $index => $character) {
            $view = $this->view();
            $view['id'] = 'v_unicode_' . $index;
            $view['name'] = str_repeat($character, 100);
            $repository->save('post', array('columns' => array()), $view);
            self::assertSame($view['name'], $repository->available('post')[$view['id']]['name']);
        }
    }

    public function testOverlongUnicodeNameIsRejected(): void
    {
        $view = $this->view();
        $view['name'] = str_repeat('界', 101);
        $this->expectException(\InvalidArgumentException::class);
        (new ViewRepository())->save('post', array('columns' => array()), $view);
    }

    public function testMalformedUtf8NameIsRejected(): void
    {
        $view = $this->view();
        $view['name'] = chr(255);
        $this->expectException(\InvalidArgumentException::class);
        (new ViewRepository())->save('post', array('columns' => array()), $view);
    }
    private function storedViews(string $visibility): array
    {
        return 'shared' === $visibility
            ? ($GLOBALS['nat_view_test_shared']['nat_shared_views_post'] ?? array())
            : ($GLOBALS['nat_view_test_personal'][1]['nat_views_post'] ?? array());
    }

    public function testReusedIdsCannotOverwriteEitherScope(): void
    {
        foreach (array('personal', 'shared') as $visibility) {
            $repository = new ViewRepository();
            $view = $this->view($visibility);
            $repository->save('post', array('columns' => array()), $view);
            $replacement = array_replace($view, array('name' => 'Unexpected overwrite'));
            try {
                $repository->save('post', array('columns' => array()), $replacement);
                self::fail('A copy-only save replaced an existing id.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('already exists', $error->getMessage());
                self::assertSame($view, $this->storedViews($visibility)['v_test']);
            }
            self::assertSame(array(), $GLOBALS['wpdb']->locks);
        }
    }

    public function testOverlappingSavesRetryAgainstFreshStateInBothScopes(): void
    {
        foreach (array('personal', 'shared') as $visibility) {
            $first = new ViewRepository();
            $second = new ViewRepository();
            $firstView = $this->view($visibility);
            $secondView = array_replace($firstView, array('id' => 'v_second'));
            $GLOBALS['nat_view_test_during_write'] = static function () use ($second, $secondView): void {
                try {
                    $second->save('post', array('columns' => array()), $secondView);
                    self::fail('Nested writer bypassed the scoped lock.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('busy', $error->getMessage());
                }
            };
            $first->save('post', array('columns' => array()), $firstView);
            $GLOBALS['nat_view_test_cache'][$visibility] = array();
            $second->save('post', array('columns' => array()), $secondView);
            self::assertSame(array('v_test', 'v_second'), array_keys($this->storedViews($visibility)));
            self::assertSame(array(), $GLOBALS['wpdb']->locks);
        }
        self::assertContains(array(1, 'user_meta'), $GLOBALS['nat_view_test_cleared']);
        self::assertContains(array('nat_shared_views_post', 'options'), $GLOBALS['nat_view_test_cleared']);
        self::assertContains(array('alloptions', 'options'), $GLOBALS['nat_view_test_cleared']);
        self::assertContains(array('notoptions', 'options'), $GLOBALS['nat_view_test_cleared']);
    }

    public function testCompetingConnectionCannotStealEitherScope(): void
    {
        foreach (array('personal', 'shared') as $visibility) {
            $repository = new ViewRepository();
            $repository->save('post', array('columns' => array()), $this->view($visibility));
            $lock = end($GLOBALS['wpdb']->acquired);
            $GLOBALS['wpdb']->locks[$lock] = 999;
            try {
                $repository->save('post', array('columns' => array()), array_replace($this->view($visibility), array('id' => 'v_other')));
                self::fail('Competing connection lock was stolen.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('busy', $error->getMessage());
                self::assertSame(999, $GLOBALS['wpdb']->locks[$lock]);
                self::assertCount(1, $this->storedViews($visibility));
            }
            unset($GLOBALS['wpdb']->locks[$lock]);
        }
    }

    public function testStorageFailureReleasesAndAllowsRetryInBothScopes(): void
    {
        foreach (array('personal', 'shared') as $visibility) {
            $repository = new ViewRepository();
            $GLOBALS['nat_view_test_fail_write'] = true;
            try {
                $repository->save('post', array('columns' => array()), $this->view($visibility));
                self::fail('Persistence failure was reported as saved.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('could not be saved', $error->getMessage());
                self::assertSame(array(), $GLOBALS['wpdb']->locks);
            }
            $GLOBALS['nat_view_test_fail_write'] = false;
            $repository->save('post', array('columns' => array()), $this->view($visibility));
            self::assertCount(1, $this->storedViews($visibility));
        }
    }

    public function testLostConnectionBeforeMutationDoesNotWrite(): void
    {
        foreach (array('personal', 'shared') as $visibility) {
            $GLOBALS['wpdb']->afterAcquire = static fn () => $GLOBALS['wpdb']->disconnect();
            try {
                (new ViewRepository())->save('post', array('columns' => array()), $this->view($visibility));
                self::fail('Lost connection continued to write.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('connection changed', $error->getMessage());
                self::assertSame(0, $GLOBALS['nat_view_test_writes']);
                self::assertSame(array(), $GLOBALS['wpdb']->locks);
            }
            // The dead connection leaves no permanent database or process lock.
            (new ViewRepository())->save('post', array('columns' => array()), $this->view($visibility));
            $GLOBALS['nat_view_test_writes'] = 0;
        }
    }

    public function testDisconnectDuringWriteReportsUncertaintyAndDoesNotRetry(): void
    {
        foreach (array('personal', 'shared') as $visibility) {
            $GLOBALS['nat_view_test_during_write'] = static fn () => $GLOBALS['wpdb']->disconnect();
            try {
                (new ViewRepository())->save('post', array('columns' => array()), $this->view($visibility));
                self::fail('In-flight disconnect was reported as success.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Verify the stored result', $error->getMessage());
                self::assertSame(1, $GLOBALS['nat_view_test_writes']);
                self::assertSame(array(), $GLOBALS['wpdb']->locks);
            }
            try {
                (new ViewRepository())->save('post', array('columns' => array()), $this->view($visibility));
                self::fail('Retry overwrote the uncertain but persisted result.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('already exists', $error->getMessage());
            }
            $GLOBALS['nat_view_test_writes'] = 0;
        }
    }

    public function testUnavailableLockNeverReachesStorage(): void
    {
        $GLOBALS['wpdb']->failAcquire = true;
        foreach (array('personal', 'shared') as $visibility) {
            try {
                (new ViewRepository())->save('post', array('columns' => array()), $this->view($visibility));
                self::fail('Unavailable lock allowed a write.');
            } catch (RuntimeException) {
                self::assertSame(0, $GLOBALS['nat_view_test_writes']);
                self::assertSame(array(), $GLOBALS['wpdb']->locks);
            }
        }
    }

    public function testReleaseFailureIsNotSuccessAndDisconnectFreesLock(): void
    {
        foreach (array('personal', 'shared') as $visibility) {
            $GLOBALS['wpdb']->failRelease = true;
            try {
                (new ViewRepository())->save('post', array('columns' => array()), $this->view($visibility));
                self::fail('Failed release was reported as success.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Verify the stored result', $error->getMessage());
            }
            $GLOBALS['wpdb']->disconnect();
            $GLOBALS['wpdb']->failRelease = false;
            (new ViewRepository())->save('post', array('columns' => array()), array_replace($this->view($visibility), array('id' => 'v_next')));
            self::assertSame(array('v_test', 'v_next'), array_keys($this->storedViews($visibility)));
            self::assertSame(array(), $GLOBALS['wpdb']->locks);
        }
    }

    public function testLockNamesSeparateUserSharedAndSiteScopes(): void
    {
        $repository = new ViewRepository();
        $repository->save('post', array('columns' => array()), $this->view());
        $GLOBALS['nat_view_test_user'] = 2;
        $repository->save('post', array('columns' => array()), $this->view());
        $GLOBALS['nat_view_test_user'] = 1;
        $repository->save('post', array('columns' => array()), $this->view('shared'));
        $GLOBALS['wpdb']->options = 'example_site2_options';
        $repository->save('post', array('columns' => array()), array_replace($this->view(), array('id' => 'v_other_site')));
        self::assertCount(4, array_unique($GLOBALS['wpdb']->acquired));
    }
}
