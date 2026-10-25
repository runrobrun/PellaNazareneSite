import puppeteer from 'puppeteer-core';
import fs from 'fs';
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
// Runs the axe accessibility engine in a real browser against the site, at desktop and phone width, with the
// mobile menu open, and on the 404 and search pages. Exit code 1 if anything fails WCAG 2.2 A/AA or best practice.
const BASE = process.env.BASE || 'http://pn-wp:8080';
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
const axeSrc = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
// Keyboard focus must be visible and distinguishable (WCAG 2.4.7, 1.4.11): tab through the page and, for every
// element that takes focus, require a visible outline (not 'none') with at least 3:1 contrast against what is
// behind it. axe cannot see this because it depends on the CSS state while an element has focus.
function luminance([r, g, b]) {
  const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}
function contrast(a, b) { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); }
const rgbOf = (s) => { const m = (s || '').match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/); return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null; };

async function createTestPage(browser, contacted = null) {
  const page = await browser.newPage();
  await page.setRequestInterception(true);
  page.on('request', (r) => {
    if (/googletagmanager\.com|google-analytics\.com/.test(r.url())) {
      if (contacted) contacted.push(r.url());
      r.abort();
    } else r.continue();
  });
  return page;
}

async function checkFocus(page, name) {
  let problems = 0, stops = 0;
  await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
  for (let i = 0; i < 60; i++) {
    await page.keyboard.press('Tab');
    const info = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body || el === document.documentElement) return null;
      const cs = getComputedStyle(el);
      let node = el.parentElement, bg = 'rgb(255, 255, 255)';
      while (node) { const c = getComputedStyle(node).backgroundColor; const m = c.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/); if (m && (m[4] === undefined || parseFloat(m[4]) > 0)) { bg = c; break; } node = node.parentElement; }
      const r = el.getBoundingClientRect();
      return { label: (el.getAttribute('aria-label') || el.textContent || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 28), tag: el.tagName.toLowerCase(),
        style: cs.outlineStyle, width: parseFloat(cs.outlineWidth), color: cs.outlineColor, bg, shadow: cs.boxShadow !== 'none', visible: r.width > 0 && r.height > 0 };
    });
    if (!info) break;
    if (!info.visible) continue;
    stops++;
    const ring = rgbOf(info.color), behind = rgbOf(info.bg);
    const hasOutline = info.style !== 'none' && info.width > 0 && ring && ring[3] > 0;
    const ratio = hasOutline && behind ? contrast(ring, behind) : 0;
    const ok = hasOutline && ratio >= 3 && (info.tag !== 'a' || info.width >= 2);
    if (!ok) { problems++; console.log(`   [focus] ${info.label} <${info.tag}>: outline ${info.style} ${info.width}px ${info.color} on ${info.bg} = ${ratio.toFixed(2)}:1 (need a visible ring, 3:1 or better, and 2px+ on links)`); }
  }
  console.log(`== ${name}: keyboard focus on ${stops} stops, ${problems} without a clearly visible ring`);
  return problems;
}

// Google Analytics in a real browser: an ordinary visitor gets the tag; a visitor whose browser says Global Privacy
// Control or Do Not Track never contacts Google. Every request to Google is stopped here, and the test site uses a
// made-up measurement ID, so a test can never record a visit in the church's real account.
async function checkAnalytics(browser) {
  let problems = 0;
  const cases = [
    ['an ordinary visitor', null, true],
    ['Global Privacy Control switched on', () => Object.defineProperty(navigator, 'globalPrivacyControl', { value: true }), false],
    ['Do Not Track switched on', () => Object.defineProperty(navigator, 'doNotTrack', { value: '1' }), false],
  ];
  for (const [who, setup, expectTag] of cases) {
    const contacted = [];
    const page = await createTestPage(browser, contacted);
    if (setup) await page.evaluateOnNewDocument(setup);
    await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
    const hasTag = (await page.$('script[src*="googletagmanager.com/gtag/js?id=G-TEST000000"]')) !== null;
    const ok = hasTag === expectTag && (contacted.length > 0) === expectTag;
    console.log(`== analytics: ${who}: ${expectTag ? 'tag added, Google asked' : 'no tag, Google never contacted'}${ok ? '' : '  <-- WRONG'}`);
    if (!ok) { problems++; console.log(`   [analytics] ${who}: tag in page = ${hasTag}, requests to Google = ${contacted.length}`); }
    await page.close();
  }
  return problems;
}

