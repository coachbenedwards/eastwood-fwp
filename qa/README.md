# Site sweep

Finds the defects nobody can see by looking at the screen: a link that
announces itself as something the page does not say, an image with no name, a
heading order that skips, a repeated element id, a new tab left unprotected,
a page that scrolls sideways on a phone.

It was written after a footer link reading "Club History" was found announcing
itself as "Contact us" to anyone hovering over it or using a screen reader.
The first run of this script found the same defect on the Safeguarding link,
eleven sponsor logos with no name at all, and eleven links opening new tabs
unprotected.

## Running it

    npm install --no-save playwright
    npx playwright install chromium          # Chrome, Edge, Chrome on Android
    npx playwright install webkit firefox    # Safari and iPhone, Firefox

    node qa/sweep.mjs                 every page, every engine, every width
    node qa/sweep.mjs --fast          chromium at one width, for a deploy gate
    node qa/sweep.mjs --gate          exit 1 on anything new since the baseline
    node qa/sweep.mjs --update-baseline

Point it somewhere else with `QA_BASE=https://staging.example.com`.

An engine that is not installed is skipped, named in the output and listed in
the report, so a partial matrix never reads as a clean one.

## What comes back

`qa/reports/latest.json`, and the same thing readably on stdout, split into:

- **Sitewide** — anything appearing on nearly every page, which means it lives
  in the shared header or footer. On the first run this was thirty of the
  thirty-five findings. Without this split the five that matter are buried.
- **Page by page** — everything else.
- **Failed requests** — anything the page asked for and did not get.

Images are not loaded. None of the checks depend on them, and the host
rate-limits them, which otherwise shows up as failures that are not real.

## The baseline

`--gate` compares against `qa/baseline.json` and fails only on something new,
so a known issue waiting on a decision does not block every deploy behind it.

There is deliberately no baseline committed yet. Writing one now would accept
the current defects as normal. Fix what the sweep found, deploy, then run
`--update-baseline` against a clean site so the baseline records a good state
rather than a bad one.

## What this does not cover

The matrix runs real engines, so Safari behaviour is really Safari behaviour.
It is not a real device. It will not tell you how fonts and colour render on a
given handset, how scroll momentum or the on-screen keyboard behave, how an
older iOS version copes, or what happens on a bad network at the ground. That
needs real hardware or a paid device cloud.
