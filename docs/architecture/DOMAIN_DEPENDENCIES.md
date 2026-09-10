# Domain Dependencies

The intended high-level direction is `Tenant -> Auth/Admin/User -> KYC -> Wallet -> SecurityDeposit -> Card`. Card may coordinate CardProduct, CardProvider, Wallet/Ledger, Eligibility, and Tenant through an Application Action.

Allowed rules:

- Application Actions coordinate multiple Domains and pass explicit identifiers/DTOs.
- Card workflows may ask Ledger for a business-specific posting operation and CardProvider for an external operation.
- Payment and Withdrawal actions follow order -> hold -> provider -> settle/release.
- Payment Application Actions may call the Payment Provider and later build the one fixed top-up posting plan for `LedgerWriter`. Payment Domain models and Payment Provider adapters do not depend on Ledger.
- Payment Provider is independent from Card Provider. A webhook Controller verifies, normalizes, persists, and dispatches only; it never settles Ledger.
- Payment state transitions are owned by the Payment Domain policy. Cross-Domain top-up settlement and business-to-Ledger reconciliation live in Application; Ledger remains unaware of Payment.
- Withdrawal Application Actions may query eligibility, construct the three fixed withdrawal posting plans, and call `LedgerWriter`. The Withdrawal Domain owns destinations/orders and a read-only blockchain verification contract; gateway adapters never import or mutate Wallet/Ledger.
- CardProduct owns platform catalog and Tenant sales configuration only. User readiness is composed in an Application query from CardProduct and existing Wallet eligibility; CardProduct Domain does not depend on Wallet, Ledger, KYC, or CardProvider.

Forbidden rules:

- Domain code does not depend on HTTP, requests, sessions, controllers, or `auth()`.
- Ledger never depends on Card, KYC, Agent, or Commission.
- A CardProvider adapter never imports Wallet/Ledger models or changes funds.
- KYC approval changes KYC/Identity and writes Audit only; it does not create or modify funds.
- KYC depends on Tenant/User identity references and Audit orchestration only. Its Domain and OCR adapter never depend on Wallet, Ledger, SecurityDeposit, Card, or CardProvider.
- KYC cryptography and OCR contracts are KYC-owned boundaries. OCR adapters return hints only and cannot write review status.
- Future Wallet eligibility may query a derived KYC status through an Application boundary; KYC never calls Wallet.
- Ledger has no business-Domain dependency. Wallet may depend on Ledger provisioning abstractions; the Application layer coordinates Tenant, User, KYC, and Wallet activation.
- Business modules may construct a business-specific immutable posting plan and call `LedgerWriter`; they may not create Postings or mutate cached balances directly.
- Business actions own their aggregate/order transaction and call `LedgerWriter` inside it. They follow the shared lock hierarchy and never pre-lock Ledger Accounts; the writer owns event and sorted Account locks and participates in the outer transaction.
- Tenant settings modify requirements, not user balances or deposits.
- Wallet Domain has no KYC persistence dependency; the Application activation action may query the KYC status boundary. Ledger remains independent of KYC, Wallet, Payment, Withdrawal, Security Deposit, Card, Card Provider, Agent, Commission, and provider-specific adapters.
- Circular Domain dependencies are prohibited.
- Blockchain gateway calls run outside business/Ledger transactions. Exact verified evidence is passed back to the Withdrawal Application Action; Ledger never depends on Withdrawal or chain-specific adapters.

Architecture tests enforce representative namespace boundaries. When a cross-Domain use case grows, add an Application Action rather than introducing a reverse dependency.
