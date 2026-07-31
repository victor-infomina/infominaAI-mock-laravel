# InfominaAI SSM Mock

A Laravel mock of 7 SSM gateway endpoints, for pointing `infominaAI-BE`'s
`SSM_API_URL` at during dev/staging instead of the real SSM API.

## Endpoints

- `POST /get-search-entity`
- `POST /v2/get-company-profile-document`
- `POST /v2/get-bizprofile-document`
- `POST /v2/get-llp-current-profile`
- `POST /get-order-document`
- `POST /get-image-list` — Idaman document list for a `regNo` (no `entityType`; matches any case)
- `POST /get-image` — a single Idaman document's content by `regNo` + `verId`
- `GET /reports/{caseKey}.pdf` (used internally — `documentUrl` in the `get-order-document` response points here)

## LLM mock endpoint (for transformer-api's LLM_DOMAIN)

- `POST /{modelOp}/invoke` — mocks the LLM inference server `transformer-api`'s SSM service calls via its `LLM_DOMAIN` env var (`{LLM_DOMAIN}/{model}_{operation}/invoke`, e.g. `llm_summarize/invoke`, `claude_recommend/invoke`). No auth required — the real client sends none.
  - `*_summarize/invoke` and `*_search/invoke` → canned static text.
  - `*_recommend/invoke` → per-product recommendation logic (`tin`, `bir`, `nearby_companies`, and a generic fallback for anything else), tolerant of `able_to_purchase`/`purchased` as booleans or `'YES'/'NO'` strings.
  - Any other `{modelOp}` → a generic fallback response (still `200`, not an error).
  - To use: point `transformer-api`'s `services/ssm/utils/.env_ssm` → `LLM_DOMAIN` at this app's public URL instead of a real LLM host or a locally-run mock.

## Adding a test case (preferred: Sync Cases tool)

The preferred way to add a case is the **Sync Cases** admin tool, which pulls a real
entity straight out of the dev database/S3 and pushes an assembled case bundle to
wherever this app is actually deployed — no manual file assembly needed.

1. Run this app locally with `APP_ENV=local`, pointed at the dev DB and S3 bucket via
   the existing `BE_DB_*` / `SSM_S3_*` env vars (see "Environment variables" below).
2. Log in (`/login`) and visit `/admin/sync-cases` — only reachable when
   `APP_ENV=local` (404s otherwise).
3. Search by company/business/LLP name or regNo, pick a result to open it, optionally
   select which Idaman documents to include, then click **Sync to shared host**.
4. This requires `SSM_MOCK_REMOTE_URL` plus an `admin_sync`-purpose credential
   (`SSM_MOCK_ADMIN_SYNC_KEY` / `SSM_MOCK_ADMIN_SYNC_SECRET`) pointed at wherever this
   app is actually deployed (the instance `infominaAI-BE`'s `SSM_API_URL` points at).
   Generate that credential from the `/tokens` UI on the **deployed** instance — pick
   `admin_sync` as the purpose — and copy the plaintext key/secret into the **local**
   instance's `.env` (see `DEPLOY.md` step 4 for details). The push lands on
   `POST /admin-api/cases` on the deployed instance, which writes the case folder
   there directly — no rebuild/redeploy needed.

If you can't run this app locally against the dev DB (or need to hand-craft a case
that doesn't exist in the dev DB), fall back to the manual method below.

### Manual fallback: hand-crafted case folder

Create a folder under `storage/app/ssm-fixtures/cases/{caseKey}/` containing:

- `meta.json` — `{ "regNo": "...", "companyName": "...", "entityType": "company" | "business" | "llp" }`
- One of `company-profile.json` / `business-profile.json` / `llp-profile.json`, matching `entityType` — the raw JSON body SSM would return for that endpoint (its `requestRefNo` field is rewritten to `{caseKey}` automatically on every response).
- `report.pdf` — the report file returned via `get-order-document` → `/reports/{caseKey}.pdf`.
- Optionally, an `idaman/` subfolder for Idaman document endpoints:
  - `idaman/list.json` — a JSON array of `{ "verId": "...", "formType": "...", "dateFiler": "..." }` entries.
  - `idaman/{verId}.<any-extension>` — the raw document content for each entry, base64-encoded automatically when served via `get-image`.
  - Cases without an `idaman/` folder simply have no Idaman documents — `get-image-list` returns a "not found" response for them.

No rebuild or redeploy is needed — drop a new folder in and it's immediately queryable.

## Environment variables

- `APP_URL` — must be this app's real public URL; it's used to build the `documentUrl` field `infominaAI-BE` fetches directly.
- `SSM_MOCK_CASES_PATH` — optional override for the case-folder directory (defaults to `storage/app/ssm-fixtures/cases`).
- `BE_DB_HOST` / `BE_DB_PORT` / `BE_DB_DATABASE` / `BE_DB_USERNAME` / `BE_DB_PASSWORD` — read-only connection to `infominaAI-BE`'s database, used by the Sync Cases tool (and `ssm:download-response`) to look up entities/requests. Copy values from `infominaAI-BE/env/.env.devcontainer.local`.
- `SSM_S3_ACCESS_KEY_ID` / `SSM_S3_SECRET_ACCESS_KEY` / `SSM_S3_REGION` / `SSM_S3_BUCKET` — the real S3 bucket the SSM raw/transformed JSON and PDF reports live in, used by the Sync Cases tool to pull Idaman document content.
- `SSM_MOCK_REMOTE_URL` / `SSM_MOCK_ADMIN_SYNC_KEY` / `SSM_MOCK_ADMIN_SYNC_SECRET` — push target and `admin_sync`-purpose credential for the local Sync Cases tool (see above); unused unless you're running `/admin/sync-cases` locally.

## Admin UI and API credentials

Gateway requests authenticate against per-client `ApiClient` records (key + secret
pairs, expiring, revocable) stored in the database — there's no static shared secret
anymore.

- **Admin login** (`/login`): a single admin account, managed via a `ssm:make-admin`
  artisan command rather than self-registration.
  - Local dev: `php artisan ssm:make-admin {email} {password}`.
  - Release builds: `build-release.sh` runs this automatically (see `DEPLOY.md`),
    seeding the account into the shipped `database/database.sqlite` with an
    `ADMIN_EMAIL` / `ADMIN_PASSWORD` you pass in or that it generates.
- **Token management** (`/tokens`, requires login): generate and revoke the
  key/secret pairs that `infominaAI-BE` authenticates gateway requests with. A
  generated secret is shown only once, at creation time — copy it immediately and
  set it as `infominaAI-BE`'s `SSM_API_KEY` / `SSM_API_SECRET`.

## Deployment

See `DEPLOY.md` for the cPanel (no-SSH) deployment steps.
