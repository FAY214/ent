# Mindful Care v2

## Scope

The user approved a calmer presentation for teenage users and more visible
companion growth. They explicitly excluded removing/replacing the existing
window, potted plant, stars and room setting. These scene elements are retained.

The interface keeps the existing light-blue palette. Changes include restrained
sans-serif headings, smaller card/button corner radii, less shadow, slate-blue
text, compact care tools, matching line icons for food, simpler names and copy.
Home's companion selector uses the actual companion artwork instead of emoji.

## Growth artwork

Stages are now First connection / Explorer / Mature companion
(初遇期 / 探索期 / 成熟期), with the existing thresholds 0 / 120 / 320 XP.
Baby/base art stays recognizable. Explorer forms develop longer torsos, limbs,
wings/tails and muzzles. Mature forms develop more adult animal silhouettes.
Pig stays quadrupedal. Bird keeps the white curl and plain light-blue clothing.
New artwork is generated as two transparent PNG atlases; SVG viewports select
each sprite with its original proportions, and the existing live blinking-eye
renderer overlays its visible eyes.
Idle and touch animations are gentler, with slower motion for mature animals.

Each stage has a short description in the growth gallery. New art appears in
the gallery, companion scene, Home and floating companion according to saved XP.

## Functionality

Feeding, food selection, stroke, cleaning, play, rest, breathing/music, planner,
needs, XP, invitations and shared-care records continue to use
their existing state/backend logic. This change does not alter database records
or grant XP. The rolled-back Mood Garden remains removed.

The later-requested removal of Left-to-select / Right-to-confirm interaction
removes both its hint and the global arrow-key listener from Mindful Care.
On-screen care controls remain available. Direct two-handle interactions are
pending the actual independent sensor data format.

## Sources

Asset prompts and generation method: public/assets/companions/generated/GROWTH_V2.md.
Growth rules retain Mori's original MIT notice and the same XP thresholds.
Prior source versions are copied under node_modules/.cache/teen-care-v2-before.

Compilation uses the focused client TypeScript configuration and Vite build.
No automated tests or feeding/XP submissions are performed for this visual change.
