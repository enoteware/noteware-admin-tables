# Export and portable-view module

This module provides original scalar file writers, a bounded authorized page iterator, a strict presentation-settings envelope, and an admin download for the active filtered view. It still does not implement a durable background queue, view import UI or settings-file templates. Issue 8 acceptance remains incomplete until those remaining pieces and independent consumer checks are done.

## File contract

`Export\StreamExporter::write($format, $columns, $rows, $stream, $maxRows)` accepts `csv`, `json` or `xlsx`, an ordered map of column keys to labels, an iterable of projected rows, and a **private writable stream**. Each row must contain exactly the chosen keys, each mapped to `Model\StoredValue`. The returned integer is the number of rows written. Missing keys, extra keys, complex values, invalid UTF-8, non-finite floats and exceeded budgets throw. An absent cell must carry null, never hidden data.

The caller must discard all output on any failure. CSV and JSON stream incrementally and can leave partial bytes before an error. Never send them directly to a browser while generating. XLSX stages its worksheet and string table in temporary files and creates the ZIP package before copying it to the output. An output stream failure can still leave partial bytes. Publish only a completed, authorized artifact.

Limits: 100 chosen columns, 100,000 rows maximum, 10,000 by default, 131,068 UTF-8 bytes per string. XLSX also enforces its 32,767 UTF-16-code-unit cell limit and rejects unsupported XML controls. Callers should set smaller job-specific budgets. Scratch disk usage scales with output size; bounded row memory is not a disk quota. PHP's zip extension is required for XLSX and absence produces an explicit error. No new Composer package is required.

CSV and XLSX add state and PHP-type companions for each chosen column. For example, `Amount`, `Amount [state]`, `Amount [type]` distinguish absent/null/empty/false/zero and string zero from integer zero. CSV prefixes an apostrophe when a value begins with a formula token, including after leading ASCII whitespace/control bytes. Ordinary email addresses with an interior `@` remain unchanged. The prefix is an intentional spreadsheet-safe representation; CSV consumers must use the companion type/state information and a documented import policy. CSV quotes every field, doubles embedded quotes and uses CRLF records. Its write loop detects short or failed output writes.

JSON emits an envelope with `schema_version`, ordered `columns`, and `rows`. Each cell has `exists`, `state`, `type`, `value`. All numeric values are encoded as decimal strings, with their original PHP type recorded separately, so consumers do not round large integers. Strings remain strings and booleans remain booleans. Floating-point values retain PHP's existing precision; exact money should arrive as a decimal string before export.

XLSX uses explicit shared-string cells, boolean cells and safe integers of at most 15 decimal digits as numeric cells. Larger integers, floats and decimal strings are text to preserve their representation. Dates remain source strings. There are no formula nodes or hyperlinks. Literal spreadsheet escape sequences are escaped in the shared-string table to preserve their text when loaded. Structured relationship/media fields need an explicit, documented scalar projection before they can use these writers.

## Bounded rows

`Export\ExportRows::iterate($load, $authorize, $cancelled, $pageSize, $maxRows)` is a generator. The loader receives a nullable cursor and a page limit from 1 to 500. It returns `rows` plus nullable `next`. Each row has a nonempty bounded string `id` and a `values` projection.

The iterator rejects oversized pages, repeated IDs, repeated cursors and empty nonterminal pages. It checks cancellation and authorization before every page and every record, then rechecks job permission before completion. `$authorize(null)` checks the job; `$authorize($id)` checks an individual record. Denial fails the export rather than silently producing an incomplete success. IDs and cursor history are bounded by the total row budget.

The loader owns the frozen active view, selected-row intersection, stable sort with a unique tie-breaker, snapshot/keyset strategy, field authorization and batched value loading. Preserving loader order does not prove a live database snapshot is stable. This module cannot detect omitted records, stale snapshots or arbitrary sort changes inside the loader. No persistent checkpoint, worker scheduling or resume protocol is implemented here.

## Presentation portability

`Portability\ViewEnvelope` receives the site's registered `ScreenDefinition` map and known role names. Its `encode($views)` and `decode($json)` round-trip a version-1 envelope of presentation overlays matching the view module's schema:

```json
{
  "schema_version": 1,
  "views": [{
    "version": 1,
    "id": "v_12345678-1234-1234-1234-123456789abc",
    "name": "Notes",
    "post_type": "post",
    "visibility": "personal",
    "roles": [],
    "columns": [{"key": "note", "label": "Note", "width": "12%", "visible": true}]
  }]
}
```

Columns are ordered by the array. Only configured column keys can be referenced. Existing column-definition validation and screen width budgets apply. Imported files cannot add sources, field keys, callbacks, SQL, editing flags or arbitrary options. Unknown versions are rejected; no speculative or legacy migration is performed. Maximum input is one MiB and 100 views, each with at most 100 columns. Duplicate view IDs/column keys and unregistered roles fail.

The envelope validates data only. The caller must check nonce/capability, enforce private/shared ownership, resolve ID collisions, revalidate against the current site catalog, and commit settings atomically through the view repository. Importing a valid envelope does not authorize any write. Template storage, version-controlled file discovery, import preview, migration history and UI are still required.

## Checks

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'Export|Portability'
vendor/bin/phpcs --standard=phpcs.xml.dist plugin/src/Export plugin/src/Portability tests/unit/ExportRowsTest.php tests/unit/ExportWriterTest.php tests/unit/PortabilityEnvelopeTest.php
vendor/bin/phpstan analyse --configuration phpstan.neon.dist --no-progress --memory-limit=1G
php tests/integration/export-consumers.php
```

The last command only creates generic artifacts in ignored test output. It needs no WordPress or database and does not itself prove consumer compatibility. Open the outputs in independent consumers, reconcile all rows and inspect types. PHP unit tests inspect ZIP/XML; separate Python CSV/JSON and openpyxl checks exercise independent parsers. Desktop Excel and LibreOffice, minimum PHP versions, background execution, live permission changes, downloads and complete filtered-view exports still need acceptance evidence.

Public API references:

PHP CSV writer contract
https://www.php.net/manual/en/function.fputcsv.php

PHP ZIP archive contract
https://www.php.net/manual/en/class.ziparchive.php

Original project requirements
https://github.com/enoteware/noteware-admin-tables/issues/8

Independent consumer smoke check, after generating fixtures (requires a test environment with openpyxl):

```bash
python3 - <<'PY'
import csv, json, pathlib, openpyxl
root = pathlib.Path('tests/artifacts/export-consumers')
with (root / 'generic.csv').open(newline='') as stream:
    rows = list(csv.reader(stream))
assert len(rows) == 5
assert rows[1][0] == '東京,"quoted"\nnext'
assert rows[2][0] == 'person@example.test'
assert rows[3][0] == "'=1+1"
value = json.loads((root / 'generic.json').read_text())
assert value['rows'][0]['integer']['value'] == '9007199254740993'
assert value['rows'][2]['flag']['state'] == 'absent'
book = openpyxl.load_workbook(root / 'generic.xlsx', read_only=True, data_only=False)
sheet = book.active
assert sheet['A2'].value == '東京,"quoted"\nnext'
assert sheet['D2'].value == '9007199254740993'
assert sheet['D3'].value == '12345678901234567890.0001'
assert sheet['A4'].value == '=1+1' and sheet['A4'].data_type == 's'
assert sheet['G2'].value is False
assert sheet['A5'].value == '_x0041_'
assert sheet['D5'].value == -12
book.close()
print('Independent consumer smoke check passed.')
PY
```
