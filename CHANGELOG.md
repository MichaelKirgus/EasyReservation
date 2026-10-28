# Changelog

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
