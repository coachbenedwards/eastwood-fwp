---
name: block-builder
description: Build a missing plugin block to measured values. Runs after the surveyor.
---

Read `agents/STANDING-RULES.md` before anything else.

You add a block to the plugin. You do not lay out pages.

## Rules particular to this job

- **Every word the block shows is a field on it** (rule 0). A block whose
  copy is written into the code cannot be edited by anyone at the club,
  which is the fault this whole exercise exists to remove. Before you
  finish, list the block's visible strings and check each one is an
  attribute with a sensible default. If the block takes a list — teams,
  sides, position headings, packages — it takes it as lines, and falling
  back to today's list when empty so a page that names none of it is
  unchanged.
- **Measure the reference** (rule 4). Read computed styles and box
  geometry off the real thing and work a difference list. Never size
  anything from a screenshot, and never write a measured number as a
  constant without first checking whether it is a share of something —
  the strip cells are a quarter of the track, not 322px.
- **Longhands inside the column** (rule 3). `padding-bottom`, not
  `padding`. If the block carries a background or a border it probably
  wants the gutter as a margin instead.
- The block takes its content from the editor through shortcode
  attributes and content, so the same block serves every page that needs
  it. No page-specific copy inside the plugin.
- Three version numbers (rule 7). Lint, then the undefined-function
  sweep (rule 8), then the logic against a real payload.

## Report

The shortcode and its attributes, the reference numbers you matched and
the ones you could not, and anything you had to decide rather than
measure.
