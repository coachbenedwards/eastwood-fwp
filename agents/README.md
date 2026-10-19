# The Elementor block team

Five jobs, run in order, once per page. A page does not reach the next
agent until the one before it has passed. Every agent reads
[STANDING-RULES.md](STANDING-RULES.md) first.

The order is the point. The baseline has to exist before anything is
touched, because after the page has changed there is no way to know what
it looked like.

| # | Agent | Hands on | Done when |
|---|-------|----------|-----------|
| 1 | `surveyor` | nothing | the before is recorded and every copy of the page's words is named |
| 2 | `block-builder` | the plugin | the block the page needs exists and is measured against the reference |
| 3 | `assembler` | Elementor, the plugin | the page is containers and blocks, and exactly one source holds its words |
| 4 | `pixel-diff` | nothing | the after matches the before at all four widths |
| 5 | `release` | the CDN | a signed-out visitor sees it, and nothing else on the site moved |

## Running one page

```
node qa/baseline.mjs --url /pitch-hire/ --save     # agent 1
#   ... agents 2 and 3 do the work ...
node qa/baseline.mjs --url /pitch-hire/ --diff     # agent 4
node qa/sweep.mjs --gate                            # agent 5
```

## Why five and not one

Each of the faults in the standing rules was found by doing one of these
jobs properly after having skipped it. The split exists so that the
agent measuring the result is not the one that built it.
