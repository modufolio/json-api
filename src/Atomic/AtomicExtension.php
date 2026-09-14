<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

/**
 * The identity of the JSON:API Atomic Operations extension.
 *
 * Every member the extension adds is prefixed with its namespace, and a
 * document that uses them must list the URI in `jsonapi.ext` and in the
 * `ext` parameter of the `Content-Type` header — which is where the
 * constants below go.
 *
 * @see https://jsonapi.org/ext/atomic/
 */
final class AtomicExtension
{
    public const URI = 'https://jsonapi.org/ext/atomic';
    public const NAMESPACE = 'atomic';
    public const OPERATIONS = 'atomic:operations';
    public const RESULTS = 'atomic:results';

    private function __construct()
    {
    }
}
