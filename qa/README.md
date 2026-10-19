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

## Two kinds of finding

Every finding is one of two kinds, and the difference matters more than the
checks do.

**Wrong.** The markup is wrong whatever anyone intended. There is no design
choice under which a link should announce itself as something the page does
not say, or an image should have no alt attribute at all. These come with the
fix and can be done without asking.

**Check first.** Probably wrong, but the same symptom can be a deliberate
choice, so a person looks before anything changes. A page with no h1 might be
an oversight or might be a deliberate visual landing page. A dead link might
be broken or might be waiting on a URL nobody has yet. Each of these comes
with the question to answer, not just the fix.

Fixing a check-first finding without asking means changing something that was
never broken, which is worse than leaving it. So `--gate` fails on a new
**wrong** finding only. A check-first finding is reported, named as not
blocking, and waits.

`qa/catalogue.mjs` holds which kind each check is, why it matters and the
suggested fix. Change the tier there, not in the sweep.

## What comes back

`qa/reports/latest.json`, and the same thing readably on stdout. Findings on
nearly every page are reported once as sitewide rather than once per page,
because they live in the shared header or footer — on the first run that was
thirty of thirty-five, and without the split the five that were page-specific
were buried.

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

## baseline.mjs — the before, and the proof

```
node qa/baseline.mjs --url /pitch-hire/ --save     # while the old page still stands
node qa/baseline.mjs --url /pitch-hire/ --diff     # after it has been converted
```

`--save` writes a full-page screenshot and the box geometry of every
element at 1512, 1440, 820 and 390 into `qa/baseline/<page>/`. `--diff`
re-measures and compares, and exits non-zero on any of:

- an element more than 2px from where it was (`--tolerance` to change it)
- the page text differing by a character
- a link whose href has changed
- the page scrolling sideways at any width
- an element that has disappeared, or one that has appeared

### What an element is keyed by

This is the part that decides whether the whole thing works, and the
first two answers were both wrong.

Keying by class is wrong: the classes are the first thing a conversion
changes. Keying by position in the tree is wrong too, and worse. Moving
a page into the builder wraps every block in three new divs, which
shifts the position of everything on the page, so the first run of this
against a converted page reported all 562 elements missing — a flood of
noise that said nothing about whether the page was right. A harness that
cannot tell a conversion from a catastrophe is no harness.

So an element is keyed by what it is: its tag and the words it holds
itself, or for a picture its file. Wrappers that hold no words and paint
nothing are skipped — they are exactly what the builder adds and
removes, and anything real inside one carries its own position with it.
A box that paints something, a band or a rule, is kept and keyed by what
it paints, because a reader can see it even without words. Where two
elements are identical — two "Match Centre" links — they are numbered in
the order they appear.

The clock on the next-match card is set to a fixed `00` before anything
is measured. Otherwise every capture differs from the last by a few
seconds, and the diff reports the passage of time as a change to the
page. Anything else that legitimately moves on its own can be added with
`--volatile`.

**The geometry is committed, the screenshots are not.** The geometry
file is what the diff reads and it is small; the screenshots are for
looking at and would put tens of megabytes into every plugin update.

Needs Playwright. If it is not in the repo's own `node_modules`, point
`NODE_PATH` at the folder that holds it.
