# Commission refund restriction — removed 2026-09-17

The user explicitly replaced the prior restriction with direct USDT receipts.
Applying for, cancelling or completing a security-deposit refund never blocks
commission income, freezes it, or claws it back. Rewards credit the USDT wallet
through LedgerWriter and use the ordinary withdrawal/transfer flow with its
normal KYC, account status, balance and idempotency checks.

There is no manual commission-to-wallet action or transfer eligibility service.
No historical financial record is changed. Card freezing, restoration and timed
deposit refund rules continue independently.

See [Direct commission receipts](DIRECT_COMMISSION_RECEIPTS.md) for the effective
contract, receipt provisioning and the authorized development balance consolidation.
