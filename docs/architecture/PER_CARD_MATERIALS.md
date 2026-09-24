# Per-card holder materials — approved architecture revision

The user explicitly approved independent materials for every card, including a holder different from the account owner (2026-09-10). This supersedes Phase 10's account-level Cardholder/KYC-reuse assumption, not its financial or tenant boundaries.

## Workflow

### Approved unissued-application editing (2026-09-13)

READY and ACTION_REQUIRED applications with no issue order may return to the material
form. The owner-scoped, CSRF-protected POST `/cards/cardholder/{application}/details`
returns only editable text fields with private/no-store headers. It never includes
identity numbers, encrypted payloads, document keys/URLs or account KYC. Text is held
only in the transient form; close/hidden-page/unmount clears loaded edit material and
late reads are ignored. Documents must be selected again; they are not exposed by this
read endpoint. Locale switching retains the current form.

Saving retains application UUID, request UUID and provider holder ID, increments the
submission version and commits SUBMITTING before calling updateCardholder outside the
transaction. Only successful provider confirmation makes the revision READY. Replays
of identical data are idempotent. UNKNOWN/PENDING/SUBMITTING cannot be edited or bypassed
with another request; a status-only READY query for an uncertain revision is insufficient
to prove delivery of the updated text/documents and leaves it locked. Exact provider
revision evidence is needed before adding automated recovery for that case.

Any existing issue order (including FAILED) permanently seals its holder material.
A definitively FAILED issue allows a new independent application with fresh material,
not mutation/reuse of the failed order's holder. New migration
`2026_09_13_001000_allow_unissued_cardholder_material_revisions` updates the guard only;
it rewrites no existing holder, order, document or financial record. Tenant/User locks
serialize editing against initial issue, and revision/status checks discard stale
provider responses. Existing one-application/one-issue and all Ledger rules remain.

This explicitly supersedes the historical ACTION_REQUIRED-only correction and
no-material-read endpoint statements below only for this unissued owner edit flow.

The user-confirmed synchronous-add revision (2026-09-11) removes the separate holder-review workflow. A successful `addCardholder` response with a non-empty returned identity marks this application's existing READY state; `cardholderReviewStatus=pending` metadata does not insert a local approval step. Explicit provider errors, rejected/disabled/modify outcomes, missing IDs, and timeouts never authorize issue. The amount-confirmation step stays open in the same modal, then `openCard` uses exactly this application's saved Provider ID. Every subsequent card has a fresh application and add call; retries of the same successful application reuse its ID and never call add again.

The server no longer flashes a review-success banner. Addition failures return sanitized, translated form errors without raw Provider messages, documents or contacts. The browser retains entered material on errors. Only a definitive rejected/disabled addition rotates the material request UUID for correction; uncertain responses retain the UUID and block another application. Recovery, when an ID is known, queries that exact ID rather than creating a replacement. No existing persisted holder/order/card is upgraded or rewritten by deployment. Existing PENDING records remain unconfirmed until a trusted query result; retained legacy enum names and review metadata are compatibility/recovery details, not a new end-user review process. No migration or new state is needed.

`Account eligibility -> choose product -> submit this card's materials -> addCardholder succeeds with cardholderId (READY) -> explicit fee/funding confirmation -> openCard -> existing settle/release`

Account KYC remains an eligibility check. It is not the identity of the person named on each card. Never populate or overwrite UserProfile, IdentityRecord, or KYC documents with per-card material. Each new application collects the holder's name, birth date, email, nationality, address, document type and fresh PNG/JPEG uploads (maximum 6 MB each; passport back optional). Holders may differ across cards. The submitter must be authorized to use their documents; the provider remains authoritative for acceptance.

