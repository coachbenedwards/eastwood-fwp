/**
 * Eastwood site sweep.
 *
 * Renders every page on every installed engine at every width and reports the
 * defects that cannot be seen by looking at the screen: a link that announces
 * itself as something the page does not say, an image with no name, a heading
 * order that skips, a repeated element id, a new tab left unprotected.
 *
 *   node qa/sweep.mjs                 the lot
 *   node qa/sweep.mjs --fast          chromium at one width, for a deploy gate
 *   node qa/sweep.mjs --gate          exit 1 on a new CERTAIN finding
 *   node qa/sweep.mjs --update-baseline
 *
 * Needs playwright. Chromium alone covers Chrome, Edge and Chrome on Android.
 * For Safari and Firefox:  npx playwright install webkit firefox
 * Engines that are not installed are skipped and named in the report rather
 * than failing the run, so a partial matrix never reads as a clean one.
 */
import { chromium, firefox, webkit } from 'playwright';
import { CATALOGUE, certaintyOf, adviceFor } from './catalogue.mjs';
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const BASE = process.env.QA_BASE || 'https://eastwoodfc.com';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
  + '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

const PAGES = ['/', '/news/', '/teams/', '/squad/', '/matches/', '/eastwood-tv/', '/academy/',
  '/tickets/', '/matchday/', '/hospitality/', '/thevenue/', '/pitch-hire/', '/sponsorship/',
  '/shop/', '/club-history/', '/contact/', '/safeguarding/', '/privacy-policy/', '/terms/'];

const ENGINES = [['chromium', chromium], ['webkit', webkit], ['firefox', firefox]];
const WIDTHS = [[390, 'iPhone'], [412, 'Android'], [820, 'iPad'],
                [1280, 'Laptop'], [1440, 'Desktop'], [1920, 'Wide']];

const fast = process.argv.includes('--fast');
const gate = process.argv.includes('--gate');
const updating = process.argv.includes('--update-baseline');
const widths  = fast ? [[1440, 'Desktop']] : WIDTHS;
const engines = fast ? [ENGINES[0]] : ENGINES;

