// Browser tests for the LANDLORDS map page.
// Usage: node map.test.js <base-url> <data-dir> [screenshot-dir]
// Serve public/ with the files from make-data.php in public/data first.
const { chromium } = require('playwright');
const zlib = require('zlib');
const fs = require('fs');
const os = require('os');

const BASE = process.argv[2];
const EXPECT = JSON.parse(fs.readFileSync(process.argv[3] + '/expected.json', 'utf8'));
const SHOTS = process.argv[4] || fs.mkdtempSync(os.tmpdir() + '/landlords-shots-');
let failures = 0, passes = 0;
const check = (ok, msg) => { if (ok) passes++; else { failures++; console.log('  FAIL: ' + msg); } };

// A plain 256px tile so screenshots show something map-like without internet access.
function tilePng() {
  const w = 256, h = 256, raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) {
    raw[y * (w * 3 + 1)] = 0;
    for (let x = 0; x < w; x++) {
      const o = y * (w * 3 + 1) + 1 + x * 3;
      const road = (x % 128 < 6) || (y % 96 < 5);
      const c = road ? [255, 255, 255] : [232, 236, 226];
      raw[o] = c[0]; raw[o + 1] = c[1]; raw[o + 2] = c[2];
    }
  }
  const crc = (buf) => { let c, t = []; for (let n = 0; n < 256; n++) { c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } let r = 0xffffffff; for (const b of buf) r = t[(r ^ b) & 0xff] ^ (r >>> 8); return (r ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const TILE = tilePng();

async function page(browser, opts = {}) {
  const ctx = await browser.newContext({ viewport: opts.viewport || { width: 1440, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
  const p = await ctx.newPage();
  p.errors = [];
  p.on('pageerror', e => p.errors.push(String(e)));
  p.on('console', m => { if (m.type() === 'error') p.errors.push(m.text()); });
  p.offsite = [];
  await p.route('**/*', route => {
    const url = route.request().url();
    if (url.includes('tile.openstreetmap.org')) return route.fulfill({ body: TILE, contentType: 'image/png' });
    if (!url.startsWith(BASE)) { p.offsite.push(url); return route.abort(); }
    return route.continue();
  });
  return p;
}
const heading = p => p.$eval('#results-heading', e => e.textContent.trim());
const ready = p => p.waitForFunction(() => !/Loading/.test(document.getElementById('fresh').textContent) && document.querySelectorAll('.item').length > 0);
const count = (p, layer) => p.$eval(`[data-count="${layer}"]`, e => Number(e.textContent));

(async () => {
  const browser = await chromium.launch();

  console.log('- Desktop load: layers, counts, list');
  let p = await page(browser);
  await p.goto(BASE + '/');
  await ready(p);
  await p.waitForTimeout(600);
  for (const [layer, n] of Object.entries(EXPECT.byLayer)) {
    check(await count(p, layer) === n, `layer ${layer} count ${await count(p, layer)} = ${n}`);
  }
  check(/Council data checked \d+ \w+ \d{4} at \d\d:\d\d/.test(await p.$eval('#fresh', e => e.textContent)), 'freshness line');
  check(/address/.test(await heading(p)), 'list heading: ' + await heading(p));
  check(await p.$$eval('.leaflet-interactive', els => els.length) >= 3, 'student outlines drawn');
  check(!(await p.$('#signup')).isVisible || !(await p.isVisible('#signup')), 'sign-up hidden while PHP says closed or is absent');
  await p.screenshot({ path: SHOTS + '/desktop.png' });

  console.log('- Layer toggles');
  const total = h => Number(h.replace(/,/g, '').match(/(\d+) address/)[1]);
  const before = total(await heading(p));
  await p.click('.layer-option:has(input[value="selective"])');
  await p.waitForTimeout(300);
  const after = total(await heading(p));
  check(after < before, `hiding selective licences shortens the list (${before} -> ${after})`);
  check(await p.$$eval('.item .swatch.layer-selective', els => els.length) === 0, 'no selective items listed');
  await p.click('.layer-option:has(input[value="selective"])');

  console.log('- Search');
  await p.fill('#q', 'westcliffe');
  await p.waitForTimeout(400);
  const items = await p.$$eval('.item', els => els.map(e => e.textContent));
  check(items.length === 1 && /Westcliffe Court, 45-47 Lenton Road/.test(items[0]) && /6 HMO licences \(additional\)/.test(items[0]),
    'block of flats is one entry with six licences: ' + JSON.stringify(items));

  console.log('- A block of flats: one pin, one popup listing every flat');
  await p.click('.item');
  await p.waitForSelector('.leaflet-popup .card');
  const card = await p.$eval('.leaflet-popup .card', e => e.innerText);
  check(/6 HMO licences \(additional\) at this address/.test(card), 'popup heading: ' + card.slice(0, 120));
  check(await p.$$eval('.leaflet-popup .unit', els => els.length) === 6, 'six flats listed');
  check(/^\?p=[0-9a-f]{10}$/.test(await p.evaluate(() => location.search)), 'address bar holds the link');
  await p.click('.leaflet-popup .unit:nth-child(3) summary');
  await p.waitForTimeout(200);
  check(await p.evaluate(() => location.search) === '?p=' + EXPECT.blockIds[2] || EXPECT.blockIds.includes((await p.evaluate(() => location.search)).slice(3)),
    'opening a flat links to that flat');
  check(/Issued[\s\S]*Expires[\s\S]*Reference/.test(await p.$eval('.leaflet-popup .unit:nth-child(3)', e => e.innerText)), 'flat details shown');
  await p.screenshot({ path: SHOTS + '/block-popup.png' });
  await p.fill('#q', '');

  console.log('- Deep links');
  p = await page(browser);
  await p.goto(BASE + '/?p=' + EXPECT.blockIds[4]);
  await ready(p);
  await p.waitForSelector('.leaflet-popup .unit.is-active details[open]');
  check(true, 'deep link to a flat opens its block with that flat expanded');
  p = await page(browser);
  await p.goto(BASE + '/?p=' + EXPECT.newId);
  await ready(p);
  await p.waitForSelector('.leaflet-popup .card');
  const newCard = await p.$eval('.leaflet-popup .card', e => e.innerText);
  check(/16 Barrack Lane/.test(newCard) && /New this week/.test(newCard) && /HMO licence \(mandatory\)/.test(newCard), 'new licence popup: ' + newCard.slice(0, 160));
  check(await p.$eval('.leaflet-popup .btn-council', e => e.href) === 'https://www.nottinghamcity.gov.uk/hmo', 'links to the council’s scheme page');
  p = await page(browser);
  await p.goto(BASE + '/?p=' + EXPECT.studentId);
  await ready(p);
  await p.waitForSelector('.leaflet-popup .card');
  const st = await p.$eval('.leaflet-popup .card', e => e.innerText);
  check(/Purpose-built student accommodation/.test(st) && /Bed spaces/.test(st), 'student popup: ' + st.slice(0, 160));
  const nimby = await p.$$eval('.leaflet-popup a', as => as.map(a => a.href).filter(h => h.includes('nimby.parknews.co.uk/?app=')));
  check(nimby.length === 1, 'student scheme links to its planning application on NIMBY');
  await p.screenshot({ path: SHOTS + '/student-popup.png' });
  p = await page(browser);
  await p.goto(BASE + '/?p=ffffffffff');
  await ready(p);
  check(await p.isVisible('#notice'), 'unknown link explained');

  console.log('- Mobile');
  p = await page(browser, { viewport: { width: 390, height: 844 } });
  await p.goto(BASE + '/');
  await ready(p);
  await p.waitForTimeout(500);
  check(await p.$eval('#map', e => e.getBoundingClientRect().height) > 300, 'map visible on a phone');
  await p.screenshot({ path: SHOTS + '/mobile.png' });
  await p.click('.item');
  await p.waitForSelector('.leaflet-popup .card');
  check(true, 'tapping a list item opens its popup');

  console.log('- No errors, nothing from other servers');
  for (const q of [p]) {
    check(q.errors.length === 0, 'console errors: ' + q.errors.join(' | '));
    check(q.offsite.length === 0, 'requests to other servers: ' + q.offsite.join(', '));
  }

  await browser.close();
  console.log(`\n${passes} checks passed, ${failures} failed. Screenshots in ${SHOTS}`);
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
