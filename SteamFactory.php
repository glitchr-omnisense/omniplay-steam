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
 * "steam": options appid (required: the game's Steam app id), key (the
 * reference of the publisher Web API key: "STEAM_WEB_API_KEY", read through
 * the secrets given, the environment by default), identity (the string the
 * game passes to GetAuthTicketForWebApi; none by default), refuse_banned
 * (false), api ("https://partner.steam-api.com"), user_agent.
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
            'omniplay.required_options' => ['appid', 'key'],
            'appid' => null,
            'key' => 'STEAM_WEB_API_KEY',
            'identity' => null,
            'refuse_banned' => false,
            'api' => 'https://partner.steam-api.com',
            'user_agent' => 'omniplay/1.0 (+https://github.com/glitchr-omnisense/omniplay)',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $appid = $c->string('appid');
        if (null === $appid || 1 !== preg_match('~^[1-9]\d{0,9}$~', $appid) || (int) $appid > 4294967295) {
            throw new InvalidConfigException(\sprintf('The "steam" provider\'s appid is the game\'s Steam app id (an unsigned 32-bit number), not "%s".', $appid));
        }
        $api = rtrim((string) $c->string('api'), '/');
        if (1 !== preg_match('~^https://[^/\s]+$~', $api)) {
            throw new InvalidConfigException(\sprintf('The "steam" provider\'s api is an https host ("https://partner.steam-api.com", "https://api.steampowered.com"), not "%s".', $api));
        }

        return new SteamIdentity(
            new JsonClient($this->http ?? HttpClient::create(['timeout' => 15]), 'Steam', ['Accept' => 'application/json', 'User-Agent' => (string) $c->string('user_agent')]),
            $this->secrets ?? new EnvironmentSecrets(),
            (string) $c->string('key'),
            (int) $appid,
            $c->string('identity'),
            $c->bool('refuse_banned'),
            $api,
        );
    }
}
