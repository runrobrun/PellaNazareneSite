import puppeteer from 'puppeteer-core';
import fs from 'fs';
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
// Runs the axe accessibility engine in a real browser against the site, at desktop and phone width, with the
// mobile menu open, and on the 404 and search pages. Exit code 1 if anything fails WCAG 2.2 A/AA or best practice.
const BASE = process.env.BASE || 'http://pn-wp:8080';
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
const axeSrc = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
(async () => {
  const browser = await puppeteer.launch({ executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium-browser', args: ['--no-sandbox', '--disable-gpu'] });
  let total = 0;
  const scenarios = [
    ['home, desktop 1280', '/', 1280, 900, false],
    ['home, phone 390', '/', 390, 800, false],
    ['home, phone 390, MENU OPEN', '/', 390, 800, true],
    ['404 page, desktop', '/does-not-exist/', 1280, 900, false],
    ['search results, desktop', '/?s=love', 1280, 900, false],
  ];
  for (const [name, path, w, h, openMenu] of scenarios) {
    const page = await browser.newPage();
    await page.setViewport({ width: w, height: h });
    await page.goto(BASE + path, { waitUntil: 'networkidle0' });
    if (openMenu) {
      await page.click('.wp-block-navigation__responsive-container-open');
      await page.waitForSelector('.wp-block-navigation__responsive-container.is-menu-open', { visible: true, timeout: 5000 });
      console.log('   (menu overlay opened: true)');
    }
    await page.evaluate(axeSrc);
    const res = await page.evaluate(async (tags) => await axe.run(document, { runOnly: { type: 'tag', values: tags } }), TAGS);
    console.log(`== ${name}: ${res.passes.length} rules passed, ${res.violations.length} violations, ${res.incomplete.length} need manual review`);
    for (const v of res.violations) {
      total += v.nodes.length;
      console.log(`   [${v.impact}] ${v.id}: ${v.help} (${v.nodes.length} element${v.nodes.length > 1 ? 's' : ''})`);
      for (const n of v.nodes.slice(0, 2)) console.log(`       ${n.target.join(' ')}  ${(n.failureSummary || '').split('\n')[1] || ''}`.slice(0, 190));
    }
    await page.close();
  }
  await browser.close();
  console.log(`\nTOTAL violating elements: ${total}`);
  process.exit(total ? 1 : 0);
})().catch(e => { console.error('RUN FAILED:', e.message); process.exit(2); });
