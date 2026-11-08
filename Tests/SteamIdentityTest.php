<?php

namespace Omniplay\Steam\Tests;

use Omniplay\Exception\IdentityRefusedException;
use Omniplay\Exception\InvalidConfigException;
use Omniplay\Exception\ProviderException;
use Omniplay\Fleet;
use Omniplay\Model\SeatRequest;
use Omniplay\Model\Server;
use Omniplay\Model\Service;
use Omniplay\Registry;
use Omniplay\Seating;
use Omniplay\Secret\FixedSecrets;
use Omniplay\Steam\SteamFactory;
use Omniplay\Steam\SteamIdentity;
use Omniplay\Storage\InMemoryStore;
use Omniplay\Ticket\TicketSigner;
use Omniplay\Tickets;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Steam's answers written by hand after the Steamworks Web API reference
 * (ISteamUserAuth/AuthenticateUserTicket: GET, key, appid, ticket,
 * identity) and the response shape Steam's clients read (response.params:
 * result, steamid, ownersteamid, vacbanned, publisherbanned;
 * response.error: errorcode, errordesc), on Symfony's MockHttpClient, with
 * no key and no ticket of a real game.
 */
final class SteamIdentityTest extends TestCase
{
    private const KEY = 'not-a-real-key';
    private const TICKET = '140000000A0B0C0D0E0F1011121314151617181920';
    private const PLAYER = '76561197960287930';
    private const URL = 'https://partner.steam-api.com/ISteamUserAuth/AuthenticateUserTicket/v1/';

    public function testAValidTicketAnswersThePlayersSteamId(): void
    {
        $recorder = new Recorder();
        $steam = self::steam($recorder->client([['~~', 200, self::ok()]]), ['identity' => 'omniplay']);

        $identity = $steam->verify(' '.self::TICKET.' ');

        self::assertSame('steam:'.self::PLAYER, $identity->player());
        self::assertSame(['owner' => '76561197960287931', 'vac_banned' => false, 'publisher_banned' => false, 'appid' => 480], $identity->claims, 'a game lent through Family Sharing: its owner is another');
        self::assertSame(['GET '.self::URL], $recorder->calls());
        self::assertSame(['key' => self::KEY, 'appid' => '480', 'ticket' => self::TICKET, 'identity' => 'omniplay'], $recorder->requests[0]['query']);
        self::assertSame('application/json', $recorder->requests[0]['headers']['accept'] ?? null);
        self::assertNull($recorder->requests[0]['body']);
        self::assertStringNotContainsString(self::KEY, (string) json_encode($identity->toArray()));
        self::assertSame(['contracts' => ['identity'], 'services' => [], 'regions' => [], 'where' => 'Steam Web API at https://partner.steam-api.com, app 480'], $steam->capabilities()->toArray());
    }

    public function testWithoutAnIdentityStringNoneIsSent(): void
    {
        $recorder = new Recorder();
        self::steam($recorder->client([['~~', 200, self::ok()]]), ['api' => 'https://api.steampowered.com/'])->verify(self::TICKET);

        self::assertSame(['GET https://api.steampowered.com/ISteamUserAuth/AuthenticateUserTicket/v1/'], $recorder->calls());
        self::assertSame(['key' => self::KEY, 'appid' => '480', 'ticket' => self::TICKET], $recorder->requests[0]['query']);
    }

    public function testATicketSteamRefusesIsRefusedAloud(): void
    {
        $this->expectException(IdentityRefusedException::class);
        $this->expectExceptionMessage('Steam refused the ticket: Invalid ticket (error 101).');
        self::steam((new Recorder())->client([['~~', 200, '{"response": {"error": {"errorcode": 101, "errordesc": "Invalid ticket"}}}']]))->verify(self::TICKET);
    }

    public function testWhatIsNotATicketIsRefusedWithoutAskingSteam(): void
    {
        $recorder = new Recorder();
        foreach (['', 'not hex', 'ABC', 'eyJyb29tIjoiZXUtMSJ9.0a1b'] as $proof) {
            try {
                self::steam($recorder->client([]))->verify($proof);
                self::fail($proof);
            } catch (IdentityRefusedException $e) {
                self::assertSame('Steam refused the ticket: it is not a session ticket written in hex (GetAuthTicketForWebApi\'s bytes, two hex digits each); Steam was not asked.', $e->getMessage());
            }
        }
        self::assertSame([], $recorder->requests);
    }

