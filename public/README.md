# The ALTCHA widget, as published

`altcha.min.js` is the file `dist/main/altcha.min.js` of the npm package
[`altcha`](https://www.npmjs.com/package/altcha) **3.3.0**, unchanged:

- tarball `https://registry.npmjs.org/altcha/-/altcha-3.3.0.tgz`, SHA-1
  `e2c52081d82899ae2050ca0763b984f856a56f93` (the registry's `shasum`), fetched on 2026-10-07;
- the file's Subresource Integrity `sha256-/CeoPc2Yo9is9tcPvkZD3L7lEqv7mIhu3Y7QRTUK2Ck=`
  (`AltchaGatewayFactory::INTEGRITY`), the same as jsDelivr serves.

It is the work of Daniel Regeci and BAU Software s.r.o., under the MIT License: its text, from the
package, is `altcha.LICENSE.txt` beside it. The rest of omniguard/altcha is under the
LGPL-3.0-or-later.

It loads nothing else: its workers are built from data: URLs (a Content-Security-Policy needs
`worker-src 'self' data:`), and the only addresses it holds are a link to altcha.org in its footer
(`configuration: {hideFooter: true}`) and Svelte's error pages.

Served by the Symfony bridge at `/omniguard/altcha/3.3.0/altcha.min.js`; outside Symfony, copy it
where the site serves its scripts (`AltchaGatewayFactory::SCRIPT_FILE`).
