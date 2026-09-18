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

## Assets carousel approval — 2026-09-18

The user replaced the Assets portrait placement with a compact landscape carousel
and explicitly selected three themes: invitations, card services, and fixed-term
wealth. This supersedes the original-art-only restriction for Assets only. The
public landing page retains the approved original portrait and its full copy.

Assets uses three redesigned 3:1 illustrations, HTML-localized headings and links
to /promotion, /cards and /wealth. Six-second rotation pauses on interaction or
hover and respects reduced motion; dots, keyboard arrows and touch swipes provide
manual navigation. No prices, balances, qualification rules or financial actions
are changed. The artwork makes no guaranteed-return or card-network claims.

Images were created using built-in image_gen, then resized/encoded to 1200×400
JPEG for delivery (original generation files retained). Files:
- public/images/marketing/growth/invitation-banner.jpg
- public/images/marketing/growth/cards-banner.jpg
- public/images/marketing/growth/wealth-banner.jpg

Prompt set: ads-marketing; premium text-free 3:1 fintech carousel illustration;
soft ivory/pale mint, polished 3D, emerald details, studio shadows; left 60%
negative space for HTML headings, subjects on right; no text, logos, numbers,
charts, promises or watermarks. Subjects respectively: translucent glass globe
with connected pearl spheres; two mint/pearl payment cards with small chips;
mint/pearl coins beside a glass monthly calendar with blank squares.

Verification: tests/Browser/asset-campaign.mjs uses isolated local fixtures with
no financial requests, checking three widths, four languages, all destinations,
keyboard, touch, reduced motion and automatic/pause behavior.

### Invitation artwork correction — 2026-09-18

The user corrected the invitation slide to follow the original campaign poster and
retain all original wording. The other two slides remain unchanged. The new
`public/images/marketing/growth/invitation-growth-banner.jpg` is a 3:1 recomposition
of the approved portrait, using its navy/red/gold palette, Earth, card, people and
bridge imagery. Original Chinese brand/headline/2030 aspiration/three slogans and
promotion CTA are embedded intact; localized alternative text describes the content.
The image is contained, never cropped, and carousel controls use gold on navy.

Built-in image_gen edit prompt: recompose the supplied original portrait as a 3:1
landscape, preserve the brand and globe/card/bridge/people identity; large left-aligned
Chinese headline, original 2030/5亿 aspiration, all three original slogans and
进入推广中心 CTA; retain exact Chinese text; navy/red/gold; no extra claims or
watermarks; reserve bottom safe space for carousel controls. Delivered as 1600px
JPEG from the original retained generated PNG.
