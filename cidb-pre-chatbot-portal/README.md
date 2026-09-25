# CIDB Pre-Chatbot Portal

Independent PHP 8.2 / PostgreSQL application. It does not load or depend on the chatbot's PHP classes, routes, sessions, or database tables.

## Requirements

- PHP 8.2+ with `pdo_pgsql` and `curl`
- PostgreSQL 13+
- Composer for the optional email module (Webklex PHP IMAP and PHPMailer)
- Email CLI extensions: `openssl`, `mbstring`, `iconv`, `libxml`, `dom`, `zip`, `fileinfo`, `curl`, `pdo_pgsql`

## Run

1. `cd cidb-pre-chatbot-portal`
2. `composer install` (required before using the email module)
3. Copy `.env.example` to `.env`, set database credentials, RPA endpoint and API key. Confirm the CRM and Email RPA field mappings with the RPA owner before production use.
4. Create the database and run `psql -d cidb_portal -f database/schema.sql`.
5. Create the first login: `php bin/create-user.php USERNAME EMAIL 'a-long-password-here'` (password must be at least 12 characters).
6. Start: `php -S 127.0.0.1:8080 -t public public/index.php`
7. Open <http://127.0.0.1:8080/login>.

Use HTTPS and set `SESSION_SECURE_COOKIE=true` in a deployed environment. Provision users with the CLI script; no public registration is exposed.

For a database used while CRM was removed, apply `psql -d cidb_portal -f database/migrations/20260924_restore_crm_requirement.sql` before deploying this version. CRM is required for all new portal submissions. Existing requests without CRM retain their original values; the legacy column remains nullable when needed to preserve those records. New databases created from `schema.sql` do not need this migration.

## Environment

See `.env.example`: `APP_ENV`, `APP_URL`, `SESSION_SECURE_COOKIE`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_TIMEOUT`, `RPA_BOT_ENDPOINT`, `RPA_BOT_API_KEY`, `RPA_BOT_TIMEOUT_MS`, `RPA_BOT_CONNECT_TIMEOUT_MS`, `RPA_COMPANY`, `RPA_SCENARIO_KEY`, and `RPA_CHANNEL`.

The portal's own environment values are read from `.env`; no chatbot environment file is loaded.

## RPA result polling

The submission response is saved in `rpa_response` / `rpa_response_text` for diagnostics only. New rows start with `status='processing'`; the backend does not subsequently assign the business status. RPA updates that same row's `status` to `success` or `failed`. Both form and email requests show **In progress** until that update, then **Success** or **Failed**. The legacy `rpa_display_message` column is no longer read, written or displayed by result handling.

The home, history and details pages poll `/request-status/{request-id}` every three seconds for the database status. Every lookup requires authentication: users can see their own form requests and shared email requests. Polling retries temporary errors with a delay up to 30 seconds and stops on a final RPA status or loss of access. Local email cases that were not submitted display their separate intake error. Uncertain submissions keep checking for a possible RPA update. Raw HTTP data remains in the technical disclosure and cannot determine the displayed business status.

Run the regression checks with `php tests/request-result.php` and `node tests/polling.cjs` (Node is only needed for the frontend test). These checks use fixtures and do not submit live RPA requests.

## Email reader (disabled until explicitly activated)

The email module has its own extraction, flat RPA payload, durable processing records, and shared history. It reads all senders in the dedicated `CIDB` folder after activation. TL notification dispatch, templates and retry requirements are temporarily commented out; the TL address is a placeholder. Unexpected incomplete cases remain recorded without sending to RPA. The worker does not start from a web request. Attachments and OCR are excluded.

Read [the email operations and configuration guide](docs/email-reader.md) before configuring or activating it. Representative extraction samples, RPA row correlation, retention, and production-runtime verification remain launch requirements. TL settings are not currently required. `php bin/email-reader.php check` performs local readiness checks only; `run` with email disabled opens no connections.

Existing databases need `database/migrations/20260925_email_reader.sql` after the CRM compatibility migration. Fresh `database/schema.sql` includes it using a psql-relative include. No migration is automatically applied by the application. The form history continues to work before the email migration is applied.

See [the exact migration, tables, columns, constraints and manual commands](docs/email-migration.md). If the email migration is already applied, the status-only change requires no additional SQL.
