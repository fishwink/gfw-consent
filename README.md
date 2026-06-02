# FISHWINK Consent

Lightweight WordPress cookie consent plugin for FISHWINK (GoFishWink) client sites. The slug, option keys, and REST routes remain `gfw-consent` / `gfw_consent` for backwards compatibility with existing installs — only user-facing labels carry the FISHWINK name.

**What it does**

- Blocks known marketing/analytics/functional scripts at PHP render time via output buffering, so nothing fires until the visitor consents.
- Swaps blocked iframes (YouTube, Vimeo, Maps) with click-to-load placeholders.
- Injects Google Consent Mode v2 defaults (denied) and pushes `consent update` when the user decides.
- Honors the Global Privacy Control browser signal.
- Logs every consent event (accept/reject/custom/withdraw/gpc_auto) to a custom DB table with hashed IP, policy version, categories, and URL — a demonstrable audit trail.
- Auto-generates a cookie policy via `[gfw_consent_policy]` based on services actually detected by a daily site scan.
- Branded via CSS variables — primary color, background, text, border, radius, font, layout (bar/box), position.
- Designed to stay compatible with LiteSpeed Cache, Cloudflare, and full-page caching (all visitors get the same cached HTML; banner state is client-side).
- Deployed via GitHub + Plugin Update Checker for centralized updates across all client sites.

---

## Install

1. Download the ZIP from the latest GitHub release or build locally: `zip -r gfw-consent.zip gfw-consent/`.
2. In WordPress admin: **Plugins → Add New → Upload Plugin**.
3. Activate.
4. Drop the Plugin Update Checker library into `lib/plugin-update-checker/` (see below).
5. Go to **Consent** in the admin sidebar and configure.

### Plugin Update Checker setup

The main plugin file loads PUC v5 from `lib/plugin-update-checker/plugin-update-checker.php`. To enable centralized GitHub updates:

1. Download Plugin Update Checker: https://github.com/YahnisElsts/plugin-update-checker
2. Extract the `plugin-update-checker-5.x` folder into this plugin's `/lib/` directory and rename it to `plugin-update-checker`.
3. In `gfw-consent.php`, update the GitHub URL to your repo.
4. (Optional) For a private repo, uncomment the `setAuthentication()` line and supply a GitHub PAT.
5. Push a tagged release to GitHub. WP admin will show the update within ~12 hours or on manual "Check Again".

### Shortcodes

| Shortcode | Purpose |
|---|---|
| `[gfw_consent_policy]` | Full cookie policy page. Place on `/cookie-policy`. |
| `[gfw_consent_preferences label="Cookie preferences"]` | Button / link to reopen the preferences modal. Place in footer. |

### JavaScript API

For theme or custom code:

```js
GFWConsentAPI.openPreferences();  // Opens the preferences modal
GFWConsentAPI.getState();         // Returns { id, c: [categories], v, t }
GFWConsentAPI.withdraw();         // Resets consent to "reject all"
```

Listen for consent changes:

```js
document.addEventListener('gfw:consent', function (e) {
	// e.detail = { id, c: ['analytics', ...], v, t }
});
```

### Adding new services to the catalog

Services live in `includes/class-gfw-consent-services.php`. Each entry needs:

```php
'service-slug' => array(
	'name'      => 'Display Name',
	'vendor'    => 'Vendor Inc.',
	'category'  => 'marketing',       // essential | functional | analytics | marketing
	'patterns'  => array( 'cdn.example.com/tracker.js' ),
	'cookies'   => array( '_svc_*' ),
	'privacy'   => 'https://example.com/privacy',
	'purpose'   => 'One-sentence plain-English purpose.',
	'retention' => 'Up to 1 year',
),
```

`patterns` are plain substrings matched case-insensitively against both `<script src="...">` and inline `<script>` bodies.

### Jurisdictional behavior

- **Auto** (default): uses the `CF-IPCountry` header when behind Cloudflare. EU/UK → prior consent required. Everyone else → opt-out style (banner still requires interaction in this plugin, but the legal meaning differs).
- **Always EU**: safest for mixed audiences, slightly more intrusive UX.
- **Always US**: if the client genuinely has no EU traffic.

GPC is always respected when the setting is on.

### Caching notes

