<?php
/**
 * Owner-bound private export artifacts with expiry.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Export;

use RuntimeException;

final class ExportJobStore
{
    public const TTL = 3600;

    public const MAX_JOBS = 3;

    public function __construct(private readonly string $directory)
    {
    }

    public static function directory(): string
    {
        return WP_CONTENT_DIR . '/nat-private-exports';
    }

    /**
     * @return array{id: string, format: string, file: string, created: int, expires: int, status: string, rows: int, path: string}
     */
    public function create(int $userId, string $format): array
    {
        if ($userId < 1 || ! in_array($format, array('csv', 'json', 'xlsx'), true)) {
            throw new RuntimeException('Export job could not be created.');
        }
        $this->prepareDirectory();
        $this->cleanup($userId);
        $jobs = $this->jobs($userId);
        if (count($jobs) >= self::MAX_JOBS) {
            $oldestReady = null;
            foreach ($jobs as $id => $job) {
                if ('ready' !== $job['status']) {
                    continue;
                }
                if (null === $oldestReady || $job['created'] < $jobs[$oldestReady]['created']) {
                    $oldestReady = $id;
                }
            }
            if (null === $oldestReady) {
                throw new RuntimeException('Wait for an existing export to expire before starting another.');
            }
            $this->unlink($jobs[$oldestReady]['file']);
            unset($jobs[$oldestReady]);
            $this->writeJobs($userId, $jobs);
        }
        $id   = wp_generate_uuid4();
        $file = $id . '.' . $format;
        $path = $this->directory . '/' . $file;
        $job  = array(
            'id'      => $id,
            'format'  => $format,
            'file'    => $file,
            'created' => time(),
            'expires' => time() + self::TTL,
            'status'  => 'pending',
            'rows'    => 0,
        );
        $jobs[$id] = $job;
        $this->writeJobs($userId, $jobs);
        $handle = fopen($path, 'xb');
        if (false === $handle) {
            unset($jobs[$id]);
            $this->writeJobs($userId, $jobs);
            throw new RuntimeException('The export file could not be created.');
        }
        fclose($handle);
        chmod($path, 0600);
        $job['path'] = $path;
        return $job;
    }

    public function complete(int $userId, string $jobId, int $rows): void
    {
        $jobs = $this->jobs($userId);
        if (! isset($jobs[$jobId]) || 'pending' !== $jobs[$jobId]['status']) {
            throw new RuntimeException('The export job is no longer writable.');
        }
        $jobs[$jobId]['status'] = 'ready';
        $jobs[$jobId]['rows']   = $rows;
        $this->writeJobs($userId, $jobs);
    }

    public function fail(int $userId, string $jobId): void
    {
        $jobs = $this->jobs($userId);
        if (! isset($jobs[$jobId])) {
            return;
        }
        $this->unlink($jobs[$jobId]['file']);
        unset($jobs[$jobId]);
        $this->writeJobs($userId, $jobs);
    }

    /**
     * @return array{id: string, format: string, file: string, created: int, expires: int, status: string, rows: int, path: string}|null
     */
    public function ready(int $userId, string $jobId): ?array
    {
        $this->cleanup($userId);
        $jobs = $this->jobs($userId);
        if (! isset($jobs[$jobId]) || 'ready' !== $jobs[$jobId]['status'] || $jobs[$jobId]['expires'] < time()) {
            return null;
        }
        $path = $this->directory . '/' . $jobs[$jobId]['file'];
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $jobs[$jobId]['path'] = $path;
        return $jobs[$jobId];
    }

    public function cleanup(int $userId): void
    {
        $jobs  = $this->jobs($userId);
        $kept  = array();
        $now   = time();
        foreach ($jobs as $id => $job) {
            if ($job['expires'] < $now || 'ready' !== $job['status'] && 'pending' !== $job['status']) {
                $this->unlink($job['file']);
                continue;
            }
            $kept[$id] = $job;
        }
        if ($kept !== $jobs) {
            $this->writeJobs($userId, $kept);
        }
    }

    /**
     * @return array<string, array{id: string, format: string, file: string, created: int, expires: int, status: string, rows: int}>
     */
    private function jobs(int $userId): array
    {
        $stored = get_user_option('nat_export_jobs', $userId);
        if (! is_array($stored)) {
            return array();
        }
        $jobs = array();
        foreach ($stored as $id => $job) {
            if (
                is_string($id)
                && is_array($job)
                && isset($job['id'], $job['format'], $job['file'], $job['created'], $job['expires'], $job['status'], $job['rows'])
                && $job['id'] === $id
                && in_array($job['format'], array('csv', 'json', 'xlsx'), true)
                && is_string($job['file'])
                && $job['file'] === $id . '.' . $job['format']
                && is_int($job['created'])
                && is_int($job['expires'])
                && is_string($job['status'])
                && is_int($job['rows'])
            ) {
                $jobs[$id] = $job;
            }
        }
        return $jobs;
    }

    /**
     * @param array<string, array<string, mixed>> $jobs
     */
    private function writeJobs(int $userId, array $jobs): void
    {
        update_user_option($userId, 'nat_export_jobs', $jobs, false);
    }

    private function unlink(string $file): void
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}\.(csv|json|xlsx)$/', $file)) {
            return;
        }
        $path = $this->directory . '/' . $file;
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function prepareDirectory(): void
    {
        if (! is_dir($this->directory) && ! wp_mkdir_p($this->directory)) {
            throw new RuntimeException('Private export storage is unavailable.');
        }
        $htaccess = $this->directory . '/.htaccess';
        if (! is_file($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n");
        }
        $index = $this->directory . '/index.php';
        if (! is_file($index)) {
            file_put_contents($index, "<?php\n// Private export storage.\n");
        }
    }
}
