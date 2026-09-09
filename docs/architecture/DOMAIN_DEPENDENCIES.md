# Domain Dependencies

The intended high-level direction is `Tenant -> Auth/Admin/User -> KYC -> Wallet -> SecurityDeposit -> Card`. Card may coordinate CardProduct, CardProvider, Wallet/Ledger, Eligibility, and Tenant through an Application Action.

Allowed rules:

- Application Actions coordinate multiple Domains and pass explicit identifiers/DTOs.
- Card workflows may ask Ledger for a business-specific posting operation and CardProvider for an external operation.
- Payment and Withdrawal actions follow order -> hold -> provider -> settle/release.

Forbidden rules:

- Domain code does not depend on HTTP, requests, sessions, controllers, or `auth()`.
- Ledger never depends on Card, KYC, Agent, or Commission.
- A CardProvider adapter never imports Wallet/Ledger models or changes funds.
- KYC approval changes KYC/Identity and writes Audit only; it does not create or modify funds.
- KYC depends on Tenant/User identity references and Audit orchestration only. Its Domain and OCR adapter never depend on Wallet, Ledger, SecurityDeposit, Card, or CardProvider.
- Future Wallet eligibility may query a derived KYC status through an Application boundary; KYC never calls Wallet.
- Tenant settings modify requirements, not user balances or deposits.
- Circular Domain dependencies are prohibited.

Architecture tests enforce representative namespace boundaries. When a cross-Domain use case grows, add an Application Action rather than introducing a reverse dependency.
