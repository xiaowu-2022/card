# UI Rules

The shared User mobile design contract uses a PokePay-inspired baseline for information hierarchy, compact financial presentation, and bottom-navigation behavior without copying PokePay branding, assets, proprietary screens, or visual identity. All surfaces continue to use the single project Design System. A broader token/theme adjustment is a separate UI Baseline Patch, not part of the Phase 4.1 Ledger audit.

The shared direction is light, clean, neutral, professional financial software. Information architecture and interaction patterns may learn from Stripe/Brex admin, Wise wallet, and Revolut card experiences without copying brand visuals.

Use shadcn/ui primitives plus focused project wrappers only. Do not add a second UI framework. Status always combines text and color using SUCCESS/WARNING/DANGER/INFO/NEUTRAL semantics. Tenant primary color affects primary controls, links, active navigation, and selected states; it never overrides risk colors.

PublicLayout, UserLayout, TenantAdminLayout, and PlatformLayout remain separate surfaces. User pages are mobile-first: 375, 768, and 1440 px must be fully usable. Phase 4 mobile navigation exposes Dashboard, Wallet, Verify, and Account. Admin is desktop-first with sidebar/topbar and a mobile drawer; 375 px remains readable with basic actions.

Phase 2 registration is a progressive contact -> code -> password flow, never one giant form. OTP fields support paste, numeric keyboards, autocomplete, labels, and visible errors. The authenticated User Dashboard shows only real account/contact, KYC, and Wallet state; it never displays mock balances or cards. Restricted users retain clear links to Wallet read-only state, account security, and logout.

Phase 3 adds a mobile-first `/kyc` status/submission flow and desktop-first `/admin/kyc` review queue/detail. End Users see derived review state but not internal OCR progress, object keys, or document images. Admin detail displays backend-masked identity and OCR hints; original images remain behind recent authentication and separate permission. Review decisions use durable feedback and explicit confirmation.

Phase 4 `/wallet` follows a restrained Wise-like hierarchy: real available balance, security-deposit current/required/remaining, Wallet status, then empty or business-friendly activity. Internal holds are hidden from Users. Tenant Admin Wallet/Ledger pages follow Stripe/Brex-like read-only information density and never contain add/subtract/adjust/correct/edit actions. Amount props are decimal strings.

White-label V1 allows logo, brand name, favicon, primary color, support information, and basic public copy through safe tokens. It does not allow custom CSS/JS/React, free themes, dashboard layout builders, or page builders.

Toast is for low-risk feedback such as saved/copied. Important failures and critical actions use durable inline feedback or an AlertDialog with explicit consequences and confirmation. Internal Ledger terminology and raw provider codes/JSON never appear to users. Motion is limited to short dialog, drawer, dropdown, hover, and navigation transitions. Accessibility requires labels, keyboard use, visible focus, adequate contrast, readable type, and non-color-only state.
