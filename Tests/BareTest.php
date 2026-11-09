<?php

namespace Omniplay\Steam\Tests;

use Omniplay\Steam\SteamProvider;
use Omniplay\Testing\BareTestCase;

/** omniplay/steam's classes load with omnisense/omniplay and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return SteamProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
