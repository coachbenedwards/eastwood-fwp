// Newitts robot: puts the website's shirt orders into the club's Newitts basket.
//
//   node robot.mjs --login   opens a window on newitts.com; sign in yourself, then close it.
//   node robot.mjs           the daily run (14:40 Mon-Fri from launchd).
//   node robot.mjs --try     a run that reads the batch and Newitts' stock but adds nothing.
//
// The robot never sees a password or a card. It uses the Newitts sign-in kept
// in its own browser profile (~/.newitts-robot/profile), made by --login.
//
// Every run ends by telling eastwoodfc.com what happened. Anything other than
// a clean result makes the site email the list to orders@ for a person.

import { chromium } from 'playwright';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const HOME = path.join(os.homedir(), '.newitts-robot');
const PROFILE = path.join(HOME, 'profile');
const RUNS = path.join(HOME, 'runs');
const CONF = JSON.parse(fs.readFileSync(path.join(HOME, 'config.json'), 'utf8')); // { "site": "https://eastwoodfc.com", "key": "..." }
const NW = 'https://www.newitts.com';
const args = new Set(process.argv.slice(2));
fs.mkdirSync(RUNS, { recursive: true });

const log = (...a) => { const l = new Date().toISOString() + ' ' + a.join(' '); console.log(l); fs.appendFileSync(path.join(RUNS, 'robot.log'), l + '\n'); };

async function site(route, body) {
  const r = await fetch(CONF.site.replace(/\/$/, '') + '/wp-json/eastwood/v1/newitts/' + route, {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-EW-Robot': CONF.key }, body: JSON.stringify(body || {}),
  });
  if (!r.ok) throw new Error('eastwoodfc.com said ' + r.status + ' to ' + route);
  return r.json();
}

class Stop extends Error {}

async function declineCookies(p) {
  const b = p.locator('#cookiescript_reject');
  if (await b.isVisible().catch(() => false)) { await b.click().catch(() => {}); await p.waitForTimeout(500); }
}

async function guardBots(p) {
  const t = (await p.content()).toLowerCase();
  if (/captcha|cf-challenge|are you a robot|verify you are human|access denied/.test(t) && !/recaptcha\/api\.js\?render=explicit/.test(t)) {
    throw new Stop('Newitts showed a robot check. The robot does not get past those.');
  }
}

