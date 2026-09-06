<?php
/**
 * Segment storage and permission boundaries without a shared WordPress database.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Segment {
    function get_current_user_id(): int
    {
        return $GLOBALS['nat_segment_test']['user'];
    }

    function get_current_blog_id(): int
    {
        return $GLOBALS['nat_segment_test']['blog'];
    }

    function get_post_type_object(string $postType): ?object
    {
        return 'missing' === $postType ? null : (object) array('cap' => (object) array('edit_posts' => 'edit_posts'));
    }

    function current_user_can(string $capability): bool
    {
        return in_array($capability, $GLOBALS['nat_segment_test']['caps'], true);
    }

    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['nat_segment_test']['shared'][$key] ?? $default;
    }

    function get_user_option(string $key): mixed
    {
        return $GLOBALS['nat_segment_test']['personal'][get_current_user_id()][$key] ?? false;
    }

    function update_option(string $key, mixed $value, bool $autoload = false): bool
    {
        if ($GLOBALS['nat_segment_test']['fail']) {
            return false;
        }
        $GLOBALS['nat_segment_test']['shared'][$key] = $value;
        return true;
    }

    function update_user_option(int $user, string $key, mixed $value, bool $global = false): bool
    {
        if ($GLOBALS['nat_segment_test']['fail']) {
            return false;
        }
        $GLOBALS['nat_segment_test']['personal'][$user][$key] = $value;
        return true;
    }
}

namespace Noteware\AdminTables\Tests {
    use Noteware\AdminTables\Segment\SegmentDefinition;
    use Noteware\AdminTables\Segment\SegmentRepository;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class SegmentRepositoryTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['nat_segment_test'] = array('user' => 1, 'blog' => 1, 'caps' => array('edit_posts'), 'shared' => array(), 'personal' => array(), 'fail' => false);
        }

        private function segment(string $id = 'one'): SegmentDefinition
        {
            return SegmentDefinition::fromArray(array('id' => $id, 'name' => 'Example'));
        }

        public function testPrivateDefaultsStayWithinEveryScope(): void
        {
            $repository = new SegmentRepository('post', 'default');
            $repository->save($this->segment());
            $repository->setDefault('one');
            self::assertSame('one', $repository->read()['default']);
            self::assertSame(array(), (new SegmentRepository('page'))->read()['segments']);
            self::assertSame(array(), (new SegmentRepository('post', 'other'))->read()['segments']);
            $GLOBALS['nat_segment_test']['user'] = 2;
            self::assertSame(array(), $repository->read()['segments']);
            $GLOBALS['nat_segment_test']['user'] = 1;
            $GLOBALS['nat_segment_test']['blog'] = 2;
            self::assertSame(array(), $repository->read()['segments']);
        }

        public function testUnderscoresCannotCollideAcrossScreenAndViewScopes(): void
        {
            $first = new SegmentRepository('a_b', 'c');
            $second = new SegmentRepository('a', 'b_c');
            $GLOBALS['nat_segment_test']['caps'][] = 'manage_options';
            foreach (array(false, true) as $shared) {
                $first->save($this->segment('first'), $shared);
                $first->setDefault('first', $shared);
                self::assertSame(array(), $second->read($shared)['segments']);
                $second->save($this->segment('second'), $shared);
                $second->setDefault('second', $shared);
                self::assertSame('first', $first->read($shared)['default']);
                self::assertSame('second', $second->read($shared)['default']);
            }
        }

        public function testPublicWritesRequireAdministratorCapability(): void
        {
            $this->expectException(RuntimeException::class);
            (new SegmentRepository('post'))->save($this->segment(), true);
        }

        public function testPublicDeleteAndDefaultAlsoRequireCapability(): void
        {
            $repository = new SegmentRepository('post');
            $GLOBALS['nat_segment_test']['caps'][] = 'manage_options';
            $repository->save($this->segment(), true);
            $GLOBALS['nat_segment_test']['caps'] = array('edit_posts');
            foreach (array('delete', 'setDefault') as $method) {
                try {
                    $repository->$method('one', true);
                    self::fail('A public write was permitted.');
                } catch (RuntimeException) {
                    self::assertCount(1, $repository->read(true)['segments']);
                }
            }
        }

        public function testDeletingDefaultClearsReference(): void
        {
            $repository = new SegmentRepository('post');
            $repository->save($this->segment());
            $repository->setDefault('one');
            $repository->delete('one');
            self::assertNull($repository->read()['default']);
            self::assertSame(array(), $repository->read()['segments']);
        }

        public function testUnknownDefaultRejected(): void
        {
            $this->expectException(RuntimeException::class);
            (new SegmentRepository('post'))->setDefault('other');
        }

        public function testSegmentCountBound(): void
        {
            $repository = new SegmentRepository('post');
            for ($index = 0; $index < 20; ++$index) {
                $repository->save($this->segment('s' . $index));
            }
            $repository->save($this->segment('s0'));
            $this->expectException(RuntimeException::class);
            $repository->save($this->segment('overflow'));
        }

        public function testLoggedOutReadRejected(): void
        {
            $GLOBALS['nat_segment_test']['user'] = 0;
            $this->expectException(RuntimeException::class);
            (new SegmentRepository('post'))->read();
        }

        public function testWriteFailureReported(): void
        {
            $GLOBALS['nat_segment_test']['fail'] = true;
            $this->expectException(RuntimeException::class);
            (new SegmentRepository('post'))->save($this->segment());
        }
    }
}
