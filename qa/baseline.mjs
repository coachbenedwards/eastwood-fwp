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
 * Runs wherever Playwright is installed. Each element is keyed by its
 * position in the tree rather than by a class, because the classes are
 * exactly what changes when a page moves into the builder.
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
const DIR = path.join(path.dirname(new URL(import.meta.url).pathname), 'baseline');
const SLUG = URL_PATH.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home';

if (!has('save') && !has('diff')) {
	console.error('Give it --save or --diff.');
	process.exit(2);
}

/* Runs in the page. Keyed by tree position: a class is the first thing a
   conversion changes, so keying on one would compare nothing to nothing. */
const SNAPSHOT = () => {
	const out = [];
	const key = (el) => {
		const parts = [];
		let n = el;
		while (n && n.nodeType === 1 && n !== document.body && parts.length < 12) {
			const p = n.parentElement;
			const i = p ? [...p.children].indexOf(n) + 1 : 1;
			parts.unshift(n.tagName.toLowerCase() + ':' + i);
			n = p;
		}
		return parts.join('>');
	};
	for (const el of document.body.querySelectorAll('*')) {
		const r = el.getBoundingClientRect();
		if (r.width < 1 || r.height < 1) { continue; }
		const own = [...el.childNodes]
			.filter((c) => c.nodeType === 3)
			.map((c) => c.textContent.trim())
			.join(' ')
			.replace(/\s+/g, ' ')
			.trim();
		out.push({
			k: key(el),
			t: el.tagName.toLowerCase(),
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
		shots[width] = await page.evaluate(SNAPSHOT);
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
	for (const o of a.nodes) {
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
}

if (!fails.length) {
	console.log('PASS  ' + URL_PATH + ' matches the before at ' + WIDTHS.join(', ') + '.');
	process.exit(0);
}

console.log('FAIL  ' + URL_PATH + ' — ' + fails.length + ' difference(s)');
for (const [w, msg] of fails.slice(0, 60)) { console.log('  ' + w + 'px  ' + msg); }
if (fails.length > 60) { console.log('  ... and ' + (fails.length - 60) + ' more'); }
process.exit(1);