/* Checks that read the same at any width. Run once per engine, not per width. */
function inspect() {
  const found = [];
  const add = (type, detail) => found.push({ type, detail });
  const text = el => (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim();
  const related = (a, b) => {
    const n = s => s.toLowerCase().replace(/[^a-z0-9]/g, '');
    return n(a) === n(b) || n(a).includes(n(b)) || n(b).includes(n(a));
  };

  document.querySelectorAll('a[title], a[aria-label]').forEach(a => {
    const t = text(a);
    if (!t) return;
    const title = (a.getAttribute('title') || '').trim();
    const aria = (a.getAttribute('aria-label') || '').trim();
    if (title && !related(title, t)) add('says-one-thing-announces-another', `"${t}" has title "${title}"`);
    if (aria && !related(aria, t)) add('says-one-thing-announces-another', `"${t}" has aria-label "${aria}"`);
  });

  document.querySelectorAll('a').forEach(a => {
    const href = a.getAttribute('href');
    const t = text(a);
    if (!href || href === '#') add('dead-link', `"${t || 'no text'}"`);
    if (a.getAttribute('target') === '_blank' && !/noopener|noreferrer/.test(a.getAttribute('rel') || ''))
      add('unprotected-new-tab', href || '');
    if (!t && !a.querySelector('img, svg') && !a.getAttribute('aria-label'))
      add('link-with-no-name', href || '');
  });

  document.querySelectorAll('img').forEach(i => {
    if (i.getAttribute('alt') === null) add('image-with-no-alt', (i.getAttribute('src') || '').slice(-54));
  });

  document.querySelectorAll('button').forEach(b => {
    if (!text(b) && !b.getAttribute('aria-label') && !b.querySelector('img, svg'))
      add('button-with-no-name', (b.className || '').toString().slice(0, 40));
  });

  const seen = new Set();
  document.querySelectorAll('[id]').forEach(e => {
    if (!e.id) return;
    if (seen.has(e.id)) add('repeated-id', `#${e.id}`); else seen.add(e.id);
  });

  const heads = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')]
    .filter(h => h.getBoundingClientRect().height > 0);
  const h1s = heads.filter(h => h.tagName === 'H1');
  if (!h1s.length) add('no-h1', 'nothing on the page is its heading');
  if (h1s.length > 1) add('more-than-one-h1', h1s.map(h => text(h).slice(0, 24)).join(' / '));
  let last = 0;
  heads.forEach(h => {
    const lv = +h.tagName[1];
    if (last && lv > last + 1) add('heading-order-skips', `h${last} to h${lv} at "${text(h).slice(0, 30)}"`);
    last = lv;
  });

  document.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(i => {
    const labelled = (i.id && document.querySelector(`label[for="${CSS.escape(i.id)}"]`))
      || i.getAttribute('aria-label') || i.closest('label') || i.getAttribute('placeholder');
    if (!labelled) add('field-with-no-label', `${i.tagName.toLowerCase()} ${i.name || ''}`.trim());
  });

  const desc = document.querySelector('meta[name="description"]');
  if (!desc || !(desc.content || '').trim()) add('no-meta-description', '');
  if (!document.title || document.title.length < 5) add('no-page-title', document.title || '');

  return found;
}

/* Checks that depend on the width. */
function inspectAtWidth() {
  const found = [];
  const doc = document.documentElement;
  const over = doc.scrollWidth - doc.clientWidth;
  if (over > 2) {
    let worst = null;
    document.querySelectorAll('*').forEach(e => {
      const b = e.getBoundingClientRect();
      if (b.right > doc.clientWidth + 2 && b.width > 40 && (!worst || b.right > worst.right))
        worst = { right: b.right, tag: e.tagName.toLowerCase(), cls: (e.className || '').toString().slice(0, 36) };
    });
    found.push({ type: 'page-scrolls-sideways',
      detail: `${over}px past the edge, widest is ${worst ? worst.tag + '.' + worst.cls : 'unknown'}` });
  }
  if (window.innerWidth <= 480) {
    document.querySelectorAll('a, button').forEach(e => {
      const b = e.getBoundingClientRect();
      if (b.width > 0 && b.height > 0 && (b.height < 24 || b.width < 24))
        found.push({ type: 'tap-target-too-small',
          detail: `${Math.round(b.width)}x${Math.round(b.height)} — "${(e.innerText || '').trim().slice(0, 22)}"` });
    });
  }
  return found;
}

const key = f => `${f.type}|${f.detail}`;

async function run() {
  const findings = [];     // { page, engine, width, type, detail }
  const failedRequests = [];
  const skipped = [];
  const started = Date.now();

  for (const [engineName, engine] of engines) {
    let browser;
    try {
      browser = await engine.launch();
    } catch (err) {
      skipped.push(engineName);
      console.log(`  ${engineName}: not installed, skipped  (npx playwright install ${engineName})`);
      continue;
    }
    const ctx = await browser.newContext({ userAgent: UA, locale: 'en-GB', viewport: { width: 1440, height: 1000 } });
    // Images do not change any of the checks above and the host rate-limits
    // them, which shows up as failures that are not really the site's.
    await ctx.route('**/*', r => (r.request().resourceType() === 'image' ? r.abort() : r.continue()));
    const page = await ctx.newPage();

    for (const path of PAGES) {
      const bad = [];
      const onResponse = r => { if (r.status() >= 400) bad.push(`${r.status()} ${r.url().replace(BASE, '')}`); };
      page.on('response', onResponse);
      try {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(BASE + path, { waitUntil: 'load', timeout: 60000 });
        await page.waitForTimeout(3000);
        for (const f of await page.evaluate(inspect))
          findings.push({ page: path, engine: engineName, width: null, ...f });
        for (const [w, wname] of widths) {
          await page.setViewportSize({ width: w, height: w < 500 ? 844 : 1000 });
          await page.waitForTimeout(700);
          for (const f of await page.evaluate(inspectAtWidth))
            findings.push({ page: path, engine: engineName, width: `${w} ${wname}`, ...f });
        }
      } catch (err) {
        findings.push({ page: path, engine: engineName, width: null,
          type: 'page-would-not-load', detail: err.message.slice(0, 80) });
      }
      page.off('response', onResponse);
      [...new Set(bad)].forEach(b => failedRequests.push({ page: path, engine: engineName, detail: b }));
      await page.waitForTimeout(1200);
    }
    await browser.close();
  }

  /* Triage. A finding on nearly every page lives in the shared header or
     footer, and reporting it per page buries the handful that do not. */
  const tally = new Map();
  findings.forEach(f => tally.set(key(f), (tally.get(key(f)) || 0) + 1));
  const perEngine = PAGES.length * engines.filter(e => !skipped.includes(e[0])).length;
  const sitewide = new Set([...tally].filter(([, n]) => n >= perEngine - 1).map(([k]) => k));

  const dress = f => {
    const a = adviceFor(f.type);
    return { ...f, certainty: certaintyOf(f.type), why: a.why, fix: a.fix, ask: a.ask };
  };

  const report = {
    ranAt: new Date().toISOString(),
    base: BASE,
    seconds: Math.round((Date.now() - started) / 1000),
    pages: PAGES.length,
    engines: engines.map(e => e[0]).filter(e => !skipped.includes(e)),
    enginesSkipped: skipped,
    widths: widths.map(w => `${w[0]} ${w[1]}`),
    sitewide: [...sitewide]
      .map(k => dress({ type: k.split('|')[0], detail: k.split('|').slice(1).join('|') })),
    perPage: PAGES.map(p => ({
      page: p,
      findings: findings.filter(f => f.page === p && !sitewide.has(key(f)))
        .map(f => dress({ type: f.type, detail: f.detail, engine: f.engine, width: f.width })),
    })).filter(x => x.findings.length),
    failedRequests,
  };

  const outDir = join(HERE, 'reports');
  if (!existsSync(outDir)) mkdirSync(outDir, { recursive: true });
  writeFileSync(join(outDir, 'latest.json'), JSON.stringify(report, null, 1));

  console.log('');
  console.log(`  ${report.pages} pages · ${report.engines.join(', ') || 'no engines'}`
    + `${skipped.length ? ` (skipped ${skipped.join(', ')})` : ''} · ${report.seconds}s`);
  const everything = [
    ...report.sitewide.map(f => ({ ...f, where: 'every page' })),
    ...report.perPage.flatMap(p => p.findings.map(f => ({ ...f, where: p.page }))),
  ];
  const certain = everything.filter(f => f.certainty === 'certain');
  const check   = everything.filter(f => f.certainty !== 'certain');

  const byType = list => {
    const m = new Map();
    list.forEach(f => {
      if (!m.has(f.type)) m.set(f.type, []);
      m.get(f.type).push(f);
    });
    return m;
  };

  console.log('');
  if (certain.length) {
    console.log(`  WRONG — ${certain.length} finding${certain.length === 1 ? '' : 's'}, no judgement needed`);
    console.log('  ' + '-'.repeat(70));
    for (const [type, list] of byType(certain)) {
      const a = adviceFor(type);
      console.log(`\n  ${type}  (${list.length})`);
      console.log(`    why   ${a.why}`);
      console.log(`    fix   ${a.fix}`);
      list.slice(0, 6).forEach(f => console.log(`          · ${f.detail}  [${f.where}]`));
      if (list.length > 6) console.log(`          · and ${list.length - 6} more`);
    }
    console.log('');
  }

  if (check.length) {
    console.log(`  CHECK FIRST — ${check.length} finding${check.length === 1 ? '' : 's'} that may be deliberate`);
    console.log('  ' + '-'.repeat(70));
    for (const [type, list] of byType(check)) {
      const a = adviceFor(type);
      console.log(`\n  ${type}  (${list.length})`);
      console.log(`    why   ${a.why}`);
      if (a.ask) console.log(`    ask   ${a.ask}`);
      console.log(`    fix   ${a.fix}`);
      list.slice(0, 6).forEach(f => console.log(`          · ${f.detail || '(no detail)'}  [${f.where}]`));
      if (list.length > 6) console.log(`          · and ${list.length - 6} more`);
    }
    console.log('');
  }

  if (!everything.length) console.log('  Nothing found.\n');

  /* The gate compares against what has already been seen and accepted, so it
     only ever fails on something new. */
  const baselinePath = join(HERE, 'baseline.json');
  const current = [...new Set([
    ...report.sitewide.map(f => `${f.type}|${f.detail}`),
    ...report.perPage.flatMap(p => p.findings.map(f => `${p.page}|${f.type}|${f.detail}`)),
  ])].sort();
  const certainKeys = new Set([
    ...report.sitewide.filter(f => f.certainty === 'certain').map(f => `${f.type}|${f.detail}`),
    ...report.perPage.flatMap(p => p.findings.filter(f => f.certainty === 'certain')
      .map(f => `${p.page}|${f.type}|${f.detail}`)),
  ]);

  if (updating) {
    writeFileSync(baselinePath, JSON.stringify(current, null, 1));
    console.log(`  Baseline updated: ${current.length} accepted findings.\n`);
    return 0;
  }
  if (gate) {
    const known = existsSync(baselinePath) ? JSON.parse(readFileSync(baselinePath, 'utf8')) : [];
    const fresh = current.filter(c => !known.includes(c));
    // Only something definitely wrong stops a release. A check-first finding
    // is reported and waits for a person; it never blocks on its own.
    const blocking = fresh.filter(f => certainKeys.has(f));
    const waiting = fresh.filter(f => !certainKeys.has(f));
    if (waiting.length) {
      console.log(`  ${waiting.length} new finding${waiting.length === 1 ? '' : 's'} to look at, not blocking:`);
      waiting.forEach(f => console.log(`    ${f}`));
      console.log('');
    }
    if (blocking.length) {
      console.log(`  GATE FAILED — ${blocking.length} new and definitely wrong:`);
      blocking.forEach(f => console.log(`    ${f}`));
      console.log('');
      return 1;
    }
    console.log('  Gate passed: nothing new that is definitely wrong.\n');
  }
  return 0;
}

run().then(code => process.exit(code)).catch(err => { console.error(err); process.exit(2); });
