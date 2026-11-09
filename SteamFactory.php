<?php

namespace Omniplay\Steam;

use Omniplay\Config;
use Omniplay\Exception\InvalidConfigException;
use Omniplay\Http\JsonClient;
use Omniplay\ProviderFactory;
use Omniplay\ProviderInterface;
use Omniplay\Secret\EnvironmentSecrets;
use Omniplay\Secret\SecretsInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "steam": options key (a Steam Web API key: only for a player's library and
 * ownership; the store needs none), language ("english", or "fr"),
 * country ("US": the prices'), user_agent, store, api; for a player's
 * identity, appid (the game's Steam app id: without it, no identity),
 * publisher_key (the reference of the publisher Web API key:
 * "STEAM_PUBLISHER_KEY", read through the secrets given, the environment by
 * default), identity (the string the game passes to GetAuthTicketForWebApi;
 * none by default), refuse_banned (false), partner_api
 * ("https://partner.steam-api.com").
 */
final class SteamFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null, private readonly ?SecretsInterface $secrets = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omniplay.factory_name' => 'steam',
            'omniplay.factory_title' => 'Steam',
            'omniplay.required_options' => [],
            'key' => null,
            'language' => 'english',
            'country' => 'US',
            'user_agent' => 'omniplay/1.0 (+https://github.com/glitchr-omnisense/omniplay)',
            'store' => 'https://store.steampowered.com',
            'api' => 'https://api.steampowered.com',
            'appid' => null,
            'publisher_key' => 'STEAM_PUBLISHER_KEY',
            'identity' => null,
            'refuse_banned' => false,
            'partner_api' => 'https://partner.steam-api.com',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $json = new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'Steam', ['Accept' => 'application/json', 'User-Agent' => (string) $c->string('user_agent')]);

        return new SteamProvider(
            $json,
            $c->string('key'),
            (string) $c->string('language'),
            (string) $c->string('country'),
            rtrim((string) $c->string('store'), '/'),
            rtrim((string) $c->string('api'), '/'),
            null === $c->get('appid') ? null : $this->identity($c, $json),
        );
    }

    /** The identity's verifier, from appid, publisher_key, identity, refuse_banned and partner_api. */
    private function identity(Config $c, JsonClient $json): SteamIdentity
    {
        $appid = $c->string('appid');
        if (null === $appid || 1 !== preg_match('~^[1-9]\d{0,9}$~', $appid) || (int) $appid > 4294967295) {
            throw new InvalidConfigException(\sprintf('The "steam" provider\'s appid is the game\'s Steam app id (an unsigned 32-bit number), not "%s".', $appid));
        }
        $api = rtrim((string) $c->string('partner_api'), '/');
        if (1 !== preg_match('~^https://[^/\s]+$~', $api)) {
            throw new InvalidConfigException(\sprintf('The "steam" provider\'s partner_api is an https host ("https://partner.steam-api.com", "https://api.steampowered.com"), not "%s".', $api));
        }
        $key = $c->string('publisher_key') ?? throw new InvalidConfigException('The "steam" provider verifies players with a publisher Web API key: its "publisher_key" reference is empty.');

        return new SteamIdentity($json, $this->secrets ?? new EnvironmentSecrets(), $key, (int) $appid, $c->string('identity'), $c->bool('refuse_banned'), $api);
    }
}
