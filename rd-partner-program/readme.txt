=== ReserveDaily Partner Program ===
Contributors: shariarbijoy
Author: Shariar Bijoy
Author URI: https://shariarbijoy.dev
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv2 or later

Multi merchant referral programs for WooCommerce. Partner tagging, automatic discounts, myCRED points multipliers, and commission tracking with manual refund settlement.

== Description ==

Customers sign up with a partner code, get permanently tagged to that partner, receive a points multiplier and automatic category discounts on purchases, and the partner earns commission on that customer's first N completed orders inside a rolling window from signup. Everything is editable from the WordPress admin under the Partner Program menu in the sidebar. The Users list shows which affiliate partner each customer is tagged to and can be filtered by partner.

Requires WooCommerce and myCRED. Built for legacy post storage, not HPOS.

Developed by Shariar Bijoy, https://shariarbijoy.dev

== Installation ==

1. Upload the rd-partner-program folder to wp-content/plugins.
2. Activate the plugin. Activation creates the commission table and seeds default settings.
3. Go to Partner Program, Affiliate Partners and create a partner. Set the parent code, coach codes, commission rate, points multiplier, signup bonus, eligible categories and products, exclusions, window, and cap.
4. Go to Partner Program, Partner Settings and confirm the myCRED point type, points per RM, rank percentages, and cookie settings.
5. Complete the four post install steps below before launching campaigns.

== Post install step 1, cache compatibility, read this ==

Referral capture is cache proof by design. A small script ships inside every page, including cached copies, and runs in the visitor's browser. When the URL carries ?ref=CODE the script calls the WordPress ajax endpoint, which is never page cached, and that endpoint counts the visit and sets the referral cookie server side. This works on any URL of the site, cached or not, with Seraphinite Accelerator and Cloudflare both active. No cache exclusions are required for tracking or tagging to work.

Optional hardening: excluding the main campaign landing pages from Seraphinite caching adds a PHP level capture path for the tiny fraction of visitors with JavaScript disabled. Nice to have, not required.

Verify after install: open any normal cached page with ?ref=HOP in a private browser window, confirm the rd_ppg_ref cookie appears (devtools, Application, Cookies, it is set by the ajax response a moment after page load), and confirm the visit shows in Products, Partner Reports, Traffic and Signups.

== Post install step 2, welcome bonus non stacking guard ==

The RM50 welcome bonus for new customers must not stack with a partner discount. Add the following guard at the top of the welcome bonus fee function inside rd-discount-engine-final.php, before any fee is added:

    if ( function_exists( 'WC' ) && WC()->session && 'yes' === WC()->session->get( 'rd_ppg_discount_active' ) ) {
        return;
    }

When this plugin applies any partner discount to the cart it sets the session flag rd_ppg_discount_active to yes, and clears it when no partner discount applies. With the guard in place the partner discount wins for tagged members and the welcome bonus still applies for everyone else.

== Post install step 3, remove the old myCRED points snippet ==

This plugin replaces the standalone snippet with the function uk_award_rank_points_on_completed_order. After activating this plugin, delete or deactivate that snippet.

Two safety nets protect against double points while both exist. The plugin automatically unhooks the old snippet function when it detects it, and it skips any order already marked with the old _uk_points_awarded meta. These are safety nets, not the plan. Delete the snippet once the plugin is confirmed active so there is a single owner of points logic.

The merged logic also fixes the old defects. Unknown ranks now fall back to vital_core instead of awarding zero, points are awarded as a clean integer, points are computed per line so discounted eligible lines can be suppressed, and the point type comes from settings.

== Post install step 4, Meta pixel ==

Verify the Meta pixel fires on each campaign landing page for retargeting. This is outside plugin scope but campaign performance depends on it.

== Customer window and deal end date ==

Two clocks govern how long the special treatment lasts. The customer window (months, per partner) runs from each customer's own signup date. The optional deal end date is the partnership's calendar end. A customer's special treatment ends at whichever comes first, and that single cutoff governs commission, automatic discounts, the points multiplier, and the redemption override together. After the cutoff the customer keeps their account and their earned points but behaves as a normal customer, and the tag remains on the account for history. When the deal end date has passed, new signups, tagging, and visit tracking for that partner also stop, and the partner's dashboard shows a partnership ended notice. Extending the deal end date renews the deal with no other changes needed.

== Landing page URL per partner ==

Each partner has an optional Landing page URL. When set, the referral links and QR codes on that partner's dashboard point to it instead of the homepage, for example a campaign page built in Elementor that carries the Meta pixel. Tagging and visit counting work on any URL of the site regardless, this field only controls what the partner is handed to share.

== Points redemption ==

