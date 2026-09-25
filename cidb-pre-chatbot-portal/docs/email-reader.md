# PHP email reader: initial launch

## Current workflow

The PHP CLI reader processes post-activation arrivals in the dedicated `CIDB` folder. `EMAIL_FOLDER=CIDB` and `EMAIL_SELECTION_MODE=all` are the configured defaults. All senders are accepted; sender allowlists, subjects, reply headers, and automated-mail headers do not filter this folder's arrivals in `all` mode. Reading the folder does not depend on the unread flag. Extraction still holds conflicting/quoted case data for review rather than silently choosing an identity.

The body labels map to exactly five flat RPA keys:

| Body label | Payload key |
|---|---|
| `Name:` | `sCustomerName` |
| `Email to Cancel ID:` | `sEmail` |
| `NRIC:` | `sIdentificationNumber` |
| `State:` | `sLocationArea` |
| Constant `Email` | `sChannel` |

No CRM, scenario wrapper, or request ID is added to email payloads. Form validation and payload mapping remain independent. IDs remain strings. Attachments/OCR are excluded; MIME is fetched to decode its body but attachments are not decoded, extracted or saved. The configurable total message limit defaults to 2 MiB, including attachment bytes.

## RPA status owns the result for both sources

New form and email rows start with the existing initial `status='processing'`. From then on the PHP backend records submission diagnostics only: it never updates `portal_requests.status` from HTTP responses, retries, exceptions, or missing fields. RPA writes the final `success` or `failed` value directly to the correct row.

| Database status | UI label | Result polling |
|---|---|---|
| `processing` or `pending` | In progress | Continue awaiting RPA |
| `success` | Success | Stop |
| `failed` | Failed | Stop |

`/request-status/{id}` and all initial views derive results from this column. `rpa_display_message` is no longer consumed, written or returned by the result API. Its existing database column is retained for historical compatibility. HTTP response data remains available as submission diagnostics, never as the business outcome. Late submission responses cannot overwrite an RPA status that arrived first. Completion timestamps are not fabricated when RPA updates status alone.

Local reading/extraction issues and exhausted connection failures remain visible separately from the unchanged RPA status. Cases not submitted to RPA stop polling; uncertain submissions continue polling because RPA may still have accepted them. Form transport errors remain diagnostic and do not invent a final outcome.

The RPA team still needs to confirm how its updater identifies the correct portal row: the email payload has no request ID or CRM. The worker does not invent a correlation field or callback API. A final status written to a different table/row will not complete this portal's polling.

## TL notifications temporarily disabled

The TL placeholder is `EMAIL_TL_ADDRESS=tl@example.invalid`. It is not used to send mail. Notification job creation in `PgStore::extracted`, dispatch/retries in `Processor::run`, and notification readiness checks in `Config::problems` are commented out for initial launch. Template and retry settings are commented out in `.env.example`. Existing queued jobs are not dispatched either.

The SMTP notifier and storage methods remain implemented for a later restoration. A notification cannot be re-enabled merely by supplying credentials or a TL address; the commented code must be restored intentionally after confirmation. SMTP host, From address, TL address, wording and retry count do not block the current launch.

If incomplete input arrives despite the planned complete-email launch, the field-presence guard records the case and missing labels without sending invented values to RPA. The UI explains that TL notifications are disabled. This guard does not assign an RPA success/failure status.

## Storage and processing

See [exact migration instructions and schema changes](email-migration.md). The additive migration creates a source discriminator, mailbox cursor, intake records, RPA attempts, and a retained notification table. All authenticated users can access email history/details/polling; form records remain owner-only. History has All/Form/Email filters and source badges.

The CLI takes a mailbox-specific PostgreSQL advisory lock, captures/discovers UIDs, saves intake records before advancing progress, decodes and extracts bodies, persists requests, and records dispatch attempts before calling RPA. Transactions are short and do not span external network requests. One message's read/parser/transport failure does not block other messages; database persistence errors stop the cycle rather than permitting unrecorded external effects.

