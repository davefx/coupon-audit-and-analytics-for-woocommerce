const puppeteer = require('puppeteer-core');
const fs = require('fs');

const BASE = 'http://127.0.0.1:8088';
const OUT  = process.argv[2];

// wp.org shows screenshots at 1280 wide; a 2x device pixel ratio keeps text
// crisp on the plugin directory's retina rendering.
const VIEWPORT = { width: 1500, height: 900, deviceScaleFactor: 2 };

// The audit table is wider than the rest: eight columns, and Findings is the
// last of them. At the default width its labels are clipped at the right edge,
// which is the one column a reader is looking for.
const SHOTS = [
  { file: 'screenshot-1.png', url: '/wp-admin/admin.php?page=dfxcaaw-inventory', wait: '.dfxcaaw-summary', width: 1800 },
  { file: 'screenshot-2.png', url: '/wp-admin/admin.php?page=dfxcaaw-margins',   wait: '.dfxcaaw-margins table' },
  { file: 'screenshot-4.png', url: '/wp-admin/admin.php?page=dfxcaaw-settings',  wait: '.dfxcaaw-settings form' },
];

(async () => {
  const browser = await puppeteer.launch({
    executablePath: '/usr/bin/google-chrome',
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--force-device-scale-factor=2'],
  });

  const page = await browser.newPage();
  await page.setViewport(VIEWPORT);

  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle2' });
  await page.type('#user_login', 'demo');
  await page.type('#user_pass', 'demo');
  await Promise.all([
    page.click('#wp-submit'),
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
  ]);

  // The admin bar and collapsed menu add nothing to a plugin screenshot and
  // steal a third of the width.
  const hideChrome = `
    #wpadminbar { display: none !important; }
    html.wp-toolbar { padding-top: 0 !important; }
    #adminmenumain { display: none !important; }
    #wpcontent, #wpfooter { margin-left: 0 !important; }
    #wpfooter, .notice.updated, #screen-meta-links { display: none !important; }
    #wpbody-content { padding-bottom: 12px !important; }
  `;

  for (const shot of SHOTS) {
    await page.setViewport({ ...VIEWPORT, width: shot.width || VIEWPORT.width });
    await page.goto(`${BASE}${shot.url}`, { waitUntil: 'networkidle2' });
    await page.addStyleTag({ content: hideChrome });
    try { await page.waitForSelector(shot.wait, { timeout: 5000 }); } catch (e) { console.log(`  (selector ${shot.wait} not found)`); }
    await new Promise(r => setTimeout(r, 400));

    const target = await page.$('#wpbody-content') || page;
    await target.screenshot({ path: `${OUT}/${shot.file}` });
    console.log(`  wrote ${shot.file}`);
  }

  await browser.close();
})().catch(e => { console.error(e.message); process.exit(1); });
