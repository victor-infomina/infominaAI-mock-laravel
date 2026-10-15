# InfominaAI SSM Mock

A Laravel mock of the SSM, AsiaVerify, and DNB gateways, for pointing
`infominaAI-BE`'s `SSM_API_URL`, `ASIAVERIFY_API_URL`, and `DNB_API_URL` at
during dev/staging instead of the real APIs.

## Endpoints

### SSM (Malaysia)

- `POST /get-search-entity`
- `POST /v2/get-company-profile-document`
- `POST /v2/get-bizprofile-document`
- `POST /v2/get-llp-current-profile`
- `POST /get-order-document`
- `POST /get-image-list` — Idaman document list for a `regNo` (no `entityType`; matches any case)
- `POST /get-image` — a single Idaman document's content by `regNo` + `verId`
- `GET /reports/{caseKey}.pdf` (used internally — `documentUrl` in the `get-order-document` response points here)

### AsiaVerify (Vietnam / Thailand / China)

- `POST /token/create` — issues a short-lived token, checked via `Authorization`/`Sign` headers against an `asiaverify`-purpose `ApiClient`.
- `GET /{country}/search?keyword=...` — `{country}` is a 3-letter ISO code (`VNM`/`THA`/`CHN`); requires the `token` header from `/token/create`.
- `POST /{country}/basic` — body `{"input": "<companyId>", "language": "ALL"}`; requires the same `token` header.

All three always respond HTTP `200`; a `code` field inside the body (`"200"`/`"404"`/`"401"`) signals the outcome, matching the real gateway.

### DNB (Singapore / Indonesia)

- `POST /dnb` — single endpoint, raw XML in/out, routed internally by the body's `<PRODUCT>` tag (`XCNS`/`BCP` for Singapore, `XICNS`/`XICDS` for Indonesia). Credentials (`USER_ID`/`PASSWORD`) travel inside the XML body, checked against a `dnb`-purpose `ApiClient`; missing/invalid → real HTTP `401`. Otherwise always `200` with an XML body — an empty `<REPORT></REPORT>` or empty inner list means "not found".

## LLM mock endpoint (for transformer-api's LLM_DOMAIN)

- `POST /{modelOp}/invoke` — mocks the LLM inference server `transformer-api`'s SSM service calls via its `LLM_DOMAIN` env var (`{LLM_DOMAIN}/{model}_{operation}/invoke`, e.g. `llm_summarize/invoke`, `claude_recommend/invoke`). No auth required — the real client sends none.
  - `*_summarize/invoke` and `*_search/invoke` → canned static text.
  - `*_recommend/invoke` → per-product recommendation logic (`tin`, `bir`, `nearby_companies`, and a generic fallback for anything else), tolerant of `able_to_purchase`/`purchased` as booleans or `'YES'/'NO'` strings.
  - Any other `{modelOp}` → a generic fallback response (still `200`, not an error).
  - To use: point `transformer-api`'s `services/ssm/utils/.env_ssm` → `LLM_DOMAIN` at this app's public URL instead of a real LLM host or a locally-run mock.

## Payment gateway mock (Senangpay protocol)

Stands in for Senangpay so `infominaAI-BE`'s `mock` payment gateway row can point at
this deployed app instead of the localhost-only NestJS mock (`infominaAI-mock`). Speaks
the same hosted-form protocol, so BE/FE need no code change beyond selecting the row.

- `POST /payment/{merchantId}` — the FE form-POSTs the Senangpay fields here
  (`order_id`, `amount`, `detail`, `name`, `email`, `hash`, optional `return_url`).
  Verifies the FE's submission hash (`HMAC-SHA256(key, key+detail+amount+order_id)`) the
  way the real gateway does, but only shows a green/red banner rather than rejecting, so a
  broken FE hash is visible without blocking the test.
  Renders a page where the tester picks the outcome (`1` success, `0` failed,
  `2` pending authorization) and confirms the frontend origin to return to. That origin
  is auto-detected from the browser's `Origin`/`Referer` headers on the cross-origin form
  POST, so one deployment serves dev and staging without configuration; an explicit
  `return_url` field wins, and `MOCK_REDIRECT_URL` is only the fallback.
- `POST /payment/{merchantId}/complete` — signs the callback params and `302`s the
  browser to `{return_url}/payment/result?status_id=&order_id=&transaction_id=&msg=&hash=`,
  exactly like Senangpay's return-URL redirect. The FE then POSTs those to BE's
  `/payment/update`, which verifies the hash with its own `SENANGPAY_SECRET_KEY`.
- `POST /payment/generate-hash` — JSON `{statusId, orderId, transactionId, msg}` → `{hash}`;
  handy for scripting callbacks. Hash is `HMAC-SHA256(key, key+status_id+order_id+transaction_id+msg)`.

