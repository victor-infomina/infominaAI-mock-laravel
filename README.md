# InfominaAI SSM Mock

A Laravel mock of 5 SSM gateway endpoints, for pointing `infominaAI-BE`'s
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

## Adding a test case

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

- `MOCK_SSM_API_KEY` / `MOCK_SSM_API_SECRET` — must match `infominaAI-BE`'s `SSM_API_KEY` / `SSM_API_SECRET` env vars.
- `APP_URL` — must be this app's real public URL; it's used to build the `documentUrl` field `infominaAI-BE` fetches directly.
- `SSM_MOCK_CASES_PATH` — optional override for the case-folder directory (defaults to `storage/app/ssm-fixtures/cases`).

## Deployment

See `DEPLOY.md` for the cPanel (no-SSH) deployment steps.
