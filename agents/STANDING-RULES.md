# Standing rules

Every agent on this team reads this first. Each line is here because it
already went wrong on this site, and the cost is written next to it.

## 1. Two systems must never hold the same words

Elementor keeps its own copy of a page's text in post meta and renders
that, ignoring whatever the plugin writes into the content. Ten pages
sat like that for weeks. The dead address `info@eastwoodcfc.co.uk` was
removed from the plugin and stayed on eleven live pages; three people
who do not work at the Academy stayed on the Academy page. Nothing
looked broken, which is what made it expensive.

**Before editing a page's words, establish which system renders them.**
After converting a page, **delete the losing copy** — not disable it,
not leave it "just in case". One page, one source.

The same fault in a different coat: the plugin was taking the front page
over at `template_redirect` and printing a captured replica, so choosing
a homepage in Settings saved the setting and changed nothing. Twice I
blamed something else — a template, then the CDN — before finding it.

## 2. Check what renders a page before you change it

Not the page list in the admin. The live page. `data-widget_type` in the
markup means Elementor is rendering it; the plugin's own block classes
(`ew-prose`, `ewp-*`) mean the plugin is. Both present means trouble.

And read the MARKUP, not a grep of the whole response: `grep -c
'ewp-strip'` matched the stylesheet and reported a block that was not on
the page. Twice.

## 3. A padding shorthand wipes the gutter

The column on this site is `padding-left/right` applied to every child.
Any rule that later sets `padding:` as a shorthand on one of those
children silently removes it. Three times so far:

- `.ewp-ha.is-away{background:transparent}` — a background shorthand
  wiping `background-size`, so the away marker filled its whole disc.
- `.ew-prose h2{padding:0 0 10px}` — every heading on every prose page
  started at the window edge while its paragraphs started 132px in.
- `.ew-prose .ew-cta{padding:26px 28px}` — a black band across the whole
  window instead of a box in the column.

**Use longhands on anything inside a laid-out column.** And remember
`box-sizing: border-box` subtracts that padding from `max-width`: a 72ch
paragraph came out 503px wide.

## 4. Measure, never eyeball

Read computed styles and box geometry off both the reference and ours,
and work a difference list. A screenshot comparison produced "nine
differences fixed" and left the cells at 272px where the reference has
322px, because the number was read as a constant when it is a share of
the track.

## 5. A regex over a whole page can fail silently

`preg_match` with a lazy `(?:(?!</a>).)*?` over a 200KB page hits
PCRE's backtracking limit and returns **no match**, not an error. One
nav item changed and the next one did not, with nothing to say why.
Find a landmark with `strpos` and walk outward instead.

## 6. Live and visible are different questions

Cloudflare serves this site with `s-maxage` of thirty days. A change can
be live on the server and invisible to everyone for a month. **Every
check ends signed out**, and a purge is part of shipping.

## 7. Three version numbers

`* Version:` in the header, `EW_FWP_VERSION`, and `plugin.json`. Only
the last one is compared by the updater, and all three must match.
Updates appear on **Dashboard → Updates**, never on
`plugins.php?force-check=1`.

## 8. `php -l` is not a test

It catches syntax, not a call to a function that no longer exists.
Deleting `ew_squad_departed()` passed the lint and took two pages down
with 500s. Run the undefined-function sweep, and run the logic against a
real payload before shipping it.

## 9. An apostrophe in a single-quoted PHP string

The blocks print their CSS from inside single-quoted PHP strings. Writing a
possessive or a contraction into one of those — a comment included — closes
the string, and PHP fails to parse the whole file.

Cost: six times now. It is always caught by `php -l`, and always after the
edit has been written, staged and queued for a release.

Before writing any string into a `.php` file, check it for `'`. Reword, or
use double quotes. A CSS comment is not exempt: it is inside the string too.

## 10. Never amend a commit that has already been pushed

2.75.0 was committed, Ben pushed it, and then it was amended to add one
more change. His copy and GitHub then disagreed about what 2.75.0 was,
and his next push was rejected as "non-fast-forward" — which reads, to
him, as something broken at his end.

Cost: a failed push, a confusing error, and a conversation about git
rather than about the club.

Amending is only ever safe before the push. Once it is up, the next
change is a new commit with the next version number, even when the
change is small and the previous one was only minutes ago. The fix, if
it happens again, is `git reset --soft origin/main` and one new commit —
never a force push, which would destroy whatever else had arrived.

## 11. A conditional asset gate searches for a string, and the string changes

The prose styling is printed only on pages that need it, and the test was
whether the page's content holds `ew-prose`. Converting those pages to a
block replaced that string with `eastwood_prose` — an underscore where
there was a hyphen — so eleven live pages lost their styling entirely and
every paragraph ran the full width of the screen.

Cost: eleven pages wrong on the live site, found by the before-and-after
rather than by looking.

Before converting a page, grep the plugin for every gate that decides
whether to print CSS or script for it, and check the new markup still
satisfies each one. A gate that matches on a class name will not match a
block name.

## 12. A shortcode's body is not run through WordPress's typography

These pages printed through the_content, which curls quotes and turns a
double hyphen into a dash. The body of a shortcode gets none of that. The
first conversion silently turned every apostrophe on eleven pages from ’
into ' — same length, invisible in a screenshot.

Caught only because the check compares the words, not just the geometry.
When writing moves from a page's own content into a block, the block has
to apply wptexturize, convert_smilies, wpautop and shortcode_unautop
itself, in that order, before do_shortcode.