Official API reference: [PhotonPay card issuing and carduser APIs](https://aurora-redoc.photonpay.com/). `addCardholder` returns a Provider Cardholder identity. `openCard` receives that identity as `cardholderId`, with a stable order UUID and the existing regular/virtual/USD/arrivalAmount mapping. Do not invent an idempotency parameter for `addCardholder`; an ambiguous result without a returned identity cannot be automatically recreated. Real environment authentication/product requirements must still be validated against the merchant's enabled API contract before production use.

## Material form and address choices

The user approved missing-locality manual input on 2026-09-13 for all countries and
both new/unissued-application material forms and existing-card holder edits. This
supersedes the strict-list/no-free-text statements below only when the corresponding
server-side list is actually empty. Country/nationality stay allowlisted. With no
subdivisions the state is typed; with no cities under a valid selected/typed state,
city is typed. Nonempty lists still require exact membership. Manual values are
required, at most 50 Unicode characters, contain a letter/digit and allow letters,
marks, numbers, spaces and common locality punctuation only, never controls, paths
or HTML. No client fallback flag is trusted. Both HTTP and Application validation
use CardholderGeography; the consumer uses the corresponding shared locality rule.
Loading/error states do not enable manual input; retries remain available. Parent
changes clear descendants and locale changes preserve entered values. Input is
user-provided, not verified administrative data or a promise of provider eligibility;
no invented options, external validation service or historical-data rewrite is added.

The material UI has two explicit fieldsets: **Card user** (name, email, optional mobile and selectable calling code, nationality, birth date, document type and fresh private uploads) and **Billing address** (country/region -> state/province -> city, detailed street address and postal code). These are this card's details, never account-prefilled data. The existing PhotonPay `residential*` DTO/API fields carry the address entered in the Billing address section; no separate provider billing-address endpoint, new card operation, or Discover-specific rule is implied by the reference screenshot. The user-confirmed optional identity number is not collected by this form; email, document type and private upload validation remain unchanged.

The user-requested issuing-country simplification (2026-09-10) removes that selector: the Application derives `document_country` / Provider `certCountryCode` from this cardholder's validated `nationality_country_code`, never the account identity, billing country or a hidden input. HTTP requests prohibit a separate `document_country`; direct Application callers cannot override it. Existing encrypted material/history is not rewritten, and canonical field order remains unchanged for idempotency. Previously submitted applications with a different issuing country keep their original facts; a changed replay conflicts rather than silently updating or recreating them.

The user explicitly confirmed removal of the optional identity-number input (2026-09-10), resolving the earlier implementation pause. New forms neither collect nor submit `identity_number`; the Application normalizes missing/null/blank numbers to null, and `ProviderIdentityDocumentDTO.identityNumber` is nullable. The adapter omits `certId` from both add/edit requests when absent; it never sends placeholders, reads account KYC numbers or introduces OCR. Optional previously supplied numbers remain validated, encrypted and part of the canonical fingerprint for legacy replay compatibility; no persisted material/history is rewritten. Confirmed Provider addition with an ID is required before any hold; there is no separate holder-review wait. The public API documentation previously checked described this field as required: omission follows the user's explicit merchant-contract direction, not a claim that every PhotonPay configuration accepts it. A real merchant smoke test remains necessary before production, and rejection/UNKNOWN must follow the existing safe handling without fabricated success.

Country/nationality/calling-code choices are searchable. Address parent changes atomically clear dependent choices. The browser loads public, version-pinned CSC reference data from the same origin on demand, without user credentials or personal data in the request; world cities are not part of the JavaScript bundle. The complete separate derived dataset, source manifest, change notice and ODbL-1.0 license are publicly downloadable under `public/data/card-geography/`, linked from the Me page footer. The user removed the explanatory introduction, no-reservation hint and attribution link from the material form; this is copy/layout only and does not change eligibility, consent/document requirements or financial behavior. `scripts/import-card-geography.mjs` reproduces it. Country names use locale-aware Intl names; region/city translations use the source's zh-CN/ms/es names when provided, otherwise canonical place names. Missing localities display an explicit support message, never invented selectable towns or free-text bypasses. HK/MO/SG missing smaller localities use the city-state/territory's metropolitan name. Dataset presence is not a promise of Provider country eligibility.

Both FormRequest and Application validation enforce exact country/state/city membership. Submitted names remain canonical Provider values, not localized display labels. Calling codes are derived server-side from the selected region, never an arbitrary client prefix; optional mobile input is validated/normalized with the existing libphonenumber metadata and remains inside the encrypted envelope. Searches are not persisted. Country options do not modify Tenant or identity state. Local PHP upload limits support 6 MiB per file and a 16 MiB total POST; deployment ingress/PHP limits must preserve those bounds.

## Storage and ownership

The form validates contacts and basic document/address fields on blur, revalidates an invalid field while editing, and validates all fields before any submission. Country-code changes revalidate a supplied mobile. Browser phone feedback uses the local libphonenumber-js full validation metadata; backend libphonenumber and FormRequest remain authoritative. Optional mobile remains optional; supplied values must be valid for the selected calling code. Errors are field-associated, translated from stable catalog keys, and never contain submitted values. Validation does not change request UUIDs, submit to a third-party validation service, or establish contact ownership. Name/date/document/upload/address limits reflect the current provider-facing request, not new eligibility or financial rules. Invalid HTTP contacts produce field errors before Provider work, document storage or holds.

Migration `2026_09_10_001100_scope_cardholder_materials_to_each_card` extends existing tables only. Provider Cardholders now carry an immutable Tenant/User-scoped request UUID, product ID, HMAC request fingerprint, submission revision and encrypted material envelope. Contact/name/address/document details and private object keys are inside the encrypted envelope; no sensitive plaintext index is added. Uploaded documents are encrypted before storage on the private disk. No document-read/reveal route, public URL, Admin edit, or generic material browser is introduced.

`CardholderMaterials` derives separate AES-256-GCM and HMAC keys with HKDF from the existing stable `KYC_DATA_ENCRYPTION_KEY` root. It does not read account KYC data and never falls back to APP_KEY. Rotating that persistent root now requires re-encrypting both KYC data and card material envelopes/files and recomputing card request fingerprints. Do not rotate it as an API credential. Model serialization hides envelopes/fingerprints. Normal Inertia and Admin props expose only allowlisted application identity/status metadata, not submitted personal details. Validation must not flash material into session; logs and audits must not include identity/contact/documents.

The order's `cardholder_request_id` is an immutable correlation, composite-FK-bound to the exact Tenant/User/product/holder application. A partial unique index allows at most one Order per new holder application, including FAILED orders. A different card always requires another submission. Existing legacy holders and orders keep null correlation and retain their original links, statuses and history; they remain readable and recoverable but cannot authorize a new issue. The original Phase 10 migration is unchanged. Rollback cannot safely collapse multiple holders into one account identity; recovery is forward-only.

## Concurrency and failure rules

Submission locks Tenant then User, rechecks eligibility and serializes outstanding applications. Same request and canonical material return the existing application without another external call. Changed material conflicts, except explicit ACTION_REQUIRED resubmission of the same application. This increments a submission revision; stale query/create responses cannot overwrite it. A new UUID cannot bypass an outstanding submission or unresolved issue. No wallet reservation occurs during material submission or uncertain addition.

Only this application's READY holder can authorize its issue, and only once. The browser may reference a local application UUID, never a raw provider identity; the server scopes it by Tenant, User and product. Request hashing now includes that application. Issue hold/settlement/release, exact decimal math, stable Provider request IDs, UNKNOWN handling, existing recovery and Ledger immutability are unchanged.

Documents and envelopes remain private after failures; a failed pre-commit upload is cleaned up without revealing its object key. Superseded encrypted upload versions are retained, not automatically purged. A dedicated retention/deletion policy and production provider-contract smoke test are outside this UI/business-model revision.

## Acceptance

Cover two cards with different people, no account-identity reuse/mutation, create-holder-before-issue ordering, no funds on submission/pending/UNKNOWN, correct external holder reference, request replay/conflict, cross-Tenant/User/product rejection, one Order per material application, encrypted private documents, no session/Inertia/audit leakage, stale review protection and real PostgreSQL concurrent requests. Preserve all previous financial tests and run ledger/payment reconciliation read-only. Frontend material/amount steps use the four-language catalog and remain usable at 375/768/1440 px.

## Explicit sandbox material retention, 2026-09-14

The user authorized testing the currently entered PhotonPay holder materials and
retaining them for a subsequent test. Local development provides explicit save/load
controls, backed by authenticated, owner-scoped `/cards/cardholder/test-materials/*`
POST endpoints. They fail closed outside APP_ENV local/testing and the isolated
card_mock/card_ui_test databases. Draft retention does not create a ProviderCardholder,
call a provider, approve identity, bypass an existing UNKNOWN request or move money.
Incomplete drafts may be retained; actual submission keeps every existing validator.

Each explicit save writes a new encrypted private envelope using CardholderMaterials,
containing allowlisted fields, the original request UUID, product, owner scope and
base64 document bytes. No original filename or public URL is stored. Metadata-only
CARD_TEST_MATERIALS_SAVED/READ audits carry the snapshot ID, owner, product, time and
request; ordinary Inertia props never contain document bytes or private object keys.
The dedicated no-store restore endpoint returns only the requested owner's latest
snapshot for that configured product. Restore is an explicit user action and never
submits automatically. Saved versions are preserved. The development-only controls
are not emitted by production frontend builds; server restrictions remain authoritative.

The same test exposed that max_cards_per_user was incorrectly counting orders from
other products. Holder submission and issue now constrain capacity by Tenant, User
and selected product; unresolved and successful orders for that product still count,
FAILED orders do not. Pending/UNKNOWN application, ownership and financial gates are
unchanged. This is a correction of the existing per-product limit, not a limit override.

Test run record (2026-09-14): the first submission for product
01a09f97-2e67-7335-a853-e61b0b7d3b11 failed the pre-provider product-capacity check;
there was no committed holder or provider creation. After correcting product scope,
Vite's backend refresh discarded the unsaved browser form. The operator restored
only observed text into encrypted snapshot d963f44b-c11f-4326-a6ae-45d870ad3e43 for
Tenant A account 202609131303. It contains ZERO documents; fresh user selection of
the original front/back files is required before another submission. Never describe
this snapshot as complete or fabricate a successful PhotonPay holder. Text/contact
values and document content are intentionally absent from this run record.
Validation: three capacity/UNKNOWN-isolation tests passed (14 assertions); two
private retention/tenant isolation/production-denial tests passed (17 assertions).
TypeScript and targeted ESLint passed.

Follow-up live sandbox run (2026-09-14): the user supplied the original front/back
files and explicitly identified their order. The complete form and both documents
were saved through the authenticated UI into encrypted snapshot
2e6cbe2e-dd4f-4949-9454-bdfdc5b3f303 (metadata document_count=2). Submission then
returned PhotonPay holder CH2099510588505341952; local application
01a0a063-fcf6-7163-858a-021175397036 is READY with no safe_reason. The browser
confirmed that the holder was added and displayed the separate issue-confirmation
step. No card issuance was submitted during this test. The complete encrypted
snapshot remains available for subsequent tests; the earlier zero-document snapshot
is historical only. Sandbox acceptance is not production identity verification.

Physical applications now include a persisted card form and validated card-face name.
See [physical cards and recipients](PHOTONPAY_PHYSICAL_CARDS.md) for the approved
2026-09-24 extension, encrypted shipping snapshot and activation/PIN rules.
