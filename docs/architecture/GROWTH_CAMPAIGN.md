# Growth strategy campaign — approved 2026-09-17

The user's latest correction supersedes the initial landscape redesign: retain
the supplied product poster's complete wording, Mastercard/U Card branding,
people, composition, and portrait layout. Only the bottom-right AI watermark is
removed. Do not substitute the rejected navy/gold redesign, crop the poster into
a banner, or overlay translated text on the approved art.

The homepage displays the complete poster after its existing hero and before the
service strip, with a separate product-anchor link underneath. The Assets page
displays the same complete artwork immediately after Accounts and before pending
orders/activity. The whole Assets poster links to `/promotion`; account collapse
and balance visibility never hide it. The portrait presentation follows the latest
instruction to preserve the layout; the previous 180–210px banner height no longer
applies. Existing financial actions and eligibility remain unchanged.

This narrowly permits the supplied campaign artwork on Assets, not a change to
the consumer design system or other financial screens. Embedded artwork retains
its original Chinese and brand marks in every locale, as explicitly requested.
Accessible descriptions and surrounding navigation use the four-language catalog.
The 2030/500-million wording is a target, never a claimed current user count.

`GrowthCampaign` provides public/account presentation using a shared static image.
Image dimensions reserve space, responsive JPEG variants reduce transfer size,
and lazy loading/async decoding keep the financial controls first. No database,
API, admin setting, payment operation, or external tracking is added.

## Asset provenance

Source supplied by the user: `codex-clipboard-6d0e2be1-6e77-4df0-9dbf-9d8415f6c990.jpg`.
The built-in imagegen tool was used for a localized watermark-removal edit.
The final PNG and optimized JPEGs are under `public/images/marketing/growth/`.

Final edit prompt: "Only remove the small grey 豆包AI生成 watermark at bottom right
and fill with the existing dark grid background. Preserve the complete portrait
framing, every other word, logo, face, card, globe, skyline, color, position and
layout. No redesign, crop, replacement text or new elements."

The initial alternative design is not referenced or shipped. Original tool outputs
remain in the local image-generation history; deployment uses only project files.
