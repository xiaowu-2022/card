# Card product provider bindings

> 2026-09-24：多账号后台配置、凭据轮换、强制账号/目录 BIN 及永久全局占用以 [PHOTONPAY_ACCOUNTS.md](PHOTONPAY_ACCOUNTS.md) 为准；下文的旧可空绑定、手工 BIN 或归档释放规则不再适用于新增配置。
Approved 2026-09-13: products accept a card merchant selected from the platform directory, with name, optional Card BIN range, existing minimum amounts and status. No provider credentials or new API integration is implemented by this change.

## Configuration and persistence

- `card_products.card_provider_reference_id` is a nullable foreign key to `platform_card_provider_references`, deletion restricted. The existing `provider_product_ref` field is displayed as Card BIN range; legacy identifiers are preserved.
- New products use `UNCONFIGURED`, including when no merchant is selected. Empty BIN values are allowed; nonempty BIN references are unique within the provider/binding. Currency USD and type REGULAR remain fixed.
- Platform product management permission controls saves. Saving settings writes product metadata and audit only. Merchant reference balance is never read for card eligibility, deductions, funding or provider truth.
- Selecting a merchant on an unused legacy product changes it to UNCONFIGURED. It cannot be promoted to a live adapter through product settings.

## Routing and historical safety

The subsequent explicit `test`-merchant approval adds one isolated LOCAL_MOCK
adapter route. It supersedes the blanket unavailable treatment below only for
persisted LOCAL_MOCK bindings in the approved local simulator environment. No live
integration or credential management was added. See LOCAL_CARD_SIMULATION.md.

- `CardProductProviderRouter` resolves existing PHOTONPAY/unbound products to the existing CardProviderInterface runtime. All new bindings resolve to an unavailable adapter, with no fallback. A future integration must implement the interface and explicit routing; selecting a merchant alone is not integration.
- Product catalog readiness is false for unconfigured products. Cardholder submission and card issue enforce this server-side before private storage/provider calls/financial holds. Creation locks the product so a simultaneous configuration edit cannot reroute the application.
- Once any holder application, issue order or card references a product, application checks and a PostgreSQL trigger forbid changes to provider, binding, BIN, currency or type. Create a new product for a different route.
- Existing card/order reconciliation and management keep their persisted legacy provider mappings. No data migration converts card identities or replays jobs. Existing isolated local simulation restrictions and live credential fail-closed behavior remain unchanged.

## Acceptance

Cover optional/missing/invalid bindings, persistence and safe DTOs, duplicate BIN scope, no provider requests or Ledger changes, unavailable card setup, routing immutability and legacy issuing/management regressions. Testing is offline on the isolated PostgreSQL test database, never live financial acceptance.

Verified 2026-09-13: CardProductTest, CardIssueTest, CardProviderReferenceTest and architecture tests passed (117 tests, 1062 assertions); 42 i18n tests, TypeScript, ESLint and Vite build passed. Browser verification confirmed the existing merchant appears in the selector; no product was submitted. Migration 000800 applied only to the local card_mock instance on port 8001. The ordinary database and real provider were not exercised.

2026-09-14: the selected PhotonPay merchant now supports a separately encrypted,
read-only balance/card-count reporting connection. See [CARD_PROVIDER_REPORTING.md](CARD_PROVIDER_REPORTING.md).
This does not change product/provider routing or make unconfigured products issuable.

## PhotonPay API-only BIN selection (2026-09-14)

User approved: an explicitly connected PhotonPay merchant uses only BIN values
returned by `GET /vcc/openApi/v4/getCardBin` with X-PD-TOKEN, from the encrypted
merchant reporting connection. This narrowly permits read-only provider validation
on product saves; it never calls an issuing API or changes runtime routing.
Dropdowns accept only USD/recharge/virtual_card capabilities, checking comma-separated
API fields and deduplicating exact opaque BIN references. Missing/failed API data
fails closed. No manual fallback, name-based routing, or copied static BIN list.
Platform-only catalog DTOs contain allowlisted BIN strings and occupied product IDs,
never credentials. Catalog reads cache for 60 seconds; a changed/new BIN is queried
fresh before the product write transaction. Inside that transaction the selected
merchant is locked, its exact encrypted connection rechecked, and duplicate BINs
rejected across all statuses for that merchant. Existing database uniqueness remains
a final guard. A current product excludes itself; used routing remains immutable.
Unchanged existing product routing can keep its historical BIN when editing other
metadata, even if the current provider catalog is unavailable. No historical product
conversion or data rewrite occurs. Non-PhotonPay merchants preserve their existing
configuration contract.

Card organization display: the BIN DTO contains only `bin` and the provider's
`cardScheme`. The dropdown and product list show the scheme without inventing it
when unavailable. `cardType=recharge` remains mandatory in the request and the
response capability filter excludes share-only BINs, even if the provider ignores
the query filter. A BIN supporting both share and recharge is available only as
our existing REGULAR/recharge product; shared-card issuance is not enabled.
