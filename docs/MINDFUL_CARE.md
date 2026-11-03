# Mindful Care: companion home and shared growth

## Design and preserved features

Mindful Care uses Mori's central companion + care actions + need meters + evolution
structure, restyled in the existing PuffSqueeze light-blue/cream visual system.
It uses the eight existing companion identities, with two newly generated forms
per species (16 new designs). Every form uses the existing live SVG blinking eyes.
The original bird sweatshirt has no lettering.

Preserved: gentle stroke, three breathing cycles (4/2/6/2 seconds), background
piano music with play/pause/mute, feeding, cleaning, happiness, plan notes and
usage logging. Added: food selection, play, rest, independently saved growth,
names, growth gallery, invitation-based shared care and caregiver history.
The old per-visit routine lock is replaced by a five-second care pause.
Previous device planner notes remain readable; new notes are scoped to the user.

## Growth

Each animal grows independently: 0 XP = Little beginnings, 120 XP = Growing
together, 320 XP = Gentle guardian. Levels increase every 40 XP. Growth never
decays; needs decrease gently with a floor of 25%, no death mechanic.
Feed +10, stroke +5, clean +8, play +12, rest +6, full breathing session +15.
Growth appears on Home and the draggable companion as well as Mindful Care.
Care actions use the on-screen controls. The initial Left-to-select /
Right-to-confirm mapping and hint were removed at the user's request. Direct
two-handle interactions require the independent sensor data format; the current
firmware only sends a single strike_value on press events.

## Online shared care

1. Open Mindful Care. Existing signed-in users click **Sign in to sync** once,
   using their current account, to obtain the new companion-care token.
2. Select an animal and create an invitation in **Grow with friends**.
3. Friends register/sign in using their own accounts on the same running app,
   and enter the 12-character invitation code. Codes expire after seven days.
4. One owner and up to five friends share that animal's state, XP and history.
   Visible pages refresh shared state every eight seconds. All members can care;
   only the owner can rename, generate/revoke invitations or remove members.
   Friends can leave. Removing a member revokes existing invitation codes.

Server tokens are random, SHA-256 hashed at rest and expire after 30 days.
New care endpoints authenticate bearer tokens rather than trusting a user ID.
Database transactions lock each pet before applying changes. Server-side rewards,
per-user/pet cooldowns and unique request IDs prevent double-awarded requests.
The client never invents friend activity or locally advances shared progress on
failure. If a response is lost after a server commit, reconnect to see that result.
Device-only care is a separate save and is explicitly labelled in the UI.

`backend/sql/companion_care.sql` adds five tables. The backend installs this
additive schema on first use and respects MOODLE_TABLE_PREFIX. On a restricted
database, have an administrator install it with the matching prefix in advance.
No original tables/data are dropped. The schema was installed on the local XAMPP
database during implementation. Use `backend/.env` for connection configuration.

During Vite development, `/backend` proxies to VITE_API_BASE_URL, so friends on
the LAN do not send requests to their own localhost. Keep PHP bound to loopback;
the development frontend is the only LAN entry point. Open the host's LAN IP on
port 3000 from other devices on the same network. Remote users need a hosted
shared frontend/backend/database; localhost is not an internet service. For a
production build configure VITE_API_BASE_URL to the actual HTTPS backend and
ALLOWED_ORIGIN to the deployed frontend origin before rebuilding.

## Open-source credit

Growth thresholds and progress calculation in `src/services/careGrowth.ts` are
adapted from [Mori/Tamagochi](https://github.com/Tech-aficionado/Tamagochi---Open-Source-Game),
`src/game/progression.ts`, copyright (c) 2026 Tamagochi contributors, MIT.
Full license: `public/credits/mori-license.txt`. The companion room, multiplayer
backend and PuffSqueeze artwork are project-specific adaptations; Mori's 3D
renderer and its mascot are not used.

## Assets

The built-in imagegen tool created two transparent atlases, saved in
`public/assets/companions/generated/`: `growth-bloom-atlas-v1.png` and
`growth-guardian-atlas-v1.png`. The originals stay in Codex generated_images.
Atlases are rendered with nested SVG viewports and hand-mapped live eye landmarks;
the PNGs are not destructively cropped. Prompts are recorded in `GROWTH_V1.md`
alongside the assets.

Implementation compilation: focused TypeScript typecheck and Vite build. No
automated tests or multiple-account feeding sessions were run in this request.
