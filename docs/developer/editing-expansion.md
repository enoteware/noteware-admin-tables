# Scalar ACF expansion

This module adds number and true/false editing to the existing ACF adapter. The configured source, name, field key, and live ACF type must agree. Every writable field remains opt-in through site configuration. The exact shared allowlist change is in `editing-wiring.md`.

## Current adapter inventory

| Source | Display | Editing |
| --- | --- | --- |
| Native | ID, title, slug, author, date, status, word count, featured image, permalink | Title, slug, featured image |
| Post metadata | Text, number, boolean, select, date, image, URL | Scalar types except image |
| Taxonomy | Select labels | A single existing term; undo can restore the audited term set |
| ACF | Text, number, true/false, scalar select, date picker, image, URL | Text, URL, scalar select; this module adds number and true/false |

These adapters currently operate on configured post list screens. Other entity screens and discovery are separate work.

## State and validation

Requests send strings. Numbers follow the existing decimal syntax and size bounds, plus live ACF minimum and maximum. Numeric comparisons preserve precision. Scientific notation in live bounds is not supported and refuses edits. Optional empty strings are accepted without turning them into absent values. Booleans accept `0`, `1`, or optional empty strings; a required boolean must be `1`. A required number can be zero. Arrays and objects stored in these scalar fields refuse authorization because they cannot be restored by this editor.

Writes preserve the value/reference pair. Removal deletes both. A changed snapshot rejects write/remove before an ACF mutation. Undo checks the current audited value through the existing controller and revalidates its target against current live policy. Changes to capabilities, required rules, and numeric bounds are checked again during authorization for each record.

## Remaining issue 7 scope

Not delivered: typed email, date editing and storage/format migration, radio/checkbox sets, ACF media writes, ACF taxonomy/user/post relationships, complex fields, other entity screens, Quick Add, field-specific bulk transformations, all-matching jobs, cancellation/resume, preview/scope confirmation, and recoverable bulk deletion. Existing current-page bulk replacement and per-record errors are inherited rather than expanded.

The complete acceptance matrix still needs real WordPress integration and browser evidence, including invalid/missing nonce, audit failure rollback, stale undo, reference-row failure, per-record permission changes and partial failure. Unit tests prove isolated adapter behavior; they do not claim actual ACF/MySQL transaction coverage.
