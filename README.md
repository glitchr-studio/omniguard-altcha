# omniguard/altcha

**ALTCHA** for [glitchr/omniguard](https://github.com/glitchr-studio/omniguard): a captcha the site
issues and checks itself. The visitor's browser solves a small proof of work - it derives keys
until one starts as the challenge asks - and the site checks the solution: signed with the site's
key, unexpired, for this action, and **never posted before**. Nobody else is involved: no third
party, no cookie, nothing to ask the visitor's consent for.

Built on the official [`altcha-org/altcha`](https://github.com/altcha-org/altcha-lib-php) (^2.3,
MIT); the widget is the [`altcha`](https://www.npmjs.com/package/altcha) web component (MIT).

```php
use Omniguard\Altcha\AltchaGatewayFactory;
use Omniguard\Model\Attempt;

$gateway = (new AltchaGatewayFactory($spentTokens))->create(['hmac_key' => getenv('ALTCHA_HMAC_KEY')]);

$widget = $gateway->widget('contact');
echo $widget->html();                       // <script type="module" ...> <altcha-widget challenge="{...}" name="altcha">

$verdict = $gateway->verify(Attempt::fromPost($_POST, $widget, $ip));
$verdict->passed;                           // the second time: false, reasons ['duplicate']
```

```yaml
omniguard:
    gateways:
        forms:
            factory: altcha
            options:
                hmac_key: '%env(ALTCHA_HMAC_KEY)%'     # required: long, random, secret
                script: /js/altcha.min.js              # serve the widget yourself: not even a CDN sees the visitor
```

**ALTCHA cannot tell a solution it saw before**: the gateway remembers each one in the
`ReplayStoreInterface` given to its factory until it would have lapsed - the application's, shared
by every request (`CacheReplayStore` on a PSR-6 pool; the Symfony bundle gives `cache.app`).
Without one, this process's memory: right in a test or a worker, not behind PHP-FPM.

[Documentation](docs/index.md): the options, the widget and its script, the challenge in the page
or from a route, the action, what was verified - the whole way in PHP: issued, solved by the
library, verified, refused when posted again.

License: LGPL-3.0-or-later.