// A real browser fills in and sends each form, the way a visitor would: the page must note when it loaded, wait a few
// seconds like a person does, and show the thank-you message.
async function checkForms(browser) {
  let problems = 0;
  const cases = [
    ['/contact/', [['#ff_%ID%_name', 'Test Visitor'], ['#ff_%ID%_email', 'visitor@example.test'], ['#ff_%ID%_message', 'Hello from the browser test.']], 'We got your message'],
    ['/prayer/', [['#ff_%ID%_request', 'Please pray for our town.']], 'Your request has gone to our pastors'],
  ];
  for (const [path, fields, thanks] of cases) {
    const page = await createTestPage(browser);
    await page.setViewport({ width: 1000, height: 900 });
    await page.goto(BASE + path, { waitUntil: 'networkidle0' });
    const formId = await page.$eval('form.frm-fluent-form', (f) => f.getAttribute('data-form_id'));
    for (const [selector, text] of fields) await page.type(selector.replace('%ID%', formId), text);
    await new Promise((r) => setTimeout(r, 3500));
    const loaded = page.evaluate(() => new Promise((resolve) => {
      const formElement = document.querySelector('form.frm-fluent-form');
      formElement.addEventListener('submit', () => resolve(formElement.querySelector('input[name="pn_loaded"]').value), { once: true });
    }));
    await page.click('form.frm-fluent-form button[type="submit"]');
    const elapsed = await loaded;
    if (!/^\d+$/.test(elapsed) || Number(elapsed) < 3000) { console.log(`   [forms] ${path}: did not record enough elapsed page-load time on submit (got '${elapsed}')`); problems++; }
    try {
      await page.waitForFunction((t) => document.body.innerText.includes(t), { timeout: 15000 }, thanks);
      console.log(`== forms: ${path}: filled in, sent, and saw "${thanks}"`);
    } catch (e) {
      const shown = await page.evaluate(() => (document.querySelector('.ff-errors-in-stack, .ff-el-is-error, .error') || {}).innerText || '(no error text)');
      console.log(`   [forms] ${path}: no thank-you message after sending; page says: ${shown}`); problems++;
    }
    await page.close();
  }
  return problems;
}

// Staff edit these pages in the block editor. Open each one as an administrator and require that the editor
// accepts every block as valid (no "this block contains unexpected content" recovery prompts).
async function checkEditor(browser) {
  const page = await createTestPage(browser);
  await page.setViewport({ width: 1400, height: 900 });
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'networkidle0' });
  await page.type('#user_login', 'boss');
  await page.type('#user_pass', 'x-Pass-1!');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#wp-submit')]);
  await page.goto(BASE + '/wp-admin/edit.php?post_type=page', { waitUntil: 'networkidle0' });
  const found = await page.evaluate(() => [...document.querySelectorAll('a.row-title')].map((a) => [a.textContent.trim(), (a.href.match(/post=(\d+)/) || [])[1]]));
  // The Home page's title is the h1 on the home page.
  const wanted = ['A church in Pella, Iowa', 'New Here', 'About Us', 'Kids', 'Giving', 'Contact', 'Resources', 'Events', 'Small Groups', 'Blog', 'Prayer Request', 'Privacy'];
  let problems = 0;
  for (const title of wanted) {
    const hit = found.find(([t]) => t === title);
    if (!hit) { console.log(`   [editor] page '${title}' not found in the admin list`); problems++; continue; }
    await page.goto(`${BASE}/wp-admin/post.php?post=${hit[1]}&action=edit`, { waitUntil: 'networkidle0' });
    try {
      await page.waitForFunction(() => window.wp && wp.data && wp.data.select('core/block-editor') && wp.data.select('core/block-editor').getBlocks().length > 0, { timeout: 30000 });
    } catch (e) { console.log(`   [editor] ${title}: the editor did not load any blocks`); problems++; continue; }
    const r = await page.evaluate(() => {
      const bad = []; let n = 0;
      const walk = (blocks) => blocks.forEach((b) => { n++; if (!b.isValid) bad.push(b.name); walk(b.innerBlocks); });
      walk(wp.data.select('core/block-editor').getBlocks());
      return { n, bad };
    });
    console.log(`== editor: ${title}: ${r.n} blocks, ${r.bad.length} invalid${r.bad.length ? ' (' + [...new Set(r.bad)].join(', ') + ')' : ''}`);
    problems += r.bad.length;
  }
  await page.close();
  return problems;
}

