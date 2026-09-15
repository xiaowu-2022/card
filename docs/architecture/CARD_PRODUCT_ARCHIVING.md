# Card product catalog archiving

The user requested clearing the current product catalog on 2026-09-14. Referenced
products are archived, never deleted: card_products.archived_at records removal
from the catalog and requires INACTIVE status through a database constraint.

ArchiveCardProductsAction requires an active Platform administrator with active
Platform membership and card_product.manage. It locks an explicit snapshot of
product IDs, writes one audit event per archived product, and is idempotent.
Products created after the snapshot are unaffected. Archived products cannot be
edited or reactivated through UpdateCardProductAction.

Platform and company catalogs exclude archived products. New user applications
and issuing retain their ACTIVE-product gates. There is no global model scope:
existing card, holder, company configuration and order relationships remain
readable, and existing card management continues through persisted provider
routing. On 2026-09-14 the user clarified that clearing the catalog must allow
selecting those BINs again. Only non-archived products reserve BINs, including
DRAFT and INACTIVE products. UI availability, request validation, application
locking and the database partial unique index all exclude archived products.
Creating a replacement produces a new product ID; historical routing, card and
order relationships remain attached to the original archived product.

Archiving makes no provider calls, cancels no cards, and changes no balance,
Ledger entry, posting, financial order or pricing snapshot. Restoring products
is outside this operation. A replacement cannot duplicate a non-archived BIN.

## Local acceptance on 2026-09-14

On the port 8001 card_mock sandbox, all 12 snapshot products were archived with
12 audit events. The browser confirmed an empty product catalog. Before/after
hashes matched for user_cards (10), card_issue_orders (24), provider_cardholders,
tenant_card_product_configs, ledger_entries, ledger_postings and ledger_accounts.
CardProductTest passed 13 tests / 122 assertions in isolated card_ui_test.
