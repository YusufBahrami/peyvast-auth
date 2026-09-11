# Peyvast Auth

Peyvast Auth is a WordPress authentication plugin with phone and email OTP login, password authentication, registration, password recovery, optional Google Sign-In, Iranian SMS and WordPress email providers, and integrations for WooCommerce, Bricks, and Blocks.

## What the plugin does

The plugin renders an authentication interface and exposes the `peyvast-auth/v1` REST API. A visitor submits an identifier, receives a time-limited OTP through the configured provider, verifies it, and then either signs in or completes registration. Existing users can also sign in with a password. Password reset requires a verified reset OTP and never signs the user in automatically.

## Authentication flows

### WordPress email template

Email is configured under **Providers → Email provider**; there is no separate Email settings tab. The settings namespace is `providers.email` with `sender_name`, `sender_address`, `subject`, and `body`. The built-in WordPress mail provider sends HTML with `Content-Type: text/html; charset=UTF-8`. The default OTP body uses the plugin's standard template structure and the placeholders `{otp}`, `{expiration}`, `{site_name}`, `{email}`, and `{identifier}`. The template itself does not hardcode a document language or RTL direction. When a template is stored as an HTML fragment, the mail adapter adds a document shell using the current WordPress locale and `is_rtl()` direction; a complete custom `<html>` document is preserved as supplied.

Custom templates are therefore not rewritten to Persian or RTL. Only the OTP value remains explicitly LTR inside the default template so numeric codes render consistently.

### Provider configuration

SMS configuration remains under `providers.sms` and `providers.sms_credentials`. Email configuration is stored under `providers.email`. The default sender name is the current site name; the default sender address is `noreply@{site_domain}` derived from the current WordPress site URL.

The administrative `settings` endpoint accepts the same `peyvast_auth_settings[providers][email]` structure as the settings UI. The `test-provider` endpoint accepts `channel=sms` for SMS tests or `channel=email` with an `email` recipient for WordPress mail tests. The `peyvast_auth_settings` REST argument is an object and preserves the nested settings structure; authorization, nonce verification, and `Settings::sanitize()` remain server-side enforcement boundaries.

### WordPress user profile phone

The Peyvast Mobile Number field on user profiles accepts supported national and international formats, including Persian/Arabic digits and common spaces, parentheses, and hyphens. Valid input is normalized to the plugin's canonical country-code representation. Empty input removes the primary phone identity. Invalid, duplicate, username-conflicting, nonce-invalid, or identity-index-unavailable changes are rejected through the WordPress profile validation lifecycle.

### Phone or email OTP login

1. The browser submits `send-otp` with an identifier and purpose `otp_login`.
2. The server normalizes the identifier, checks configured capabilities, resolves existing identity data, evaluates server-side security buckets, and applies the resend cooldown.
3. A challenge stores only an HMAC OTP digest, expiry, purpose, a site-keyed HMAC identifier hash, delivery channels, and an opaque guest-session binding.
4. The code is delivered through the configured SMS or email adapter. All configured channels are mandatory: delivery is asynchronous, the code is encrypted at rest in the delivery queue and sent by a background Action Scheduler job, and only channels that failed are retried, so the request does not wait for provider latency. The queued job re-validates the challenge before sending, so a code can never be delivered after the challenge is invalidated, replaced, expired, or consumed. If queue persistence or scheduling is unavailable, the challenge is invalidated and the request fails fast; provider HTTP delivery is never performed inside the authentication request.
5. The browser submits `verify-otp` with the challenge ID and code.
6. Successful verification creates a short-lived, single-use verification token.
7. `otp-login` consumes that token and creates the WordPress session. If no account exists, `register` consumes the registration token instead.

### Logout redirects

The Redirects settings provide independent destinations for WooCommerce customer logout and native WordPress logout. Disable preserves each platform's existing default. Specific pages and custom URLs are restricted to internal site destinations. Login/registration origin fallback is shown and used only when Return to origin is selected.

OTP delivery is asynchronous, but the frontend reports successful handoff simply as **The verification code has been sent.** Resend reports **A new verification code has been sent.** Background delivery state is not presented to users. The OTP information area contains only its primary information message.

### Password login

`password-login` resolves a phone, email, or username according to settings, checks the WordPress password, runs `wp_authenticate_user` and then the full core `authenticate` filter chain seeded with the verified user (so SSO, 2FA, membership, and audit plugins participate without being able to skip the password check), applies the plugin login policy, optionally rehashes the password, and creates the WordPress session.

### Registration

Registration requires a verified phone OTP. The service acquires phone, email, and username locks, rechecks identity ownership, validates enabled fields, creates the WordPress user, stores the canonical phone metadata, synchronizes the phone identity index, synchronizes WooCommerce, creates the session, and only then finalizes the OTP token. The token stays in a recoverable processing state until every coupled write succeeds: any pre-finalization failure releases the token for a clean retry and rolls back the created account.

### Password recovery

### Password recovery

`send-otp` with purpose `password_reset` only proceeds for an existing phone or email identity; unknown identifiers receive a success-shaped neutral response so account existence is not disclosed. The verified reset token is bound to the intended user and held in a recoverable processing state until the new password is persisted and verified and all existing WordPress sessions are revoked; it is consumed only after every step succeeds, and released for a clean retry on failure. The user must then sign in separately.

### Google sign-in

When enabled, `google-login` verifies the Google ID token locally: JWT structure, algorithm, signature, issuer, audience, subject, email claims, and key retrieval are checked. Google JWKS data is fetched through the WordPress HTTP API, size-limited, and cached.

## Security model

