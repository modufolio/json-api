<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Http;

use InvalidArgumentException;
use Stringable;

/**
 * One media type as it appears in a `Content-Type` or `Accept` header.
 *
 * JSON:API 1.1 hangs its extension and profile negotiation on two media type
 * parameters, `ext` and `profile`, each a quoted, space-separated list of
 * URIs:
 *
 *     application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"; profile="https://example.com/p"
 *
 * The general-purpose negotiators refuse a media type carrying parameters
 * they were not told about, and none of them know that `ext` is a list. This
 * value object parses the header itself — quoted strings, `q` weights, the
 * lot — and keeps the two JSON:API parameters apart from every other one, so
 * {@see MediaTypeNegotiator} can apply the specification's rules and a
 * response can echo exactly the extensions and profiles it applied.
 */
final class MediaType implements Stringable
{
    public const JSON_API = 'application/vnd.api+json';

    /**
     * @param string               $type       Lower-cased `type/subtype`
     * @param list<string>         $extensions URIs from the `ext` parameter, in header order
     * @param list<string>         $profiles   URIs from the `profile` parameter, in header order
     * @param array<string, string> $parameters Every other parameter, keys lower-cased, `q` excluded
     * @param float                $quality    The `q` weight; 1.0 when absent
     */
    public function __construct(
        public readonly string $type,
        public readonly array $extensions = [],
        public readonly array $profiles = [],
        public readonly array $parameters = [],
        public readonly float $quality = 1.0,
    ) {
    }

    /**
     * The JSON:API media type, optionally modified by extensions and profiles.
     *
     * @param list<string> $extensions
     * @param list<string> $profiles
     */
    public static function jsonApi(array $extensions = [], array $profiles = []): self
    {
        return new self(type: self::JSON_API, extensions: $extensions, profiles: $profiles);
    }

    /**
     * Parse a single media type, as sent in a `Content-Type` header.
     *
     * @throws InvalidArgumentException when the value is not `type/subtype[; param=value]*`
     */
    public static function parse(string $value): self
    {
        $parts = self::splitOutsideQuotes(trim($value), ';');
        $type = strtolower(trim(array_shift($parts) ?? ''));

        if ($type === '' || !preg_match('#^[!\#$%&\'*+.^_`|~0-9a-z-]+/[!\#$%&\'*+.^_`|~0-9a-z-]+$#', $type)) {
            throw new InvalidArgumentException("'$value' is not a media type.");
        }

        $extensions = [];
        $profiles = [];
        $parameters = [];
        $quality = 1.0;

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            [$name, $raw] = array_pad(explode('=', $part, 2), 2, '');
            $name = strtolower(trim($name));
            $raw = trim($raw);

            if ($name === '') {
                throw new InvalidArgumentException("'$value' carries a parameter without a name.");
            }

            $unquoted = self::unquote($raw);

            switch ($name) {
                case 'ext':
                    $extensions = [...$extensions, ...self::uriList($unquoted)];
                    break;
                case 'profile':
                    $profiles = [...$profiles, ...self::uriList($unquoted)];
                    break;
                case 'q':
                    if (!is_numeric($unquoted)) {
                        throw new InvalidArgumentException("'$value' carries a non-numeric q weight.");
                    }
                    $quality = max(0.0, min(1.0, (float) $unquoted));
                    break;
                default:
                    $parameters[$name] = $unquoted;
            }
        }

        return new self(
            type: $type,
            extensions: $extensions,
            profiles: $profiles,
            parameters: $parameters,
            quality: $quality,
        );
    }

    /**
     * Parse an `Accept` header into its media types, most preferred first.
     *
     * Ordering follows RFC 9110: higher `q` first, then the more specific
     * type, then header order. Empty entries are skipped and an unparsable one
     * is dropped rather than failing the whole header — a client's stray
     * garbage should not turn every response into a 406.
     *
     * @return list<self>
     */
    public static function parseList(string $header): array
    {
        $types = [];
        foreach (self::splitOutsideQuotes($header, ',') as $index => $entry) {
            if (trim($entry) === '') {
                continue;
            }
            try {
                $types[] = [$index, self::parse($entry)];
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        usort($types, static function (array $a, array $b): int {
            [$ia, $ta] = $a;
            [$ib, $tb] = $b;

            return $tb->quality <=> $ta->quality
                ?: $tb->specificity() <=> $ta->specificity()
                ?: $ia <=> $ib;
        });

        return array_map(static fn (array $pair): self => $pair[1], $types);
    }

    public function isJsonApi(): bool
    {
        return $this->type === self::JSON_API;
    }

    /**
     * Whether this entry admits the JSON:API media type: the type itself, or a
     * wildcard that covers it.
     */
    public function matchesJsonApi(): bool
    {
        return $this->isJsonApi() || $this->type === '*/*' || $this->type === 'application/*';
    }

    /**
     * True when no parameter other than `ext`, `profile` and `q` is present.
     *
     * The specification treats any other parameter on the JSON:API media type
     * as disqualifying: a 415 in `Content-Type`, an ignored instance in
     * `Accept`. `charset` included — the media type defines its own encoding.
     */
    public function hasOnlyJsonApiParameters(): bool
    {
        return $this->parameters === [];
    }

    /**
     * @param list<string> $extensions
     */
    public function withExtensions(array $extensions): self
    {
        return new self($this->type, $extensions, $this->profiles, $this->parameters, $this->quality);
    }

    /**
     * @param list<string> $profiles
     */
    public function withProfiles(array $profiles): self
    {
        return new self($this->type, $this->extensions, $profiles, $this->parameters, $this->quality);
    }

    /**
     * Render as a header value. `ext` and `profile` are emitted quoted, as the
     * specification requires; `q` is never emitted, it belongs to requests.
     */
    public function toString(): string
    {
        $value = $this->type;

        if ($this->extensions !== []) {
            $value .= '; ext="' . implode(' ', $this->extensions) . '"';
        }
        if ($this->profiles !== []) {
            $value .= '; profile="' . implode(' ', $this->profiles) . '"';
        }
        foreach ($this->parameters as $name => $parameter) {
            $value .= "; $name=" . self::quoteIfNeeded($parameter);
        }

        return $value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private function specificity(): int
    {
        if ($this->type === '*/*') {
            return 0;
        }

        return str_ends_with($this->type, '/*') ? 1 : 2;
    }

    /**
     * @return list<string>
     */
    private static function uriList(string $value): array
    {
        $uris = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return $uris === false ? [] : $uris;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private static function quoteIfNeeded(string $value): string
    {
        return preg_match('/^[!\#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $value) === 1
            ? $value
            : '"' . addcslashes($value, '"\\') . '"';
    }

    /**
     * Split on a delimiter, ignoring delimiters inside double quotes.
     *
     * @return list<string>
     */
    private static function splitOutsideQuotes(string $value, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\' && $quoted && $i + 1 < $length) {
                $current .= $char . $value[++$i];
                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
            }
            if ($char === $delimiter && !$quoted) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }
}
