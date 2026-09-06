<?php
/**
 * Generate generic files for independent consumer validation, without WordPress or a DB.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

use Noteware\AdminTables\Export\StreamExporter;
use Noteware\AdminTables\Model\StoredValue;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$directory = dirname(__DIR__) . '/artifacts/export-consumers';
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create private consumer fixture directory.');
}
$columns = array('text' => 'Text', 'integer' => 'Integer', 'flag' => 'Flag');
$rows = array(
    array('text' => new StoredValue(true, "東京,\"quoted\"\nnext"), 'integer' => new StoredValue(true, 9007199254740993), 'flag' => new StoredValue(true, false)),
    array('text' => new StoredValue(true, 'person@example.test'), 'integer' => new StoredValue(true, '12345678901234567890.0001'), 'flag' => new StoredValue(true, true)),
    array('text' => new StoredValue(true, '=1+1'), 'integer' => new StoredValue(true, 0), 'flag' => new StoredValue(false, null)),
    array('text' => new StoredValue(true, '_x0041_'), 'integer' => new StoredValue(true, -12), 'flag' => new StoredValue(true, null)),
);
foreach (array('csv', 'json', 'xlsx') as $format) {
    $stream = fopen($directory . '/generic.' . $format, 'w+b');
    if (false === $stream) {
        throw new RuntimeException('Cannot open private export fixture.');
    }
    try {
        (new StreamExporter())->write($format, $columns, $rows, $stream);
    } finally {
        fclose($stream);
    }
}
