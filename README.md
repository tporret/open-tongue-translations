# Open Tongue Translations

**Contributors:** tporret  
**Tags:** translation, multilingual, privacy, libretranslate, local  
**Requires at least:** 6.0  
**Tested up to:** 6.9  
**Requires PHP:** 8.2  
**Stable tag:** 0.6.0  
**License:** [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

> A privacy-first WordPress translation plugin. Core guarantee: no translation data ever leaves the host server.

---

## Description

Open Tongue Translations connects WordPress to a self-hosted [LibreTranslate](https://libretranslate.com/) instance — running on the same server, over a Unix socket, or on a private VPC network — so that every translation request stays entirely within your infrastructure.

**Zero external calls. Zero cloud dependencies. Zero data leakage.**

---

### How it works

The plugin intercepts translatable content at two independent layers:

1. **Gettext Interception** — hooks into WordPress's own translation pipeline (`gettext` filter) to translate strings that pass through `__()`, `_e()`, and related functions.
2. **Output Buffer Interception** — opens an output buffer on `template_redirect`, captures the full rendered HTML, extracts visible text nodes in a single batched API call, and re-injects the translated text before the response is sent.

Together these layers provide complete coverage: strings loaded from `.mo` files _and_ strings printed directly by themes or plugins.

---

### Persistence and caching

Translations are stored locally in a dedicated MySQL table (`{prefix}libre_translations`) and served through a two-level read-through cache:

- **L1 — Object Cache** (`wp_cache_*`) uses Redis, Memcached, APCu, or a per-request in-memory array on sites without a persistent drop-in. Batch lookups use a single `MGET` round-trip — never a loop.
- **L2 — Database** falls back to a single `SELECT … IN (…)` query when L1 misses. L2 hits are backfilled into L1 automatically.

Result: O(1) for cached strings. The translation API is only called on a cold miss.

Human-edited translations are protected by an `is_manual` flag. API re-translations can never overwrite a row where `is_manual = 1`, enforced atomically at the SQL level.

---

### Static-cache compatibility

When WP Rocket or Cloudflare caches a translated page as static HTML, the plugin automatically purges those caches whenever a translation changes:

- **WP Rocket** — calls `rocket_clean_post()` per-post or `rocket_clean_domain()` on a full locale flush.
- **Cloudflare** — supports both the Cloudflare WordPress Plugin (via action hooks) and direct API calls (via Zone ID + Bearer token credentials stored in options).

Both layers can be active simultaneously.

---

### Automatic maintenance

A WP-Cron job (`ltp_weekly`) prunes translation rows unused for more than 90 days (configurable via `ltp_prune_days`). Deletions are batched at 1,000 rows per query to avoid table locks. Human-edited rows are never pruned.

---

### Admin settings page

All plugin options are configurable via the **Open Tongue** top-level admin menu. The settings page is organised into five tabs:

| Tab | Contents |
|---|---|
| **Dashboard** | System health overview: DB tables, API reachability, L1/L2 cache status, WP-Cron schedule, Privacy Status badge |
| **Translation Engine** | `ltp_target_lang` select (populated from the live LibreTranslate `/languages` endpoint); `ltp_detect_browser_locale` checkbox |
| **Connectivity** | Radio selector for `ltp_connection_mode` (`localhost` / `socket` / `vpc`) with JS-toggled conditional fields; Privacy Guard banner |
| **Exclusions & Glossary** | Full CRUD for `{prefix}ott_exclusion_rules` — CSS, XPath, and Regex rule types; scope to global / post type / post ID |
| **Performance** | `ltp_prune_days`; WP Rocket and Cloudflare compat toggles with inactive-plugin notices; L1/L2 status cards |

A **Privacy Guard** banner is rendered below the page heading. It shows green for `localhost` / `socket` modes or any RFC 1918 VPC IP, and red for publicly-routable VPC addresses with a direct link to the Connectivity tab.

All forms use `settings_fields()` / `check_admin_referer()`. All output is escaped (`esc_html`, `esc_attr`). All inputs are sanitized via typed callbacks registered with `register_setting`.

---

### Language routing and browser detection

`OTT_Language_Service` fetches the list of supported languages from the configured LibreTranslate driver and caches the response for 24 hours in a WordPress transient (`ott_supported_langs`).

`OTT_Language_Router` resolves the effective locale for each request in priority order:

1. **Cookie** (`ott_user_lang`) — validated against the supported-languages list; invalid codes are ignored and cleared.
2. **`Accept-Language` header** — only evaluated when `ltp_detect_browser_locale` is `true`; the highest-quality browser locale that matches a supported LibreTranslate language wins.
3. **`ltp_target_lang` option** — global fallback; always present.

**Validation mode** (`ltp_validation_mode`): when enabled, translation is applied only for `manage_options` users. All other visitors receive the original untranslated site — ideal for previewing translations before making them public.

The `ott_user_lang` cookie is written with `HttpOnly => true`, `SameSite => Lax`, scoped to the WordPress site path. It is never readable by front-end JavaScript.

---

### Front-end language switcher

**Shortcode:** `[open_tongue_switcher]` — renders a `<select>` dropdown. Add `style="list"` for a `<ul class="ott-lang-list">` link list.

**Block:** `ott/language-switcher` — available in the Gutenberg block inserter under the **Open Tongue** category; renders via the same shortcode output.

When a visitor selects a language, a `fetch()` call posts to `POST /wp-json/ott/v1/set-lang`, the cookie is updated, and the page reloads. All future requests from that browser use the cookie value, bypassing header detection.

---

### Connection modes

| Mode | Description |
|---|---|
| `localhost` | HTTP to `127.0.0.1:5000` via `WP_Http`. Default. |
| `socket` | Raw cURL over a Unix domain socket (zero TCP overhead). |
| `vpc` | HTTP to a private RFC 1918 IP with optional Bearer token auth. |

The active mode is selected by the `ltp_connection_mode` WordPress option (`localhost` \| `socket` \| `vpc`).

---

### WP-CLI command suite

| Command | Key options | Description |
|---|---|---|
| `wp ott translate batch <locale>` | `--force`, `--dry-run`, `--post-type`, `--format` | Bulk-translate all posts |
| `wp ott translate post <ID> <locale>` | — | Translate one post, purge static cache |
| `wp ott translate string <text> <locale>` | — | Spot-test; never persists |
| `wp ott cache warm` | `--locale` | Pre-populate object cache from DB |
| `wp ott cache flush` | `--locale`, `--yes` | Flush one or all locales |
| `wp ott cache status` | `--format` | Show counts, backend, last prune |
| `wp ott glossary import <file>` | `--overwrite` | Stream CSV into DB |
| `wp ott glossary export <file>` | `--protected-only` | Stream DB to CSV |
| `wp ott glossary list` | `--format` | Tabular glossary listing |
| `wp ott status` | `--verbose` | Health check; exits 1 on failure |

---

### HTML-aware translation pipeline

Before text reaches the LibreTranslate API it passes through a three-stage protection pipeline:

1. **AttributePreserver** — replaces `href`, `src`, `srcset`, `action`, `formaction`, `poster`, `cite`, and `data-*` attribute values with `[[OTT_ATTR_n]]` tokens so URL-like values are never mangled.
2. **TagProtector** — replaces every HTML tag with `[[OTT_TAG_n]]` tokens. Prefers `DOMDocument` when `dom` and `mbstring` are available; falls back to an attribute-aware regex otherwise.
3. **HtmlAwareTranslator** — orchestrates the 9-step pipeline: `shouldExclude → maskExcluded → attrPreserver.protect → tagProtector.protect → ott_pre_translate → API call → tagProtector.restore → attrPreserver.restore → unmaskExcluded`. Falls back to the original text if tag counts do not match after restore.

---

### Exclusion rules engine

| Rule type | Example value | How it works |
|---|---|---|
| `css_selector` | `.no-translate` | Converted to XPath internally; matched nodes are masked before the API call |
| `regex` | `/\bACME Corp\b/` | Applied as a text-node fast-path check via `preg_match()` |
| `xpath` | `//code\|//pre` | Evaluated directly on the DOMDocument tree |

Rules are stored in `{prefix}ott_exclusion_rules` and can be scoped to `global`, a specific `post_type`, or an individual `post_id`. The `ott_exclusion_rules` filter lets developers inject programmatic rules without a database entry.

---

### Privacy

This plugin makes no outbound connections to any third-party service. All translation traffic is routed exclusively to the LibreTranslate endpoint you configure, which must resolve to a loopback address, a Unix socket, or an RFC 1918 private IP. The VPC driver actively refuses requests to any public IP address and logs the attempt.

---

## Installation

1. Upload the `open-tongue-translations` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Ensure a LibreTranslate instance is running and reachable from the WordPress host.
4. Configure the plugin via the **Open Tongue** settings page in the WordPress admin, or use WP-CLI:

**Localhost mode (default)**

```bash
wp option update ltp_connection_mode localhost
wp option update ltp_localhost_host   127.0.0.1
wp option update ltp_localhost_port   5000
wp option update ltp_target_lang      fr
```

**Unix socket mode**

```bash
wp option update ltp_connection_mode socket
wp option update ltp_socket_path      /run/libretranslate/libretranslate.sock
wp option update ltp_target_lang      de
```

**Private VPC mode**

```bash
wp option update ltp_connection_mode vpc
wp option update ltp_vpc_ip           10.0.1.50
wp option update ltp_vpc_port         5000
wp option update ltp_vpc_api_key      your-secret-key   # optional
wp option update ltp_target_lang      es
```

**Optional tuning**

```bash
# Days before unused translations are pruned (default: 90)
wp option update ltp_prune_days 60

# Enable browser locale auto-detection
wp option update ltp_detect_browser_locale 1

# Enable validation mode (admin-only preview)
wp option update ltp_validation_mode 1

# Cloudflare direct-API credentials (Mode B — skip if using the CF plugin)
wp option update ltp_cf_zone_id    your-zone-id
wp option update ltp_cf_api_token  your-api-token
```

---

## Frequently Asked Questions

**Does this plugin send data to any external server?**  
No. All translation requests are routed to the LibreTranslate instance you configure. The VPC driver enforces RFC 1918 validation at the code level and will refuse — and log — any attempt to contact a public IP.

**Which LibreTranslate version is required?**  
Any LibreTranslate release that exposes `POST /translate` returning `{ "translatedText": "..." }` is compatible. Tested against LibreTranslate 1.6+.

**Can I exclude specific text domains from translation?**  
Yes. Add domain names to the `ltp_bypass_domains` option (an array). Example:

```bash
wp option update ltp_bypass_domains '["default","woocommerce"]' --format=json
```

**Why is my admin dashboard not being translated?**  
By design. The Output Buffer Interceptor only runs on front-end page requests (`is_admin() === false` and `wp_doing_ajax() === false`). The Gettext Interceptor also fires on admin screens — add `'default'` to `ltp_bypass_domains` if you want to suppress that.

**Can I configure the plugin without WP-CLI?**  
Yes. All settings are available through the **Open Tongue** admin menu. The settings page provides tabbed controls for language selection, connection mode, exclusion rules, and performance tuning.

**What is Validation Mode?**  
When `ltp_validation_mode` is enabled, translation only applies to admin users (`manage_options`). All other visitors receive the original untranslated site. Use this to preview translations before making them live.

**What is the `ott_user_lang` cookie?**  
When a visitor selects a language via the `[open_tongue_switcher]` shortcode or the `ott/language-switcher` block, their preference is stored in an `HttpOnly`, `SameSite=Lax` cookie named `ott_user_lang`. This overrides both the `Accept-Language` header and the global `ltp_target_lang` option for all subsequent requests from that browser.

**How do I add a language switcher to my site?**  
Use the `[open_tongue_switcher]` shortcode in any post, page, or widget area. For a link list instead of a dropdown, use `[open_tongue_switcher style="list"]`. The block is also available in the Gutenberg block inserter under the **Open Tongue** category.

**What happens if the LibreTranslate backend is unavailable?**  
All three drivers are designed to fail gracefully. On any connection error, HTTP non-200, or malformed response the original text is returned unchanged and the error is written to the PHP error log. Page rendering is never blocked or broken.

**How does the translation cache work?**  
Translations are cached in two layers. L1 is the WordPress Object Cache (Redis / Memcached / in-process array). L2 is the plugin's own MySQL table. On a page load, all strings needed for that page are resolved in a single batch read from L1, with L2 filling any gaps in one `SELECT … IN (…)` query. The LibreTranslate API is only called for strings not found in either layer.

**Can I pre-warm the cache for a language?**  
Yes. Run `wp ott cache warm --locale=<locale>` to pre-populate the object cache from the database in a single batch query. Omit `--locale` to warm all known locales in sequence.

**Are human-edited translations protected from being overwritten?**  
Yes. Any row with `is_manual = 1` will never have its `translated_text` replaced by the machine API. This protection is enforced inside the SQL `INSERT … ON DUPLICATE KEY UPDATE` statement — not in application logic — so it cannot be bypassed by a concurrent request.

**Does the plugin work with WP Rocket or Cloudflare?**  
Yes. When WP Rocket is active the plugin purges the static cache for affected posts whenever a translation is updated, and purges the entire domain on a full locale flush. For Cloudflare, the plugin supports both the official Cloudflare WordPress Plugin (via action hooks) and direct API calls using a Zone ID and Bearer token stored in options.

---

## Architecture Notes

### Task 1 — Dual-Layer Interception

#### Why `UnixSocketClient` uses raw cURL instead of `WP_Http`

`WP_Http` provides no mechanism to set `CURLOPT_UNIX_SOCKET_PATH`. The only reliable way to bind a socket file path is to call `curl_setopt_array()` directly. The URL (`http://localhost/translate`) is protocol framing only — cURL ignores DNS resolution when a Unix socket path is set.

#### Infinite-recursion risk in `GettextInterceptor`

WordPress fires the `gettext` filter for every translated string, including strings generated deep inside the HTTP client stack. Without protection, calling `translate()` inside the filter triggers more `gettext` calls → stack overflow. The `private bool $processing` flag is set to `true` at the start of each API call and released in a `finally` block, breaking the cycle.

#### Task 1 edge cases

- **Batch separator collision** — `OutputBufferInterceptor` joins text nodes with a control-character separator. If LibreTranslate strips it, the `explode()` produces fewer parts than were sent. Switching to the LibreTranslate array-batch API eliminates this risk. A `TODO` marks the location.
- **Double-translation** — strings passing through `__()` _and_ appearing in the final HTML are translated by both interceptors. Consider disabling one layer per deployment or adding an in-memory dedup cache.
- **`PrivateVpcClient` and IPv6** — `isPrivateIp()` validates IPv4 only. IPv6 ULA addresses (`fc00::/7`) are silently refused. Add RFC 4193 ULA validation before deploying on IPv6 or dual-stack VPCs.
- **Fresh install missing `ltp_connection_mode`** — The activation hook seeds the schema, but if the option is missing at runtime `ConnectionFactory` throws `ConnectionException` and disables the plugin silently. Seed `ltp_connection_mode` with `'localhost'` on activation.
- **Output buffer and fatal errors** — If a fatal error or another plugin calls `ob_end_clean()` before WordPress's shutdown, the output buffer callback is never invoked and the page is served untranslated without any logged error.

---

### Task 2 — Persistence, Caching and Static-Cache Compatibility

#### Version-counter flush strategy in `ObjectCacheDriver`

Every cache key embeds a per-locale version integer: `"{$locale}:{$version}:{$hash}"`. `flushLocale()` increments this counter with `wp_cache_set()`. The next read computes a key with the new version number — instant logical invalidation in O(1), zero key scanning.

`wp_cache_flush()` is deliberately not used — on a shared Redis instance it issues `FLUSHDB` and wipes all keys for the entire WordPress site, causing a catastrophic cache stampede.

#### Why `upsertBatch()` chunks at 500 rows

MySQL's `max_allowed_packet` can be as low as 1 MB on shared hosting. Since `source_text` and `translated_text` are `MEDIUMTEXT`, a single row can be several KB. `500 rows × ~4 KB ≈ 2 MB` — safely within even the most conservative server settings.

#### `ON DUPLICATE KEY UPDATE … IF(is_manual = 1, …)` guard

```sql
translated_text = IF(is_manual = 1, translated_text, VALUES(translated_text))
```

MySQL evaluates the existing `is_manual` flag atomically before deciding what to write. The human translation is preserved with zero application-level locking or race conditions.

#### WordPress actions/filters — complete list

| Hook | Priority | Registered by |
|---|---|---|
| `plugins_loaded` | 10 | `Plugin::boot()` — language service, router, switcher |
| `plugins_loaded` | 99 | Static-cache compat registration |
| `admin_menu` | — | `OTT_Admin_Settings` — top-level **Open Tongue** menu |
| `admin_init` | — | Settings groups (`ott_engine`, `ott_connectivity`, `ott_performance`) |
| `init` | 1 | `OTT_Language_Router::handleAutoDetect()` — parses `Accept-Language`, writes cookie |
| `init` | 5 | Interceptors wired when `OTT_Language_Router::isTranslationAllowed()` is `true` |
| `rest_api_init` | — | `POST /ott/v1/set-lang` (language switcher); `GET+POST /ott/v1/batch/*` (batch REST) |
| `cron_schedules` | — | Adds `ltp_weekly` (604,800 s) |
| `ltp_prune_translations` | — | `PruningJob::run()` |
| `gettext` | 10 | `GettextInterceptor::intercept()` |
| `template_redirect` | 1 | `OutputBufferInterceptor::startBuffer()` |
| `ltp_translation_updated` | — | Fired by `StaticCacheCompatManager::purgePost()`; hooked by `WpRocketCompat` + `CloudflareCompat` at priority 20 |
| `ltp_content_updated` | — | Fired by `StaticCacheCompatManager::purgeUrl()`; hooked by both compat classes at priority 20 |
| `ltp_locale_flushed` | — | Fired by `CacheManager::flushLocale()`; hooked by both compat classes at priority 20 |
| `cloudflare_purge_by_url` | — | Fired by `CloudflareCompat` (Mode A, single URL) |
| `cloudflare_purge_everything` | — | Fired by `CloudflareCompat` (Mode A, full purge) |

---

### Task 4 — Developer & Language Expert Features

#### Why `TagProtector` prefers `DOMDocument` over regex

HTML is not a regular language. A pure regex cannot reliably handle `>` inside quoted attribute values, CDATA sections, or nested SVG/MathML. `DOMDocument` parses into a proper tree and serialises with `saveHTML($node)` — not `saveHTML()` on the document — to avoid injecting `<!DOCTYPE>`, `<html>`, and `<body>` wrappers. The regex fallback is retained for environments without the `dom` or `mbstring` PHP extension.

#### Sandboxed regex validation

`ExclusionRuleValidator::validateRegex()` tests arbitrary user-supplied patterns safely:

1. `set_error_handler()` — captures `E_WARNING` from `preg_match()` into a local variable.
2. `@preg_match($pattern, '')` — secondary `@` guard.
3. `finally { restore_error_handler(); }` — **always** restores the original handler, even on exception.

#### `ExclusionEngine` and `maskExcluded()`

`maskExcluded()` wraps the input HTML in `<ott-root>…</ott-root>` before calling `DOMDocument::loadHTML()`, then extracts only the children of `<ott-root>` via `saveHTML($child)`. This avoids the `<html><head><body>` wrapper `loadHTML()` injects on fragments without relying on `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD` (unreliable across libxml versions).

Token namespaces are distinct — `OTT_TAG_`, `OTT_ATTR_`, `OTT_EXCL_`, `OTT_TERM_` — so `unmaskExcluded()` can safely `str_replace()` without collisions.

#### Additional filter hooks (Task 4)

| Filter | Applied where | Purpose |
|---|---|---|
| `ott_pre_translate` | Inside `HtmlAwareTranslator`, before API call | Inject glossary substitutions or normalise terminology |
| `ott_post_translate` | After `tagProtector.restore` + `attrPreserver.restore` | Post-processing or quality checks |
| `ott_cli_batch_query_args` | `TranslateCommand::batch()` WP_Query args | Inject `post_status`, `post_type`, or date ranges |
| `ott_exclusion_rules` | `ExclusionRuleRepository::findAll()` | Inject programmatic rules without a database row |

#### Database tables — complete list

| Table | Created by | Purpose |
|---|---|---|
| `{prefix}libre_translations` | `Schema::createOrUpgrade()` (activation) | Translation cache: source hash → translated text, locale, `is_manual`, `last_used` |
| `{prefix}ott_exclusion_rules` | `Migration_1_2_0::up()` | HTML exclusion rules: type, value, scope, active flag, `created_by` |
| `{prefix}ott_integrity_log` | `Migration_1_3_0::up()` | Token-mismatch events: source hash, expected/found token counts, request URL, timestamp |

#### Task 4 edge cases

- **Token survival through the translation engine** — LibreTranslate may add spaces inside tokens (`[[ OTT_TAG_0 ]]`). `TagProtector::restore()` does an exact `str_replace()` and falls back to original HTML on count mismatch. Switching to the array-batch API eliminates this risk entirely.
- **CSS selector scope** — `CssToXPath::convert()` supports seven selector patterns. Descendant combinators, sibling combinators, pseudo-classes, and attribute substring matchers are unsupported. Attempting to store such a selector returns a `WP_Error` and the rule is not saved.
- **WP-CLI and the full DI graph** — Commands are registered inside `Plugin::boot()` after the full object graph has been constructed. If `ConnectionException` is thrown during boot, CLI commands are silently not registered. Run `wp ott status` first to diagnose connection issues.

---

### Task 5 — Admin Settings UI

#### Tab rendering without a full page reload

The five tabs use the standard `?page=open-tongue&tab=<slug>` query-string pattern. Each tab form posts to `options.php` (WordPress Settings API) or back to the settings page for CRUD actions. Dependency-free and compatible with all caching plugins.

#### Privacy Guard resolution logic

`OTT_Admin_Settings::renderPrivacyGuard()` reads `ltp_connection_mode`. For `localhost` and `socket` it always shows green. For `vpc` it calls `filter_var($ip, FILTER_VALIDATE_IP)` and checks RFC 1918 ranges (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) and loopback (`127.0.0.0/8`). Any IP that fails renders a red banner with a direct link to the Connectivity tab.

#### Dashboard health checks

The Dashboard tab replicates `wp ott status --verbose` in the browser. It calls `wp_remote_get($languagesUrl)` with a 3-second timeout for API reachability, reads `wp_using_ext_object_cache()` for L1 status, `$wpdb->get_var()` for L2 row count, and `wp_next_scheduled('ltp_prune_translations')` for the cron check. No transients or cron jobs are created; all checks run inline.

---

### Task 6 — Core Language Routing & Selection

#### Language resolution priority chain

`OTT_Language_Router::getEffectiveLang()` evaluates three sources in order:

1. **Cookie** (`ott_user_lang`) — validated against supported-languages list; unknown codes ignored and cleared.
2. **`Accept-Language` header** — only when `ltp_detect_browser_locale` is `true`; primary subtag (e.g. `fr` from `fr-CA`) matched against LibreTranslate language codes; highest-quality match wins.
3. **`ltp_target_lang` option** — global fallback; always present.

#### Cookie security

The `ott_user_lang` cookie is written with `HttpOnly => true`, `SameSite => Lax`, and `Path` scoped to the WordPress site URL path. Never output via JavaScript. The `POST /ott/v1/set-lang` REST endpoint validates the supplied code before writing, rejecting unknown codes with a `400` response.

#### Transient cache for supported languages

`OTT_Language_Service` stores the `/languages` response under `ott_supported_langs` for 86,400 seconds (24 hours). An in-process static cache means repeat calls within the same PHP request never hit the transient store. `bustCache()` deletes the transient and clears the in-process copy; the Connectivity settings form calls it after saving new connection settings.

---

## Changelog

### 0.7.0
- `OTT_Manual_Edits_Table`: `WP_List_Table` for `is_manual = 1` rows — search, sort, paginate, inline `<textarea>` edit, "Revert to Machine", Delete, bulk actions; all queries via `$wpdb->prepare()`.
- `OTT_Integrity_Monitor_Table`: `WP_List_Table` for `{prefix}ott_integrity_log` — coloured Token Delta column, request URL links, bulk/single delete; graceful no-op if migration hasn't run.
- `OTT_Batch_REST_Controller`: `POST /ott/v1/batch/start` + `GET /ott/v1/batch/status`; WP-Cron chunk processor (50 rows/tick); job state in transients; `manage_options` guard.
- `assets/js/ott-admin-batch.js`: polls `/batch/status` every 2.5 s; animated progress bar with %, done/failed counters, human ETA; auto-resumes if a job is mid-flight on page reload.
- `TagProtector::restore()`: token mismatches now written to `{prefix}ott_integrity_log` via `$wpdb->insert()` instead of `error_log()`.
- `Migration_1_3_0`: creates `{prefix}ott_integrity_log` table.
- Two new admin tabs: **Manual Edits** and **Integrity Monitor**.

### 0.6.0
- `OTT_Language_Service`: fetches supported languages from the configured LibreTranslate driver; 24-hour transient cache with in-process memoisation; `bustCache()` on connectivity settings save.
- `OTT_Language_Router`: singleton resolving effective language via cookie → `Accept-Language` → global option. Enforces `ltp_validation_mode`. Writes `HttpOnly` + `SameSite=Lax` `ott_user_lang` cookie. Registers `POST /ott/v1/set-lang`.
- `OTT_Language_Switcher`: `[open_tongue_switcher style="select|list"]` shortcode; `ott/language-switcher` Gutenberg block. Inline `fetch()`-based JS calls `set-lang` and reloads on language change.
- Interceptors now registered at `init` priority 5 (was `plugins_loaded`) so language resolution and validation-mode checks complete first.
- New options: `ltp_detect_browser_locale` (bool), `ltp_validation_mode` (bool).

### 0.5.0
- `OTT_Admin_Settings`: singleton; top-level **Open Tongue** admin menu (`dashicons-translation`); five-tab settings page (Dashboard, Translation Engine, Connectivity, Exclusions & Glossary, Performance).
- All forms use `settings_fields()` / `check_admin_referer()`; all output escaped; all inputs sanitized via typed callbacks.

### 0.4.0
- WP-CLI suite: `wp ott translate` (batch/post/string), `wp ott cache` (warm/flush/status), `wp ott glossary` (import/export/list), `wp ott status`.
- `HtmlAwareTranslator` decorator: full HTML-protection pipeline around every API call.
- `TagProtector`, `AttributePreserver`, `ExclusionEngine`, `ExclusionRuleRepository`, `ExclusionRuleValidator`.
- `Migration_1_2_0`: creates `{prefix}ott_exclusion_rules`.
- New filters: `ott_pre_translate`, `ott_post_translate`, `ott_cli_batch_query_args`, `ott_exclusion_rules`.

### 0.3.0
- `Schema`: removed `ON UPDATE CURRENT_TIMESTAMP`; schema bumped to `1.0.1` with gated `ALTER TABLE`.
- `CacheManager::warmLocale()`: OOM guard (< 20 % free headroom aborts warm-up).
- `StaticCacheCompatManager::purgeUrl()`: new `ltp_content_updated` action for non-post content.
- `WpRocketCompat` + `CloudflareCompat`: handle `ltp_content_updated`.

### 0.2.0
- Persistence layer: `{prefix}libre_translations` with full schema versioning.
- Two-level read-through cache: L1 Object Cache → L2 Database.
- Human-edit protection: `is_manual = 1` guard in SQL.
- Static-cache compat: WP Rocket + Cloudflare.
- WP-Cron pruning job (`ltp_weekly`).
- `CacheManager::warmLocale()`.

### 0.1.0
- Initial scaffold: local-first architecture, dual-layer interception.
- `LocalhostRestClient`, `UnixSocketClient`, `PrivateVpcClient`, `ConnectionFactory`.
- `GettextInterceptor` (with re-entrancy guard), `OutputBufferInterceptor`.

---

## Upgrade Notices

**0.7.0** — Runs `Migration_1_3_0` automatically on the next page load to create `{prefix}ott_integrity_log`. No manual steps required.

**0.6.0** — No database changes. `ott_user_lang` cookie is written automatically when browser-locale detection is enabled. Existing `ltp_target_lang` installs are unaffected.

**0.5.0** — No database changes. The admin settings page is available immediately after upgrade at **Open Tongue** in the WordPress admin menu. Existing WP-CLI-configured options are read and displayed correctly.

**0.4.0** — Runs `Migration_1_2_0` automatically on the next page load to create `{prefix}ott_exclusion_rules`. WP-CLI commands available immediately.

**0.3.0** — Schema bumped to `1.0.1`. The `ALTER TABLE` runs once automatically on the next page load. No manual steps required.

**0.2.0** — `{prefix}libre_translations` table created automatically on activation or next page load. No manual steps required.

**0.1.0** — Initial release. No upgrade steps required.
