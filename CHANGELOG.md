# Changelog

## v1.4.4 (2026-11-19) — Security release

Rebuilds both images on the latest patched base components and updates all PHP/JS application dependencies to their fixed versions:

### Base image / runtime components

- **PHP 8.3.37** in the backend (`php:8.3-fpm-alpine` floating tag), fixing the [November 2026 PHP security releases](https://www.heise.de/news/PHP-behebt-17-Sicherheitsluecken-vier-erlauben-Ausfuehrung-von-Schadcode-11531249.html) — 17 vulnerabilities (fixed in 8.2.36 / **8.3.37** / 8.4.28 / 8.5.13), including RCE-capable ones:
  - CVE-2026-129289 — FPM `FCGI_KEEP_CONN` handling (remote code execution)
  - CVE-2026-115560 — deserialization via session upload progress
  - CVE-2026-129496 — use-after-free in the timezone cache
  - plus critical CVE-2026-126714 and CVE-2026-129481.
- **nginx** patched against the [November 2026 nginx security release](https://www.heise.de/news/Webserver-nginx-Update-schliesst-schwere-Luecken-11530216.html) — 10 CVEs, five of them CVSS 9.2 use-after-free issues enabling RCE ("nginx2root"), some present in the code since 2007:
  - frontend: `nginx:alpine` now resolves to **1.31.9** (mainline fix)
  - backend: Alpine's `nginx` package is now **1.30.7-r0** (stable fix)
  - CVE-2026-113425, CVE-2026-118899, CVE-2026-120967, CVE-2026-120993, CVE-2026-121810, CVE-2026-123007, CVE-2026-123633, CVE-2026-123634, CVE-2026-123640, CVE-2026-125283
- **Node.js v22.23.5** for the frontend build stage (floating `node:22` tag).
- curl 8.23.0 / brotli-libs 1.2.1 remain at their latest patched Alpine versions via the existing `apk upgrade --available` step in both runtime stages.

### Backend PHP dependencies (`composer.lock`)

All Composer packages updated to fixed versions — `composer audit` now reports **zero advisories** (previously 15+):

- **laravel/framework v12.46.0 → v12.73.0** — fixes [CVE-2026-114509](https://nvd.nist.gov/vuln/detail/CVE-2026-114509) (critical)
- guzzlehttp/guzzle 7.10.0 → 7.16.3, guzzlehttp/psr7 → 2.13.4
- symfony/* components to the latest patched 7.4.x releases (http-foundation v7.4.23, http-kernel v7.4.23, yaml v7.4.23, mailer v7.4.22, …)
- league/commonmark → 2.10.7

Also added a `config.platform.php = "8.3.99"` pin in `composer.json` so future dependency updates always resolve against the runtime PHP (8.3) instead of whatever version runs on the developer machine — this prevents lock files from containing packages that require a newer PHP than the image actually ships.

### Frontend JS dependencies (`package-lock.json`)

All npm dependencies re-resolved — `npm audit` went from **17 vulnerabilities** (2 critical, 8 high) down to **4 moderate**, all in dev-only test tooling:

- vite → 7.3.8 (fixes GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw, GHSA-587h-7wmj-h5pm, GHSA-9jrq-w75r-8gcw, GHSA-rq7h-c2jc-7f22)
- axios → 1.21.1 (prototype-pollution gadgets, ReDoS in `data:` URL parser and proxy bypass, HTTP/2 DNS/proxy control bypass)
- vue + @vue/server-renderer → 3.5.45 (fixes XSS via missing CR in attribute-name blacklist, GHSA-g2v6-rqmx-r4w6)
- source-map-js → 1.2.4 (event-loop DoS), shell-quote → 1.12.0 (critical command injection via concurrently), follow-redirects → 1.16.3 (credential stripping on redirects), dompurify → 3.4.18, vitest/@vitest/mocker → 4.1.12
- happy-dom ^20.11.6 → ^21.0.4 (dev-only; fixes GHSA-834v-8h66-3h79 host code execution and SSRF via iframe/stylesheet parsing)

Remaining: 4 moderate advisories in the dev-only chain `@vue/test-utils` → js-beautify → editorconfig → minimatch <11.0.1 (CPU DoS; not shipped in any production image — fixing would require a breaking change of test tooling).

## v1.4.3 (2026-10-28) — Security release

Rebuilds both images on the latest patched base components:

- **brotli 1.2.1** in both runtime images (frontend `nginx:alpine`, backend `php:8.3-fpm-alpine` + nginx/curl), fixing [CVE-2026-111497](https://nvd.nist.gov/vuln/detail/CVE-2026-111497) — buffer overflow in the Brotli decoder (out-of-bounds write, potential RCE via crafted brotli stream). **Listed in CISA's Known Exploited Vulnerabilities catalog** (added 2026-10-27).
- **Node.js v22.23.4 'Jod' LTS** for the frontend build stage (floating `node:22` tag), fixing the [October 2026 Node.js security releases](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases): CVE-2026-105481/105482/105483, CVE-2026-106069/106070–106074 and the bundled brotli fix (CVE-2026-111497).
- curl updated to 8.23.0 in both images as part of the Alpine security updates.
- `backend/.dockerignore` added: keeps local-only files (`vendor/`, stale `bootstrap/cache/*.php` discovery caches, `.env`) out of the build context so local builds match CI and no secrets leak into image layers.

## v1.4.2 (2026-10-21) — Security release

- Rebuilt on **PHP 8.3.36** to address two critical PHP vulnerabilities:
  - [CVE-2026-109618](https://nvd.nist.gov/vuln/detail/CVE-2026-109618) — memory corruption during request parsing (unauthenticated RCE via crafted HTTP requests, CVSS 8.1/Important)
  - [CVE-2026-109619](https://nvd.nist.gov/vuln/detail/CVE-2026-109619) — use-after-free in error handling / worker state (unauthenticated RCE, CVSS 8.1/Important, 9.2 Critical per PHP Group)
- No application code changes. The backend base image `php:8.3-fpm-alpine` now resolves to the patched 8.3.36 release; this version republishes the images so deployed instances run the fixed PHP.
