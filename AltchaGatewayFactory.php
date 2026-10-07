<?php

namespace Omniguard\Altcha;

use AltchaOrg\Altcha\Algorithm\DeriveKeyInterface;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Algorithm\Sha;
use AltchaOrg\Altcha\Algorithm\ShaAlgorithm;
use AltchaOrg\Altcha\HmacAlgorithm;
use Omniguard\Config;
use Omniguard\Exception\InvalidConfigException;
use Omniguard\GatewayFactory;
use Omniguard\GatewayInterface;
use Omniguard\Replay\InMemoryReplayStore;
use Omniguard\Replay\ReplayStoreInterface;

/**
 * ALTCHA: a proof of work the site issues and checks itself - the visitor's
 * browser derives keys until one starts as the challenge asks, the site
 * checks the one it found. Nobody else is involved: no third party, no
 * cookie.
 *
 *   options:
 *     hmac_key: '%env(ALTCHA_HMAC_KEY)%'   # required: signs the challenges - long, random, secret
 *     algorithm: PBKDF2/SHA-256            # or PBKDF2/SHA-384, PBKDF2/SHA-512, SHA-256, SHA-384, SHA-512
 *     cost: 5000                           # the key derivation's iterations: the work of one try
 *     key_prefix: '00'                     # what the derived key must start with: each hex pair, 256 times the tries
 *     expires: 600                         # seconds a challenge may be solved and posted in
 *     challenge_url: ~                     # where the widget fetches a challenge (the bridge's route); none: inline in the page
 *     field: altcha                        # the posted field
 *     script: https://cdn.jsdelivr.net/npm/altcha@3.3.0/dist/main/altcha.min.js   # serve it yourself: /js/altcha.min.js
 *     integrity: sha256-...                # the script's Subresource Integrity, set for the default address
 *     attributes: { auto: onsubmit, display: standard, language: fr }   # <altcha-widget>'s own attributes
 *     configuration: { hideFooter: true }  # its configuration attribute (JSON)
 *
 * Spent solutions are remembered in the ReplayStoreInterface given to the
 * factory - the application's, shared by every request. Without one, this
 * process's memory: right in a test or a worker, not behind PHP-FPM.
 */
final class AltchaGatewayFactory extends GatewayFactory
{
    /** The widget, as published on npm (altcha 3.3.0, MIT) and served by jsDelivr. */
    public const SCRIPT = 'https://cdn.jsdelivr.net/npm/altcha@3.3.0/dist/main/altcha.min.js';
    public const INTEGRITY = 'sha256-/CeoPc2Yo9is9tcPvkZD3L7lEqv7mIhu3Y7QRTUK2Ck=';

    public function __construct(private readonly ?ReplayStoreInterface $replays = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omniguard.factory_name' => 'altcha',
            'omniguard.factory_title' => 'ALTCHA',
            'omniguard.required_options' => ['hmac_key'],
            'algorithm' => 'PBKDF2/SHA-256',
            'cost' => 5000,
            'key_prefix' => '00',
            'expires' => 600,
            'challenge_url' => null,
            'field' => 'altcha',
            'script' => self::SCRIPT,
            'integrity' => null,
            'attributes' => [],
            'configuration' => [],
        ]);
    }

    protected function build(Config $c): GatewayInterface
    {
        $script = $c->string('script');
        $prefix = strtolower((string) $c['key_prefix']);
        if ('' === $prefix || !ctype_xdigit($prefix)) {
            throw new InvalidConfigException(\sprintf('The "altcha" gateway\'s key_prefix must be hexadecimal: "%s".', $c['key_prefix']));
        }

        return new AltchaGateway(
            hmacKey: (string) $c['hmac_key'],
            algorithm: self::algorithm((string) $c['algorithm']),
            replays: $this->replays ?? new InMemoryReplayStore(),
            cost: max(1, (int) $c['cost']),
            keyPrefix: $prefix,
            expires: max(1, (int) $c['expires']),
            challengeUrl: $c->string('challenge_url'),
            field: $c->string('field') ?? 'altcha',
            script: $script,
            integrity: $c->string('integrity') ?? (self::SCRIPT === $script ? self::INTEGRITY : null),
            attributes: (array) $c['attributes'],
            configuration: (array) $c['configuration'],
        );
    }

    public static function algorithm(string $name): DeriveKeyInterface
    {
        return match (strtoupper($name)) {
            'PBKDF2/SHA-256' => new Pbkdf2(HmacAlgorithm::SHA256),
            'PBKDF2/SHA-384' => new Pbkdf2(HmacAlgorithm::SHA384),
            'PBKDF2/SHA-512' => new Pbkdf2(HmacAlgorithm::SHA512),
            'SHA-256' => new Sha(ShaAlgorithm::SHA256),
            'SHA-384' => new Sha(ShaAlgorithm::SHA384),
            'SHA-512' => new Sha(ShaAlgorithm::SHA512),
            default => throw new InvalidConfigException(\sprintf('The "altcha" gateway does not know the algorithm "%s": PBKDF2/SHA-256, PBKDF2/SHA-384, PBKDF2/SHA-512, SHA-256, SHA-384 or SHA-512.', $name)),
        };
    }
}
