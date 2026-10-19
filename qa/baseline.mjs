/**
 * The before, and the proof that the after matches it.
 *
 *   node qa/baseline.mjs --url /pitch-hire/ --save
 *   node qa/baseline.mjs --url /pitch-hire/ --diff
 *
 * Save writes a screenshot and the box geometry of every element at four
 * widths. Diff re-measures and compares. A page is only "a pixel perfect
 * copy of how it was before" if something recorded how it was before, and
 * that has to happen while the old page is still standing.
 *
 * Runs wherever Playwright is installed.
 *
 * What an element is keyed by matters more than anything else here, and
 * the first two answers were both wrong. A class is wrong, because the
 * classes are the first thing a conversion changes. Its position in the
 * tree is wrong too, and worse: moving a page into the builder wraps
 * every block in three new divs, which shifts the position of all of it,
 * so the diff reported the entire page missing and told us nothing.
 *
 * So an element is keyed by what it IS: a tag and the words it holds, or
 * for a picture its file. Wrappers that hold no words and paint nothing
 * are skipped entirely -- they are exactly what the builder adds and
 * removes, and anything real inside one carries its own position. A box
 * that paints something, a band or a rule, is kept and keyed by what it
 * paints, because a reader can see it even though it has no words.
 */

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

/* require() rather than import, because an ESM import ignores NODE_PATH
   and this has to run both inside the repo and from a sandbox where
   Playwright lives somewhere else entirely. */
const need = createRequire(import.meta.url);
let chromium;
try {
	({ chromium } = need('playwright'));
} catch (e) {
	console.error('Playwright is not reachable from here. Install it, or set '
		+ 'NODE_PATH to the folder that holds it.');
	process.exit(2);
}

const argv = process.argv.slice(2);
const arg = (k, d) => {
	const i = argv.indexOf('--' + k);
	return i > -1 && argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : d;
};
const has = (k) => argv.includes('--' + k);

const URL_PATH = arg('url', '/');
const ORIGIN = arg('origin', 'https://eastwoodfc.com');
const WIDTHS = (arg('widths', '1512,1440,820,390')).split(',').map(Number);
const TOL = Number(arg('tolerance', 2));
// Things that legitimately differ between two captures of the same page.
const VOLATILE = arg('volatile', '[data-u]');
const DIR = path.join(path.dirname(new URL(import.meta.url).pathname), 'baseline');
const SLUG = URL_PATH.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home';

if (!has('save') && !has('diff')) {
	console.error('Give it --save or --diff.');
	process.exit(2);
}

/*
 * Runs in the page. See the note at the top on what an element is keyed by.
 *
 * The clock on the next-match card counts down in real time, so every
 * capture of a page carrying one differs from the last by a few seconds.
 * Those digits are set to a fixed 00 before anything is measured: always
 * two characters, so nothing moves, and the comparison stops reporting
 * the passage of time as a change to the page.
 */
const SNAPSHOT = ( volatileSel ) => {
	const out = [];
	const seen = new Map();

	if ( volatileSel ) {
		for ( const el of document.querySelectorAll( volatileSel ) ) { el.textContent = '00'; }
	}

	const paints = (cs) => {
		const bg = cs.backgroundColor || '';
		const img = cs.backgroundImage || 'none';
		const solid = bg && bg !== 'transparent' && !/rgba\(\s*0,\s*0,\s*0,\s*0\s*\)/.test(bg);
		const edge = ['borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth']
			.some((k) => parseFloat(cs[k]) > 0);
		return solid || img !== 'none' || edge;
	};

	for (const el of document.body.querySelectorAll('*')) {
		const r = el.getBoundingClientRect();
		if (r.width < 1 || r.height < 1) { continue; }

		const t = el.tagName.toLowerCase();
		if (t === 'svg' || t === 'path' || t === 'script' || t === 'style') { continue; }

		// Only the words this element holds itself, not its children's.
		const own = [...el.childNodes]
			.filter((c) => c.nodeType === 3)
			.map((c) => c.textContent.trim())
			.join(' ')
			.replace(/\s+/g, ' ')
			.trim();

		const cs = getComputedStyle(el);
		let id = null;
		if (own) {
			id = t + '|' + own.slice(0, 80);
		} else if (t === 'img') {
			id = 'img|' + (el.getAttribute('src') || '').split('/').pop().split('?')[0];
		} else if (t === 'a' && el.getAttribute('href')) {
			id = 'a|' + el.getAttribute('href');
		} else if (paints(cs)) {
			id = t + '|paints|' + cs.backgroundColor + '|' + (cs.backgroundImage || '').slice(0, 60);
		}
		if (!id) { continue; }

		// Two things can be identical -- two "Match Centre" links. Number them
		// in the order they appear, so the second before matches the second after.
		const n = (seen.get(id) || 0) + 1;
		seen.set(id, n);

		out.push({
			k: id + '::' + n,
			t,
			x: Math.round(r.left),
			y: Math.round(r.top + window.scrollY),
			w: Math.round(r.width),
			h: Math.round(r.height),
			s: own.slice(0, 80),
			href: el.tagName === 'A' ? (el.getAttribute('href') || '') : undefined,
		});
	}

	return {
		title: document.title,
		text: (document.body.innerText || '').replace(/\s+/g, ' ').trim(),
		scrollW: document.documentElement.scrollWidth,
		vw: window.innerWidth,
		nodes: out,
	};
};

