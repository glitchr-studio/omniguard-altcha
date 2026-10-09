<?php

namespace Omnishield\Altcha;

use AltchaOrg\Altcha\Algorithm\DeriveKeyInterface;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Omnishield\ChallengeIssuerInterface;
use Omnishield\LocalizableInterface;
use Omnishield\Model\Attempt;
use Omnishield\Model\Capabilities;
use Omnishield\Model\Verdict;
use Omnishield\Model\Widget;
use Omnishield\Replay\ReplayStoreInterface;

/**
 * An ALTCHA challenge issued, solved by the visitor's browser, checked here:
 * its signature (the site's key), its expiry, the solution, the action it
 * was issued for - and, since ALTCHA itself cannot tell a solution it saw
 * before, that it was not posted already (the replay store).
 *
 * The action is signed into the challenge's data with the time it was
 * issued: {"action": "contact", "issued": 1791363600}.
 */
final class AltchaGateway implements ChallengeIssuerInterface, LocalizableInterface
{
    private readonly Altcha $altcha;

    /** The texts the widget shows (altcha 3.3.0's i18n keys). */
    public const TEXTS = ['label', 'verifying', 'verified', 'verificationRequired', 'waitAlert', 'error', 'expired', 'footer', 'ariaLinkLabel', 'loading', 'reload', 'verify', 'cancel', 'enterCode', 'enterCodeAria', 'enterCodeFromImage', 'getAudioChallenge'];

    /** The widget's language, and its texts: localized(), or the `language` and `strings` options. */
    private ?string $language = null;

    /** The language given to the gateway itself (its `language` option, or its language attribute): it wins over localized()'s */
    private ?string $ownLanguage = null;

    /** @var array<string, string> */
    private array $strings = [];

    /** @var array<string, string> the texts given to the gateway itself: they win over localized()'s */
    private array $ownStrings = [];

    /**
     * @param array<string, string|bool> $attributes
     * @param array<string, mixed>       $configuration
     * @param array<string, string>      $strings       the widget's texts, by name (TEXTS)
     */
    public function __construct(
        string $hmacKey,
        private readonly DeriveKeyInterface $algorithm,
        private readonly ReplayStoreInterface $replays,
        private readonly int $cost = 5000,
        private readonly string $keyPrefix = '00',
        private readonly int $expires = 600,
        private readonly ?string $challengeUrl = null,
        private readonly string $field = 'altcha',
        private readonly ?string $script = AltchaGatewayFactory::SCRIPT,
        private readonly ?string $integrity = AltchaGatewayFactory::INTEGRITY,
        private readonly array $attributes = [],
        private readonly array $configuration = [],
        ?string $language = null,
        array $strings = [],
    ) {
        $this->altcha = new Altcha($hmacKey);
        $language ??= \is_string($attributes['language'] ?? null) ? $attributes['language'] : null;
        $this->language = $this->ownLanguage = self::tag($language);
        $this->strings = $this->ownStrings = self::given($strings);
    }

    public function texts(): array
    {
        return self::TEXTS;
    }

    public function language(): ?string
    {
        return $this->ownLanguage;
    }

    public function localized(string $language, array $strings = []): static
    {
        $copy = clone $this;
        $copy->language = $this->ownLanguage ?? self::tag($language);
        $copy->strings = array_replace(self::given($strings), $this->ownStrings);

        return $copy;
    }

    /** "fr_FR" as the widget matches it: "fr-fr" */
    private static function tag(?string $language): ?string
    {
        return null !== $language && '' !== trim($language) ? strtolower(str_replace('_', '-', trim($language))) : null;
    }

    /**
     * @param array<mixed> $strings
     *
     * @return array<string, string> the texts that say something
     */
    private static function given(array $strings): array
    {
        return array_filter($strings, static fn ($text, $name) => \is_string($name) && \is_string($text) && '' !== $text, \ARRAY_FILTER_USE_BOTH);
    }

    public function getName(): string
    {
        return 'altcha';
    }

    public function getTitle(): string
    {
        return 'ALTCHA';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(challenge: true, thirdParty: false, cookies: false, scores: false, actions: true);
    }

