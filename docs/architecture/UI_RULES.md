# UI Rules

The shared direction is light, clean, neutral, professional financial software. Information architecture and interaction patterns may learn from Stripe/Brex admin, Wise wallet, and Revolut card experiences without copying brand visuals.

Use shadcn/ui primitives plus focused project wrappers only. Do not add a second UI framework. Status always combines text and color using SUCCESS/WARNING/DANGER/INFO/NEUTRAL semantics. Tenant primary color affects primary controls, links, active navigation, and selected states; it never overrides risk colors.

PublicLayout, UserLayout, TenantAdminLayout, and PlatformLayout remain separate surfaces. User pages are mobile-first: 375, 768, and 1440 px must be fully usable. Phase 2 mobile navigation exposes only Dashboard, Account, and Security; future Wallet/Card navigation is added only with its owning business phase. Admin is desktop-first with sidebar/topbar and a mobile drawer; 375 px remains readable with basic actions.

Phase 2 registration is a progressive contact -> code -> password flow, never one giant form. OTP fields support paste, numeric keyboards, autocomplete, labels, and visible errors. The authenticated User Dashboard shows only real account/contact state and marks KYC/Wallet/Card steps as unavailable; it never displays mock balances or cards. Restricted users retain clear links to account security and logout.

Phase 3 adds a mobile-first `/kyc` status/submission flow and desktop-first `/admin/kyc` review queue/detail. End Users see derived review state but not internal OCR progress, object keys, or document images. Admin detail displays backend-masked identity and OCR hints; original images remain behind recent authentication and separate permission. Review decisions use durable feedback and explicit confirmation.

White-label V1 allows logo, brand name, favicon, primary color, support information, and basic public copy through safe tokens. It does not allow custom CSS/JS/React, free themes, dashboard layout builders, or page builders.

Toast is for low-risk feedback such as saved/copied. Important failures and critical actions use durable inline feedback or an AlertDialog with explicit consequences and confirmation. Internal Ledger terminology and raw provider codes/JSON never appear to users. Motion is limited to short dialog, drawer, dropdown, hover, and navigation transitions. Accessibility requires labels, keyboard use, visible focus, adequate contrast, readable type, and non-color-only state.
