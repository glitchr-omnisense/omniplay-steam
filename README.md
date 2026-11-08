# omniplay/steam

[omnisense/omniplay](https://github.com/glitchr-omnisense/omniplay)'s Steam player identity, under
any PHP host: the platform verifies a player's Steam session ticket with Steam and answers the
player's SteamID, or refuses with Steam's reason. It implements omniplay's `IdentityInterface`; it
runs on the platform, never in the game client.

```php
use Omniplay\Model\{SeatRequest, Service};
use Omniplay\Steam\SteamFactory;

$steam = (new SteamFactory())->create([
    'appid' => 480,                 // the game's Steam app id
    'key' => 'STEAM_WEB_API_KEY',   // the publisher Web API key's reference: an environment variable's name
    'identity' => 'omniplay',       // the string the game passes to GetAuthTicketForWebApi
]);

$player = $steam->verify($request->get('ticket'));   // Identity "steam:76561197960287930", or IdentityRefusedException
$seat = $seating->seat(new Service('game', secret: 'GAME_TICKET_SECRET'), new SeatRequest($player, 'eu'));
```

## The flow

1. The game client calls `ISteamUser::GetAuthTicketForWebApi("omniplay")`, waits for
   `GetTicketForWebApiResponse_t`, and sends the ticket's bytes as hex to the application, with its
   request to play.
2. The application calls `verify()`: `GET
   https://partner.steam-api.com/ISteamUserAuth/AuthenticateUserTicket/v1/?key=...&appid=...&ticket=...&identity=...`.
3. Steam answers the player's SteamID (`response.params`: `result` OK, `steamid`, `ownersteamid`,
   `vacbanned`, `publisherbanned`): `verify()` returns `Identity('steam', <SteamID64>)`, its claims
   `owner` (another SteamID when the game is lent through Family Sharing), `vac_banned`,
   `publisher_banned`, `appid`. Or Steam refuses (`response.error`: `errorcode`, `errordesc`):
   `verify()` throws `IdentityRefusedException` - `Steam refused the ticket: Invalid ticket (error
   101).` A proof that is not hex is refused without asking Steam.
4. The application seats the player under `steam:<SteamID64>`: the omniplay ticket's `sub`.

## Lobbies stay in the game

omniplay/steam does not make, list or join lobbies: they stay in the game, through the Steamworks
SDK (`ISteamMatchmaking`). The lobby host asks the platform for a seat (with its Steam ticket, as
above); the platform answers the server's address and an omniplay ticket. The host puts the
server address and the omniplay ticket in the lobby's metadata (`SetLobbyData`), and the other
players read them and connect with them; the game server checks the ticket by itself, as for any
player. A game that wants one ticket per player has each player ask the platform for their own
seat on that server instead.

## Options

| Option | Default | |
|---|---|---|
| `appid` | required | the game's Steam app id |
| `key` | `STEAM_WEB_API_KEY` | the publisher Web API key's reference, read through the factory's `SecretsInterface` (the environment by default) at each call; in no option, no exception, nothing returned |
| `identity` | none | sent as `identity`: Steam then authenticates only the tickets made with that string; not sent when none |
| `refuse_banned` | false | a player banned by VAC or by the publisher is refused rather than answered with the ban in the claims |
| `api` | `https://partner.steam-api.com` | Steam's documented host for a publisher key; `https://api.steampowered.com` is accepted too |
| `user_agent` | `omniplay/1.0 (+...)` | |

The key must be the publisher key of the ticket's app: Steam's reference says the call "requires
a publisher API key" and "MUST be called from a secure server". See
[docs/index.md](docs/index.md) for the errors.

## Verified, and not

- Verified (omnisense/omniplay's harness, PHP 8.4, 2026-11-08): the request (method, URL, query,
  headers), a valid ticket's SteamID and claims, Steam's refusal quoted, a proof that is not hex
  refused without a request, the bans answered or refused, no verdict and error statuses as
  `ProviderException` with the key masked, the key read by its reference, the factory's refusals,
  a verified player seated under their SteamID through the core's registry - on Symfony's
  `MockHttpClient`, against the Steamworks Web API reference; `BareTest`; PHPStan max.
- Not verified: anything live - there is no publisher key and no game ticket. The response's shape
  (`response.params`, `response.error`) is not printed in Steam's reference: it is the one Steam's
  clients read; the error code and text in the tests are examples.

## Responsibility

An independent client of the Steamworks Web API, affiliated with Valve in no way. Steam and
Steamworks are Valve's trademarks. The MIT licence covers this code only. Whoever runs it is
responsible for its publisher key and the players' data it keeps. The authors publish it free of
charge and accept no liability for its use.

## Licence

MIT.