- Output is identical for all visitors. No personalization in HTML.
- The banner is always rendered; the JS hides it if consent cookie is present.
- All blocked scripts ship as `type="text/plain"` and are rehydrated client-side.
- No cache exclusions needed for LiteSpeed Cache or Cloudflare full-page cache.

### Rolling out across client sites

1. Push tagged release to GitHub.
2. Each client site auto-detects the update via PUC.
3. For settings that should be identical across clients (log retention, consent mode, honor GPC), use `wp option update` via WP-CLI on each server.
4. Per-client customization (brand colors, company name, contact email, banner copy) stays local on each site.

### Maintenance checklist (run quarterly)

- [ ] Review the services catalog — any new trackers any clients adopted?
- [ ] Check Consent Log tab on a sample of sites — are logs accumulating?
- [ ] Test banner on a logged-out browser — does reject-all actually block everything in Network tab?
- [ ] Check GA4 / Ads conversions — Consent Mode v2 modeling working?
- [ ] Legal: scan industry news for any new state laws (IAPP US State Privacy Legislation Tracker).

## Legal disclaimer

This plugin provides the *technical mechanism* for cookie consent. Legal compliance also requires accurate policy text, a proper privacy policy, a DPA where applicable, and responding to data subject requests. This plugin does not provide legal advice. Clients should have their cookie and privacy policies reviewed by counsel.

## License

GPLv2 or later.

---

## Changelog

### 1.0.15

**US opt-out mode + Consent Mode fix.** Previously the script blocker hard-blocked Google Analytics / Tag Manager until consent, which prevented Consent Mode from ever sending data and caused GA4 traffic to collapse for non-consenting visitors. This release fixes that and adds a true US opt-out posture.

- Google Analytics 4 and Google Tag Manager are now flagged `consent_mode` in the services catalog and are **no longer hard-blocked** when Consent Mode v2 is enabled. They load on every page and are governed by the `gtag` consent signal instead.
- New **opt-out (US) behavior**, driven by the existing `jurisdiction_mode` setting (`us`, or `auto` when the visitor is not detected as EU/UK):
  - `analytics_storage` defaults to **granted** (GA4 measurement is counted by default).
  - Advertising (`ad_storage`/`ad_user_data`/`ad_personalization`) and functional storage stay **denied** by default — advertising remains opt-in.
  - The banner becomes a **dismissible notice** ("Got it" / "Opt out" / "Preferences") rather than a blocking gate.
- "Got it" grants the analytics consent **signal only** — it does not auto-restore other hard-blocked analytics scripts (e.g. session-replay tools), which stay opt-in via the preferences modal.
- Global Privacy Control is still honored in both modes (client-side, plus a best-effort server-side `Sec-GPC` check that keeps analytics denied-by-default for GPC visitors).
- EU/UK behavior is unchanged: full opt-in, everything denied until consent (and GA4/GTM now correctly run under Advanced Consent Mode, enabling cookieless modeling).

**Notes / caveats**

- *Audience assumption:* opt-out mode is appropriate for US audiences. If a site set to `us`/`auto` draws meaningful EU/UK traffic, opt-out-for-everyone is not GDPR-compliant for those visitors — set `jurisdiction_mode` to `eu` for those sites, or rely on `auto` behind Cloudflare so EU visitors are detected and gated.
- *Caching:* in `auto` mode the rendered Consent Mode default varies by Cloudflare country / `Sec-GPC` header, which is not full-page-cache safe on mixed-audience sites. US-only sites always resolve to opt-out, so they remain cache-safe.
- *GTM containers:* now that `gtm.js` is allowed to load, any ad/marketing tags fired inside GTM must be gated by GTM's own Consent Mode settings — the PHP blocker can no longer stop them.
- *Analytics category scope:* turning the analytics toggle on (via Preferences → Save) restores the whole analytics category, including any session-analytics scripts present. Keep session-replay tools opt-in by leaving them un-consented.

### 1.0.16

- Added **CallTrackingMetrics** (`tctm.co`) to the built-in services catalog (category `marketing`) so it is disclosed in the auto-generated cookie policy.
- Introduced an `always_load` service flag: a flagged service is never hard-blocked but is still disclosed. CTM uses it because its tracker does not read Google Consent Mode, so the realistic options are hard-block (which kills call attribution) or load-and-disclose. CTM therefore loads by default. NOTE: an opt-out / GPC signal does not stop an `always_load` service.
