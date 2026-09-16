# Multi-asset previews

Browser-only example balances and enabled networks; not live account balances or financial results.
Screenshots use the real application components. No financial/configuration request is submitted.

- [Mobile assets](375-assets.png), [tablet assets](768-assets.png), [desktop assets](1440-assets.png)
- [Currency picker](375-currencies.png), [network picker](375-networks.png)
- [Exchange confirmation](375-exchange.png), [withdrawal review](375-withdrawal.png)
- [SaaS withdrawal review](1440-admin-orders.png)

Reproduce against the isolated local UI with `node tests/Browser/multi-assets.mjs` and
`node tests/Browser/multi-assets-admin.mjs`. A local test login is required; these scripts
block financial and configuration POSTs after login. They never enable server-side rails.