No credentials on any of these — the browser posts straight from the FE, as with the
real gateway. Access is instead restricted by frontend host: the FE is identified from
the `return_url` field / `Origin` / `Referer`, and only hosts listed in
`SENANGPAY_SECRET_KEYS` are served (`403` otherwise). That same map supplies the secret
used for that host, so one deployment signs correctly for local, dev and staging, each
of whose BE has its own key. All three endpoints answer `503` until the map is set.
`POST /payment/generate-hash` accepts an optional `origin` field to pick the key when
called from a script. Not mocked:
Senangpay's `apiv1/query_order_status` polling API (BE's pending-payment cron tolerates
the `404`; unpaid orders still time out after an hour).

To use: insert a `payment_gateway` row in the BE database with `name='mock'`,
`environment='sandbox'`, `url=<this app's public URL>`, any `merchant_id`, and have
the BE select it (today the gateway name is hardcoded to `senangpay` in the three
`saveDatasourcePayment` copies, so that needs the DB-driven provider selection first).

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

### Bulk import from a CSV export (e.g. from the prod database)

When the entities you want aren't in the dev DB, export them from whichever BE database
has them and feed the CSV to `ssm:import-cases`. It needs no BE database connection —
only the `SSM_S3_*` env vars (pointed at the bucket the exported URLs live in) and, for
pushing, the same `SSM_MOCK_REMOTE_URL` / `SSM_MOCK_ADMIN_SYNC_*` env as the Sync Cases
tool.

1. Export a CSV with the header `company_name,reg_no,entity_type,raw_s3_url,pdf_s3_url`:

       select distinct on (dr.subject_reg_no)
              dr.subject_name   as company_name,
              dr.subject_reg_no as reg_no,
              lower(dr.type)    as entity_type,
              dp.raw_s3_url,
              drep.s3_url       as pdf_s3_url
       from datasource_requests dr
       join datasource_transactions dt on dt.request_id_id = dr.id
       join datasource_purchased dp on dp.transaction_id_id = dt.id
       join datasources ds on ds.id = dp.datasource_id
       left join datasource_reports drep on drep.id = dp.report_id
       where ds.item in ('company_profile_v2', 'business_profile_v2', 'llp_profile')
         and dp.raw_s3_url is not null
         and dp.deleted_at is null
         and dp.purchase_date >= '2026-01-01'          -- adjust the date range
         and dp.purchase_date <  '2026-02-01'
       order by dr.subject_reg_no, dp.purchase_date desc
       limit 50;

   `distinct on (dr.subject_reg_no)` keeps one row per entity — the most recent
   purchase in the range, because of the `purchase_date desc` ordering. The two
   `purchase_date` bounds and the `limit` are the knobs to adjust per export.
   The `ds.item` filter keeps only the three SSM profile products this mock serves
   (`company_profile_v2`, `business_profile_v2`, `llp_profile`); the v1, CTC and
   involvement products write a different JSON shape and are not served here.
   `entity_type` must be `company`, `business` or `llp`. The URL columns accept the
   full `https://{bucket}.s3.{region}.amazonaws.com/{key}` form stored in the DB or a
   bare object key (expanded with `SSM_S3_BUCKET` / `SSM_S3_REGION`). `pdf_s3_url` may
   be empty. Columns may appear in any order.

2. Dry run locally — each row becomes a folder under the local cases path:

       php artisan ssm:import-cases /path/to/export.csv

3. Push to the deployed instance instead (same `POST /admin-api/cases` endpoint the
   Sync Cases tool uses; an existing case with the same key is replaced):

       php artisan ssm:import-cases /path/to/export.csv --push

The CSV is validated up front, so a bad header or entity type fails before anything is
downloaded. Download or push failures are reported per row, the remaining rows still
run, and the exit code is non-zero if any row failed.

#### Including Idaman documents

Idaman documents come from a second CSV passed via `--idaman`, since one entity can
have many documents. Export it from the same database with the header
`reg_no,version_id,form,document_date,s3_url`:

       select d.entity_number                      as reg_no,
              d.version_id,
              d.form,
              to_char(d.document_date, 'YYYY-MM-DD') as document_date,
              d.s3_url
       from idaman_document d
       where d.status = 'ready'                      -- the BE stores this lowercase
         and d.s3_url is not null
         and d.deleted_at is null
         and d.entity_number in ('199401031473', '...')   -- the reg_no values from the cases CSV
       order by d.entity_number, d.document_date desc;

Then run either form with the extra option:

       php artisan ssm:import-cases /path/to/export.csv --idaman=/path/to/idaman.csv
       php artisan ssm:import-cases /path/to/export.csv --idaman=/path/to/idaman.csv --push

Rows are matched to cases by `reg_no`, which must equal the cases CSV value exactly.
Documents whose `reg_no` has no case row are reported as a warning and skipped. `s3_url`
is the key of the stored `get-image` JSON envelope (the `docContent` inside is decoded
to `idaman/{version_id}.tiff`); a full `https://...amazonaws.com/{key}` URL is reduced to
its key, and the bucket is always `SSM_S3_BUCKET`. Both CSVs are validated before any
download starts. A document download failure fails that entity's row (nothing partial is
written), and the remaining rows still run. Without `--idaman`, behaviour is unchanged
and cases are written without an `idaman/` folder.

If you can't run this app locally against the dev DB (or need to hand-craft a case
that doesn't exist in the dev DB), fall back to the manual method below.

