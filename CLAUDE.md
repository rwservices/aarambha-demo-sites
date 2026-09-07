# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`aarambha-demo-sites` is a WordPress admin plugin ("Aarambha Demo Sites") that imports pre-built
theme demo content — posts/pages (WXR), Customizer settings, widgets, Smart Slider exports, and
nav menus — in a single guided, AJAX-driven flow. Demo listings and downloadable packages are
fetched from a remote API (`AARAMBHA_DS_API_URL`, default `http://demo.aarambhathemes.com/wp-json/demos/v1/`).
Actual WXR parsing is delegated to the official **WordPress Importer** plugin, which is a hard
dependency (`Requires Plugins: wordpress-importer`) — it is no longer bundled as of 2.0.0.

Runtime PHP target is **7.0+** (see plugin header); tooling (phpcs/phpstan/composer platform)
targets **8.0**. Node 22 (`.nvmrc`).

## Commands

Build / develop (npm):

| Command | Purpose |
| --- | --- |
| `npm start` | Watch-build JS + CSS into `assets/build/` (wp-scripts + custom `webpack.config.js`) |
| `npm run build` | Dev build of all assets |
| `npm run build:prod` | Production build: clean, minify, strip source maps, then `composer install --no-dev` |
| `npm run lint` | Run all linters in parallel (`lint:js`, `lint:css`, `lint:php`, plus `lint:js:types`) |
| `npm run lint:js` / `lint:js:fix` | ESLint (`@wordpress/eslint-plugin`) |
| `npm run lint:css` / `lint:css:fix` | Stylelint |
| `npm run lint:js:types` | `tsc --noEmit` |
| `npm run i18n:make-pot` | Regenerate `languages/aarambha-demo-sites.pot` (needs WP-CLI) |
| `npm run cypress:open` | Open Cypress E2E runner |
| `grunt release` | Assemble `build/aarambha-demo-sites/` and produce `build/aarambha-demo-sites-<version>.zip` |

PHP (composer scripts, run from repo root):

| Command | Purpose |
| --- | --- |
| `composer run-script lint` | phpcs against `phpcs.xml.dist` |
| `composer run-script format` | phpcbf (auto-fix) |
| `composer run-script phpstan` | PHPStan level 8 (`--memory-limit=1G`) |
| `composer run-script test` | PHPUnit (`phpunit.xml.dist`, suite `unit` → `tests/phpunit/`) |
| `npm run test:php` | Same, run inside `wp-env` (`@wordpress/env`) |
| `vendor/bin/phpunit --filter <TestName>` | Run a single test / case |
| `vendor/bin/phpcs path/to/file.php` | Lint one file |

There is a `.claude/skills/wpcs` skill for the phpcs/phpcbf workflow and `.claude/skills/php-doc-comments`
for docblocks — prefer invoking those when the task matches.

### Config drift to be aware of

The PHP tooling configs were originally copied from a sibling project ("Zenvy"). `composer.json`
and `phpstan.neon.dist` have been corrected to this plugin (package `rwservices/aarambha-demo-sites`,
PHPStan analysing `aarambha-demo-sites.php` + `inc/`). Still stale: `phpcs.xml.dist` (ruleset
`name`/`description` say "Zenvy") and `phpunit.xml.dist` (points coverage at a nonexistent
`functions.php`). `tests/phpunit/` contains only `.gitkeep`, and most of `cypress/e2e/` is the
default Cypress example spec set — PHPUnit has no real tests to run yet.

## Architecture

### Bootstrap and the singleton graph

`aarambha-demo-sites.php` defines `AARAMBHA_BOOTSTRAP`, loads `inc/helpers/constant.php` (all
`AARAMBHA_DS_*` path/URL constants — theme code may pre-define several to override them) and
`inc/helpers/functions.php`, registers the activation hook (`inc/main/class-aarambha-ds-activation.php`),
then calls `Aarambha_DS()` to boot the core singleton.

`Aarambha_DS` (`inc/main/class-aarambha-ds.php`) is the god object / service locator. Every
subsystem is a `getInstance()` singleton reached through an accessor on it:

- `Aarambha_DS()->api()` → `Aarambha_DS_API` — all remote API calls, results cached in **site transients**
  (`aarambha_ds_author_themes`, `aarambha_ds_{theme}_demo_{slug}`, etc.).
