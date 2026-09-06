# ADR 0013: Optional WooCommerce order read model

Status: Proposed module; bootstrap and real-store acceptance pending.

Use WooCommerce's public order query and data-object APIs behind a bounded query service. An explicit admin table consumes that service. Avoid treating orders as WordPress posts because HPOS can move authoritative order storage. Core plugin loading does not require WooCommerce.

The query service returns at most 50 rows and only a small fixed projection. There is no arbitrary query payload or raw SQL. Orders and refunds are separate record types. Duplicate or mixed-type results reject the page, avoiding accidental double counting by consumers.

Monetary values remain source decimal strings, paired with the source record's currency. This module performs no arithmetic, conversion, or aggregate metric. PHP floats would discard precision and combining currencies without a conversion policy would create misleading metrics.

Capability checks protect both the page request and each record; refunds use the parent order permission. No data is edited or exported. Shared configuration and existing adapters remain untouched.

Real-store consumer and HPOS/legacy checks are required before calling this integration accepted. Unit gateway doubles prove the boundary contract only. Licensed integration availability is reported separately, never replaced by mock parity evidence.
