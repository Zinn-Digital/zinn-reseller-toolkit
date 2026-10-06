=== Zinn® Reseller Toolkit ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-reseller
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: hosting, reseller, domains, woocommerce, api
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.7.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell Zinn Digital® hosting from your own site: search domains, sign clients into their panel, provision hosting on a paid WooCommerce order.

== Description ==

If you resell Zinn® hosting, this plugin connects your own WordPress site to your reseller account so your customers never have to leave it.

It has three parts and **they are separate switches**. Turn on only what you need — nothing that is switched off loads any code, registers any hook or enqueues any stylesheet.

**1. Domain search**

Put `[zinn_domain_search]` on any page. A visitor types a name, and the results come back live. Once your reseller key is set they come from your own domain account with your own prices on them; before that, the search already works against the public catalogue and its prices.

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
2. In your Zinn® dashboard, go to **Developer → API keys** and create a key. Give it only the permissions you need: `sites.create` and `org.read` for WooCommerce provisioning, `reseller.view` and `reseller.provision` for the panel link.
3. In WordPress, go to **Zinn Digital® → Reseller Toolkit**, paste the key on the **Account** tab, and save.
4. The status panel at the top of that screen tells you whether a key is set and which features are switched on.
5. Switch on the modules you want on the **Features** tab.

== External services ==

= AI apps you connect (MCP sign-in) =

Only when an AI app such as Claude or ChatGPT starts connecting to the site's MCP server does the site fetch that app's public OAuth client metadata from the address the app gives, for example `https://claude.ai/oauth/mcp-oauth-client-metadata` or `https://chatgpt.com/oauth/client.json`. No site content is sent.

* Claude: terms https://www.anthropic.com/legal/consumer-terms, privacy policy https://www.anthropic.com/legal/privacy
* ChatGPT: terms https://openai.com/policies/terms-of-use/, privacy policy https://openai.com/policies/privacy-policy/

This plugin lets you sell Zinn Digital® hosting from your own WordPress site. It is a front end
for the Zinn® reseller API and requires a reseller account.

**What is sent, and when**

* **Domain search (when a visitor searches).** The search term is sent to
  `https://api.zinndigital.com/v1/public/domains/search` (or `/v1/domains/search` when
  authenticated) to check availability and price. **The search term is the visitor's input; no
  other visitor data, IP address or identifier is added by this plugin.**
* **Programme and plan data.** `/v1/reseller/program` is read to render your plans and prices.
* **Provisioning (when a WooCommerce order is paid).** Two requests, in order. The customer's
  name and e-mail go to `/v1/orgs` so their hosting account can be created, and the ordered
  plan and domain name then go to `/v1/sites` so the site itself can be provisioned. This is a
  transfer of your customer's personal data to Zinn Digital® as a processor, and you should
  reflect it in your own privacy policy.
* **Client sign-in (when a signed-in customer presses "Open hosting panel").** This site asks
  `/v1/reseller/services/<service-id>/sso`, using your reseller credential, for a one-time link
  and then redirects the browser to `https://app.zinndigital.com`. No password and no customer
  credential passes through this site — only the identifier of the service being opened.
