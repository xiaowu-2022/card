# Platform invitation data (2026-10-12)

The read-only `/platform/invitation-data` page sits below Overview and Partner balances.
It lists consumer accounts with company/search filters and three account categories:
all, users (no currently enabled partner configuration), and enabled partners.
Disabled partner configurations belong to users. This filter selects report owners;
it does not change each owner's complete company-scoped descendant statistics.

The table shows registered/unactivated members, ordinary members and each configured
agent rank, with separate direct/indirect headcounts, total direct/indirect members,
annual commission, activation commission and cumulative commission. Account details
reuse the existing Platform invitation report UI: per-rank annual/activation totals,
expandable direct/indirect award counts, amounts, reward ranges and paginated immutable
award records. Headcounts follow current membership qualification; awards retain the
source rank and relation at award time, exactly as the consumer invitation page does.
Cumulative commission uses the consumer's signed, deduplicated income projection,
so legacy/manual entries need not equal the annual plus activation table totals.
Money remains decimal strings in USDT; no number conversion or balance mutation.

`PartnerInvitationReport::readUser` shares the consumer projections with the existing
partner endpoint, validating company/user ownership without ensuring promotion
membership or provisioning wallets. Page summaries are calculated only for the
current page. Reads run in a repeatable-read read-only snapshot. Platform users.read
and ledger.read plus active Platform administrator scope protect both list and
`/platform/invitation-data/{tenant}/{user}/report`; company administrators cannot use
this Platform route. Selected accounts must still match company/category filters.

List page, filters, selected account/company and independent invitation detail page
are retained in URL state. Shared AdminPagination provides exact scoped totals,
numbered pages and direct jump for both list and detail records. Closing details
returns to the same filtered list page. No provider requests, account creation,
financial writes, historical reclassification or migration. Publish matching PHP,
routes and rebuilt admin assets together; no consumer rebuild is required.
