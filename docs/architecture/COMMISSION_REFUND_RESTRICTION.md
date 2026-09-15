# Commission transfer restriction after deposit refund

User-confirmed 2026-09-14: from submitting a deposit refund through successful
completion, the requesting user's commission cannot be transferred to Wallet and
therefore cannot be withdrawn. Commission can still be earned and credited normally.
Existing Wallet balance (including earlier commission transfers) can still use the
existing withdrawal flow. No direct commission withdrawal is introduced.

This supersedes the earlier no-effect-on-commission rule only for transfer
eligibility. Never freeze/claw back awards, move commission to a new account,
rewrite financial history, or restrict a referrer's commission merely because a
different user requested a refund.

CommissionTransferEligibility derives the restriction from the same tenant/user's
CHECKING or COMPLETED refund records, including legacy records. Cancellation remains
restricted while cards are being restored (CHECKING); a CANCELLED request alone no
longer blocks transfer. A previous COMPLETED request still blocks it, including
after subsequent deposit funding: no automatic restoration rule was authorized.

TransferCommissionAction checks eligibility under the shared Tenant-then-User locks
before creating any new transfer, account or Ledger event. Refund application,
cancellation and settlement use those locks too, serializing racing requests. A
completed idempotent transfer replay returns the existing receipt without posting
again, even if a refund was requested subsequently. Failed/new UUID attempts cannot
bypass the restriction. PromotionQuery uses the same predicate to disable the button
and expose a safe explanation. Deposit page and confirmation contain the same warning
in all four supported consumer locales.

No schema, refund lifecycle state, earning algorithm, withdrawal eligibility or
historical balance changes. No refund/card operations are performed by this policy.
Automatic card destruction remains a separate unapproved extension.

Verification: 68 Promotion/Withdrawal/Deposit tests passed (509 assertions) in
isolated card_ui_test, including continued real commission awards during/after
refund, API rejection, unchanged funds on blocked transfer, old receipt replay,
cancellation restoration, scoped isolation and Wallet withdrawal holds in both
restricted stages. All 60 frontend tests, typecheck, focused lint and asset build
passed. Read-only local browser verification confirmed the revised notice; no live
refund, commission transfer or withdrawal was submitted and no migration was needed.
