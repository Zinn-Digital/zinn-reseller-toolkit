=== Zinn Reseller Toolkit ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: hosting, reseller, domains, woocommerce, api
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell Zinn® hosting from your own WordPress site: search domains, sign clients into their panel, and provision hosting when a WooCommerce order is paid.

== Description ==

If you resell Zinn® hosting, this plugin connects your own WordPress site to your reseller account so your customers never have to leave it.

It has three parts and **they are separate switches**. Turn on only what you need — nothing that is switched off loads any code, registers any hook or enqueues any stylesheet.

**1. Domain search**

Put `[zinn_domain_search]` on any page. A visitor types a name, and the results come back live from your domain account with your own prices on them.

* No JavaScript at all. The form is a plain search that works with scripting off, is crawlable, and produces a shareable result URL.
* Three honest answers, not two. Available, already registered, and **"we could not check"** — because when no registrar can be reached, telling a visitor a name is taken makes them abandon a name that may well be free.
* Results are cached for five minutes so a busy page does not hammer the registry.

Attributes:

`[zinn_domain_search placeholder="Find your domain" button="Search" tlds="com,co.uk,io"]`

`tlds` accepts up to five extensions. Leave it out and the default set is used.

**2. Panel link**

Put `[zinn_panel_link]` anywhere a signed-in customer will see it — a My Account page is the usual home. It renders a single link that takes them straight into their hosting panel, already signed in.

The sign-in link is minted **when the link is clicked**, not when the page is rendered, so it never sits in a page cache and never reaches a crawler. It is single-use and expires within minutes.

`[zinn_panel_link label="Manage my hosting"]`

**3. WooCommerce provisioning**

Sell hosting as an ordinary WooCommerce product, take the money through your own gateway into your own account, and hosting is set up the moment the order is paid.

* Set the Zinn® product line on the product's **Inventory** tab, or set one default for the whole shop in the plugin settings.
* The customer's chosen hostname is read from the order item's `zinn_domain` meta — set it from your own domain field, or from the domain search above.
* A returning customer's second order lands under the **same** client account, not a second one.
* Every call is idempotent, so a gateway retry or an order moved from Processing to Completed can never provision twice.
* If provisioning fails, the reason is written to the order as a note **and** shown in a panel on the order screen — never only to a PHP log where nobody would find it.

== Installation ==

1. Install and activate the plugin.
2. In your Zinn® dashboard, go to **API keys** and create a key. Give it only the permissions you need: `sites.create` and `org.read` for WooCommerce provisioning, `reseller.view` and `reseller.provision` for the panel link.
3. In WordPress, go to **Settings → Zinn® Reseller**, paste the key, and save.
4. The **Connection** section at the bottom of that screen makes a real call to the API and tells you what it said. It does not simply check that the box is filled in.
5. Switch on the modules you want.

== Frequently Asked Questions ==

= Do my customers' payments go through Zinn®? =

No. WooCommerce settles the money into your own account through your own gateway. This plugin runs after the order is paid and only tells the hosting platform to provision.

= Can I restyle the domain search? =

Yes. It ships with a deliberately small stylesheet that sets layout and state colour and nothing else. Override these classes in your theme: `.zinn-domain-search`, `.zinn-domain-results`, `.zinn-domain-result`, `.zinn-domain-result--available`, `.zinn-domain-result--taken`, `.zinn-domain-result--unknown`, `.zinn-domain-name`, `.zinn-domain-state`, `.zinn-domain-buy`, `.zinn-domain-error`.

= What happens if I deactivate the plugin? =

Nothing is provisioned or cancelled. Uninstalling removes the plugin's own settings; it deliberately leaves the links between your customers and their hosting alone, because those are the only record of what a customer is paying for.

= Does it work on multisite? =

Yes. Settings are per site, and uninstalling clears them on every site in the network.

== Screenshots ==

1. The settings screen, with a live connection check.
2. Domain search results on a page.
3. The Zinn® hosting panel on a WooCommerce order.

== Changelog ==

= 1.0.0 =
* First release: domain search, panel sign-in link, and WooCommerce provisioning.