(async () => {
  const browser = await puppeteer.launch({ executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium-browser', args: ['--no-sandbox', '--disable-gpu'] });
  let total = 0;
  const scenarios = [
    ['home, desktop 1280', '/', 1280, 900, false],
    ['home, phone 390', '/', 390, 800, false],
    ['home, phone 390, MENU OPEN', '/', 390, 800, true],
    ['brand fixture page, desktop', '/brand-check/', 1280, 900, false],
    ['brand fixture page, phone', '/brand-check/', 390, 800, false],
    ...['new-here', 'about-us', 'kids', 'giving', 'contact', 'resources', 'events', 'small-groups', 'blog', 'welcome-to-our-new-website', 'prayer', 'privacy'].flatMap((s) => [
      [`${s}, desktop 1280`, `/${s}/`, 1280, 900, false],
      [`${s}, phone 390`, `/${s}/`, 390, 800, false],
    ]),
    ['404 page, desktop', '/does-not-exist/', 1280, 900, false],
    ['search results, desktop', '/?s=love', 1280, 900, false],
  ];
  for (const [name, path, w, h, openMenu] of scenarios) {
    const page = await createTestPage(browser);
    await page.setViewport({ width: w, height: h });
    await page.goto(BASE + path, { waitUntil: 'networkidle0' });
    if (openMenu) {
      if (!(await page.$('.wp-block-navigation__responsive-container-open'))) console.log('   [menu] open button missing; header is: ' + (await page.evaluate(() => (document.querySelector('header') || document.body).outerHTML.replace(/\s+/g, ' ').slice(0, 1200))));
      await page.click('.wp-block-navigation__responsive-container-open');
      await page.waitForSelector('.wp-block-navigation__responsive-container.is-menu-open', { visible: true, timeout: 5000 });
      console.log('   (menu overlay opened: true)');
    }
    if (process.env.BREAK_FOCUS) await page.addStyleTag({ content: 'a:focus-visible{outline-color:#fff !important}' });
    await page.evaluate(axeSrc);
    const res = await page.evaluate(async (tags) => await axe.run(document, { runOnly: { type: 'tag', values: tags } }), TAGS);
    console.log(`== ${name}: ${res.passes.length} rules passed, ${res.violations.length} violations, ${res.incomplete.length} need manual review`);
    for (const v of res.violations) {
      total += v.nodes.length;
      console.log(`   [${v.impact}] ${v.id}: ${v.help} (${v.nodes.length} element${v.nodes.length > 1 ? 's' : ''})`);
      for (const n of v.nodes.slice(0, 2)) console.log(`       ${n.target.join(' ')}  ${(n.failureSummary || '').split('\n')[1] || ''}`.slice(0, 190));
    }
    // Focus walk last: WordPress closes the mobile menu when focus leaves it, so scan with the menu open first.
    total += await checkFocus(page, `${name}: focus`);
    await page.close();
  }
  total += await checkForms(browser);
  total += await checkAnalytics(browser);
  total += await checkEditor(browser);
  await browser.close();
  console.log(`\nTOTAL violating elements: ${total}`);
  process.exit(total ? 1 : 0);
})().catch(e => { console.error('RUN FAILED:', e.message); process.exit(2); });
