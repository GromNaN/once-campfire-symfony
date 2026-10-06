<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The cookie that remembers the last room a user opened.
 *
 * The original application sets it as a permanent cookie, which is twenty
 * years.
 */
final class LastRoom
{
    public const COOKIE = 'last_room';
    public const LIFETIME = 630720000;
}
