# Card transaction fee display — 2026-09-29

Source: PhotonPay published OpenAPI `2026-08-06_zh.json`,
`vccTradeOrderResp` used by `/vcc/openApi/v4/pagingVccTradeOrder`.

Persist nullable fee deduction and return amount/currency pairs on the scoped
transaction read model. Preserve signed exact decimals, including zero; missing
fees are not invented. React consumer, uni-app and SaaS use the same exact currency
formatter: USD `$`, CNY/RMB `￥`, other codes retained. Fee refunds are separate.
Combined local funding rows carry fees only from an exact scoped provider match.

Validation:
- PhotonPay transaction normalizer: 23 tests passed, including numeric JSON fee
  tokens, negative amounts, independent currencies, zero/missing, malformed pairs.
- Targeted CardIssue transaction read/record/scope/deduplication tests: 10 passed,
  136 assertions. GETs remain read-only; no provider or financial writes.
- Frontend fee formatter/DTO/React rendering tests: 3 passed. React and uni-app typechecks passed.
- React and H5 production builds completed. Existing local hot reload preserved.
- An initial wider name filter also selected an unrelated refund fixture which
  switches the app to production without an OSS configuration; it failed at image
  upload. This was not a fee regression and is not included in the targeted result.

The nullable-column migration was applied to the current local application DB.
No real card transaction, financial posting or historical provider replay was run.
Previously recorded fee-less transactions remain unknown until ordinary verified
refresh/explicit sync supplies the provider fields. No signed native app was built.

Publication: 225 updated resources uploaded and verified, 702 unchanged; full OSS
manifest published. Browser acceptance passed (64 remote responses, zero failed
responses/local static requests/page errors), then the current workspace H5 entry
was atomically replaced. Previous entry retained in
`output/oss-verification/h5-index-before-fees.html`. No remote application server
deployment or signed App release was performed.