- State-changing REST requests require a WordPress nonce, same-origin validation, and route-level argument schemas.
- Public permission callbacks verify the nonce/origin; administrative routes require `manage_options`.
- Guest nonce identities use a negative namespace distinct from logged-in WordPress user IDs; guest challenges are also bound to a random HttpOnly, SameSite cookie.
- OTPs, verification tokens, and identifier hashes use keyed digests derived from the site identifier key; plaintext identifiers and codes are not stored in challenges.
- Challenges expire, have bounded attempts, and are single-use.
- Server-side IP, network, identifier, and combination security buckets provide rate limiting and progressive blocking; fixed-window limits reject requests at the configured threshold.
- Unknown email OTP logins and unknown password-reset identifiers return the same success-shaped response as known accounts (nothing stored, no delivery) so account existence is not disclosed; rate limits still apply identically.
- Redirect page IDs are accepted only for published or private WordPress pages, and custom redirects are host-validated.
- Google JWKS refreshes do not discard an existing cache when the upstream endpoint is unavailable.
- Provider credentials are sanitized, never sent in request URLs, never returned by success responses, and redacted from provider diagnostics; raw provider response bodies are not stored in operational logs.
- Delivery-payload encryption keys are derived from the WordPress deployment secrets (`wp_salt('auth')`), never from a database-stored option.
- Operational logs redact sensitive values, use stable error categories, and support retention cleanup.
- HTTPS is strongly recommended, especially for authentication and provider credentials.

## Installation and configuration

1. Place the repository in `wp-content/plugins/peyvast-auth`.
2. Activate **Peyvast Auth**.
3. Open **Peyvast Authentication → Settings**.
4. Configure at least one SMS provider or WordPress email delivery.
5. Add the **Peyvast Auth** Blocks or Bricks element to a page.
6. Test all enabled flows on staging before production use.

Activation creates five InnoDB tables using the WordPress database prefix:

- `{prefix}peyvast_auth_otp_challenges`
- `{prefix}peyvast_auth_phone_identity`
- `{prefix}peyvast_auth_logs`
- `{prefix}peyvast_auth_security_state`
- `{prefix}peyvast_auth_otp_deliveries` (encrypted at-rest queue for asynchronous OTP delivery)

The plugin stores its settings and operational keys under the `peyvast_auth_` namespace. Uninstall cleanup is conditional on the administrator's **delete data on uninstall** setting and removes migration cancellation state together with the other plugin-owned options.

## Background jobs

The plugin bundles Action Scheduler in `lib/action-scheduler` and loads it through its official bootstrap, registering a version so it coexists with WooCommerce or any other plugin that provides Action Scheduler (the newest registered version wins). All background work runs on Action Scheduler in the dedicated `peyvast-auth` group:

- **Recurring jobs:** expired OTP challenge cleanup (every 15 minutes), log retention cleanup (daily), security-state cleanup (daily), and a schema watchdog (daily).
- **Chained single-action jobs:** phone migration batches and phone-identity index rebuild batches — one batch per action, idempotent, serialized by named database locks across the complete state-lock acquisition sequence, retried with exponential backoff, and re-armed by a watchdog when a chain stalls.
- **Async delivery:** OTP provider delivery and lazy phone migration run outside the request.

Recurring jobs are created on activation and recovered if missing; deactivation cancels only Peyvast Auth-owned actions. Batch migration notices are presentation-only and reflect the backend `idle`, `busy`, `running`, `needs_review`, `completed`, and `failed` states.

## Deployment notes

WordPress Studio uses SQLite through its compatibility layer for local development. Production deployments should use MySQL or MariaDB because the plugin relies on MySQL-native row/advisory locking and bounded SQL operations; SQLite compatibility in Studio is not a production support promise. The plugin detects a non-MySQL database engine at runtime and shows an admin notice explaining that MySQL/MariaDB is required for production.

## REST endpoints

Public endpoints: `send-otp`, `delivery-status`, `verify-otp`, `otp-login`, `register`, `password-login`, `reset-password`, `google-login`, `logout`, and `security-status`. Administrative endpoints: `settings`, `security-blocks`, `migrate-user-phone`, `migration-status`, `migration-notice`, `validate-phone-meta`, and `test-provider`.

All endpoints are POST routes with argument schemas. Public permission callbacks verify the nonce and origin; administrative routes additionally require `manage_options`. Identity, guest-session, and security checks are performed by the controller/service layer.

## Integrations

- **Blocks:** authentication and back-button blocks with server rendering.
- **Bricks:** authentication/back-button elements and dynamic tags.
- **WooCommerce:** optional customer phone synchronization, account/checkout/order-receipt redirects, notices, and HPOS compatibility declaration.
- **WordPress Privacy:** phone export and erasure, related OTP/index/log cleanup.

## Phone data and privacy

Phone numbers are normalized into a canonical Iranian mobile representation. The primary phone is stored in `_peyvast_auth_phone`; a keyed canonical hash is stored in `_peyvast_auth_phone_canonical`; the identity table accelerates lookup. Privacy erasure removes these values and related plugin-owned rows.

See `ARCHITECTURE.md` for detailed components and data flow, `STRUCTURE.md` for the repository map, `SECURITY.md` for vulnerability reporting, `CONTRIBUTING.md` for development rules.

## Production Architecture Notes (1.0.0)

Peyvast Auth 1.0.0 is intended for single-site WordPress installations. Multisite activation is explicitly rejected.

Phone identity indexing is performed in background batches. Authentication requests never perform a database-wide phone-meta scan or index rebuild. Rebuilds use keyset cursors and resumable Action Scheduler jobs.

OTP provider delivery is asynchronous. The REST response distinguishes queue persistence from final delivery, and the frontend consumes `delivery-status` with bounded polling. When phone OTP is configured for secondary email delivery, the API exposes only the presence of the email channel and a privacy-safe masked presentation; the raw account email is never returned.
