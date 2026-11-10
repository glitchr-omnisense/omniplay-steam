<?php

namespace Omniplay\Steam\Tests;

use Omniplay\Exception\OmniplayException;
use Omniplay\Exception\UsageLimitReachedException;
use Omniplay\Model\Query;
use Omniplay\Registry;
use Omniplay\Steam\SteamFactory;
use Omniplay\Steam\SteamProvider;
use Omniplay\Testing\RecordedHttpClient;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** Steam's usage policy, as policy.json declares it, held through omniplay's registry. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = self::registry(self::answering())->policy('steam');

        self::assertSame('steam', $policy->provider);
        self::assertSame(null, null === $policy->rateLimits ? null : array_map(static fn (RateLimit $limit) => $limit->id(), $policy->rateLimits));
        self::assertSame(Permission::Unknown, $policy->rights->commercial);
        self::assertSame(true, $policy->rights->attribution->required);
        self::assertSame(false, $policy->requirements->key);
        self::assertSame(null, $policy->provenance->verifiedAt);
        self::assertSame(UsagePolicy::fromFile(SteamProvider::POLICY)->toArray(), $policy->toArray(), 'the registry runs the policy the package declares');
        self::assertSame($policy->toArray(), self::registry(self::answering())->get('steam')->capabilities()->policy()->toArray(), 'its Capabilities carry it too');
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $http = self::answering();
        $registry = self::registry($http, ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['search']]]]);

        $registry->search('steam')->search(new Query('portal'));
        $made = \count($http->requests);
        try {
            $registry->search('steam')->search(new Query('portal'));
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmniplayException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/minute/application/search', $e->limit?->id());
        }
        self::assertCount($made, $http->requests, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = RecordedHttpClient::routes(['~~' => [429, '', ['retry-after' => ['30']]]]);
        $registry = self::registry($http);

        try {
            $registry->search('steam')->search(new Query('portal'));
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        $made = \count($http->requests);
        try {
            $registry->search('steam')->search(new Query('portal'));
            self::fail('A call went out while Steam asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(29, $e->retryAfter);
        }
        self::assertCount($made, $http->requests, 'the wait held the next request back');
    }

    /** @param array<string, mixed> $policy */
    private static function registry(RecordedHttpClient $http, array $policy = []): Registry
    {
        return new Registry([new SteamFactory($http)], ['steam' => ['factory' => 'steam', 'options' => []] + ([] === $policy ? [] : ['policy' => $policy])]);
    }

    private static function answering(): RecordedHttpClient
    {
        return RecordedHttpClient::routes(['~/api/storesearch/~' => [200, '{"total": 0, "items": []}']]);
    }
}
