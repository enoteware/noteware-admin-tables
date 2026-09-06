# ADR 0011: Scalar ACF editing preserves stored state

Status: Proposed module, public allowlist integration pending.

Expand ACF writing to number and true/false fields using the existing public types. An explicit site allowlist remains required. Authorization checks the post, value metadata key, and any explicit policy on the ACF reference key. Unknown and complex fields remain read-only.

For numbers and booleans, snapshots read the stored metadata through WordPress's public metadata API. Display formatting is not the audit state: a formatted boolean collapses an explicit empty string into false. Raw strings preserve absence, empty strings, `0`, `1`, and decimal precision. Existing boolean presentation still renders Yes/No. Existing formatted-boolean snapshots were display-only because these types were not previously editable.

All mutations still use ACF's public `update_field()` and `delete_field()` functions with the field key. Value and reference rows are checked together; inconsistent or orphaned references reject the operation. Expected-state checks now protect adapter writes and removals in addition to the controller's locked snapshot comparison.

Optional numbers and booleans can store an explicit empty string. Removing a value remains a separate operation. Required numbers accept zero; required true/false fields require `1`, consistent with ACF's field validation. Required fields cannot be removed. Undo revalidates the target against current constraints.

Live field constraints are refreshed during authorization, including the check after locking and each bulk record. Numeric minimum and maximum comparisons use decimal strings rather than binary floating point. Unsupported bound syntax fails closed. Step is an increment hint, not a new transformation or server-side interval rule.

The existing controller owns nonce checking, transactions, audit creation, rollback, and conditional undo. This change does not create a second write route or bypass these boundaries.

## Public API sources

ACF field-key update contract:
https://www.advancedcustomfields.com/resources/update_field/

ACF field-object contract:
https://www.advancedcustomfields.com/resources/get_field_object/

ACF number field:
https://www.advancedcustomfields.com/resources/number/

ACF Free true/false validation behavior, GPL source consulted for behavior only:
https://github.com/AdvancedCustomFields/acf/blob/master/includes/fields/class-acf-field-true_false.php

ACF Free number validation behavior, GPL source consulted for behavior only:
https://github.com/AdvancedCustomFields/acf/blob/master/includes/fields/class-acf-field-number.php

No source text or implementation was copied.
