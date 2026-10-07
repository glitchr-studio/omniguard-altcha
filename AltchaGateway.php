<?php

namespace Omniguard\Altcha;

use AltchaOrg\Altcha\Algorithm\DeriveKeyInterface;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Omniguard\ChallengeIssuerInterface;
use Omniguard\Model\Attempt;
use Omniguard\Model\Capabilities;
use Omniguard\Model\Verdict;
use Omniguard\Model\Widget;
use Omniguard\Replay\ReplayStoreInterface;

/**
 * An ALTCHA challenge issued, solved by the visitor's browser, checked here:
 * its signature (the site's key), its expiry, the solution, the action it
 * was issued for - and, since ALTCHA itself cannot tell a solution it saw
 * before, that it was not posted already (the replay store).
 *
 * The action is signed into the challenge's data with the time it was
 * issued: {"action": "contact", "issued": 1791363600}.
 */
final class AltchaGateway implements ChallengeIssuerInterface
{
    private readonly Altcha $altcha;

    /**
     * @param array<string, string|bool> $attributes
     * @param array<string, mixed>       $configuration
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
    ) {
        $this->altcha = new Altcha($hmacKey);
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
            attributes: ['challenge' => $challenge, 'name' => $this->field] + $this->attributes + ([] !== $this->configuration ? ['configuration' => json_encode($this->configuration, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)] : []),
            action: $action,
            thirdParty: false,
            cookies: false,
            origins: null !== $origin ? [str_starts_with($origin, '//') ? 'https:'.$origin : $origin] : [],
        );
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
