<?php

namespace Omniguard\Altcha\Tests;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Omniguard\Altcha\AltchaGateway;
use Omniguard\Altcha\AltchaGatewayFactory;
use Omniguard\Exception\InvalidConfigException;
use Omniguard\Model\Attempt;
use Omniguard\Model\Verdict;
use Omniguard\Replay\InMemoryReplayStore;
use PHPUnit\Framework\TestCase;

/**
 * The whole way, as a browser goes it: a challenge issued, solved by the
 * library itself (what the widget does in its workers), posted base64 as
 * the widget posts it, checked - then posted again. A low cost keeps it
 * quick; nothing else differs.
 */
final class AltchaGatewayTest extends TestCase
{
    private const KEY = 'a-long-random-secret-of-the-site';

    /** @param array<string, mixed> $options */
    private function gateway(array $options = [], ?InMemoryReplayStore $replays = null): AltchaGateway
    {
        $gateway = (new AltchaGatewayFactory($replays ?? new InMemoryReplayStore()))->create($options + ['hmac_key' => self::KEY, 'cost' => 10]);
        self::assertInstanceOf(AltchaGateway::class, $gateway);

        return $gateway;
    }

    /** @param array<string, mixed> $challenge as issue() gives it */
    private static function solve(array $challenge, string $algorithm = 'PBKDF2/SHA-256'): string
    {
        $challenge = Challenge::fromArray($challenge);
        $solution = (new Altcha())->solveChallenge(new SolveChallengeOptions(algorithm: AltchaGatewayFactory::algorithm($algorithm), challenge: $challenge));
        self::assertNotNull($solution);

        return (new Payload($challenge, $solution))->toBase64();
    }

    public function testASolutionPassesOnceAndIsRefusedTheSecondTime(): void
    {
        $gateway = $this->gateway();
        $token = self::solve($gateway->issue('contact'));

        $verdict = $gateway->verify(new Attempt($token, '192.0.2.10', 'contact'));
        self::assertTrue($verdict->passed, implode(', ', $verdict->codes));
        self::assertSame('contact', $verdict->action);
        self::assertEqualsWithDelta(time(), $verdict->at?->getTimestamp(), 5);
        self::assertNull($verdict->score, 'a proof of work, not a score');

        $again = $gateway->verify(new Attempt($token, '192.0.2.10', 'contact'));
        self::assertFalse($again->passed);
        self::assertSame([Verdict::DUPLICATE], $again->reasons);
    }

    public function testTheStoreIsWhatRemembersAcrossRequests(): void
    {
        $replays = new InMemoryReplayStore();
        $token = self::solve($this->gateway([], $replays)->issue());

        self::assertTrue($this->gateway([], $replays)->verify(new Attempt($token))->passed, 'one request');
        self::assertTrue($this->gateway([], $replays)->verify(new Attempt($token))->failedFor(Verdict::DUPLICATE), 'another, the same store');
        self::assertTrue($this->gateway([], new InMemoryReplayStore())->verify(new Attempt($token))->passed, 'a store that forgot: why it must be shared');
    }

    public function testTheActionIsSignedAndCheckedBack(): void
    {
        $gateway = $this->gateway();

        $verdict = $gateway->verify(new Attempt(self::solve($gateway->issue('contact')), action: 'signup'));
        self::assertSame([Verdict::ACTION], $verdict->reasons);
        self::assertSame('contact', $verdict->action);
        self::assertTrue($gateway->verify(new Attempt(self::solve($gateway->issue()), action: null))->passed, 'no action expected: none checked');
        self::assertTrue($gateway->verify(new Attempt(self::solve($gateway->issue()), action: 'contact'))->failedFor(Verdict::ACTION), 'issued for no action, expected for one');
    }

    public function testWhatTheSiteDidNotIssueIsRefused(): void
    {
        $gateway = $this->gateway();
        $other = (new AltchaGatewayFactory())->create(['hmac_key' => 'another-site-s-secret', 'cost' => 10]);

        self::assertSame([Verdict::MISSING], $gateway->verify(new Attempt(''))->reasons);
        self::assertSame(['malformed'], $gateway->verify(new Attempt('not base64 at all'))->codes);
        self::assertSame(['malformed'], $gateway->verify(new Attempt(base64_encode('{"challenge":null,"solution":null,"test":true}')))->codes, 'the widget\'s test mode');
        self::assertSame(['invalidSignature'], $gateway->verify(new Attempt(self::solve($other->issue())))->codes, 'signed with another key');

        $challenge = Challenge::fromArray($gateway->issue());
        $wrong = (new Payload($challenge, new Solution(1, str_repeat('ab', 32), 0.1)))->toBase64();
        self::assertSame(['invalidSolution'], $gateway->verify(new Attempt($wrong))->codes);
        self::assertSame([Verdict::INVALID], $gateway->verify(new Attempt($wrong))->reasons);
    }

