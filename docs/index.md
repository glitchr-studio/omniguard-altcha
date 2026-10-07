---
title: omniguard/altcha
order: 1
---

# omniguard/altcha

## Installation

```sh
composer require omniguard/altcha
```

PHP 8.2 or later, `glitchr/omniguard` and `altcha-org/altcha` ^2.3. No HTTP client: this package
calls nothing.

```php
use Omniguard\Altcha\AltchaGatewayFactory;
use Omniguard\Replay\CacheReplayStore;

$gateway = (new AltchaGatewayFactory(new CacheReplayStore($psr6Pool)))->create(['hmac_key' => getenv('ALTCHA_HMAC_KEY')]);
```

## The sources

Written on 2026-10-07 from the library's own code and README at
[v2.3.0](https://github.com/altcha-org/altcha-lib-php/tree/v2.3.0) (`Altcha::createChallenge()`,
`solveChallenge()`, `verifySolution()`, `Payload`, `CreateChallengeOptions`), and from the widget's
README and code, `altcha` 3.3.0 on npm (its attributes, the inline JSON challenge it accepts, the
base64 payload it posts in the `altcha` field).

## How it goes

1. `widget($action)` - or `issue($action)`, behind a route - makes a **challenge**: random nonce
   and salt, a key derivation (PBKDF2/SHA-256 by default) and its cost, the prefix the derived key
   must start with, an expiry, and `{"action": ..., "issued": ...}` in its data - all of it signed
   with HMAC-SHA-256 under the site's `hmac_key`.
2. The widget's workers try counters until a derived key starts with the prefix, and post
   `{"challenge": ..., "solution": {"counter", "derivedKey"}}`, base64, in the `altcha` field.
3. `verify()` checks the signature, the expiry, the solution (one derivation), the action - and
   spends the challenge's signature in the store: the second post is `duplicate`.

| Verdict | When |
|---|---|
| passed | signed, unexpired, solved, for the expected action, first time |
| `missing` | nothing posted |
| `invalid` (`malformed`) | not base64 JSON of a payload - the widget's test mode included |
| `invalid` (`invalidSignature`) | not signed with this site's key |
| `invalid` (`invalidSolution`) | the derived key does not hold |
| `expired` | past its `expires` |
| `action` | issued for another action, or for none when one is expected |
| `duplicate` | posted before |

`Verdict::$action` is the action it was issued for, `$at` when it was issued; no score, no host:
ALTCHA does not know where the widget was shown.

## Options

| Option | Default | |
|---|---|---|
| `hmac_key` | required | signs the challenges: long, random, secret - an environment variable |
| `algorithm` | `PBKDF2/SHA-256` | `PBKDF2/SHA-384`, `PBKDF2/SHA-512`, `SHA-256`, `SHA-384`, `SHA-512`: what the widget bundles |
| `cost` | 5000 | the derivation's iterations: the work of one try |
| `key_prefix` | `00` | what the derived key must start with: each hexadecimal pair multiplies the tries by 256 |
| `expires` | 600 | seconds a challenge may be solved and posted in |
| `challenge_url` | none | where the widget fetches its challenge; none: the challenge is in the page |
| `field` | `altcha` | the posted field |
| `script` | jsDelivr, `altcha@3.3.0/dist/main/altcha.min.js`; in Symfony, the site's own copy (`/omniguard/altcha/3.3.0/altcha.min.js`) | the widget's script; `integrity` is set for both addresses |
| `integrity` | | the script's Subresource Integrity, for another address |
| `attributes` | `{}` | `<altcha-widget>`'s own attributes: `auto` (`onsubmit`, `onfocus`, `onload`), `display`, `language`, `type`, `theme`, `workers` |
| `configuration` | `{}` | its `configuration` attribute, as JSON: `{hideFooter: true}`, `{minDuration: 1000}` |

The widget is printed as `<script type="module" async defer>` and `<altcha-widget challenge="..."
name="altcha">`.

## The script: the site's own

The widget's script - `altcha` 3.3.0's `dist/main/altcha.min.js`, MIT - is **shipped in this
package** (`public/altcha.min.js`, its licence beside it, `AltchaGatewayFactory::SCRIPT_FILE`),
unchanged: its integrity is `sha256-/CeoPc2Yo9is9tcPvkZD3L7lEqv7mIhu3Y7QRTUK2Ck=`
(`AltchaGatewayFactory::INTEGRITY`), the same as the CDN's. It loads nothing else: its workers are
data: URLs (`worker-src 'self' data:` in a Content-Security-Policy).

**In a Symfony application** the bridge serves it at `/omniguard/altcha/3.3.0/altcha.min.js`
(`AltchaGatewayFactory::SCRIPT_PATH`, cached a year) and makes that the gateways' default: the
widget reaches nobody - `Widget::$origins` is empty, `reachesOthers()` false, no consent to ask.
Nothing to install, no route to import, no asset pipeline. `omniguard.serve_scripts: false` goes
back to the CDN.

**In PHP alone** the default stays jsDelivr, pinned and checked (`AltchaGatewayFactory::SCRIPT`):
`Widget::$origins` then names `https://cdn.jsdelivr.net` - a CDN sees the visitor's address, though
the check itself stays on the site (`thirdParty: false`). Serve the package's copy yourself and
nobody does:

```sh
cp vendor/omniguard/altcha/public/altcha.min.js public/js/altcha.min.js
```

```php
$gateway = (new AltchaGatewayFactory($store))->create(['hmac_key' => getenv('ALTCHA_HMAC_KEY'), 'script' => '/js/altcha.min.js']);
```

## In the page, or from a route

Without `challenge_url` the challenge travels in the page, as the widget's `challenge` attribute
(JSON). A page served from a cache would give everyone the same challenge - the first to post it
spends it: let the widget fetch a fresh one, from the Symfony bridge's route
(`/omniguard/<gateway>/challenge`) or one of yours that answers `$gateway->issue($action)` with
`Cache-Control: no-store`.

## Verified, and not

| | |
|---|---|
| The whole way in PHP | **done on 2026-10-07**, by the tests and `docker compose run --rm omniguard bare --live`: a challenge issued, solved by `altcha-org/altcha`'s own `solveChallenge()` (what the widget does), verified, refused the second time (`duplicate`) and for another action (`action`); a challenge signed with another key, a wrong solution, an expired challenge refused |
| The widget's script on jsDelivr | fetched on 2026-10-07: served, an ES module, its SHA-256 the `integrity` above |
| The copy shipped in `public/` | **done on 2026-10-07**: npm's tarball `altcha-3.3.0.tgz` (the registry's SHA-1 checked), its `dist/main/altcha.min.js` of the same SHA-256 as jsDelivr's - asserted by the tests; licence MIT, its text beside it; read for what it loads: nothing (data: workers) |
| The bridge serving it | **done on 2026-10-07** by the tests: the address answers the file, `text/javascript`, cached a year; the widget built by the bridge points there, no origin. In a browser: not done |
| The widget in a browser | **not done**: no browser was run. Its attributes, its inline challenge and the payload it posts are read from its README and its code (`altcha` 3.3.0) |
| Other algorithms | `SHA-256` by the tests; the PBKDF2 variants by the library's own |
| `CacheReplayStore` under concurrent requests | not exercised: PSR-6 has no atomic add - see the store's note |
