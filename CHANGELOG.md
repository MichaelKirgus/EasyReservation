# Changelog

## v1.4.2 (2026-10-21) — Security release

- Rebuilt on **PHP 8.3.36** to address two critical PHP vulnerabilities:
  - [CVE-2026-109618](https://nvd.nist.gov/vuln/detail/CVE-2026-109618) — memory corruption during request parsing (unauthenticated RCE via crafted HTTP requests, CVSS 8.1/Important)
  - [CVE-2026-109619](https://nvd.nist.gov/vuln/detail/CVE-2026-109619) — use-after-free in error handling / worker state (unauthenticated RCE, CVSS 8.1/Important, 9.2 Critical per PHP Group)
- No application code changes. The backend base image `php:8.3-fpm-alpine` now resolves to the patched 8.3.36 release; this version republishes the images so deployed instances run the fixed PHP.