* **AI agents (MCP), only when an administrator's AI app asks.** Each request uses your reseller
  credential: `/v1/reseller/overview`, `/v1/orgs` (list or create a client: its name and
  currency), `/v1/reseller/clients/<client-id>/plan` (read or set a client's plan),
  `/v1/catalog/plans`, `/v1/reseller/services` (list sites) and
  `/v1/reseller/services/<site-id>/suspend` / `unsuspend` (with your own note), and `/v1/sites`
  (create a site: client, product line and domain name). Nothing is sent unless an administrator
  connected an AI app with an application password and that app called the action; switch it off
  under Reseller Toolkit → AI agents (MCP).

Nothing is transmitted until you enter reseller API credentials.

Service terms: https://zinndigital.com/legal/terms
Privacy policy: https://zinndigital.com/legal/privacy

* **Support diagnostics (only when you press send).** If you ask us for help, the plugin can send
  a support report to `https://api.zinndigital.com/v1/connector/diagnostics`. **You are shown the
  exact payload first, already redacted, and nothing leaves your site until you press send.**
  Credentials are excluded by declaration rather than by matching key names, and render as
  `[not sent — credential]`. The plugin never sends this on its own initiative.

== Translations ==

**Every string this plugin adds to your admin is translated into 57 languages** — labels, notices,
errors and settings, not a subset. The catalogues are bundled in the plugin, so they work as soon
as you set your site language; there is no separate language pack to install.

Every user-visible string is complete in every one of the 53 languages WordPress can serve
today:

Amharic (am), Arabic (ar), Azerbaijani (az), Bulgarian (bg_BG), Bengali (Bangladesh)
(bn_BD), Czech (cs_CZ), German (de_DE), Greek (el), Spanish (Spain) (es_ES), Persian
(fa_IR), French (France) (fr_FR), Gujarati (gu), Hebrew (he_IL), Hindi (hi_IN), Croatian
(hr), Hungarian (hu_HU), Armenian (hy), Indonesian (id_ID), Italian (it_IT), Japanese
(ja), Georgian (ka_GE), Kazakh (kk), Khmer (km), Kannada (kn), Korean (ko_KR), Lao (lo),
Malayalam (ml_IN), Mongolian (mn), Marathi (mr), Malay (ms_MY), Myanmar (Burmese)
(my_MM), Nepali (ne_NP), Dutch (nl_NL), Panjabi (India) (pa_IN), Polish (pl_PL), Pashto
(ps), Portuguese (Brazil) (pt_BR), Romanian (ro_RO), Russian (ru_RU), Sinhala (si_LK),
Albanian (sq), Serbian (sr_RS), Swahili (sw), Tamil (ta_IN), Telugu (te), Thai (th),
Tagalog (tl), Turkish (tr_TR), Ukrainian (uk), Urdu (ur), Uzbek (uz_UZ), Vietnamese
(vi), Chinese (China) (zh_CN)

A further 4 ship complete in the plugin — Hausa (ha), Somali (so_SO), Tajik (tg), Yoruba (yo) — but
WordPress core does not currently provide a locale for them, so WordPress cannot load them.

= Right-to-left =

Arabic, Persian, Hebrew, Pashto and Urdu are right-to-left. Every screen this plugin adds was
rendered in a real WordPress install in each of those languages and checked, not assumed.

= For translators =

`languages/` holds the `.pot` template plus a `.po`, `.mo` and `.l10n.php` for every language, so
corrections and new languages can be contributed directly.

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

1. The settings screen and its connection status.
2. Domain search results on a page.
3. A WooCommerce order: the Zinn® hosting panel shows what provisioning did, or why it did not.

== Changelog ==

= 1.7.9 =
* The readme's External services section now lists the request made when an AI app signs in to the site's MCP server (its public OAuth client metadata); no behaviour change.

= 1.7.8 =
* Security-scan annotation on the AI agents (MCP) sign-in screen (no behaviour change): every value on its Allow button is escaped.

= 1.7.7 =
* AI apps (Claude, ChatGPT) can now sign in with OAuth on a site where this is the only Zinn® plugin with an AI-agent server; the sign-in did not start there.

= 1.7.6 =
* MCP tools for AI connector directories: tool descriptions state only what each tool does (no references to other tools); every tool that takes input declares it; list results reach MCP clients as objects.

= 1.7.5 =
* Serbian: quotation marks are now „…“ throughout, as the Serbian WordPress translation team writes them.

= 1.7.4 =
* Translations follow each language's WordPress.org translation team style guide: its quotation marks, spacing before punctuation, apostrophes and ellipsis, and the forms of address it uses.

= 1.7.3 =
* Japanese follows the WordPress.org Japanese team's style guide: a half-width space around Latin text, half-width colons and question marks.

= 1.7.2 =
* New: two wp-config.php switches for the Zinn Digital® panel. define( 'ZINN_RESELLER_PROMO', false ); removes the panel (dashboard widget, settings block and footer), and define( 'ZINN_RESELLER_PROMO_HOSTING_URL', 'https://…' ); points its hosting offer at another https address.

= 1.7.1 =
* Security: the AI-app sign-in (MCP OAuth) checks a client's metadata address more strictly before fetching it (a public web address only, a limit per address, and a refused address remembered).

= 1.7.0 =
* AI apps such as Claude and ChatGPT can connect by signing in (OAuth 2.1) — no application password needed; every MCP tool declares whether it is read-only or destructive.

= 1.6.2 =
* Every bundled translation is redone with the current Google model: each locale in its own script (Serbian in Cyrillic), in the register its WordPress translation team uses, with the original spacing, placeholders and entities preserved.

= 1.6.1 =
* AI agents (MCP) and REST: list and create clients, put them on plans, list, create, suspend and unsuspend their sites through your reseller account — from AI apps, administrators only. On by default; switch under Reseller Toolkit → AI agents (MCP).

= 1.6.0 =
* On sites hosted by Zinn Digital®, this plugin is now installed from WordPress.org, so it updates from WordPress.org like any directory plugin. No change to what it does.

= 1.5.0 =
* White label: the domain search and the "Manage my hosting" link no longer show our name to your visitors — neutral class names, form fields and stylesheet handle, and the stylesheet is inlined instead of linked from the plugin folder. Old search links and panel links keep working.

= 1.4.0 =
* Smaller download: the editable translation sources (.po) are no longer shipped; WordPress only ever loads the compiled .mo and .l10n.php files, which are unchanged.

= 1.3.0 =
* Updates install whenever you click Update, even months later: the download link is fetched fresh at install time instead of expiring in WordPress's saved update data.

= 1.2.10 =
* Security hardening: the design-token stylesheet validates every component id and strips anything that could close the inline style.

= 1.2.9 =
* WooCommerce provisioning no longer writes "No Zinn® API key is configured" onto orders that are not hosting; the settings screen says domain search already works before a key is set; readme and screenshot captions corrected; listed on WordPress.org.

= 1.2.8 =
* A neutral plugin description. The installation steps and the missing-key message now point at the real settings screen, Zinn Digital® → Reseller Toolkit, and describe its status panel as it is. Its "Open my Zinn® dashboard" button opened a page that does not exist; it now opens API keys.

= 1.2.7 =
* Translations: a word written in the wrong alphabet (for example a Korean word inside a Malayalam sentence, or an Urdu word ending a Punjabi one) is corrected in every language that had one. Each affected string was translated again and checked.

= 1.2.6 =
* Tested up to: 7.1 — the major version only, as WordPress.org's Plugin Check requires (7.1.1 was refused as invalid_tested_upto_minor).

= 1.2.5 =
* Tested up to WordPress 7.1.1.

= 1.2.4 =
* In a right-to-left admin language, the Zinn Digital® menu entry showed its trademark symbol on the wrong side of the name. The name is now isolated so it reads correctly in Arabic, Hebrew, Persian, Pashto and Urdu.

= 1.2.3 =
* The admin screens' styles and scripts are now enqueued through WordPress rather than printed into the page, so they can be dequeued, deferred or optimised by your site like any other asset — and they still work on a site whose security policy forbids inline code.

= 1.2.2 =
* Translations: every string this plugin's admin shows is now translated in every bundled language. A few strings the machine translator refused were shipping in English; they are now translated by hand.

= 1.2.1 =
* Hardening: a settings rule can no longer be mistaken for a PHP function with the same name. The same shared settings code is what stopped Zinn® Translate saving its settings. Nothing about how this plugin behaves changes.

= 1.2.0 =
The domain-search widget can be styled — four presets and eight colours and sizes you can override — and the settings screen now tells you whether anything is actually switched on.
= 1.1.3 =
* Documented two API requests the plugin has always made and the readme did not mention: creating the site after an order is paid, and minting the one-time hosting-panel sign-in link.

= 1.1.2 =
* Added: automatic updates from the Zinn Digital® control plane — the same signed, checksum-verified update path the other Zinn® plugins use. Previously a new version could not reach an installed site.

= 1.1.0 =
* Added the Zinn® panel: links to Zinn Digital® hosting, the Zinn® marketplace, Zinn Hub® and this plugin's user guide, from inside the WordPress admin.

= 1.0.0 =
* First release: domain search, panel sign-in link, and WooCommerce provisioning.
