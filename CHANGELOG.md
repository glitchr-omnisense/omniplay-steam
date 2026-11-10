# Changelog

## Unreleased (1.x)

### Changed
- 2026-11-10: the usage policy declared as data, in `policy.json` (omnisense/contracts' schema, named in `composer.json`'s `extra.omnisense.policy`). The provider implements `DeclaresUsagePolicyInterface` and gives the policy to its Capabilities. The factory hands the provider's Backoff to its JsonClient, so that a 429 and its Retry-After hold every call. `Tests/PolicyTest.php`.
- One package, both contracts: omnigame/steam's catalogue merged in on 2026-11-09, its history kept
  (this repository's merge of omnigame-steam at 95d62f3), when the omnigame family merged into
  omniplay. `SteamProvider` - an app's store record and the store's search with no key, a player's
  library and ownership with a Steam Web API key - is factory `steam`'s provider and implements
  `IdentityInterface` too, through `SteamIdentity`, when `appid` is configured; `SteamIdentity` is
  its verifier, no longer a provider of its own.
- The identity's options `key` and `api` are now `publisher_key` (its default reference
  `STEAM_PUBLISHER_KEY`, was `STEAM_WEB_API_KEY`) and `partner_api`: `key` and `api` are the store's
  (the Web API key itself, `https://api.steampowered.com`). `appid` is no longer required: without
  it the provider is the store's alone, and `verify()` is an `InvalidConfigException`.

### Added
- `omniplay/steam`: omnisense/omniplay's `IdentityInterface` over the Steamworks Web API.
  `SteamFactory` (factory `steam`: `appid`, `key`, `identity`, `refuse_banned`, `api`),
  `SteamIdentity`. A player's session ticket (hex) verified through
  `ISteamUserAuth/AuthenticateUserTicket/v1` with the publisher key read by its reference at each
  call; the answer an `Identity` (`steam:<SteamID64>`, claims `owner`, `vac_banned`,
  `publisher_banned`, `appid`), or an `IdentityRefusedException` quoting Steam.