async function measure() {
	const browser = await chromium.launch();
	const shots = {};
	for (const width of WIDTHS) {
		const ctx = await browser.newContext({ viewport: { width, height: 1000 } });
		const page = await ctx.newPage();
		await page.goto(ORIGIN + URL_PATH, { waitUntil: 'networkidle', timeout: 60000 });
		await page.waitForTimeout(1500);
		shots[width] = await page.evaluate(SNAPSHOT, VOLATILE);
		if (has('save')) {
			fs.mkdirSync(path.join(DIR, SLUG), { recursive: true });
			await page.screenshot({ path: path.join(DIR, SLUG, width + '.png'), fullPage: true });
		}
		await ctx.close();
		await new Promise((r) => setTimeout(r, 900)); // the host rate-limits bursts
	}
	await browser.close();
	return shots;
}

const now = await measure();
const file = path.join(DIR, SLUG, 'geometry.json');

if (has('save')) {
	fs.mkdirSync(path.join(DIR, SLUG), { recursive: true });
	fs.writeFileSync(file, JSON.stringify({ url: URL_PATH, at: new Date().toISOString(), widths: now }, null, 1));
	console.log('Saved the before for ' + URL_PATH);
	for (const w of WIDTHS) {
		console.log('  ' + w + 'px  ' + now[w].nodes.length + ' elements, ' + now[w].text.length + ' characters');
	}
	process.exit(0);
}

if (!fs.existsSync(file)) {
	console.error('No before recorded for ' + URL_PATH + '. Run --save against the old page first.');
	process.exit(2);
}

const was = JSON.parse(fs.readFileSync(file, 'utf8')).widths;
const fails = [];

for (const w of WIDTHS) {
	const a = was[w];
	const b = now[w];
	if (!a) { fails.push([w, 'no before at this width']); continue; }

	if (a.text !== b.text) {
		// Say WHERE, not just that it differs.
		let i = 0;
		while (i < a.text.length && a.text[i] === b.text[i]) { i++; }
		fails.push([w, 'the words changed near "' + a.text.slice(Math.max(0, i - 40), i + 40) + '"']);
	}
	if (b.scrollW > b.vw) {
		fails.push([w, 'the page scrolls sideways (' + b.scrollW + ' in ' + b.vw + ')']);
	}

	const index = new Map(b.nodes.map((n) => [n.k, n]));
	const hit = new Set();
	for (const o of a.nodes) {
		if (index.has(o.k)) { hit.add(o.k); }
		const n = index.get(o.k);
		if (!n) {
			if (o.s) { fails.push([w, 'gone: ' + o.t + ' "' + o.s.slice(0, 40) + '"']); }
			continue;
		}
		for (const f of ['x', 'y', 'w', 'h']) {
			if (Math.abs(o[f] - n[f]) > TOL) {
				fails.push([w, o.t + ' "' + (o.s || '').slice(0, 30) + '" ' + f
					+ ' ' + o[f] + ' -> ' + n[f]]);
				break;
			}
		}
		if (o.href !== undefined && o.href !== n.href) {
			fails.push([w, 'link moved: "' + (o.s || '').slice(0, 30) + '" ' + o.href + ' -> ' + n.href]);
		}
	}

	// Something on the page now that was not there before is just as wrong
	// as something missing, and only this direction catches it.
	for (const n of b.nodes) {
		if (!hit.has(n.k) && n.s) {
			fails.push([w, 'new: ' + n.t + ' "' + n.s.slice(0, 40) + '"']);
		}
	}
}

if (!fails.length) {
	console.log('PASS  ' + URL_PATH + ' matches the before at ' + WIDTHS.join(', ') + '.');
	process.exit(0);
}

console.log('FAIL  ' + URL_PATH + ' — ' + fails.length + ' difference(s)');
for (const [w, msg] of fails.slice(0, 60)) { console.log('  ' + w + 'px  ' + msg); }
if (fails.length > 60) { console.log('  ... and ' + (fails.length - 60) + ' more'); }
process.exit(1);