Each RPA submission allows at most one additional attempt, at least one minute later, only when non-dispatch is established (such as failure to resolve/connect). Timeouts, HTTP errors, invalid responses and interrupted dispatches are uncertain and not blindly retried. A delayed RPA status never triggers resubmission. Before retrying, the worker checks for an already-final RPA database status.

Mailbox identity includes host, port, username and folder. UID validity plus UID prevents duplicate intake. Activation records `UIDNEXT - 1`; existing messages are excluded. Restarts preserve progress. Changed UID validity stops processing for review rather than resetting the baseline. Do not delete intake records or reset activation to recover an uncertain case.

## Configuration and launch

The feature remains disabled by default; no schedule or live configuration is installed. `.env.example` lists the full configuration. Secrets belong in the deployment environment or protected untracked `.env`, not source or logs. Existing local `.env` values take precedence over environment variables under the portal's existing loader.

Confirmed connection values are `mail.daythree.com.my:993` for IMAP and `support.rentabot@daythree.com.my` for the mailbox. Implicit TLS uses certificate verification. Email RPA reuses the existing endpoint, `X-API-Key` and timeouts. Selection is confirmed; representative extraction samples, RPA row correlation, retention approval and production runtime verification remain launch gates. Do not set approval flags merely to bypass readiness checks.

Install locked Composer dependencies (Webklex PHP IMAP, PHPMailer). Required CLI extensions are openssl, mbstring, iconv, libxml, dom, zip, fileinfo, curl and pdo_pgsql. Native ext-imap and Python are not required. ZIP/fileinfo are package dependencies even though attachments are excluded. On the development machine these were loaded per command with `php -d extension=zip -d extension=fileinfo`; global PHP configuration was not changed.

After reviewing the production environment, migration and remaining contracts:

1. Apply the reviewed email migration to the intended database, with a backup and suitable permissions. Do not apply the base schema to an existing database.
2. Supply the confirmed configuration, keeping the scheduler disabled. `php bin/email-reader.php check` checks local readiness only; it does not connect or prove credentials/correlation work.
3. After explicit live-test approval, set `EMAIL_ENABLED=true` and run `php bin/email-reader.php activate` once to record the baseline. This contacts IMAP and writes database state but sends no requests.
4. Send an agreed complete real email into `CIDB` after activation. Run `php bin/email-reader.php run` for one processing cycle and verify the RPA-updated status in history.
5. When approved, invoke the absolute PHP binary and script path every minute through Linux cron or Windows Task Scheduler. The database lock prevents overlap. No web request starts the worker.

Every cycle is bounded by batch size, message size and connection timeouts. A slow cycle can exceed a minute; overlapping workers skip and subsequent cycles resume. Monitor exit codes, counts, mailbox health, pending/uncertain work and retry exhaustion. For rollback, disable the feature and schedule while retaining schema and progress. Do not reset historical statuses: earlier releases could have written them, and the database does not identify their writer.

## Tests

```text
php tests/request-result.php
node tests/polling.cjs
php -d extension=zip -d extension=fileinfo tests/email-unit.php
node tests/email-database.cjs
```

Tests cover status-only presentation, ignoring legacy display messages, folder-only selection, exact payloads, disabled notifications (including previously queued jobs), owner/shared authorization, retries and final-status races for both forms and emails. They do not contact live IMAP, SMTP or RPA services.

The optional isolated database harness uses an in-memory PostgreSQL/WASM instance on localhost port 55439. Install its test-only dependencies in the ignored directory with:

```text
npm install --prefix var/email-test-runtime --no-save --no-audit --no-fund @electric-sql/pglite@0.5.8 @electric-sql/pglite-socket@0.2.11
```

It passes an explicit test DSN to PHP, creates randomly named test schemas, and removes only those schemas. It never loads the portal `.env` or modifies the portal database. This does not replace production PostgreSQL concurrency, scheduler, TLS/authentication and end-to-end RPA acceptance checks.