Two myCRED point types are in play. Welcome Credits (type key mycred_welcome) hold the partner welcome bonus, are 100 percent redeemable through the Toolkit's own per type maximum, and are zeroed by a daily sweep once the partner's expiry days pass. Vital Points (the main type) follow the standard redemption cap from Partner Settings, default 5 percent of the cart. Each affiliate partner can lift that cap for their tagged in window customers through the Points Redemption Perk box: off, on selected items (a blended cap where the perk percent applies to the perk items' share of the cart), or on all items. The plugin swaps the maximum at read time on the Toolkit's partial payment requests; no myCRED file is modified.

== Commission rate per item ==

The partner's Default commission rate applies to the whole order when no item rate is set. To pay different rates per item, fill the Commission rate column on the eligible category or product rows. Blank keeps the default, a number (including 0) is that item's own rate, and a product row beats a category row. As soon as any row carries its own rate, commission is calculated line by line on product value only, shipping and fees excluded, and the per line breakdown is shown on the Reports page and in the CSV export.

== Excluded products guidance ==

Seed the excluded products list on each partner with the thin margin SKUs: VIP Cardiac, VIP Health, Endoscopy packages, and the RM2,500 NAD+. These never receive the automatic discount and never suppress points.

== How it works ==

Traffic tracking. Every landing on a URL carrying a valid partner code is counted as a visit for that code, and every customer tagged through a code is counted as a signup. Capture happens in the browser and reports to an ajax endpoint, so it works on fully cached pages. Bots and social link preview fetchers are filtered two ways: crawlers that do not execute JavaScript never trigger the endpoint at all, and a user agent filter plus a short per visitor throttle catch the rest. One visit is counted per code per browser tab session, so refreshes do not inflate the numbers. The Traffic and Signups table on the Reports page breaks this down per code with a conversion rate, filterable by merchant and date range. Counts are stored per day in the rd_ppg_stats table.

Affiliate partner dashboard. Each affiliate partner can be linked to one or more WordPress user accounts through the Affiliate Partner accounts field on the partner edit screen, or a fresh account can be created right there: enter name and email, tick create on save, and the plugin creates a normal customer account, links it, and sends the standard WordPress welcome email with a password setup link. Accounts created from wp-admin are never referral tagged, so an admin's own test cookie cannot leak onto them. Linked users get a Partner Dashboard tab in My Account showing their program summary (multiplier, signup bonus, commission rate, cap, window), their referral links per code with one click copy and an offline capable QR code for print or social sharing, their eligible items with the customer benefit per item, and per code performance: visits, signups, and purchases, filterable by period (7, 30, 90 days, all time). Merchants only ever see their own partner data. Purchases count completed orders by referred customers net of voided entries. QR codes are generated locally in the browser using the bundled qrcodejs library (MIT), no external service receives the links.

Partner code field. Off by default since 2.1.1, the referral link is the only way in. When "Typed partner code fields" is enabled in Partner Settings, registration and checkout carry an optional Partner code field for customers who heard a code but never clicked a link. A valid typed code tags the new account exactly like a link click, counts a signup, and awards the signup bonus. A typed code wins over the cookie. An unrecognized code blocks checkout with a clear error so typos do not silently lose attribution. For a pre existing logged in customer the typed code only tags when the merchant has Allow existing customers enabled. The field prefills from the referral cookie when one is present. If the registration form is rendered by a custom plugin that ignores standard form hooks, add a text input named rd_ppg_partner_code to that form and the tagging works unchanged.

Points only programs. To reward referred customers with points instead of discounts, set every eligible row's discount rate to 0 or add no eligible rows at all. Tagged customers then pay full price and the merchant points multiplier applies to their whole order. Discount rates above 0 trade points for a price cut on those items.

First order pricing. Partner discounts read the account tag, so they apply to logged in tagged customers. A guest who creates the account during checkout itself still gets tagged, earns points and commission on that first order, but its cart was priced before the account existed, so the automatic discount starts from their next visit. The plugin shows no cart or checkout notice to guests carrying a referral cookie.

Tagging. A visit with ?ref=CODE sets a first touch cookie for 90 days by default. On registration the user is permanently tagged to the resolved merchant, receives the merchant signup bonus once, and the cookie is cleared. Tags are never overwritten. Coach codes such as HOP-JOHN roll up to the parent code HOP and are retained for per coach reporting. An existing logged in untagged customer arriving with a code is only tagged when the merchant has Allow existing customers enabled.

Discounts. Tagged members inside their window automatically receive the configured percentage off eligible categories and products, no coupon needed. Different rates per category or product are supported in one cart. Excluded products stay full price. Once the window or the deal ends, prices return to normal automatically.

Points. On order completion, points are awarded per line at the customer's rank rate multiplied by the partner multiplier. Lines that received a partner discount earn zero points. Untagged customers, and tagged customers whose window or deal has ended, earn at multiplier 1. Points always compute on the paid value after discounts.

Commission. The partner earns commission on completed orders by referred customers inside the window from signup, at the partner default rate on the whole order or, when item rates are set, line by line at each item's own rate. The order cap limits how many orders per customer count; 0 or blank (the default) means unlimited, every completed order inside the window pays commission. Later orders are recorded as overflow. Orders outside the window are recorded but not counted. Refund settlement is manual from Products, Partner Reports. Voiding an entry frees a slot, promoting an overflow entry fills a freed slot, and adjusting handles partial refunds. An optional per partner auto backfill toggle promotes the earliest in window overflow automatically after a void, off by default.

Guest checkout. Guests are never tagged and earn no commission. Guest checkout is never blocked and the plugin shows guests no notices.

== Uninstall ==

Deactivation keeps all data. Uninstall only deletes the commission table, partners, tags, and settings when Delete all plugin data on uninstall is enabled in Partner Settings. Off by default.

== Credits ==

Designed and developed by Shariar Bijoy for ReserveDaily.

Website: https://shariarbijoy.dev

This plugin absorbs and replaces the original standalone myCRED rank points snippet, also authored by Shariar Bijoy, fixing its known defects and extending it into a full multi merchant partner program.
