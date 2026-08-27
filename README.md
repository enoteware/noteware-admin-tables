# Noteware Admin Tables

Noteware Admin Tables is an open-source WordPress plugin for building useful admin list screens. It aims to provide configurable columns, filters, sorting, safe editing, export, saved views, and integrations such as Advanced Custom Fields.

The project is in early development. It is not ready for production sites yet.

## Supported versions

The first review milestone targets WordPress 6.5 or later and PHP 8.1 or later. The continuous integration matrix checks the minimum versions and the current supported versions. A passing current-version sandbox does not replace the minimum-version checks.

## Project principles

- Build original, clean-room code from public WordPress APIs and documented behavior.
- Keep the core useful without paid extensions.
- Treat writes as security-sensitive operations with capability, nonce, validation, and audit checks.
- Keep site-specific configuration outside the reusable plugin.
- Make large admin tables predictable and fast.
- Follow WordPress coding, accessibility, privacy, and internationalization practices.

## Current capabilities

- Configurable columns for native post fields, post metadata, ACF fields, and registered taxonomies
- Display of text, number, boolean, select, date, image, and link values, plus computed permalinks
- Typed sorting, and filters with `is`, `is empty`, and `has a value` operators
- Deny-by-default inline editing for allowlisted metadata scalars, ACF text, link, and select fields, taxonomy terms, post titles, post slugs, and featured images
- Bulk editing with one capability check, one transaction, and one audit record per record
- An append-only write audit and a conditional undo that survives a page reload
- Explicit column order, replacement of selected built-in columns, and site-owned column widths

## Planned capabilities

- User, media, comment, and taxonomy list tables
- Relationship columns
- CSV export based on the active view
- Saved personal and shared views
- Conditional formatting
- ACF integration, followed by WooCommerce and selected ecosystem adapters
- Import helpers for teams moving from another admin-column tool

See [ROADMAP.md](ROADMAP.md) for the delivery plan and boundaries.

## Local WordPress sandbox

The sandbox runs WordPress and MariaDB in Docker. It binds WordPress to loopback by default. Set `NAT_WP_BIND_ADDRESS` and `NAT_WP_URL` in the ignored `.env` file when a trusted remote device needs access.

```bash
cp .env.example .env
bash scripts/sandbox-up.sh
```

The default URL is `http://127.0.0.1:8097`.

Useful commands:

```bash
docker compose ps
docker compose logs -f wordpress
docker compose run --rm wpcli plugin list
docker compose down
```

Do not commit `.env`. It contains local sandbox credentials.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request. Security reports follow [SECURITY.md](SECURITY.md).

Developer documentation:

- [Site configuration](docs/developer/configuration.md)
- [Adapter authoring](docs/developer/adapter-authoring.md)
- [Testing and sandbox proof](docs/developer/testing.md)
- [Security and performance checks](docs/developer/security-performance.md)
- [Clean-room development record](docs/clean-room.md)
- [Architecture decisions](docs/adr/README.md)

## License

Noteware Admin Tables is free software licensed under GPL-2.0-or-later. See [LICENSE](LICENSE).
