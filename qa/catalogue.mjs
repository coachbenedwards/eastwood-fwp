/**
 * What each kind of finding means, how sure we are, and what to do about it.
 *
 * Two tiers, and the difference matters more than the checks themselves.
 *
 *   certain — the markup is wrong whatever the intention was. There is no
 *             design choice under which a link should announce itself as
 *             something the page does not say. Fix it.
 *
 *   check   — probably wrong, but a person has to look first, because the
 *             same symptom can be a deliberate choice. A page with no h1
 *             might be an oversight or might be a deliberate visual landing
 *             page. Fixing one of these without asking means changing
 *             something that was never broken.
 *
 * The gate fails on certain findings only. A check finding waits for a human
 * and never blocks a release on its own.
 */
export const CATALOGUE = {
  'says-one-thing-announces-another': {
    certainty: 'certain',
    why: 'The title or aria-label contradicts the words in the link, so it is read out as something the page never says.',
    fix: 'Set the title to the link text, or delete the title. Keep it only where it adds to the label rather than replacing it.',
  },
  'image-with-no-alt': {
    certainty: 'certain',
    why: 'No alt attribute at all, so the image is announced by its file name or not at all.',
    fix: 'Add alt with what the image says. If it is purely decorative, add alt="" so it is skipped deliberately.',
  },
  'unprotected-new-tab': {
    certainty: 'certain',
    why: 'target="_blank" without noopener leaves the opened page able to reach back at this one.',
    fix: 'Add noopener to the existing rel.',
  },
  'repeated-id': {
    certainty: 'certain',
    why: 'Two elements share one id. Invalid markup; anchors, labels and assistive tech resolve to whichever came first.',
    fix: 'Make the second one unique, or drop the id if nothing points at it.',
  },
  'link-with-no-name': {
    certainty: 'certain',
    why: 'No text, no image and no label, so the link cannot be identified or announced.',
    fix: 'Give it text, or an aria-label saying where it goes.',
  },
  'button-with-no-name': {
    certainty: 'certain',
    why: 'A control nobody can identify without seeing it.',
    fix: 'Add text or an aria-label.',
  },
  'field-with-no-label': {
    certainty: 'certain',
    why: 'A form field with nothing naming it.',
    fix: 'Add a label with a matching for attribute, or an aria-label.',
  },
  'page-scrolls-sideways': {
    certainty: 'certain',
    why: 'The page is wider than the screen, so the reader has to scroll sideways to see it.',
    fix: 'Find the element past the edge and let it wrap or scroll inside its own container.',
  },
  'page-would-not-load': {
    certainty: 'certain',
    why: 'The page did not render.',
    fix: 'Check the URL is still live and the server responded.',
  },

  'no-h1': {
    certainty: 'check',
    why: 'Nothing on the page is marked as its heading. Search engines and readers use it to tell what the page is.',
    fix: 'Confirm what the page heading should be, then mark it h1. On a page built as a visual landing page this can be deliberate.',
    ask: 'Is this page meant to have a visible heading?',
  },
  'more-than-one-h1': {
    certainty: 'check',
    why: 'Several headings claim to be the page heading.',
    fix: 'Decide which one is the page heading and drop the others to h2.',
    ask: 'Which of these is the real heading?',
  },
  'heading-order-skips': {
    certainty: 'check',
    why: 'A heading level is missed out, so the outline reads as though a section is missing.',
    fix: 'Move the heading to the next level down, and carry its styling across so it still looks the same.',
    ask: 'Is the jump deliberate styling, or should the level change?',
  },
  'no-meta-description': {
    certainty: 'check',
    why: 'Nothing tells a search engine what the page is about, so it writes its own.',
    fix: 'Write one sentence per page. Deliberate on a page that should not be indexed.',
    ask: 'Should this page be indexed at all?',
  },
  'dead-link': {
    certainty: 'check',
    why: 'A link with nowhere to go.',
    fix: 'Give it a destination, or stop rendering it as a link.',
    ask: 'Is this waiting on something, or should it come out?',
  },
  'tap-target-too-small': {
    certainty: 'check',
    why: 'Under 24px on a phone, which is hard to hit accurately.',
    fix: 'Give it more padding, or more space around it.',
    ask: 'Is this a primary control or something incidental?',
  },
};

export const certaintyOf = type => (CATALOGUE[type] || {}).certainty || 'check';
export const adviceFor = type => CATALOGUE[type] || { why: '', fix: '' };
