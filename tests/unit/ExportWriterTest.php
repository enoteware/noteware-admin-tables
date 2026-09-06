<?php
/** @package NotewareAdminTables */
declare(strict_types=1);
namespace Noteware\AdminTables\Tests;

use Noteware\AdminTables\Export\StreamExporter;
use Noteware\AdminTables\Model\StoredValue;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ExportWriterTest extends TestCase
{
    public function test_csv_round_trip_preserves_quotes_unicode_and_ordinary_email(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        (new StreamExporter())->write('csv', array('a' => 'Address', 'b' => 'Text'), array(array('a' => new StoredValue(true, 'person@example.test'), 'b' => new StoredValue(true, "é,\"quoted\"\nnext"))), $stream);
        rewind($stream);
        self::assertSame(array('Address', 'Address [state]', 'Address [type]', 'Text', 'Text [state]', 'Text [type]'), fgetcsv($stream, null, ',', '"', ''));
        self::assertSame(array('person@example.test', 'value', 'string', "é,\"quoted\"\nnext", 'value', 'string'), fgetcsv($stream, null, ',', '"', ''));
        fclose($stream);
    }

    public function test_json_distinguishes_all_states_and_large_integer_precision(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        $rows = array();
        foreach (array(new StoredValue(false, null), new StoredValue(true, null), new StoredValue(true, ''), new StoredValue(true, false), new StoredValue(true, 0), new StoredValue(true, '0'), new StoredValue(true, PHP_INT_MAX)) as $value) {
            $rows[] = array('a' => $value);
        }
        (new StreamExporter())->write('json', array('a' => 'Value'), $rows, $stream);
        rewind($stream);
        $decoded = json_decode(stream_get_contents($stream), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(array('absent', 'null', 'empty_string', 'false', 'zero', 'zero', 'value'), array_column(array_column($decoded['rows'], 'a'), 'state'));
        self::assertSame((string) PHP_INT_MAX, $decoded['rows'][6]['a']['value']);
        self::assertSame('int', $decoded['rows'][4]['a']['type']);
        self::assertSame('string', $decoded['rows'][5]['a']['type']);
        fclose($stream);
    }

    public function test_csv_neutralizes_formula_prefixes_but_not_embedded_at_signs(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        $inputs = array('=1+1', '+SUM(A1)', '-2+3', '@SUM(A1)', " \t=1+1", 'person@example.test');
        $rows = array_map(static fn (string $text): array => array('a' => new StoredValue(true, $text)), $inputs);
        (new StreamExporter())->write('csv', array('a' => '=header'), $rows, $stream);
        rewind($stream);
        self::assertSame("'=header", fgetcsv($stream, null, ',', '"', '')[0]);
        foreach ($inputs as $input) {
            self::assertSame('person@example.test' === $input ? $input : "'" . $input, fgetcsv($stream, null, ',', '"', '')[0]);
        }
        fclose($stream);
    }

    public function test_xlsx_is_a_readable_package_with_text_not_formula_nodes(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        (new StreamExporter())->write('xlsx', array('a' => 'Value'), array(array('a' => new StoredValue(true, '=1+1')), array('a' => new StoredValue(true, '12345678901234567890')), array('a' => new StoredValue(true, "line\n東京"))), $stream);
        $meta = stream_get_meta_data($stream);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($meta['uri']));
        self::assertNotFalse($zip->getFromName('[Content_Types].xml'));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertIsString($xml);
        $sheet = simplexml_load_string($xml);
        self::assertNotFalse($sheet);
        $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        self::assertCount(0, $sheet->xpath('//s:f'));
        $strings = simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
        self::assertNotFalse($strings);
        self::assertSame('=1+1', (string) $strings->si[(int) $sheet->xpath('//s:c[@r="A2"]/s:v')[0]]->t);
        self::assertSame('12345678901234567890', (string) $strings->si[(int) $sheet->xpath('//s:c[@r="A3"]/s:v')[0]]->t);
        $zip->close();
        fclose($stream);
    }

    public function test_missing_selected_column_fails_instead_of_silently_omitting_it(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new StreamExporter())->write('json', array('a' => 'Value'), array(array()), $stream);
        } finally {
            fclose($stream);
        }
    }
    public function test_invalid_utf8_does_not_silently_replace_source_bytes(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new StreamExporter())->write('csv', array('a' => 'Value'), array(array('a' => new StoredValue(true, "invalid\xB1"))), $stream);
        } finally {
            fclose($stream);
        }
    }

    public function test_complex_value_requires_an_explicit_projection(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new StreamExporter())->write('json', array('a' => 'Value'), array(array('a' => new StoredValue(true, array('nested')))), $stream);
        } finally {
            fclose($stream);
        }
    }

    public function test_total_writer_budget_is_enforced(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new StreamExporter())->write('json', array('a' => 'Value'), array(array('a' => new StoredValue(true, 'one')), array('a' => new StoredValue(true, 'two'))), $stream, 1);
        } finally {
            fclose($stream);
        }
    }
}
