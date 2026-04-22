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
