---
name: release
description: Check the page as a stranger sees it, and that nothing else moved.
---

Read `agents/STANDING-RULES.md` before anything else.

## Signed out, through the CDN

The site is served with a thirty-day edge cache (rule 6). Signed in as
an administrator you see the origin; everybody else may see a month-old
copy.

1. Purge the CDN.
2. Fetch the page with no cookies and confirm `cf-cache-status` is a MISS
   and the new markup is there.
3. Only then call it visible.

## Nothing else moved

Run `node qa/sweep.mjs --gate` across every address in the tracker.
Converting one page must not change another. Report any address whose
status, height or word count has shifted, including the ones nobody
touched.

## Report

Visible or not, with the cache header as evidence, and the list of any
other address that moved.