    public function issue(?string $action = null): array
    {
        $data = ['issued' => time()];
        if (null !== $action) {
            $data['action'] = $action;
        }

        return $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $this->algorithm,
            cost: $this->cost,
            keyPrefix: $this->keyPrefix,
            expiresAt: time() + $this->expires,
            data: $data,
        ))->toArray();
    }

    public function widget(?string $action = null): Widget
    {
        $challenge = null === $this->challengeUrl
            ? json_encode($this->issue($action), \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)
            : $this->challengeUrl.(null === $action ? '' : (str_contains($this->challengeUrl, '?') ? '&' : '?').'action='.rawurlencode($action));
        $remote = null !== $this->script && preg_match('~^(?:https?:)?//~i', $this->script);
        $origin = $remote ? (string) preg_replace('~^((?:https?:)?//[^/]+).*$~i', '$1', $this->script) : null;

        return new Widget(
            field: $this->field,
            script: $this->script,
            scriptAttributes: ['type' => 'module', 'async' => true, 'defer' => true] + (null !== $this->integrity ? ['integrity' => $this->integrity] : []) + ($remote ? ['crossorigin' => 'anonymous'] : []),
            tag: 'altcha-widget',
            attributes: ['challenge' => $challenge, 'name' => $this->field] + (null !== $this->language ? ['language' => $this->language] : []) + array_diff_key($this->attributes, ['language' => true]) + ([] !== $this->configuration ? ['configuration' => json_encode($this->configuration, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)] : []),
            inline: $this->inline(),
            action: $action,
            thirdParty: false,
            cookies: false,
            origins: null !== $origin ? [str_starts_with($origin, '//') ? 'https:'.$origin : $origin] : [],
        );
    }

    /**
     * The texts, registered with the widget once its script has run: altcha
     * 3.3.0 reads them from its registry (globalThis.$altcha.i18n), by the
     * widget's language - the English ones kept for any left out. Null when
     * there are none to give.
     */
    private function inline(): ?string
    {
        if ([] === $this->strings) {
            return null;
        }
        $language = json_encode($this->language ?? 'en', \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_THROW_ON_ERROR);
        $strings = json_encode($this->strings, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_THROW_ON_ERROR);

        return '(function(l,s){function a(){var g=globalThis.$altcha;if(!g||!g.i18n){return false}g.i18n.set(l,Object.assign({},g.i18n.get("en")||{},g.i18n.get(l)||{},s));return true}'
            .'if(!a()){var t=setInterval(function(){if(a()){clearInterval(t)}},50);setTimeout(function(){clearInterval(t)},15000)}})('.$language.','.$strings.');';
    }

    public function verify(Attempt $attempt): Verdict
    {
        if ($attempt->isEmpty()) {
            return Verdict::fail(Verdict::MISSING);
        }
        try {
            $payload = Payload::fromBase64($attempt->token);
        } catch (\InvalidArgumentException) {
            return Verdict::fail(Verdict::INVALID, ['malformed']);
        }

        $result = $this->altcha->verifySolution(new VerifySolutionOptions($payload, $this->algorithm));
        if ($result->expired) {
            return Verdict::fail(Verdict::EXPIRED, ['expired']);
        }
        if (!$result->verified) {
            return Verdict::fail(Verdict::INVALID, [$result->invalidSignature ? 'invalidSignature' : 'invalidSolution']);
        }

        $parameters = $payload->challenge->parameters;
        $data = $parameters->data ?? [];
        $action = isset($data['action']) && \is_string($data['action']) ? $data['action'] : null;
        $at = isset($data['issued']) && \is_int($data['issued']) ? new \DateTimeImmutable('@'.$data['issued']) : null;
        if (null !== $attempt->action && $attempt->action !== $action) {
            return new Verdict(false, action: $action, at: $at, reasons: [Verdict::ACTION]);
        }
        // Signed and solved: now, only once. Kept until it would have lapsed anyway.
        $until = new \DateTimeImmutable('@'.(int) ceil((float) ($parameters->expiresAt ?: time() + $this->expires)));
        if (!$this->replays->spend('altcha:'.$payload->challenge->signature, $until)) {
            return new Verdict(false, action: $action, at: $at, reasons: [Verdict::DUPLICATE]);
        }

        return new Verdict(true, action: $action, at: $at);
    }
}
