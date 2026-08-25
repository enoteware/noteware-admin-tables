# Clean-room development record

Noteware Admin Tables is original GPL-2.0-or-later code. Contributors implement behavior from public platform contracts and this repository's own requirements.

## Allowed sources

- Public WordPress developer documentation and WordPress core source distributed under the GPL.
- Public ACF developer documentation and ACF Free behavior reached through documented functions.
- Public PHP, JavaScript, browser, accessibility, and database standards.
- Original requirements, tests, fixtures, and designs created for this repository.
- Dependencies with a recorded GPL-compatible license.

## Prohibited inputs

- Proprietary source code, decompiled code, licensed commercial packages, or copied test suites.
- Proprietary assets, icons, screenshots, layouts, text, names, or visual trade dress.
- Private field keys, client names, production URLs, credentials, database exports, or real user data.
- Copied documentation or examples from a competing product.
- Behavior inferred by bypassing access controls or license checks.

## Contribution record

Each pull request states:

1. The contribution is original or names every reused GPL-compatible source.
2. The implementation uses public APIs.
3. The fixtures contain only generic generated data.
4. No private or licensed material is present.
5. New dependency licenses were checked.

Reviewers inspect the diff and run the repository clean-room, secret, and dependency checks. A scan supports human review. It does not replace it.

## Public API references for the first milestone

WordPress:

- https://developer.wordpress.org/reference/functions/get_post_types/
- https://developer.wordpress.org/reference/classes/wp_query/
- https://developer.wordpress.org/reference/classes/wp_meta_query/
- https://developer.wordpress.org/reference/functions/get_post_meta/
- https://developer.wordpress.org/reference/functions/update_post_meta/
- https://developer.wordpress.org/reference/functions/current_user_can/
- https://developer.wordpress.org/reference/functions/check_ajax_referer/
- https://developer.wordpress.org/reference/functions/dbdelta/

ACF:

- https://www.advancedcustomfields.com/resources/get_field/
- https://www.advancedcustomfields.com/resources/get_field_object/

These links identify API contracts. No prose, assets, or code are copied into the plugin from them.
