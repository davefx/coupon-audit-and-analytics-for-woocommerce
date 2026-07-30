const puppeteer = require('puppeteer-core');

const BASE = 'http://127.0.0.1:8088';
const OUT  = process.argv[2];

// The coupon the warnings are shown against: unrestricted, no expiry and no
// usage limit, so it carries three of them at once. Found by its code rather
// than by an ID passed in from outside — the ID changes every time the demo shop
// is rebuilt, and a script that needs one handed to it is a script that does not
// run on its own.
const CODE = 'welcome10';

(async () => {
  const browser = await puppeteer.launch({
    executablePath: process.env.CHROME || '/usr/bin/google-chrome',
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  const page = await browser.newPage();
  await page.setViewport({ width: 1500, height: 840, deviceScaleFactor: 2 });

  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle2' });
  await page.type('#user_login', 'demo');
  await page.type('#user_pass', 'demo');
  await Promise.all([ page.click('#wp-submit'), page.waitForNavigation({ waitUntil: 'networkidle2' }) ]);

  await page.goto(`${BASE}/wp-admin/edit.php?post_type=shop_coupon`, { waitUntil: 'networkidle2' });

  const editUrl = await page.evaluate((code) => {
    const link = [...document.querySelectorAll('a.row-title')].find((a) => a.textContent.trim() === code);

    return link ? link.href : null;
  }, CODE);

  if (!editUrl) {
    throw new Error(`No coupon called ${CODE} in this shop — has the seed run?`);
  }

  await page.goto(editUrl, { waitUntil: 'networkidle2' });

  await page.addStyleTag({ content: `
    #wpadminbar { display: none !important; }
    html.wp-toolbar { padding-top: 0 !important; }
    /* The menu stays, collapsed: hiding it entirely widens #poststuff past the
       viewport and clips the Publish box off the right edge. */
    #wpfooter, #screen-meta-links, .wp-heading-inline + .page-title-action { display: none !important; }
    #wpbody-content { padding-top: 14px !important; }
  ` });

  // Everything WooCommerce and WordPress want to say on this screen that is not
  // this plugin talking. A screenshot advertising the plugin should show the
  // plugin, not an unrelated HTTPS advisory.
  await page.evaluate(() => {
    document.body.classList.add('folded');
    document.querySelectorAll('.notice, .updated, .error').forEach((el) => {
      if (!el.textContent.includes('Coupon audit:')) {
        el.remove();
      }
    });
  });

  await new Promise(r => setTimeout(r, 400));
  await page.screenshot({ path: `${OUT}/screenshot-3.png` });
  console.log('  wrote screenshot-3.png');

  await browser.close();
})().catch(e => { console.error(e.message); process.exit(1); });
