# Regenerating the screenshots

`readme.txt` promises four screenshots, and they go stale: a changed column, a
reworded notice, a new finding. These scripts rebuild them from nothing, so
"take the screenshots again" is a command rather than an afternoon.

They deliberately do **not** touch any existing site. A disposable WordPress is
installed against a throwaway MySQL container, seeded with a shop that exercises
every finding and all three cost-coverage states, and the plugin under test is
the built distribution rather than the working tree — the pictures should show
what a user installs.

```bash
docker run -d --name dfxcaaw-shots -e MYSQL_ROOT_PASSWORD=root \
	-e MYSQL_DATABASE=shots -p 3308:3306 mysql:8.0

mkdir -p /tmp/shots/wp && cd /tmp/shots/wp
wp core download
wp config create --dbname=shots --dbuser=root --dbpass=root --dbhost=127.0.0.1:3308
wp core install --url=http://127.0.0.1:8088 --title="Demo Shop" \
	--admin_user=demo --admin_password=demo --admin_email=demo@example.test --skip-email
wp plugin install woocommerce --activate
wp option update woocommerce_currency EUR
wp option update woocommerce_feature_cost_of_goods_sold_enabled yes

# The distribution build, not the repository.
composer run dist
cp -r build/coupon-audit-and-analytics-for-woocommerce wp-content/plugins/
wp plugin activate coupon-audit-and-analytics-for-woocommerce

wp eval-file bin/screenshots/seed.php
wp eval-file bin/screenshots/seed2.php
wp server --host=127.0.0.1 --port=8088 &

npm install puppeteer-core
node bin/screenshots/shoot.js  .wordpress-org   # screens 1, 2 and 4
node bin/screenshots/shoot3.js .wordpress-org   # the coupon editor
```

Two things about the images themselves. They are captured at a 2x device pixel
ratio, because the plugin directory renders them on displays that will otherwise
show blurred text. And the coupon editor is shot with WordPress's own menu
collapsed rather than hidden: hiding it entirely widens the two-column layout
past the viewport and clips the Publish box off the right edge.

`seed2.php` exists separately because the third cost-coverage state — a coupon
whose orders carry no cost at all — needs an unrestricted coupon applied to a
basket of products that have no cost recorded. It is easier to add afterwards
than to weave into the first pass.