// Stock and price for one item code, from the product page's Availability table.
async function stockOf(p, line) {
  await p.goto(line.url + '?sku=' + line.code, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await p.waitForTimeout(2500);
  await guardBots(p);
  await declineCookies(p);
  const cells = await p.evaluate(code => {
    const r = [...document.querySelectorAll('tr[itemprop=hasVariant]')].find(r => (r.querySelector('td') || {}).innerText?.trim().startsWith(code));
    return r ? [...r.querySelectorAll('td')].map(t => t.innerText.trim()) : null;
  }, line.code);
  if (!cells) throw new Stop(line.code + ' is no longer on ' + line.url);
  const [, avail, colour, size, price] = cells;
  if (colour !== line.colour || size !== line.size) throw new Stop(line.code + ' is now ' + colour + ' ' + size + ', not ' + line.colour + ' ' + line.size);
  return { stock: parseInt(avail, 10) || 0, price: parseFloat(String(price).replace(/[^\d.]/g, '')) || 0 };
}

async function basketLines(p) {
  await p.goto(NW + '/basket', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await p.waitForTimeout(2500);
  await guardBots(p);
  await declineCookies(p);
  const text = await p.locator('main').innerText();
  const codes = [...text.matchAll(/Item Code:\s*\n?\s*(IT\d+)/g)].map(m => m[1]);
  const money = k => { const m = text.match(new RegExp(k + '\\s*£([\\d,.]+)')); return m ? parseFloat(m[1].replace(',', '')) : null; };
  return { codes, items: money('Item total'), total: money('Total'), text };
}

async function login() {
  const ctx = await chromium.launchPersistentContext(PROFILE, { headless: false, viewport: { width: 1280, height: 860 } });
  const p = ctx.pages()[0] || await ctx.newPage();
  await p.goto(NW + '/account');
  console.log('Sign in to Newitts in the window, then close the window.');
  await new Promise(r => ctx.on('close', r));
}

async function run() {
  const batch = await site('batch');
  const lines = batch.lines || [];
  const qty = lines.reduce((n, l) => n + l.qty, 0);
  log('batch', batch.id, 'mode', batch.mode, 'shirts', qty);
  if (!qty) return;
  const report = { id: batch.id, stock: {} };

  const ctx = await chromium.launchPersistentContext(PROFILE, { headless: !args.has('--show'), viewport: { width: 1400, height: 900 } });
  const p = ctx.pages()[0] || await ctx.newPage();
  // Newitts' pop-ups: the cookie banner (declined) and the 5%-off sign-up (no thanks).
  await p.addLocatorHandler(p.locator('#cookiescript_reject'), async b => { await b.click().catch(() => {}); });
  await p.addLocatorHandler(p.getByText("No thanks, I'll pay full price"), async b => { await b.click().catch(() => {}); });
  try {
    if (qty > batch.cap) throw new Stop(qty + ' shirts is over the daily cap of ' + batch.cap);

    // 1. Newitts has every shirt, at a sane price.
    for (const l of lines) {
      const s = await stockOf(p, l);
      report.stock[l.code] = s.stock;
      if (s.stock < l.qty) throw new Stop(l.code + ' (' + l.colour + ' ' + l.size + '): Newitts have ' + s.stock + ', we need ' + l.qty);
      if (s.price > batch.max_each) throw new Stop(l.code + ' costs £' + s.price + ', over the £' + batch.max_each + ' limit');
      if (l.qty > 9) throw new Stop(l.code + ': more than 9 of one size; order by hand');
    }
    if (args.has('--try')) { log('try run: stock fine', JSON.stringify(report.stock)); return; }

    // 2. Signed in, and the basket starts empty, so nothing else rides along.
    await p.goto(NW + '/account', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await p.waitForTimeout(2000);
    await guardBots(p);
    if (/login|sign-?in/i.test(p.url()) || await p.locator('input[type=password]').count()) {
      throw new Stop('the robot is signed out of Newitts. Run "node robot.mjs --login" on the Mac and sign in.');
    }
    const before = await basketLines(p);
    if (before.codes.length) throw new Stop('the Newitts basket already had ' + before.codes.join(', ') + ' in it, so the robot left it alone');

    // 3. Add each shirt.
    for (const l of lines) {
      await p.goto(l.url + '?sku=' + l.code, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await p.waitForTimeout(2500);
      await declineCookies(p);
      const sz = (await p.locator('#dropdownAllSize').innerText()).trim();
      const co = (await p.locator('#dropdownAllColour').innerText()).trim();
      if (sz !== l.size || co !== l.colour) throw new Stop(l.code + ' opened as ' + co + ' ' + sz);
      if (l.qty > 1) {
        await p.locator('#dropdownAllQuantity').click();
        await p.locator('label', { has: p.locator(`input[name=quantity][value="${l.qty}"]`) }).click();
      }
      const added = p.waitForResponse(r => r.url().endsWith('/basket/add') && r.request().method() === 'POST', { timeout: 20000 });
      await p.locator('#addToBasketButton').click();
      const r = await added;
      const sent = JSON.parse(r.request().postData() || '{}');
      if (!r.ok() || sent.itemSku !== l.code || Number(sent.quantity) !== l.qty) throw new Stop('adding ' + l.code + ' failed (' + r.status() + ')');
      log('added', l.qty, 'x', l.code);
    }

    // 4. The basket holds exactly what we asked for. Express delivery.
    const after = await basketLines(p);
    const want = lines.map(l => l.code).sort().join(',');
    if ([...new Set(after.codes)].sort().join(',') !== want) throw new Stop('the basket shows ' + after.codes.join(', ') + ', expected ' + want);
    const express = () => p.evaluate(() => {
      const i = [...document.querySelectorAll('input[name=delivery]')].find(i => /Express Delivery/.test(i.closest('.card-body, .card, div.row')?.innerText || ''));
      if (i && !i.checked) i.click();
      return !!i;
    });
    if (!await express()) throw new Stop('could not find Express delivery in the basket');
    await p.waitForTimeout(2500);
    const picked = await p.evaluate(() => [...document.querySelectorAll('input[name=delivery]')].some(i => i.checked && /Express Delivery/.test(i.closest('.card-body, .card, div.row')?.innerText || '')));
    if (!picked) throw new Stop('could not pick Express delivery');
    const fin = { ...(await (async () => { const t = await p.locator('main').innerText(); const m = k => { const x = t.match(new RegExp(k + '\\s*£([\\d,.]+)')); return x ? parseFloat(x[1].replace(',', '')) : null; }; return { items: m('Item total'), total: m('Total') }; })()) };
    report.total = fin.total != null ? '£' + fin.total.toFixed(2) : '';
    if (fin.items != null && fin.items > qty * batch.max_each) throw new Stop('basket items come to £' + fin.items + ', over the limit');
    await p.screenshot({ path: path.join(RUNS, batch.id + '-basket.png'), fullPage: true });

    // 5. Paying. Not switched on until the checkout pages have been walked
    //    through once with Ben watching; until then a person presses Pay.
    report.state = 'basket';
    report.note = batch.mode === 'live' ? 'Live checkout is not recorded yet, so a person needs to press Pay.' : '';
  } catch (e) {
    report.state = 'failed';
    report.note = e instanceof Stop ? e.message : 'robot error: ' + (e.message || e).toString().split('\n')[0];
    await p.screenshot({ path: path.join(RUNS, batch.id + '-failed.png') }).catch(() => {});
  } finally {
    await ctx.close();
  }
  log('result', report.state, report.total || '', report.note || '');
  await site('result', report);
}

if (args.has('--login')) await login();
else await run().catch(async e => {
  log('could not finish:', e.message);
  process.exitCode = 1;
});
