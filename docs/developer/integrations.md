# Additional integration matrix

This document inventories the scope and the current implementation. Every activated-plugin test cell remains **unverified**. No integration in this slice supports writes, sorting, filtering, export or inline editing. Public API detection is implemented only for the two readers below. The catalog deliberately reports unknown availability for the remaining integrations.

| Integration | Field/surface inventory | Current module |
| --- | --- | --- |
| Meta Box | Explicit text, textarea, number, email, URL, checkbox, select and radio post fields; clone/multiple excluded | Bounded read-only API projection with live field definition checks |
| JetEngine | Custom fields and custom content type REST surfaces | Not implemented or activated-tested |
| Toolset Types | Post/user custom fields and field definitions | Not implemented or activated-tested |
| Pods | Scalar fields, relationship/file values and registered content types | Not implemented or activated-tested |
| Gravity Forms | Form definitions, entry scalar/compound values and paged entries | Not implemented or activated-tested |
| Yoast SEO | Computed post title, description and canonical URL | Bounded read-only Surfaces API projection |
| Rank Math | SEO output and plugin hooks | Not implemented or activated-tested |
| SEOPress | SEO output and documented hooks | Not implemented or activated-tested |
| The Events Calendar | Event start/end dates and decorated event posts | Not implemented or activated-tested |
| BuddyPress | User-requested member/profile scope; detailed API inventory still pending | Not implemented; documentation retrieval and activated tests pending |
| Beaver Builder | Separate page-builder coexistence check | Not tested; no content parser or write path |
| Media Library Assistant | Separate media/assistant listing, taxonomy and attachment metadata coexistence check | Not tested; no screen adapter |

## Implemented interfaces

`MetaBoxReader(new PublicFunctions(), $fieldIdToTypeMap)->readPage($postIds, $fieldId)` accepts at most one hundred explicitly registered field definitions and two hundred IDs. It checks `edit_post` for every ID before API reads and rejects changed definitions, clone/multiple fields and structured values. Register only fields intended for the current admin view. It does not discover or expose all plugin fields automatically.

`YoastReader(new PublicFunctions())->readPage($postIds, $field)` accepts `title`, `description` or `canonical`. It checks object edit access, reads the documented `meta->for_post()` surface and returns bounded text/null projections. Duplicate input IDs are read once. It supports the documented magic property surface without assuming a concrete vendor class.

Both readers return post-ID-keyed `ReadResult` objects. The value remains a scalar or null; storage presence is unknown and editability is false. Unsupported API shapes, absent dependencies, unauthorized rows and oversized results throw exceptions. Renderers must escape values at the final boundary.

`IntegrationCatalog::report($api)` always distinguishes `api_available` (true/false/null), `read_fields`, `writes = false`, and `activated_tested = false`. Availability proves only that documented entry-point functions exist. It does not establish plugin versions, schema compatibility or successful real reads.

## Reproducible fixture specification

The unit contract fixtures are generated in `AdditionalIntegrationTest`: three synthetic post IDs return zero, an empty string and null; a changed clone definition is rejected; one denied post prevents every API read; a magic SEO surface supplies generic title and reserved example URL values. No real plugin/database fixture is created by these tests.

Before claiming activated support, create an isolated WordPress database and record WordPress, PHP and exact plugin versions. For each supported scalar field, generate absent, empty, zero, false and ordinary values through the integration's own setup UI or documented API. Confirm which states its API actually distinguishes. Add a denied editor and two-hundred-row page; inspect actual rendered output and record query counts. Keep unsupported structured values explicitly read-disabled. Other integrations need their own reproducible fixtures before their matrix cells change.

## Dependency artifacts and evidence gaps

No optional dependency ZIPs were installed or activated in this task. Testing JetEngine, Toolset Types and Gravity Forms requires the corresponding authorized distribution artifacts. Premium editions or extensions of any integration, including Beaver Builder, require their exact authorized artifacts before those edition-specific cells can be tested. Do not obtain redistributed licensed packages or copy proprietary source into this repository. Free editions also require exact recorded versions and actual activation; a public download URL is not test proof.

Remaining scope includes every integration beyond the two narrow readers, real per-screen fixtures, performance and coexistence testing, Gravity Forms capability handling, BuddyPress profile visibility, structured fields/relationships, and all write adapters. No issue-completion claim is made.

## Public references reviewed

Meta Box field settings:
https://docs.metabox.io/functions/rwmb-get-field-settings/

Meta Box scalar value API:
https://docs.metabox.io/functions/rwmb-get-value/

JetEngine REST overview:
https://crocoblock.com/knowledge-base/features/rest-api-overview/

Toolset public field API:
https://toolset.com/documentation/programmer-reference/toolset-custom-field-api/

Pods field values:
https://docs.pods.io/code/pods/field/

Gravity Forms API examples:
https://docs.gravityforms.com/getting-started-gravity-forms-api-gfapi/

Gravity Forms entry capability reference:
https://docs.gravityforms.com/searching-and-getting-entries-with-the-rest-api-v2/

Yoast SEO public surfaces:
https://developer.yoast.com/customization/apis/surfaces-api/

Rank Math developer hooks:
https://rankmath.com/kb/filters-hooks-api-developer/

SEOPress hooks:
https://www.seopress.org/support/hooks/

Event date API:
https://docs.theeventscalendar.com/reference/functions/tribe_get_start_date/

Beaver Builder documentation index:
https://docs.wpbeaverbuilder.com/

Media Library Assistant official listing and documentation links:
https://wordpress.org/plugins/media-library-assistant/

BuddyPress detailed public API reference retrieval was unsuccessful during this task; its inventory remains pending rather than inferred from an unavailable page.
