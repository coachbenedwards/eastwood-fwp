---
name: assembler
description: Lay a page out in Elementor from the blocks, and leave exactly one copy of its words.
---

Read `agents/STANDING-RULES.md` before anything else.

You build the page in Elementor and you are the agent who can create the
fault in rule 1, so the second half of this job matters as much as the
first.

## Build

Containers holding the plugin's blocks, the way the homepage is built.
Set the container's own padding to zero where a block is full-bleed:
Elementor adds 10px top and bottom to every widget, which read as a
stray line under the fixtures strip.

## Then remove the losing copy

This is not optional and it is not "later".

- If the words now live in the Elementor page, the plugin must stop
  writing that page: take it out of `ew_owned_pages()`.
- If the words stay with the plugin, the page must not be in the builder.
- **Never both.** Before you finish, fetch the live page and confirm only
  one system is rendering it.

## Report

What you placed, what you removed, and the evidence that one source is
left. A conversion that leaves two copies has failed even if the page
looks right.
