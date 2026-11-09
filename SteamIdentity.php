<?php

namespace Omniplay\Steam;

use Omniplay\Exception\IdentityRefusedException;
use Omniplay\Exception\ProviderException;
use Omniplay\Http\JsonClient;
use Omniplay\IdentityInterface;
use Omniplay\Model\Identity;
use Omniplay\Secret\SecretsInterface;

/**
 * A player's Steam session ticket verified by Steam, on the platform:
 * ISteamUserAuth/AuthenticateUserTicket (GET, the Steamworks Web API) with
 * the publisher Web API key - read by its reference at each call, never
 * returned - the game's app id, the ticket as hex, and the identity string
 * the game passed to GetAuthTicketForWebApi when one is configured. The
 * answer is the player's SteamID ("steam:<SteamID64>"), or a refusal that
 * quotes Steam.
 *
 * SteamProvider answers IdentityInterface through it when the "appid"
 * option is given. Lobbies are not here: they stay in the game, through
 * the Steamworks SDK.
 */
final class SteamIdentity implements IdentityInterface
{
    public const PLATFORM = 'steam';

    /**
     * @param string  $key          the reference of the publisher Web API key (an environment variable's name, a vault's key)
     * @param ?string $identity     the string the game passes to GetAuthTicketForWebApi; Steam then authenticates only the tickets made with it
     * @param bool    $refuseBanned a player banned by VAC or by the publisher is refused, not answered
     */
    public function __construct(
        private readonly JsonClient $json,
        private readonly SecretsInterface $secrets,
        private readonly string $key,
        private readonly int $appid,
        private readonly ?string $identity = null,
        private readonly bool $refuseBanned = false,
        private readonly string $api = 'https://partner.steam-api.com',
    ) {
    }

    /** Whom it asks, for a person to read: Capabilities::$where. */
    public function where(): string
    {
        return \sprintf('Steam Web API at %s, app %d', $this->api, $this->appid);
    }

    public function platform(): string
    {
        return self::PLATFORM;
    }

    /**
     * The player whose session ticket this is (the hex the game made of
     * GetAuthTicketForWebApi's bytes). Claims: "owner" (the SteamID that owns
     * the game, another when it is lent through Family Sharing), "vac_banned",
     * "publisher_banned", "appid".
     */
    public function verify(string $proof): Identity
    {
        $ticket = trim($proof);
        if ('' === $ticket || 1 !== preg_match('~^(?:[0-9a-fA-F]{2})+$~', $ticket)) {
            throw IdentityRefusedException::refused('Steam', 'ticket', 'it is not a session ticket written in hex (GetAuthTicketForWebApi\'s bytes, two hex digits each); Steam was not asked');
        }
        $answer = $this->json->get($this->api.'/ISteamUserAuth/AuthenticateUserTicket/v1/', [
            'key' => $this->secrets->secret($this->key),
            'appid' => $this->appid,
            'ticket' => $ticket,
            'identity' => $this->identity,
        ]) ?? throw new ProviderException('Steam answered 404 for GET /ISteamUserAuth/AuthenticateUserTicket/v1/.');

        $error = $answer->data('response')->data('error');
        if ([] !== $error->keys()) {
            throw IdentityRefusedException::refused('Steam', 'ticket', ($error->string('errordesc') ?? 'no reason given').(null !== $error->string('errorcode') ? ' (error '.$error->string('errorcode').')' : ''));
        }
        $params = $answer->data('response')->data('params');
        $steamid = $params->string('steamid');
        if ('OK' !== $params->string('result') || null === $steamid || 1 !== preg_match('~^[1-9]\d*$~', $steamid)) {
            if (null !== $params->string('result') && 'OK' !== $params->string('result')) {
                throw IdentityRefusedException::refused('Steam', 'ticket', 'result '.$params->string('result'));
            }

            throw new ProviderException('Steam answered no verdict for GET /ISteamUserAuth/AuthenticateUserTicket/v1/: neither response.params with a SteamID nor response.error.');
        }
        $vac = true === $params->bool('vacbanned');
        $publisher = true === $params->bool('publisherbanned');
        if ($this->refuseBanned && ($vac || $publisher)) {
            throw IdentityRefusedException::refused('Steam', 'player '.$steamid, $publisher ? 'banned by the publisher' : 'banned by VAC');
        }

        return new Identity(self::PLATFORM, $steamid, [
            'owner' => $params->string('ownersteamid') ?? $steamid,
            'vac_banned' => $vac,
            'publisher_banned' => $publisher,
            'appid' => $this->appid,
        ]);
    }
}