    public function testABannedPlayerIsAnsweredOrRefusedAsConfigured(): void
    {
        $banned = self::ok(true);

        self::assertSame(['owner' => self::PLAYER, 'vac_banned' => false, 'publisher_banned' => true, 'appid' => 480], self::steam((new Recorder())->client([['~~', 200, $banned]]))->verify(self::TICKET)->claims);

        $this->expectException(IdentityRefusedException::class);
        $this->expectExceptionMessage('Steam refused the player 76561197960287930: banned by the publisher.');
        self::steam((new Recorder())->client([['~~', 200, $banned]]), ['refuse_banned' => true])->verify(self::TICKET);
    }

    public function testAnAnswerThatIsNoVerdictAndAnErrorStatusAreTheProvidersNeverAPlayer(): void
    {
        foreach ([
            [200, '{"response": {}}', 'Steam answered no verdict for GET /ISteamUserAuth/AuthenticateUserTicket/v1/: neither response.params with a SteamID nor response.error.'],
            [200, '{"response": {"params": {"result": "OK", "steamid": "0"}}}', 'Steam answered no verdict for GET /ISteamUserAuth/AuthenticateUserTicket/v1/: neither response.params with a SteamID nor response.error.'],
            [403, '<html><body>Forbidden: verify your key=not-a-real-key</body></html>', 'Steam answered 403 for GET /ISteamUserAuth/AuthenticateUserTicket/v1/. Forbidden: verify your key=***'],
            [404, '', 'Steam answered 404 for GET /ISteamUserAuth/AuthenticateUserTicket/v1/.'],
        ] as [$status, $body, $message]) {
            try {
                self::steam((new Recorder())->client([['~~', $status, $body]]))->verify(self::TICKET);
                self::fail($message);
            } catch (ProviderException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        $this->expectException(IdentityRefusedException::class);
        $this->expectExceptionMessage('Steam refused the ticket: result Invalid.');
        self::steam((new Recorder())->client([['~~', 200, '{"response": {"params": {"result": "Invalid"}}}']]))->verify(self::TICKET);
    }

    public function testTheKeyIsReadByItsReferenceAndTheFactoryRefusesWhatIsNoAppId(): void
    {
        foreach ([
            [[], 'The "steam" provider needs: appid.'],
            [['appid' => 'spacewar'], 'The "steam" provider\'s appid is the game\'s Steam app id (an unsigned 32-bit number), not "spacewar".'],
            [['appid' => 480, 'api' => 'http://api.steampowered.com'], 'The "steam" provider\'s api is an https host ("https://partner.steam-api.com", "https://api.steampowered.com"), not "http://api.steampowered.com".'],
        ] as [$options, $message]) {
            try {
                (new SteamFactory((new Recorder())->client([]), self::secrets()))->create($options);
                self::fail($message);
            } catch (InvalidConfigException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        $steam = (new SteamFactory((new Recorder())->client([['~~', 200, self::ok()]]), new FixedSecrets([])))->create(['appid' => 480, 'key' => 'OTHER_KEY']);
        self::assertInstanceOf(SteamIdentity::class, $steam);
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No secret "OTHER_KEY".');
        $steam->verify(self::TICKET);
    }

    public function testAVerifiedSteamPlayerIsSeatedUnderItsSteamId(): void
    {
        $registry = new Registry([new SteamFactory((new Recorder())->client([['~~', 200, self::ok()]]), self::secrets())], ['steam' => ['factory' => 'steam', 'options' => ['appid' => 480]]]);
        $secrets = new FixedSecrets(['GAME_SECRET' => 'not-a-real-secret']);
        $fleet = new Fleet(new InMemoryStore());
        $fleet->register(new Server('eu-1', 'game', '203.0.113.7:7777', 8));
        $seating = new Seating($fleet, new Tickets($secrets));

        $identity = $registry->identityFor('steam')?->verify(self::TICKET);
        self::assertNotNull($identity);
        $seat = $seating->seat(new Service('game', secret: 'GAME_SECRET'), new SeatRequest($identity));

        self::assertNotNull($seat);
        self::assertSame('steam:'.self::PLAYER, TicketSigner::verify($seat->ticket->token, 'not-a-real-secret', 'eu-1', time())['sub'] ?? null);
    }

    /** @param array<string, mixed> $options */
    private static function steam(HttpClientInterface $http, array $options = []): SteamIdentity
    {
        $steam = (new SteamFactory($http, self::secrets()))->create($options + ['appid' => 480]);
        self::assertInstanceOf(SteamIdentity::class, $steam);

        return $steam;
    }

    private static function secrets(): FixedSecrets
    {
        return new FixedSecrets(['STEAM_WEB_API_KEY' => self::KEY]);
    }

    private static function ok(bool $banned = false): string
    {
        return '{"response": {"params": {"result": "OK", "steamid": "'.self::PLAYER.'", "ownersteamid": "'.($banned ? self::PLAYER : '76561197960287931').'", "vacbanned": false, "publisherbanned": '.($banned ? 'true' : 'false').'}}}';
    }
}
