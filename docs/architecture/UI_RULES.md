# UI Rules

The shared direction is light, clean, neutral, professional financial software. Information architecture and interaction patterns may learn from Stripe/Brex admin, Wise wallet, and Revolut card experiences without copying brand visuals.

Use shadcn/ui primitives plus focused project wrappers only. Do not add a second UI framework. Status always combines text and color using SUCCESS/WARNING/DANGER/INFO/NEUTRAL semantics. Tenant primary color affects primary controls, links, active navigation, and selected states; it never overrides risk colors.

PublicLayout, UserLayout, TenantAdminLayout, and PlatformLayout remain separate surfaces. User pages are mobile-first: 375, 768, and 1440 px must be fully usable; mobile uses Home/Wallet/Cards/Account bottom navigation and stacked transaction rows rather than compressed desktop tables. Admin is desktop-first with sidebar/topbar and a mobile drawer; 375 px remains readable with basic actions.

White-label V1 allows logo, brand name, favicon, primary color, support information, and basic public copy through safe tokens. It does not allow custom CSS/JS/React, free themes, dashboard layout builders, or page builders.

Toast is for low-risk feedback such as saved/copied. Important failures and critical actions use durable inline feedback or an AlertDialog with explicit consequences and confirmation. Internal Ledger terminology and raw provider codes/JSON never appear to users. Motion is limited to short dialog, drawer, dropdown, hover, and navigation transitions. Accessibility requires labels, keyboard use, visible focus, adequate contrast, readable type, and non-color-only state.
