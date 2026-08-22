# Zinn® Reseller Toolkit

Sell Zinn® hosting from your own WordPress site.

Three things, and they are three separate switches — nothing you leave off loads any code,
registers any hook, or adds a stylesheet to your pages.

- **Domain search.** Put `[zinn_domain_search]` on a page. Visitors search, and results come
  back live from your reseller domain account with your prices on them. No JavaScript: it works
  with scripting off, it is crawlable, and a result is a shareable URL.
- **Panel sign-in link.** Put `[zinn_panel_link]` where a signed-in customer will see it. One
  click takes them into their hosting, already signed in.
- **WooCommerce provisioning.** Sell hosting as an ordinary WooCommerce product, take the money
  through your own gateway into your own account, and the hosting is set up the moment the
  order is paid.

## Install

1. Download the latest release, or clone this repository into `wp-content/plugins/`.
2. Activate the plugin.
3. In your Zinn® dashboard, go to **API keys** and create one. Give it only what you need:
   `org.read`, `sites.create`, `sites.view`, `sites.delete`, `reseller.view`,
   `reseller.provision`.
4. In WordPress, go to **Settings → Zinn® Reseller**, paste the key, and save. The
   **Connection** section makes a real call and tells you what the platform said — it does not
   simply check that the box is filled in.
5. Switch on the modules you want.

⛔ Do not give an integration key `reseller.manage`. That permission edits your price list and
your own payment-gateway credentials; `reseller.provision` exists so a key you paste into a
website does not need it.

## Shortcodes

```
[zinn_domain_search placeholder="Find your domain" button="Search" tlds="com,co.uk,io"]
[zinn_panel_link label="Manage my hosting"]
```

`tlds` takes up to five extensions. Leave it out for the default set.

## Styling the domain search

The bundled stylesheet sets layout and state colour and nothing else, so it sits inside your
theme rather than fighting it. Override these classes:

`.zinn-domain-search` `.zinn-domain-results` `.zinn-domain-result`
`.zinn-domain-result--available` `.zinn-domain-result--taken` `.zinn-domain-result--unknown`
`.zinn-domain-name` `.zinn-domain-state` `.zinn-domain-buy` `.zinn-domain-error`

Note the three result states. A search has three answers, not two: available, already
registered, and **we could not check**. Telling a visitor a name is taken when no registrar
could be reached makes them abandon a name that may well be free, so that case has its own
wording and its own class.

## WooCommerce

- Set the Zinn® product line on the product's **Inventory** tab, or set one default for the
  whole shop in the plugin settings.
- The customer's chosen hostname is read from the order item's `zinn_domain` meta.
- A product is treated as hosting only if it names a product line **or** carries a hostname, so
  the rest of your catalogue is left alone.
- A returning customer's second order lands under the **same** client account.
- Every call is idempotent, so a gateway retry or an order moved from Processing to Completed
  cannot provision twice.
- If provisioning fails, the reason is written to the order as a note **and** shown in a panel
  on the order screen.

Payments never touch us. WooCommerce settles into your own account through your own gateway;
this plugin runs afterwards and asks the platform to provision.

## Documentation

- API reference — <https://zinndigital.com/developers/api>
- Getting started as a reseller — <https://zinndigital.com/kb/reseller-api-getting-started>

## Requirements

WordPress 6.6+, PHP 8.2+, and a Zinn® reseller account.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).

Author: Neil Lock — CEO, Zinn Digital® Ltd — <https://zinndigital.com>
