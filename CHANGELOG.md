# Changelog

## Unreleased (1.x)

### Added
- `omniplay/steam`: omnisense/omniplay's `IdentityInterface` over the Steamworks Web API.
  `SteamFactory` (factory `steam`: `appid`, `key`, `identity`, `refuse_banned`, `api`),
  `SteamIdentity`. A player's session ticket (hex) verified through
  `ISteamUserAuth/AuthenticateUserTicket/v1` with the publisher key read by its reference at each
  call; the answer an `Identity` (`steam:<SteamID64>`, claims `owner`, `vac_banned`,
  `publisher_banned`, `appid`), or an `IdentityRefusedException` quoting Steam.