### Manual fallback: hand-crafted case folder

Create a folder under `storage/app/ssm-fixtures/cases/{caseKey}/` containing:

- `meta.json` — `{ "regNo": "...", "companyName": "...", "entityType": "company" | "business" | "llp" }`
- One of `company-profile.json` / `business-profile.json` / `llp-profile.json`, matching `entityType` — the raw JSON body SSM would return for that endpoint (its `requestRefNo` field is rewritten to `{caseKey}` automatically on every response).
- `report.pdf` — the report file returned via `get-order-document` → `/reports/{caseKey}.pdf`.
- Optionally, an `idaman/` subfolder for Idaman document endpoints:
  - `idaman/list.json` — a JSON array of `documentInfos` entries as the real gateway returns them. The fields the BE and FE read are `verId`, `formType` and `documentDate` (`YYYYMMDD`); `dateFiler` (ISO-8601, `+08:00`), `companyNo`, `imageName`, `receivedDate`, `totalPage`, `batchId`, `comments`, `sourceData`, `stateCode` complete the shape. A hand-written entry may give just `{ "verId", "formType", "dateFiler": "YYYY-MM-DD" }` — `documentDate` and the ISO `dateFiler` are derived when served.
  - `idaman/{verId}.<any-extension>` — the raw document content for each entry, base64-encoded automatically when served via `get-image`.
  - Cases without an `idaman/` folder simply have no Idaman documents — `get-image-list` returns a "not found" response for them.

No rebuild or redeploy is needed — drop a new folder in and it's immediately queryable.

## Adding an AsiaVerify test case

Create a folder under `storage/app/asiaverify-fixtures/cases/{caseKey}/` containing:

- `meta.json` — `{ "country": "VNM" | "THA" | "CHN", "companyId": "...", "companyName": "..." }`.
- `profile.json` — the `result` object a real `basic` response would return for this company (not the full envelope — `code`/`orderNo`/`lastUpdated` are regenerated fresh on every request).

There is no separate search fixture — search results are built from `meta.json` alone (`companyId`/`companyName`), matching how the SSM search endpoint already works.

## Adding a DNB test case

Create a folder under `storage/app/dnb-fixtures/cases/{caseKey}/` containing:

- `meta.json` — `{ "country": "singapore", "regNo": "...", "companyName": "..." }` or `{ "country": "indonesia", "companyId": "...", "companyName": "..." }`.
- `profile.xml` — the full captured profile XML response, stored verbatim.

## Environment variables

- `APP_URL` — must be this app's real public URL; it's used to build the `documentUrl` field `infominaAI-BE` fetches directly.
- `SSM_MOCK_CASES_PATH` — optional override for the case-folder directory (defaults to `storage/app/ssm-fixtures/cases`).
- `ASIAVERIFY_MOCK_CASES_PATH` — optional override for the AsiaVerify case-folder directory (defaults to `storage/app/asiaverify-fixtures/cases`).
- `ASIAVERIFY_MOCK_TOKEN_TTL_SECONDS` — optional override for how long an issued AsiaVerify token stays valid in the cache (defaults to `3600`).
- `DNB_MOCK_CASES_PATH` — optional override for the DNB case-folder directory (defaults to `storage/app/dnb-fixtures/cases`).
- `SENANGPAY_SECRET_KEYS` — required by the payment mock: `frontend-host=secret` pairs separated by `;` (e.g. `localhost=…;dev-aiexe.infomina.ai=…;staging-aiexe.infomina.ai=…`). Each secret must equal the `SENANGPAY_SECRET_KEY` of the `infominaAI-BE` serving that frontend, since that BE verifies the callback hash. Hosts not listed cannot use the mock. Hostnames are matched case-insensitively, ignoring port.
- `MOCK_REDIRECT_URL` — optional fallback frontend origin for the mock payment page, used only when the browser sent neither `Origin` nor `Referer` (HTTPS→HTTP downgrade, or a scripted caller) and no `return_url` field was posted. Normally unnecessary: the origin is detected from the request. The tester can always edit it on the page.
- `BE_DB_HOST` / `BE_DB_PORT` / `BE_DB_DATABASE` / `BE_DB_USERNAME` / `BE_DB_PASSWORD` — read-only connection to `infominaAI-BE`'s database, used by the Sync Cases tool (and `ssm:download-response`) to look up entities/requests. Copy values from `infominaAI-BE/env/.env.devcontainer.local`.
- `SSM_S3_ACCESS_KEY_ID` / `SSM_S3_SECRET_ACCESS_KEY` / `SSM_S3_REGION` / `SSM_S3_BUCKET` — the real S3 bucket the SSM raw/transformed JSON and PDF reports live in, used by the Sync Cases tool, `ssm:download-response` and `ssm:import-cases` to pull raw JSON, PDF reports and Idaman document content.
- `SSM_MOCK_REMOTE_URL` / `SSM_MOCK_ADMIN_SYNC_KEY` / `SSM_MOCK_ADMIN_SYNC_SECRET` — push target and `admin_sync`-purpose credential for the local Sync Cases tool and `ssm:import-cases --push` (see above); unused otherwise.

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
