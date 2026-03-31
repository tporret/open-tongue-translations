=== Open Tongue Translations ===
Contributors:      tporret
Tags:              translation, multilingual, privacy, libretranslate, local
Requires at least: 6.0
Tested up to:      6.9
Requires PHP:      8.2
Stable tag:        0.4.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A privacy-first WordPress translation plugin. Core guarantee: no translation data ever leaves the host server.

== Description ==

Open Tongue Translations connects WordPress to a self-hosted [LibreTranslate](https://libretranslate.com/) instance — running on the same server, over a Unix socket, or on a private VPC network — so that every translation request stays entirely within your infrastructure.

**Zero external calls. Zero cloud dependencies. Zero data leakage.**

= How it works =

The plugin intercepts translatable content at two independent layers:

1. **Gettext Interception** — hooks into WordPress's own translation pipeline (`gettext` filter) to translate strings that pass through `__()`, `_e()`, and related functions.
2. **Output Buffer Interception** — opens an output buffer on `template_redirect`, captures the full rendered HTML, extracts visible text nodes in a single batched API call, and re-injects the translated text before the response is sent.

Together these layers provide complete coverage: strings loaded from `.mo` files *and* strings printed directly by themes or plugins.

= Persistence and caching =

Translations are stored locally in a dedicated MySQL table (`{prefix}libre_translations`) and served through a two-level read-through cache:

* **L1 — Object Cache** (`wp_cache_*`) uses Redis, Memcached, APCu, or a per-request in-memory array on sites without a persistent drop-in. Batch lookups use a single `MGET` round-trip — never a loop.
* **L2 — Database** falls back to a single `SELECT … IN (…)` query when L1 misses. L2 hits are backfilled into L1 automatically.

Result: O(1) for cached strings. The translation API is only called on a cold miss.

Human-edited translations are protected by an `is_manual` flag. API re-translations can never overwrite a row where `is_manual = 1`, enforced atomically at the SQL level.

= Static-cache compatibility =

When WP Rocket or Cloudflare caches a translated page as static HTML, the plugin automatically purges those caches whenever a translation changes:

* **WP Rocket** — calls `rocket_clean_post()` per-post or `rocket_clean_domain()` on a full locale flush.
* **Cloudflare** — supports both the Cloudflare WordPress Plugin (via action hooks) and direct API calls (via Zone ID + Bearer token credentials stored in options).

Both layers can be active simultaneously.

= Automatic maintenance =

A WP-Cron job (`ltp_weekly`) prunes translation rows unused for more than 90 days (configurable via `ltp_prune_days`). Deletions are batched at 1 000 rows per query to avoid table locks. Human-edited rows are never pruned.

= Connection modes =

| Mode | Description |
| --- | --- |
| `localhost` | HTTP to `127.0.0.1:5000` via `WP_Http`. Default. |
| `socket` | Raw cURL over a Unix domain socket (zero TCP overhead). |
| `vpc` | HTTP to a private RFC 1918 IP with optional Bearer token auth. |

The active mode is selected by the `ltp_connection_mode` WordPress option (`localhost` \| `socket` \| `vpc`).

= WP-CLI command suite =

Four command groups are registered under the `ott` namespace:

* `wp ott translate batch <locale>` — translate all published posts in bulk, with `--force` to re-translate manually-edited rows and `--dry-run` to preview without writing.
* `wp ott translate post <ID> <locale>` — translate a single post and immediately purge its static cache entry.
* `wp ott translate string <text> <locale>` — spot-test a single string against the live API (never persists, always shows cache hit/miss and elapsed ms).
* `wp ott cache warm [--locale=<locale>]` — pre-populate the object cache from the database in one batch query.
* `wp ott cache flush [--locale=<locale>] [--yes]` — flush one or all locales (prompts for confirmation unless `--yes` is passed).
* `wp ott cache status [--format=<format>]` — show backend type, per-locale row counts, and last prune time.
* `wp ott glossary import <file> [--overwrite]` — stream a CSV (source_term, target_term, is_protected, case_sensitive) without loading it into memory.
* `wp ott glossary export <file> [--protected-only]` — write a CSV; streams line-by-line via `fputcsv()`, never builds the full string in RAM.
* `wp ott glossary list [--format=<format>]` — tabular output of all glossary entries.
* `wp ott status [--verbose]` — health check across DB tables, API endpoint, object cache, cron schedule, URL strategy, and active compat plugins. Exits with code 1 if any check fails (CI-friendly).

= HTML-aware translation pipeline =

Before text reaches the LibreTranslate API it passes through a three-stage protection pipeline:

1. **AttributePreserver** — replaces `href`, `src`, `srcset`, `action`, `formaction`, `poster`, `cite`, and `data-*` attribute values with `[[OTT_ATTR_n]]` tokens so URL-like values are never mangled by the translation engine.
2. **TagProtector** — replaces every HTML tag (including those with `>` inside quoted attribute values) with `[[OTT_TAG_n]]` tokens. Prefers `DOMDocument` when the `dom` **and** `mbstring` PHP extensions are available; falls back to an attribute-aware regex otherwise.
3. **HtmlAwareTranslator** — orchestrates the 9-step pipeline: `shouldExclude → maskExcluded → attrPreserver.protect → tagProtector.protect → ott_pre_translate filter → API call → tagProtector.restore → attrPreserver.restore → unmaskExcluded`. Falls back to the original text if tag counts do not match after restore.

= Exclusion rules engine =

Site administrators and developers can define rules that prevent specific DOM regions from being translated:

| Rule type | Example value | How it works |
| --- | --- | --- |
| `css_selector` | `.no-translate` | Converted to XPath internally; matched nodes are masked before the API call |
| `regex` | `/\bACME Corp\b/` | Applied as a text-node fast-path check via `preg_match()` |
| `xpath` | `//code\|//pre` | Evaluated directly on the DOMDocument tree |

Rules are stored in `{prefix}ott_exclusion_rules` and can be scoped to `global`, a specific `post_type`, or an individual `post_id`. The `ott_exclusion_rules` filter lets developers inject programmatic rules without a database entry.

All rule values are validated before storage: CSS selectors are round-tripped through the internal `CssToXPath` converter and evaluated on an empty `DOMXPath`; regex patterns are tested inside a sandboxed `set_error_handler` that is **always** restored in a `finally` block.

= Privacy =

This plugin makes no outbound connections to any third-party service. All translation traffic is routed exclusively to the LibreTranslate endpoint you configure, which must resolve to a loopback address, a Unix socket, or an RFC 1918 private IP. The VPC driver actively refuses requests to any public IP address and logs the attempt.

== Installation ==

1. Upload the `open-tongue-translations` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Ensure a LibreTranslate instance is running and reachable from the WordPress host.
4. Set the following WordPress options (via WP-CLI or a later settings UI):

**Localhost mode (default)**

    wp option update ltp_connection_mode localhost
    wp option update ltp_localhost_host   127.0.0.1
    wp option update ltp_localhost_port   5000
    wp option update ltp_target_lang      fr

**Unix socket mode**

    wp option update ltp_connection_mode socket
    wp option update ltp_socket_path      /run/libretranslate/libretranslate.sock
    wp option update ltp_target_lang      de

**Private VPC mode**

    wp option update ltp_connection_mode vpc
    wp option update ltp_vpc_ip           10.0.1.50
    wp option update ltp_vpc_port         5000
    wp option update ltp_vpc_api_key      your-secret-key   # optional
    wp option update ltp_target_lang      es

**Optional tuning**

    # Days before unused translations are pruned (default: 90)
    wp option update ltp_prune_days 60

    # Cloudflare direct-API credentials (Mode B — skip if using the CF plugin)
    wp option update ltp_cf_zone_id    your-zone-id
    wp option update ltp_cf_api_token  your-api-token

== Frequently Asked Questions ==

= Does this plugin send data to any external server? =

No. All translation requests are routed to the LibreTranslate instance you configure. The VPC driver enforces RFC 1918 validation at the code level and will refuse — and log — any attempt to contact a public IP.

= Which LibreTranslate version is required? =

Any LibreTranslate release that exposes `POST /translate` returning `{ "translatedText": "..." }` is compatible. Tested against LibreTranslate 1.6+.

= Can I exclude specific text domains from translation? =

Yes. Add domain names to the `ltp_bypass_domains` option (an array). Example via WP-CLI:

    wp option update ltp_bypass_domains '["default","woocommerce"]' --format=json

= Why is my admin dashboard not being translated? =

By design. The Output Buffer Interceptor only runs on front-end page requests (`is_admin() === false` and `wp_doing_ajax() === false`). The Gettext Interceptor also fires on admin screens — add `'default'` to `ltp_bypass_domains` if you want to suppress that.

= What happens if the LibreTranslate backend is unavailable? =

All three drivers are designed to fail gracefully. On any connection error, HTTP non-200, or malformed response the original text is returned unchanged and the error is written to the PHP error log. Page rendering is never blocked or broken.

= How does the translation cache work? =

Translations are cached in two layers. L1 is the WordPress Object Cache (Redis / Memcached / in-process array). L2 is the plugin's own MySQL table. On a page load, all strings needed for that page are resolved in a single batch read from L1, with L2 filling any gaps in one `SELECT … IN (…)` query. The LibreTranslate API is only called for strings not found in either layer.

= Can I pre-warm the cache for a language? =

Yes. Run `wp ott cache warm --locale=<locale>` to pre-populate the object cache from the database in a single batch query. Omit `--locale` to warm all known locales in sequence. The command reports how many rows were loaded and the elapsed time.

= Are human-edited translations protected from being overwritten? =

Yes. Any row in the database with `is_manual = 1` will never have its `translated_text` replaced by the machine API, even if the same source string passes through the translation pipeline again. This protection is enforced inside the SQL `INSERT … ON DUPLICATE KEY UPDATE` statement — not in application logic — so it cannot be bypassed by a concurrent request.

= Does the plugin work with WP Rocket or Cloudflare? =

Yes. When WP Rocket is active, the plugin purges the static cache for the affected post whenever a translation is updated, and purges the entire domain when a full locale is flushed. For Cloudflare, the plugin supports both the official Cloudflare WordPress Plugin (via action hooks) and direct API calls to the Cloudflare API using a Zone ID and Bearer token stored in options.

== Architecture Notes ==

=== Task 1 — Dual-Layer Interception ===

==== Why UnixSocketClient uses raw cURL instead of WP_Http ====

`WP_Http` wraps either PHP's cURL extension or its stream transport, but it provides no mechanism to set `CURLOPT_UNIX_SOCKET_PATH`. Even when WP uses cURL internally, the transport class does not expose that option to callers. The only reliable way to bind a socket file path is to call `curl_setopt_array()` directly, which is why `UnixSocketClient` bypasses `WP_Http` entirely. The URL (`http://localhost/translate`) is protocol framing only — cURL ignores DNS resolution when a Unix socket path is set, so no network I/O occurs.

==== Infinite-recursion risk in GettextInterceptor ====

WordPress fires the `gettext` filter for **every** translated string, including strings generated deep inside the HTTP client stack (`WP_Http`, `wp_remote_post` logging, error messages, etc.). Without protection, calling `translate()` inside the filter callback triggers more `gettext` calls which trigger more `translate()` calls, ending in a stack overflow. The `private bool $processing` flag is set to `true` at the start of each API call and released in a `finally` block. Any re-entrant invocation detects `$processing === true` and returns immediately, breaking the cycle.

==== Task 1 edge cases ====

* **Batch separator collision** — `OutputBufferInterceptor` joins text nodes with a control-character separator before the API call. If LibreTranslate normalises or strips that separator, the response `explode()` produces fewer parts than were sent and some strings silently fall back to originals. Switching to the LibreTranslate array-batch API eliminates this risk. A `TODO` comment marks the location in `OutputBufferInterceptor`.
* **Double-translation** — strings that pass through `__()` *and* appear in the final HTML are translated by both interceptors. Consider disabling one layer per deployment or adding an in-memory cache keyed on `source + targetLang`.
* **PrivateVpcClient and IPv6** — `isPrivateIp()` validates IPv4 only. IPv6 ULA addresses (`fc00::/7`) are silently refused. Add RFC 4193 ULA validation before deploying on IPv6-only or dual-stack VPCs.
* **Fresh install — missing `ltp_connection_mode` option** — The activation hook now seeds the schema, but if the option is missing at runtime the `ConnectionFactory` will throw `ConnectionException` and disable the plugin silently. Seed `ltp_connection_mode` with `'localhost'` on activation.
* **Output buffer and fatal errors** — If a fatal error or another plugin calls `ob_end_clean()` before WordPress's shutdown, the output buffer callback is never invoked and the page is served untranslated without any logged error. Safe but silent — note in your monitoring runbook.

=== Task 2 — Persistence, Caching and Static-Cache Compatibility ===

==== Version-counter flush strategy in ObjectCacheDriver ====

Every cache key embeds a per-locale version integer: `"{$locale}:{$version}:{$hash}"`. The version is stored as its own cache entry at `"ltp_ver_{$locale}"`. `flushLocale()` simply increments this counter with `wp_cache_set()`. The next read computes a key with the new version number, which has no matching entry — instant logical invalidation in O(1), zero key scanning.

**Why not `wp_cache_flush()`?** On a shared Redis instance, `wp_cache_flush()` issues `FLUSHDB` and wipes *all* keys for the entire WordPress site: sessions, query cache, transients, and every other plugin's data. This causes a catastrophic cache stampede. The version-counter isolates invalidation to the translation namespace with zero collateral damage.

==== Why upsertBatch() chunks at 500 rows ====

MySQL's `max_allowed_packet` controls the maximum network packet size. Its default is 64 MB in MySQL 8.0 but as low as 1 MB on shared hosting. Since `translated_text` and `source_text` are `MEDIUMTEXT` columns (up to 16 MB each), a single row can be several KB for real-world page content. `500 rows × ~4 KB ≈ 2 MB` — safely within even the most conservative server settings. Using 1 000 rows would routinely exceed this limit on hosts with large content blocks.

==== ON DUPLICATE KEY UPDATE … IF(is_manual = 1, …) guard ====

Scenario: a human translator edits a row and sets `is_manual = 1`. Later the API returns a different (lower-quality) translation for the same string. Without the guard, `upsert()` would silently overwrite the human translation. With the guard:

    translated_text = IF(is_manual = 1, translated_text, VALUES(translated_text))

MySQL evaluates the existing `is_manual` flag inside the same atomic statement before deciding what to write. The human translation is preserved with zero application-level locking or race conditions.

==== WordPress actions/filters — complete list ====

* `plugins_loaded` (priority 10) — plugin boot; (priority 99) — static-cache compat registration.
* `cron_schedules` — adds the `ltp_weekly` schedule (604 800 s interval).
* `ltp_prune_translations` — `PruningJob::run()` executes a deletion batch.
* `gettext` (priority 10) — `GettextInterceptor::intercept()`.
* `template_redirect` (priority 1) — `OutputBufferInterceptor::startBuffer()`.
* `ltp_translation_updated` — **fired** by `StaticCacheCompatManager::purgePost()`; hooked by `WpRocketCompat` and `CloudflareCompat` at priority 20.
* `ltp_content_updated` — **fired** by `StaticCacheCompatManager::purgeUrl()`; hooked by `WpRocketCompat` (`rocket_clean_files`) and `CloudflareCompat` at priority 20. Use for non-post content (widgets, nav menus, template parts).
* `ltp_locale_flushed` — **fired** by `CacheManager::flushLocale()`; hooked by `WpRocketCompat` and `CloudflareCompat` at priority 20.
* `cloudflare_purge_by_url` — **fired** by `CloudflareCompat` (Mode A, single URL).
* `cloudflare_purge_everything` — **fired** by `CloudflareCompat` (Mode A, full purge).

=== Task 4 — Developer & Language Expert Features ===

==== Why TagProtector prefers DOMDocument over regex ====

HTML is not a regular language. A pure regex cannot reliably handle `>` characters inside quoted attribute values (e.g. `data-label="a > b"`), CDATA sections, or heavily nested SVG/MathML. `DOMDocument` + `libxml` parse the document into a proper tree and serialise nodes back to string with `saveHTML($node)` — not `saveHTML()` on the document — to avoid injecting `<!DOCTYPE>`, `<html>`, and `<body>` wrappers. The regex fallback is retained for environments where the `dom` or `mbstring` PHP extension is absent, but it is not suitable for production sites with complex markup.

==== Sandboxed regex validation ====

`ExclusionRuleValidator::validateRegex()` must test an arbitrary user-supplied pattern without risking a PHP fatal on a content page. The approach:

1. `set_error_handler()` — installs a custom handler that captures `E_WARNING` from `preg_match()` into a local variable instead of displaying or escalating it.
2. `@preg_match($pattern, '')` — `@` suppression is a secondary guard; the custom handler is primary.
3. `finally { restore_error_handler(); }` — **always** restores the original handler, even if an exception is thrown. This is the critical invariant: if the handler is not restored, PHP errors elsewhere on the same request will be silently swallowed.

A pattern that produces `false` (PCRE engine error) or triggers the custom handler is rejected with a descriptive `WP_Error`. The pattern is never stored if validation fails.

==== ExclusionEngine and maskExcluded() ====

`ExclusionEngine::maskExcluded()` wraps the input HTML in `<ott-root>…</ott-root>` before calling `DOMDocument::loadHTML()`. After masking, `innerHtml()` extracts only the children of `<ott-root>` via `saveHTML($child)` on each child node. This strategy avoids the `<html><head><body>` wrapper that `loadHTML()` injects when given a fragment, without requiring `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD` (which are unreliable across libxml versions).

`unmaskExcluded()` is a plain `str_replace(array_keys($map), array_values($map), $html)`. Tokens cannot collide with tag or attribute tokens because each namespace uses a distinct prefix: `OTT_TAG_`, `OTT_ATTR_`, `OTT_EXCL_`, `OTT_TERM_`.

==== WordPress actions/filters added in Task 4 ====

* `ott_pre_translate` — filter applied to the text string **before** the API call inside `HtmlAwareTranslator`. Use to inject glossary substitutions or normalise terminology.
* `ott_post_translate` — filter applied to the translated text **after** `tagProtector.restore` and `attrPreserver.restore`. Use for post-processing or quality checks.
* `ott_cli_batch_query_args` — filter applied to the `WP_Query` arguments in `TranslateCommand::batch()`. Inject `post_status`, `post_type`, or date ranges to narrow the translation scope.
* `ott_exclusion_rules` — filter applied to the `ExclusionRule[]` array returned by `ExclusionRuleRepository::findAll()`. Inject programmatic rules without a database row.

==== Database tables — complete list ====

| Table | Created by | Purpose |
| --- | --- | --- |
| `{prefix}libre_translations` | `Schema::createOrUpgrade()` (activation) | Translation cache: source hash → translated text, locale, is_manual flag, last_used |
| `{prefix}ott_exclusion_rules` | `Migration_1_2_0::up()` | HTML exclusion rules: type, value, scope, active flag, created_by |

==== WP-CLI commands — complete reference ====

| Command | Key options | Description |
| --- | --- | --- |
| `wp ott translate batch <locale>` | `--force`, `--dry-run`, `--post-type`, `--format` | Bulk-translate all posts |
| `wp ott translate post <ID> <locale>` | — | Translate one post, purge static cache |
| `wp ott translate string <text> <locale>` | — | Spot-test, never persists |
| `wp ott cache warm` | `--locale` | Pre-populate object cache from DB |
| `wp ott cache flush` | `--locale`, `--yes` | Flush one or all locales |
| `wp ott cache status` | `--format` | Show counts, backend, last prune |
| `wp ott glossary import <file>` | `--overwrite` | Stream CSV into DB |
| `wp ott glossary export <file>` | `--protected-only` | Stream DB to CSV |
| `wp ott glossary list` | `--format` | Tabular glossary listing |
| `wp ott status` | `--verbose` | Health check; exits 1 on failure |

==== Task 4 edge cases ====

* **Token survival through the translation engine** — LibreTranslate may reorder, lowercase, or add spaces inside token strings. `[[OTT_TAG_0]]` could come back as `[[ OTT_TAG_0 ]]`. `TagProtector::restore()` does an exact `str_replace()` and falls back to the original HTML if the token count does not match. Sites that consistently see mangled tokens should switch to the LibreTranslate array-batch API, which sends each text node as a separate element and eliminates the token-survival problem entirely.
* **CSS selector scope in ExclusionEngine** — `CssToXPath::convert()` supports seven selector patterns. Descendant combinators (space), sibling combinators (`~`, `+`), pseudo-classes (`:first-child`, `:not()`, `:nth-child()`), and attribute substring matchers (`[class^=foo]`) are explicitly unsupported. Attempting to store such a selector will return a `WP_Error` from `ExclusionRuleValidator` and the rule will not be saved.
* **WP-CLI commands and the full DI graph** — Commands are registered inside `Plugin::boot()` after the full object graph (client, cache manager, repository) has been constructed. This means `wp ott *` commands require the plugin to be active and the connection mode to be correctly configured. If the `ConnectionException` is thrown during boot, CLI commands are silently not registered. Check `wp ott status` first to diagnose connection issues.

==== Task 2 edge cases (resolved in 0.3.0) ====

* **`dbDelta()` and `ON UPDATE CURRENT_TIMESTAMP`** — ✅ *Resolved.* `ON UPDATE CURRENT_TIMESTAMP` has been removed from the `last_used` column definition. `TranslationRepository` sets `last_used = NOW()` explicitly in every write, so the trigger is unnecessary. Schema version bumped to `1.0.1`; a gated `ALTER TABLE … MODIFY COLUMN` runs once for existing `1.0.0` installs. Future column changes must still use explicit `ALTER TABLE` inside a numbered migration.
* **`warmLocale()` OOM risk** — ✅ *Resolved.* `CacheManager::warmLocale()` now checks `memory_get_usage()` before loading. If free headroom is below 20 % of `memory_limit`, the warm-up is skipped and logged. After a successful load, a > 32 MB allocation triggers an advisory log warning operators to consider a paginated strategy. A private `resolveMemoryLimit()` helper parses the ini value to bytes; returns 0 (guard disabled) when the limit is `-1` or unset.
* **`ltp_translation_updated` requires a valid post ID** — ✅ *Resolved.* A new `ltp_content_updated` action accepts a fully-qualified URL instead of a post ID. `StaticCacheCompatManager::purgeUrl(string $url, string $locale)` fires it. `WpRocketCompat` handles it via `rocket_clean_files([$url])`; `CloudflareCompat` reuses its existing `purgeViaPlugin` / `purgeViaApi` helpers. Non-post content (widgets, nav menus, template parts) can now trigger targeted CDN purges.

== Changelog ==

= 0.4.0 =
* WP-CLI suite: `wp ott translate` (batch/post/string), `wp ott cache` (warm/flush/status), `wp ott glossary` (import/export/list), `wp ott status` (health check, exits 1 on failure).
* `HtmlAwareTranslator` decorator wraps the raw connection client — all translation requests now pass through the HTML-protection pipeline before reaching the API.
* `TagProtector`: replaces HTML tags with `[[OTT_TAG_n]]` tokens; DOMDocument strategy preferred when `dom`+`mbstring` available; attribute-aware regex fallback; integrity check on restore.
* `AttributePreserver`: protects `href`, `src`, `srcset`, `action`, `formaction`, `poster`, `cite`, and `data-*` attribute values from mutation by the translation engine.
* `ExclusionEngine` + `ExclusionRuleRepository` + `ExclusionRuleValidator`: CSS selector, regex, and XPath rules with global/post_type/post_id scope; sandboxed regex validation with guaranteed `restore_error_handler()` in `finally`.
* `Migration_1_2_0`: creates `{prefix}ott_exclusion_rules` table via `dbDelta()`.
* `OutputBufferInterceptor`: `setExclusionEngine()` setter; `maskExcluded()`/`unmaskExcluded()` called around the existing two-pass regex.
* New filter hooks: `ott_pre_translate`, `ott_post_translate`, `ott_cli_batch_query_args`, `ott_exclusion_rules`.
* `Plugin::boot()` wires the full `HtmlAwareTranslator` decorator chain and registers CLI commands with injected dependencies.

= 0.3.0 =
* `Schema`: removed `ON UPDATE CURRENT_TIMESTAMP` from `last_used` — `TranslationRepository` now manages the timestamp explicitly. Schema version bumped to `1.0.1` with a gated `ALTER TABLE` for existing installs.
* `CacheManager::warmLocale()`: pre-load OOM guard added — checks free memory headroom (< 20 % of `memory_limit` aborts) and logs a warning when > 32 MB allocated.
* `StaticCacheCompatManager::purgeUrl()`: new entry point firing `ltp_content_updated` action for non-post content (widgets, nav menus, template parts).
* `WpRocketCompat`: hooks `ltp_content_updated` → `rocket_clean_files([$url])`.
* `CloudflareCompat`: hooks `ltp_content_updated` → targeted CDN purge by URL.

= 0.2.0 =
* Persistence layer: custom MySQL table (`{prefix}libre_translations`) with full schema versioning via `dbDelta()`.
* Two-level read-through cache: L1 `ObjectCacheDriver` (Redis/Memcached/in-process) → L2 `DatabaseCacheDriver`.
* Human-edit protection: `is_manual = 1` rows are never overwritten by the machine API (enforced atomically in SQL).
* Static-cache compat: WP Rocket (`rocket_clean_post`/`rocket_clean_domain`) and Cloudflare (plugin actions + direct REST API).
* WP-Cron pruning job (`ltp_weekly`): removes stale rows in batches of 1000; `is_manual` rows are never pruned.
* `CacheManager::warmLocale()` pre-populates the object cache from the database in a single batch query.
* Activation hook creates the schema and schedules the pruning cron; deactivation hook clears the cron.

= 0.1.0 =
* Initial scaffold: Local-First Architecture and Dual-Layer Interception system.
* `LocalhostRestClient` — WP_Http driver for loopback LibreTranslate instances.
* `UnixSocketClient` — raw cURL driver over Unix domain sockets.
* `PrivateVpcClient` — WP_Http driver for RFC 1918 VPC endpoints with optional Bearer auth.
* `ConnectionFactory` — option-driven driver resolution with request-scoped memoisation.
* `GettextInterceptor` — `gettext` filter with re-entrancy guard and bypass-domain support.
* `OutputBufferInterceptor` — full-page HTML buffering with two-pass regex text extraction.

== Upgrade Notice ==

= 0.4.0 =
Runs `Migration_1_2_0` automatically on the next page load to create the `{prefix}ott_exclusion_rules` table. No manual steps required. WP-CLI commands (`wp ott translate`, `wp ott cache`, `wp ott glossary`, `wp ott status`) are available immediately after upgrade.

= 0.3.0 =
Schema version bumped to `1.0.1`. The `ON UPDATE CURRENT_TIMESTAMP` trigger is removed from the `last_used` column via an automatic `ALTER TABLE` that runs once on the next page load after upgrading. No manual steps required.

= 0.2.0 = (`{prefix}libre_translations`). The table is created automatically on plugin activation or on the next page load if already active. No manual steps are required when upgrading from 0.1.0.

= 0.1.0 =
Initial release. No upgrade steps required.
