---
title: omniplay/steam
order: 10
---

# omniplay/steam

A player's Steam session ticket verified by Steam, on the platform. The provider implements
omnisense/omniplay's `IdentityInterface`: `platform()` is `steam`, `verify($hexTicket)` answers an
`Identity` or refuses. See omniplay's [Player identity](https://github.com/glitchr-omnisense/omniplay/blob/1.x/docs/identity.md).

## The call

`GET https://partner.steam-api.com/ISteamUserAuth/AuthenticateUserTicket/v1/` with:

| Parameter | |
|---|---|
| `key` | the publisher Web API key, read by its reference (`key` option) |
| `appid` | the `appid` option |
| `ticket` | the proof, as given: the hex of `GetAuthTicketForWebApi`'s bytes |
| `identity` | the `identity` option, when set |

Reference: [ISteamUserAuth](https://partner.steamgames.com/doc/webapi/ISteamUserAuth),
[User Authentication and Ownership](https://partner.steamgames.com/doc/features/auth).

## The answer

| Steam answers | `verify()` |
|---|---|
| `response.params` with `result` `OK` and a `steamid` | `Identity('steam', steamid, ['owner' => ownersteamid, 'vac_banned' => ..., 'publisher_banned' => ..., 'appid' => ...])` |
| the same, banned, with `refuse_banned` | `IdentityRefusedException`: `Steam refused the player <steamid>: banned by the publisher.` (or `by VAC`) |
| `response.error` | `IdentityRefusedException`: `Steam refused the ticket: <errordesc> (error <errorcode>).` |
| `response.params` with another `result` | `IdentityRefusedException`: `Steam refused the ticket: result <result>.` |
| neither, or a `steamid` of 0 | `ProviderException`: `Steam answered no verdict ...` |
| an error status | `ProviderException`: `Steam answered 403 for GET /ISteamUserAuth/AuthenticateUserTicket/v1/. ...`, the key masked |
| nothing (network) | `UnreachableException` |

A proof that is not hex (pairs of hex digits) is refused before any request:
`Steam refused the ticket: it is not a session ticket written in hex (...); Steam was not asked.`

## Errors of configuration

`InvalidConfigException`: no `appid`, an `appid` that is no unsigned 32-bit number, an `api` that
is not an https host, the key's secret not found (`No secret in the environment variable
"STEAM_WEB_API_KEY".`).

## Tests

`Tests/SteamIdentityTest.php` on Symfony's `MockHttpClient`, every request checked:

```sh
cd ../core/docker   # omnisense/omniplay's harness, OMNIPLAY_PLUGINS=../.. in its .env
docker compose run --rm omniplay test --testsuite steam
```
