<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Tells whether the client is able to read a Turbo Stream answer.
 *
 * The client says so in its Accept header, which Turbo does on the requests it
 * takes over. The framework no longer copies that header into the request
 * format, so the preferred format is read here instead: the stream format is
 * registered in config/packages/framework.yaml, which is what lets the mime
 * type be recognised.
 *
 * Turbo announces this on every form post, including the ones whose answer is
 * a page it will follow to. So a yes here says what the client can read, not
 * what the answer is. A controller that really answers with a stream marks the
 * request with setRequestFormat() before it renders.
 */
final class TurboStream
{
    public static function wants(Request $request): bool
    {
        return TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
    }
}
