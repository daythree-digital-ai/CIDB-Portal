# CIDB Pre-Chatbot Portal

Independent PHP 8.2 / PostgreSQL application. It does not load or depend on the chatbot's PHP classes, routes, sessions, or database tables.

## Requirements

- PHP 8.2+ with `pdo_pgsql` and `curl`
- PostgreSQL 13+
- Composer (optional; no third-party PHP package dependencies)

## Run

1. `cd cidb-pre-chatbot-portal`
2. `composer install` (or skip; the app has no Composer packages)
3. Copy `.env.example` to `.env`, set database credentials, RPA endpoint and API key. Confirm the CRM and Email RPA field mappings with the RPA owner before production use.
4. Create the database and run `psql -d cidb_portal -f database/schema.sql`.
5. Create the first login: `php bin/create-user.php USERNAME EMAIL 'a-long-password-here'` (password must be at least 12 characters).
6. Start: `php -S 127.0.0.1:8080 -t public public/index.php`
7. Open <http://127.0.0.1:8080/login>.

Use HTTPS and set `SESSION_SECURE_COOKIE=true` in a deployed environment. Provision users with the CLI script; no public registration is exposed.

## Environment

See `.env.example`: `APP_ENV`, `APP_URL`, `SESSION_SECURE_COOKIE`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_TIMEOUT`, `RPA_BOT_ENDPOINT`, `RPA_BOT_API_KEY`, `RPA_BOT_TIMEOUT_MS`, `RPA_BOT_CONNECT_TIMEOUT_MS`, `RPA_COMPANY`, `RPA_SCENARIO_KEY`, and `RPA_CHANNEL`.

The portal's own environment values are read from `.env`; no chatbot environment file is loaded.

## RPA result polling

The submission response is saved in `rpa_response` / `rpa_response_text`. An `inserted` acknowledgement leaves `rpa_display_message` empty and the request pending. The external RPA process must populate that same row's `rpa_display_message` with the final user-facing message; this portal does not fetch a separate RPA result endpoint.

The existing result areas on the home, history and details pages poll `/request-status/{request-id}` every three seconds while awaiting a message. Each database lookup also checks the signed-in user's ID. Polling retries temporary errors with a delay up to 30 seconds and stops when the final message arrives, submission fails, or access is lost. Legacy JSON acknowledgements in `rpa_display_message` are treated as waiting without changing stored records. Raw technical data remains available in the existing details disclosure.

Run the regression checks with `php tests/request-result.php` and `node tests/polling.cjs` (Node is only needed for the frontend test). These checks use fixtures and do not submit live RPA requests.
