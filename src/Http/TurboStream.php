<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Tells whether the client asked for a Turbo Stream answer.
 *
 * The answer is a stream when the client says so in its Accept header, which
 * Turbo does on the requests it takes over. The framework no longer copies that
 * header into the request format, so the preferred format is read here instead:
 * the stream format is registered in config/packages/framework.yaml, which is
 * what lets the mime type be recognised.
 */
final class TurboStream
{
    public static function wants(Request $request): bool
    {
        return TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
    }
}
