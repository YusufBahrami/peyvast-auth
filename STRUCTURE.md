# Repository Structure

## Runtime

- `peyvast-auth.php`: plugin metadata, constants, Action Scheduler bootstrap loading, autoloader, lifecycle hooks, and boot entry point.
- `lib/action-scheduler/`: bundled Action Scheduler distribution (4.1.0), loaded through its official bootstrap at plugin load time so the runtime picks the newest registered version among plugins.
- `uninstall.php`: conditional options, transient, scheduled-action, table, metadata, challenge, delivery, identity, and log cleanup.
- `src/Core`: lifecycle, compatibility, and settings.
- `src/Domain`: phone, identity, OTP, authentication policy, and security rules.
- `src/Application`: OTP, password, registration, authentication sessions, and phone migration use cases.
- `src/Infrastructure/Persistence`: custom-table stores (including the encrypted OTP delivery queue), named database locks, and the identity index.
- `src/Infrastructure/Scheduling`: centralized Action Scheduler ownership (hooks, recurring-job lifecycle, unique scheduling) and the async OTP delivery handler.
- `src/Infrastructure/Providers`: WordPress HTML email plus supported SMS adapters; the email adapter preserves complete custom HTML documents and derives wrapper locale/direction from WordPress when a fragment needs a document shell.
- `src/Infrastructure/WordPress`: installer, guest session, privacy, redirects, and migration notices.
- `src/Infrastructure/Logging`: redacted operational logging with stable error categories.
- `src/Presentation/Rest`: public/admin REST routes with argument schemas, controllers, nonce/origin permission callbacks, and safe response DTOs.
- `src/Presentation/Frontend`: frontend asset loading and localized authentication configuration.
- `src/Presentation/Admin`: settings, logs, migration UI, provider configuration (including email), and user profile phone field.
- `src/Presentation/Privacy`: masked identifier presentation.
- `src/Integrations`: Blocks, Bricks, and WooCommerce adapters.

## Frontend and editor assets

- `assets/js/auth.js`: browser authentication state machine, API client, OTP input handling, timers, DOM-built notices (no raw `innerHTML` for messages), focus management, and security status.
- `assets/js/admin.js`: settings tabs, save flow, provider testing, logs, and security controls.
- `assets/js/admin-migration.js`: batch migration UI.
- `assets/css/auth.css`: frontend authentication styling.
- `assets/css/admin.css`: settings/log/profile styling.
- `assets/css/blocks-editor.css`: Block editor controls.
- `assets/img`: bundled SVG status/loading icons.
- `blocks/auth`: authentication block metadata and renderer.
- `blocks/back-button`: back-button block metadata and renderer.
- `blocks/editor.js`: Block editor controls and preview configuration.

## Localization

`languages/` contains the POT template, Persian PO/MO catalog, and WordPress JavaScript JSON catalogs. Runtime source strings use `peyvast-auth`; locale assets are the only intended source of Persian text.

## Testing

`tests/` contains an integration suite that runs against a local WordPress Studio site via the Studio-wrapped WP-CLI (`studio wp eval-file`, see `tests/run-tests.sh`):

- `test-bootstrap.php`: Action Scheduler bootstrap, version arbitration, recurring-ensure usage, coexistence checks, and regression coverage for keyed identifiers, redirect validation, guest nonce isolation, JWKS cache retention, migration locking, and fixed-window thresholds.
- `test-scheduler.php`: recurring scheduling, duplicate prevention, missing-job recovery and deactivation cleanup.
- `test-cleanup.php`: OTP challenge, log retention, and security-state purge semantics.
- `test-delivery.php`: encrypted delivery queue, challenge-state guards, retry backoff, idempotency, and per-channel retry after partial delivery.
- `test-migration.php`: chained migration and identity-index rebuild batches, idempotency, and interrupted-chain recovery.
- `test-flows.php`: enumeration-neutral responses for unknown identifiers and token-state invariants when registration or password updates fail.
- `test-migration-ui.js`: deterministic Node.js tests for migration notice state mapping and user-facing failure-message safety.
- `helpers.php`: shared assertion helpers.

## Documentation

- `README.md`: user-facing installation and behavior reference.
- `ARCHITECTURE.md`: component and flow reference, including the scheduling layer.
- `STRUCTURE.md`: this map.
- `changelog.txt`: version history and distribution release notes.
- `readme.txt`: WordPress.org plugin readme with changelog and upgrade notices.
- `SECURITY.md`: supported versions and private vulnerability reporting guidance.
- `CONTRIBUTING.md`: contribution rules.

## Release contents

Include runtime PHP, JavaScript, CSS, block, image, localization, the bundled `lib/action-scheduler` library, metadata, license, and user/developer documentation files. Exclude local editor settings, generated archives, and temporary workspace data. Verify the ZIP contains a single `peyvast-auth/` plugin directory and that no secrets or local test artifacts are included.