- `Aarambha_DS()->plugins()` → `Aarambha_DS_Plugins` — required-plugin install/activate (TGMPA-style, `ocdi-*` actions).
- `Aarambha_DS()->core()` → `Aarambha_DS_Core` — orchestrates download + the per-step imports.
- `Aarambha_DS()->ajax()` → `Aarambha_DS_Ajax` — registers all `wp_ajax_*` handlers.
- `Aarambha_DS()->admin()` → `Aarambha_DS_Admin` — admin menu page, asset enqueue, JS templates.
- `Aarambha_DS()->importer()` → a fresh `Aarambha_WP_Import` (thin subclass of the WordPress
  Importer's `WP_Import`), or `false` + an admin notice when the importer plugin is absent
  (`hasWpImporter()` gates this; `loadWordPressImporter()` carefully requires the plugin's
  `parsers.php` before `class-wp-import.php`).
- `Aarambha_DS()->view($name, $data)` includes `inc/views/{$name}.php` (plain PHP partials;
  `inc/views/popups/*` are SweetAlert2 modal bodies).

`pluginsLoaded()` decides whether the current theme is an Aarambha theme (via the API's theme
list + `aarambha_ds_get_theme_author()`); if not it shows an incompatibility notice instead of
the importer — **but note there is currently an unconditional `$this->admin();` call at the end
of that method marked "For testing purposes"** that forces the UI on regardless.

### The import flow (this is the core of the plugin)

The whole import is a sequence of independent AJAX POSTs from `assets/src/js/admin.js`, one per
step, each a key in `Aarambha_DS_Ajax::$actions`:

```
retrieve-demo → list-plugins → ocdi-install-plugin / ocdi-activate-plugin (loop)
  → prepare-import → content-import → customizer-import → widgets-import
  → slider-import → menu-import → pages-import → finalize-import
```

Splitting into steps is deliberate: a full WXR import easily exceeds `max_execution_time` and
`memory_limit` on shared hosts.

The `content-import` step is itself a two-phase state machine that repeats across several AJAX
requests (it replies with `action: "content-import"` to make the JS re-call it, tracking
progress in the `aarambha_ds_mediacache_{theme}_{slug}` site transient):

1. **Media pre-cache** — `Aarambha_DS_Media_Cache` (`inc/main/classes/importer/`) parses
   `<wp:attachment_url>` entries out of the WXR and downloads them ~10 per request into
   `{demo dir}/_media_cache/`. This exists because the demo API server is slow (~1s+/file); a
   70-file import in one request blows past nginx's 60s `fastcgi_read_timeout` and surfaces as
   "Gateway Time-out / Something Went Wrong!" even though PHP keeps running.
2. **Import** — once every file is cached, `Aarambha_DS_Media_Cache::attach_interceptor()` adds a
   `pre_http_request` filter that serves those URLs from the local cache, then the normal
   `WP_Import` runs (`fetch_attachments = true`) and finishes in seconds with featured-image /
   `url_remap` handling fully intact. The cache dir and transient are then deleted.

Every handler runs through `Aarambha_DS_Ajax::startBuffer()`,
which lifts PHP limits (`set_time_limit(0)`, `memory_limit` up to 1024M), disables
`display_errors`, and starts an output buffer. `sendSuccess()` / `sendError()` **discard that
buffer** before emitting JSON — this exists specifically to stop stray PHP notices or importer
`echo` output from corrupting the JSON response (the classic "Unexpected token 'F'" front-end
error). When adding or editing an AJAX handler, keep this buffer discipline and verify the nonce
via `verifyNonce()`.

`admin.js` is a single IIFE (jQuery, no build-time framework) with `aarambhaDSAjax` (transport),
`aarambhaDSHelpers`, and step-runner logic; it drives SweetAlert2 modals for progress and
outcomes. Localized data is injected via `wp_localize_script($slug, 'aarambhaDSData', …)` —
note the script also reads a global `aarambhaDS` object; keep the localize object name
(`aarambhaDSData`) in sync with what the JS consumes.

The React / `@wordpress/*` dependencies in `package.json` are not currently used by shipped code
(`assets/src/js/admin.js` is vanilla jQuery); `webpack.config.js` has a `@` → `assets/src` alias
and TS/TSX resolution wired up for future use.

### Asset build

`webpack.config.js` exports **two** configs (`[scripts, styles]`) extending `@wordpress/scripts`:

- **scripts**: single entry `assets/src/js/admin.js` → `assets/build/js/admin.js` (+ `admin.asset.php`).
- **styles**: every top-level file in `assets/src/css/` → `assets/build/css/` (CSS/SCSS, `url()`
  rewriting disabled so relative asset paths survive), plus RTL output consumed as
  `admin{AARAMBHA_DS_RTL}.css`.
- Anything under an `assets/src/library/` folder (e.g. the vendored SweetAlert2) is **excluded
  from minification** and copied verbatim to `assets/build/library/`.

PHP loads built assets through `AARAMBHA_DS_JS` / `AARAMBHA_DS_CSS` / `AARAMBHA_DS_LIBRARY` URL
constants (all point into `assets/build/`), via `register_script()` / `register_style()` helpers
in `Aarambha_DS_Admin` that read version + deps from `admin.asset.php`.

### Conventions

- Classes are `Aarambha_DS_*`, files `class-aarambha-ds-*.php`, one class per file, each guarded
  by `if (!defined('WPINC')) exit;` and implementing the singleton (`getInstance`, private
  `__construct`, `__clone`/`__wakeup` → `_doing_it_wrong`).
- Method names inside classes are `camelCase`; global helper functions are
  `aarambha_ds_snake_case` (`inc/helpers/functions.php`).
- Text domain is `aarambha-demo-sites` everywhere; `grunt checktextdomain` enforces it over `inc/**/*.php`.
- Extension points are filters/actions prefixed `aarambha_ds_` (`aarambha_ds_api_url`,
  `aarambha_ds_admin_page_args`, `aarambha_ds_localize_data`, `aarambha_ds_after_demo_imported`,
  `aarambha_ds_setup_after_import`, …). Elementor-specific fix-ups live in `functions.php`
  (`aarambha_ds_set_elementor_active_kit`, `aarambha_ds_regenerate_elementor_css`, FA4 shim).
- phpcs ruleset is **WordPress-VIP-Go** based (`phpcs.xml.dist`), `testVersion 8.0-`,
  `minimum_wp_version 6.8`.