    public function testAChallengePastItsTimeIsExpired(): void
    {
        $algorithm = AltchaGatewayFactory::algorithm('PBKDF2/SHA-256');
        $challenge = (new Altcha(self::KEY))->createChallenge(new CreateChallengeOptions(algorithm: $algorithm, cost: 10, expiresAt: time() - 1));
        $solution = (new Altcha())->solveChallenge(new SolveChallengeOptions(algorithm: $algorithm, challenge: $challenge));

        $verdict = $this->gateway()->verify(new Attempt((new Payload($challenge, $solution))->toBase64()));
        self::assertSame([Verdict::EXPIRED], $verdict->reasons);
    }

    public function testTheWidgetCarriesItsChallengeInlineFromTheCdnByDefault(): void
    {
        $widget = $this->gateway(['attributes' => ['auto' => 'onsubmit', 'language' => 'fr'], 'configuration' => ['hideFooter' => true]])->widget('contact');

        self::assertSame(['altcha', 'altcha-widget', 'contact', false, false], [$widget->field, $widget->tag, $widget->action, $widget->thirdParty, $widget->cookies]);
        self::assertSame(AltchaGatewayFactory::SCRIPT, $widget->script);
        self::assertSame(['type' => 'module', 'async' => true, 'defer' => true, 'integrity' => AltchaGatewayFactory::INTEGRITY, 'crossorigin' => 'anonymous'], $widget->scriptAttributes);
        self::assertSame(['https://cdn.jsdelivr.net'], $widget->origins, 'the script\'s host sees the visitor: serve it yourself and nobody does');
        $challenge = json_decode((string) $widget->attributes['challenge'], true);
        self::assertSame(['parameters', 'signature'], array_keys($challenge));
        self::assertSame(['PBKDF2/SHA-256', 10, '00', 'contact'], [$challenge['parameters']['algorithm'], $challenge['parameters']['cost'], $challenge['parameters']['keyPrefix'], $challenge['parameters']['data']['action']]);
        self::assertSame(['name' => 'altcha', 'auto' => 'onsubmit', 'language' => 'fr', 'configuration' => '{"hideFooter":true}'], array_diff_key($widget->attributes, ['challenge' => 1]));
        self::assertStringStartsWith('<script src="https://cdn.jsdelivr.net/npm/altcha@3.3.0/dist/main/altcha.min.js" type="module" async defer integrity="sha256-', $widget->html());
        self::assertStringContainsString('<altcha-widget challenge="{&quot;parameters&quot;:', $widget->html());
    }

    public function testASelfHostedScriptAndAChallengeRouteLeaveNoOrigin(): void
    {
        $widget = $this->gateway(['script' => '/js/altcha.min.js', 'challenge_url' => '/omniguard/forms/challenge', 'field' => 'captcha'])->widget('contact');

        self::assertSame('/omniguard/forms/challenge?action=contact', $widget->attributes['challenge']);
        self::assertSame('captcha', $widget->field);
        self::assertSame([], $widget->origins);
        self::assertFalse($widget->reachesOthers());
        self::assertSame(['type' => 'module', 'async' => true, 'defer' => true], $widget->scriptAttributes, 'no integrity for a file of the site\'s');
        self::assertSame('/c?k=1&action=a%20b', $this->gateway(['challenge_url' => '/c?k=1'])->widget('a b')->attributes['challenge']);
    }

    public function testOtherAlgorithmsAndWhatIsMisconfigured(): void
    {
        $gateway = $this->gateway(['algorithm' => 'SHA-256', 'cost' => 1]);
        self::assertTrue($gateway->verify(new Attempt(self::solve($gateway->issue(), 'SHA-256')))->passed);
        self::assertSame(['challenge'], $gateway->capabilities()->questions());

        foreach ([['algorithm' => 'MD5'], ['key_prefix' => 'zz'], ['hmac_key' => '']] as $options) {
            try {
                (new AltchaGatewayFactory())->create($options + ['hmac_key' => self::KEY]);
                self::fail(json_encode($options));
            } catch (InvalidConfigException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
