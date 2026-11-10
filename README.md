# omniplay/steam

[omnisense/omniplay](https://github.com/glitchr-omnisense/omniplay)'s Steam, under any PHP host:

- **an app's store record** (`store.steampowered.com/api/appdetails`, no key): name, short
  description, release date (read in the store's languages: "18 Apr, 2011", "18 avr. 2011"),
  developers, publishers, genres, categories, platforms, price in the country asked, header,
  background and screenshots, Metacritic score;
- **the store's search** (`/api/storesearch`, no key);
- **a player's library** and **ownership** with a Steam Web API key
  (`IPlayerService/GetOwnedGames`); a player is a 64-bit Steam ID, and a private profile reads as
  an empty library. A publisher's `ISteamUser/CheckAppOwnership` (publisher key) is not used;
- **a player's identity**, on the platform, with the game's app id and its publisher key: the
  player's Steam session ticket verified through `ISteamUserAuth/AuthenticateUserTicket`, answering
  their SteamID (`steam:<SteamID64>`) or refusing with Steam's reason (`IdentityInterface`). It
  runs on the platform, never in the game client.

One provider, factory `steam`, implementing the catalogue's contracts and identity.

```php
$steam = (new SteamFactory($httpClient))->create(['key' => getenv('STEAM_WEB_API_KEY'), 'language' => 'fr', 'country' => 'FR']);
$portal = $steam->game(GameRef::steam(620));

$steam = (new SteamFactory($httpClient))->create([
    'appid' => 480,                          // the game's Steam app id
    'publisher_key' => 'STEAM_PUBLISHER_KEY', // the publisher Web API key's reference: an environment variable's name
    'identity' => 'omniplay',                // the string the game passes to GetAuthTicketForWebApi
]);
$player = $steam->verify($request->get('ticket'));   // Identity "steam:76561197960287930", or IdentityRefusedException
$seat = $seating->seat(new Service('game', secret: 'GAME_TICKET_SECRET'), new SeatRequest($player, 'eu'));
```

Options: `key`, `language` ("english", or an ISO code: "fr" asks "french"), `country` ("US"),
`user_agent`, `store`, `api`; for identity `appid`, `publisher_key`, `identity`, `refuse_banned`,
`partner_api` ("https://partner.steam-api.com"). See [docs/index.md](docs/index.md).

## Lobbies stay in the game

omniplay/steam does not make, list or join lobbies: they stay in the game, through the Steamworks
SDK (`ISteamMatchmaking`). The lobby host asks the platform for a seat (with its Steam ticket, as
above); the platform answers the server's address and an omniplay ticket. The host puts both in
the lobby's metadata (`SetLobbyData`), and the other players read them and connect with them; the
game server checks the ticket by itself, as for any player. A game that wants one ticket per player
has each player ask the platform for their own seat on that server instead.

## Valve's terms, as read on 2026-10-24

From the [Steam Web API terms](https://steamcommunity.com/dev/apiterms): "one hundred thousand
(100,000) calls to the Steam Web API per day"; Valve's name and logos only as the documentation
describes, no implied endorsement, links to Valve without "nofollow"; Steam data about a player
retrieved only as that player requests; a privacy policy saying what is stored. The store's
`appdetails` and `storesearch` are the storefront's own endpoints, not documented by Valve: keep
to a gentle pace and keep what you read.

## Verified, and not

- Verified (the core's harness, PHP 8.4): Portal 2's record in French with its French price, an
  unknown app, a search - answers recorded on 2026-10-24 -; the library and ownership on an answer
  shaped after the Web API's documentation (no key); `BareTest`; PHPStan max.
- Verified live (no key, 2026-10-24): Portal 2's record in English with its US price, a search.
- Verified (2026-11-08, the identity; 2026-11-09, both in one provider): the identity's requests and
  answers on Symfony's `MockHttpClient` against the Steamworks Web API reference, a verified player
  seated under their SteamID through the core's registry.
- Not verified live: the library and ownership (no Web API key was used), the identity (no
  publisher key, no game ticket).

## Responsibility

An independent, unofficial client, affiliated with, endorsed by or sponsored by none of the services
it reaches. The MIT licence covers this code only and grants no right to the service or its data:
whoever runs it complies with the terms above and obtains what they require, and is responsible for
its publisher key and the players' data it keeps. The authors publish
it free of charge, make no commercial use of it and accept no liability for its use.

## Usage policy

The package declares its usage policy as data, in [policy.json](policy.json) (omnisense/contracts' schema). Steam's terms could not be read from here on 2026-11-10 (steamcommunity.com timed out), so the limits and rights are declared unknown (`verifiedAt` null). The 100,000 calls a day the Web API terms are quoted for, and the storefront's unofficial 200 requests per 5 minutes, sit in the notes, not in the limits. The credit is Valve's trademark line. One `policy.json` covers both contracts, the store and the identity check, since `SteamProvider` is both.

## Licence

MIT.
