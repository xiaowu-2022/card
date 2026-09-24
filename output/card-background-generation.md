# Card background generation

Mode: built-in image_gen (precise-object-edit), not CLI.

Reference: public/images/marketing/spec-pay-gold-world.png

Final web asset: [spec-pay-gold-background.jpg](../public/images/cards/spec-pay-gold-background.jpg)

The generated PNG was converted to JPEG at quality 90 for web delivery (323 KB). All card data, Spec Pay branding, chip and Mastercard mark remain separate UI overlays.

## Final prompt

Use case: precise-object-edit. Edit target: attached gold-world card image. Produce ONLY a clean blank card-face background texture for a live web payment card component. Remove ALL text, logos, symbols, chip, numbers, Mastercard circles, date and cardholder letters entirely; fill these areas seamlessly with matching dark satin charcoal. Preserve the exact luxurious metallic GOLD reflective rim and the dramatically bright GOLD curved globe horizon on the right, dotted gold world map at upper right, diagonal satin texture, bright specular highlight along upper-left rim and bottom rim. Make the map and golden arc clearly visible even at 350px width, matching the reference brightness, not faint. Crop/zoom to the rectangular card face itself: front-on flat 1.586:1 aspect landscape, rim at image perimeter with near-zero outer margin, no tabletop, no floor reflection, no perspective, no surroundings. Output only this blank black/gold card background. No text, no chip, no logos at all; live UI will overlay those. Keep left two-thirds dark and clean for readable live fields.
