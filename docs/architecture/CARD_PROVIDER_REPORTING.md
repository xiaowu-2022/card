# Card merchant reporting

> 2026-09-24：多账号后台配置、凭据轮换、强制账号/目录 BIN 及永久全局占用以 [PHOTONPAY_ACCOUNTS.md](PHOTONPAY_ACCOUNTS.md) 为准；下文的旧可空绑定、手工 BIN 或归档释放规则不再适用于新增配置。
Approved 2026-09-14: the selected PhotonPay merchant displays API account balance
and cumulative card count instead of editable reference balance. This is a narrow
read-only extension to CARD_PRODUCT_PROVIDER_BINDINGS.md, not a runtime conversion.

`photonpay_reporting_encrypted` holds a Laravel-encrypted credential envelope on
one selected directory UUID. The model hides it; DTOs and audit exclude its content.
The local-only `cards:configure-photonpay-reporting {reference} --env-file=...`
command binds explicitly selected credentials and records a SYSTEM audit. It rejects
LOCAL_MOCK merchants. Renaming never changes the connection. The ordinary admin
save endpoint cannot configure credentials and rejects any balance submission for
an API-connected record. Prior manual reference values are preserved but not used
as fallback or displayed. No provider calls occur during configuration transactions.

Only official HTTPS production/sandbox hosts are accepted; sandbox reporting is
restricted to local/testing. The existing card runtime, products and historical
cards/orders remain unchanged, including in the isolated simulator database.

Official API schema (2026-08-06):
https://api-doc.sandbox.photontech.cc/data/2026-08-06_zh.json
- POST /oauth2/token/accessToken uses Authorization: basic Base64(appId/appSecret).
- GET /wallet/openApi/v4/account/single with currency=USD, accountType=FT10001
  supplies USD available balance. Validate returned account type/currency; preserve
  exact JSON numeric tokens as decimal strings. Never relabel USD as USDT.
- GET /vcc/openApi/v4/pagingVccCard, pageIndex=1/pageSize=1, uses the balance
  response's memberId to scope the query to the same merchant. Display `total`
  without status or date filters. This is the provider's all-status card-list total,
  not an independently audited lifetime statistic. Provider retention can limit it.
  Unconnected merchants display the local persisted UserCard count joined through
  their bound products, including cancelled cards; never count failed issue orders.

Reporting uses X-PD-TOKEN. Token cache values are encrypted, expire before the
provider deadline, and are protected by a cache lock. Sanitized report values have
a 60-second cache and a query timestamp. A failed/invalid response returns unavailable,
never zero or a manual fallback. Card rows/PAN/CVV are discarded and never cached,
logged or sent to the browser. No Ledger, wallet, deposit, commission or card cache
writes and no financial jobs/replays occur. Platform route permissions remain intact.

Sandbox acceptance on 2026-09-14: both endpoints returned 0000, available balance
100000 USD and total 0. This does not establish production or issuance acceptance.
