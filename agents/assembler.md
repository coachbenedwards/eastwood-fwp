---
name: assembler
description: Lay a page out in Elementor from the blocks, and leave exactly one copy of its words.
---

Read `agents/STANDING-RULES.md` before anything else.

You build the page in Elementor and you are the agent who can create the
fault in rule 1, so the second half of this job matters as much as the
first.

## Build

Rule 0 decides the shape of every page you build: Elementor blocks a
person at the club can edit and carry on from. Concretely, for each piece
of a page, in this order of preference:

1. **A heading widget** where the content is a heading. **A text widget**
   where it is prose. A person clicks it and types.
2. **A block of this plugin** where the content is live data or
   interactive — fixtures, the squad, the sponsor wall, the TV strip —
   and every word it shows is a field on that block.
3. **Nothing else.** A page of HTML in one code box is not a block page.
   If you are about to write one, you have the wrong answer: go back and
   either split it into widgets or add the fields to a block.

Sections are separate widgets inside one wrapper container, so they can
be reordered and added to. The wrapper carries the class the styling
needs; it never carries the words.

If a CSS rule stands in the way of splitting a page into editable
widgets — a direct-child rule is the usual one — fix the rule. That has
happened and the fix is in the plugin: a measure rule reaches both a bare
paragraph and one inside a text widget. Do not ship one box instead.

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
