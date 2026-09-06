<?php
/**
 * Bounded CSV, JSON and single-sheet XLSX writers. Output is private until success.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Export;

use InvalidArgumentException;
use Noteware\AdminTables\Model\StoredValue;
use RuntimeException;
use ZipArchive;

final class StreamExporter
{
    /**
     * @param array<string,string> $columns Ordered selected keys and labels.
     * @param iterable<array<string,StoredValue>> $rows Already scoped and authorized rows.
     * @param resource $stream Private writable stream; caller discards all output on failure.
     */
    public function write(string $format, array $columns, iterable $rows, $stream, int $maxRows = 10000): int
    {
        if (! in_array($format, array('csv', 'json', 'xlsx'), true) || ! is_resource($stream) || 'stream' !== get_resource_type($stream) || $maxRows < 1 || $maxRows > 100000 || count($columns) < 1 || count($columns) > 100) {
            throw new InvalidArgumentException('Invalid export format, stream or budget.');
        }
        foreach ($columns as $key => $label) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_-]{0,99}$/', $key) || ! is_string($label) || '' === $label || strlen($label) > 200) {
                throw new InvalidArgumentException('Export columns require bounded keys and labels.');
            }
            ExportValue::encode(new StoredValue(true, $label));
        }
        if ('xlsx' === $format) {
            return $this->xlsx($columns, $rows, $stream, $maxRows);
        }
        $header = array();
        foreach ($columns as $label) {
            array_push($header, $label, $label . ' [state]', $label . ' [type]');
        }
        if ('csv' === $format) {
            $this->csv($stream, $header);
        } else {
            $this->put($stream, '{"schema_version":1,"columns":' . json_encode($columns, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . ',"rows":[');
        }
        $count = 0;
        foreach ($rows as $row) {
            $this->row($columns, $row, ++$count, $maxRows);
            $values = array();
            $csvValues = array();
            foreach ($columns as $key => $label) {
                $encoded = ExportValue::encode($row[$key]);
                if ('csv' === $format) {
                    array_push($csvValues, ExportValue::text($row[$key]), $encoded['state'], $encoded['type']);
                } else {
                    $values[$key] = $encoded;
                }
            }
            if ('csv' === $format) {
                $this->csv($stream, $csvValues);
            } else {
                $this->put($stream, ($count > 1 ? ',' : '') . json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
        }
        if ('json' === $format) {
            $this->put($stream, ']}');
        }
        return $count;
    }

    /**
     * @param array<string,string> $columns
     * @param array<string,StoredValue> $row
     */
    private function row(array $columns, array $row, int $count, int $maxRows): void
    {
        if ($count > $maxRows || array_diff_key($columns, $row) || array_diff_key($row, $columns)) {
            throw new InvalidArgumentException('Export exceeded its row budget or received a different column projection.');
        }
        foreach ($row as $value) {
            if (! $value instanceof StoredValue) {
                throw new InvalidArgumentException('Every export cell must include its stored state.');
            }
            ExportValue::encode($value);
        }
    }

    /**
     * @param resource $stream
     * @param list<string> $values
     */
    private function csv($stream, array $values): void
    {
        $fields = array_map(static fn (string $value): string => '"' . str_replace('"', '""', ExportValue::csvText($value)) . '"', $values);
        $this->put($stream, implode(',', $fields) . "\r\n");
    }

    /** @param resource $stream */
    private function put($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (false === $written || 0 === $written) {
                throw new RuntimeException('Writing export output failed.');
            }
            $offset += $written;
        }
    }

    /**
     * @param array<string,string> $columns
     * @param iterable<array<string,StoredValue>> $rows
     * @param resource $output
     */
    private function xlsx(array $columns, iterable $rows, $output, int $maxRows): int
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX requires the PHP zip extension.');
        }
        $sheet = tmpfile();
        $archive = tmpfile();
        $strings = tmpfile();
        if (false === $sheet || false === $archive || false === $strings) {
            if (is_resource($sheet)) {
                fclose($sheet);
            }
            if (is_resource($archive)) {
                fclose($archive);
            }
            if (is_resource($strings)) {
                fclose($strings);
            }
            throw new RuntimeException('Cannot create private export scratch files.');
        }
        try {
            $stringCount = 0;
            $this->put($strings, '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
            $this->put($sheet, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
            $header = array();
            foreach ($columns as $label) {
                array_push($header, new StoredValue(true, $label), new StoredValue(true, $label . ' [state]'), new StoredValue(true, $label . ' [type]'));
            }
            $this->xmlRow($sheet, $header, 1, $strings, $stringCount);
            $count = 0;
            foreach ($rows as $row) {
                $this->row($columns, $row, ++$count, $maxRows);
                $values = array();
                foreach ($columns as $key => $label) {
                    array_push($values, $row[$key], new StoredValue(true, $row[$key]->state()), new StoredValue(true, get_debug_type($row[$key]->value)));
                }
                $this->xmlRow($sheet, $values, $count + 1, $strings, $stringCount);
            }
            $this->put($sheet, '</sheetData></worksheet>');
            $this->put($strings, '</sst>');
            if (! fflush($sheet) || ! fflush($strings)) {
                throw new RuntimeException('Flushing worksheet failed.');
            }
            $zip = new ZipArchive();
            $archivePath = stream_get_meta_data($archive)['uri'] ?? '';
            $sheetPath = stream_get_meta_data($sheet)['uri'] ?? '';
            $stringsPath = stream_get_meta_data($strings)['uri'] ?? '';
            if ('' === $archivePath || '' === $sheetPath || '' === $stringsPath) {
                throw new RuntimeException('Export scratch streams must have filesystem paths.');
            }
            if (true !== $zip->open($archivePath, ZipArchive::OVERWRITE)) {
                throw new RuntimeException('Cannot create XLSX archive.');
            }
            try {
                foreach ($this->parts() as $path => $xml) {
                    if (! $zip->addFromString($path, $xml)) {
                        throw new RuntimeException('Cannot add XLSX package part.');
                    }
                }
                if (! $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml') || ! $zip->addFile($stringsPath, 'xl/sharedStrings.xml')) {
                    throw new RuntimeException('Cannot add XLSX worksheet.');
                }
            } finally {
                if (! $zip->close()) {
                    throw new RuntimeException('Cannot finalize XLSX package.');
                }
            }
            // ZipArchive may replace the inode; reopen the path instead of reading the old descriptor.
            $ready = fopen($archivePath, 'rb');
            if (false === $ready) {
                throw new RuntimeException('Cannot read finalized XLSX package.');
            }
            try {
                while (! feof($ready)) {
                    $chunk = fread($ready, 65536);
                    if (false === $chunk) {
                        throw new RuntimeException('Cannot read XLSX bytes.');
                    }
                    $this->put($output, $chunk);
                }
            } finally {
                fclose($ready);
            }
            return $count;
        } finally {
            fclose($sheet);
            fclose($archive);
            fclose($strings);
        }
    }

    /**
     * @param resource $stream
     * @param list<StoredValue> $values
     * @param resource $strings
     */
    private function xmlRow($stream, array $values, int $number, $strings, int &$stringCount): void
    {
        $this->put($stream, '<row r="' . $number . '">');
        foreach ($values as $index => $value) {
            $text = ExportValue::text($value);
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', $text) || preg_match_all('/./us', $text) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text) > 32767) {
                throw new InvalidArgumentException('XLSX cell contains unsupported XML characters or exceeds its text limit.');
            }
            $reference = $this->columnName($index + 1) . $number;
            // All strings, including decimal strings, are explicit text. Excel must not round them.
            if (is_bool($value->value)) {
                $cell = '<c r="' . $reference . '" t="b"><v>' . $text . '</v></c>';
            } elseif (is_int($value->value) && strlen(ltrim($text, '-')) <= 15) {
                $cell = '<c r="' . $reference . '" t="n"><v>' . $text . '</v></c>';
            } else {
                $text = preg_replace('/_x([0-9A-Fa-f]{4})_/', '_x005F_x$1_', $text);
                $this->put($strings, '<si><t xml:space="preserve">' . htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>');
                $cell = '<c r="' . $reference . '" t="s"><v>' . $stringCount++ . '</v></c>';
            }
            $this->put($stream, $cell);
        }
        $this->put($stream, '</row>');
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            --$number;
            $name = chr(65 + $number % 26) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    /** @return array<string,string> */
    private function parts(): array
    {
        return array(
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
        );
    }
}
