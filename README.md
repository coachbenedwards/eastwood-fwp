# Eastwood — club data

The WordPress plugin behind Eastwood Community FC's website. Everything the site needs
from outside WordPress lives here.

## What it does

- **Football Web Pages proxy** — fixtures, results, the league table and full match
  detail (line-ups, goalscorers with minutes, referee, attendance). The feed sends no
  CORS headers, so the front end reads it through a whitelisted server-side route.
- **Crest store** — each club's badge kept locally at `uploads/ew-badges/<team-id>.png`,
  refreshed weekly from a recorded source URL.
- **Sponsor store** — the club's sponsors at `uploads/ew-sponsors/<slug>.png`, with tier
  and click-through, on the same weekly refresh.
- **Matches page** — `[eastwood_matches]` renders fixtures, results and the table.
- **Markup rewrite** — the theme is a captured replica, so a single output pass swaps in
  our own icon set and sponsor artwork and repairs the image markup it shipped with.
- **News importer** — brings the club's existing posts across, deduplicated on source URL.

## Install

A normal plugin at `wp-content/plugins/eastwood-fwp/`. After activating, paste the
Football Web Pages key once at **Settings → Eastwood FWP**.

The API key is **not** in this repository and never should be — it lives in the site's
options table. Nothing here is secret, which is why this repo is public.

## Updates

The plugin polls `plugin.json` on `main` and offers an update through the normal Plugins
screen when the version here is newer than the installed one.

To publish a release:

1. Bump `Version:` in the plugin header **and** `EW_FWP_VERSION` in the same file
2. Bump `version` in `plugin.json`
3. Commit and push to `main`
4. On the site: Plugins → Check again → Update

The update downloads the branch zip. GitHub names that folder `eastwood-fwp-main`, so
the plugin carries an `upgrader_source_selection` filter to rename it back — without
that, WordPress would install a second, separate copy alongside the first.

## Why this exists

The site sits behind a firewall that rejects any POST body containing PHP, so the
WordPress plugin and theme editors return 403 and every change used to mean chunking a
zip through the browser by hand. Pointing the plugin at its own repository replaced all
of that with one click.

## Notes for whoever works on this next

- Eastwood CFC is team **2059**; United Counties League Premier Division North is
  competition **143**.
- The licence carries no written match reports — but the `match` endpoint holds a
  complete structured account of every game.
- `api.github.com` is not reachable from the host. `raw.githubusercontent.com` and the
  branch-zip download are, which is why the updater reads a plain file.
- Namespace every CSS class you add. The theme ships a utility stylesheet that already
  defines `.club`, `.us`, `.home`, `.away`, `.pts` and `.hide`.
- After installing crests or sponsors, purge the site cache — the cache-busting version
  is baked into the page HTML.
