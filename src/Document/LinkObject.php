<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Document;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A JSON:API 1.1 link object: an `href` with optional description.
 *
 * A link in a links object may be a plain URL string or an object. 1.0
 * allowed the object only `href` and `meta`; 1.1 adds `rel`, `describedby`,
 * `title`, `type` and `hreflang`, so a link can say what it is (`rel`), what
 * it returns (`type`), which language (`hreflang`) and where its schema lives
 * (`describedby`). Use it wherever a links array takes a value:
 *
 *     $document->setLinks([
 *         'self'        => 'https://api.example.com/articles/1',
 *         'describedby' => (new LinkObject('https://api.example.com/schema/articles'))
 *             ->setType('application/schema+json'),
 *     ]);
 *
 * Members are emitted only when set, and `href` always comes first.
 */
final class LinkObject implements JsonSerializable
{
    private ?string $rel = null;
    /** @var string|LinkObject|null */
    private string|LinkObject|null $describedBy = null;
    private ?string $title = null;
    private ?string $type = null;
    /** @var string|list<string>|null */
    private string|array|null $hreflang = null;
    /** @var array<string, mixed> */
    private array $meta = [];

    public function __construct(private readonly string $href)
    {
        if (trim($href) === '') {
            throw new InvalidArgumentException('A link object must have a non-empty href.');
        }
    }

    public function getHref(): string
    {
        return $this->href;
    }

    /**
     * The link relation type, as in RFC 8288 (`next`, `alternate`, ...).
     */
    public function setRel(string $rel): self
    {
        $this->rel = $rel;
        return $this;
    }

    /**
     * A link to a description document (an OpenAPI or JSON Schema document)
     * for the link target.
     */
    public function setDescribedBy(string|LinkObject $describedBy): self
    {
        $this->describedBy = $describedBy;
        return $this;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    /**
     * The media type of the link's target.
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * The language(s) of the link's target — one RFC 5646 tag, or several.
     *
     * @param string|list<string> $hreflang
     */
    public function setHreflang(string|array $hreflang): self
    {
        if (is_array($hreflang)) {
            if ($hreflang === []) {
                throw new InvalidArgumentException('hreflang must name at least one language.');
            }
        }

        $this->hreflang = $hreflang;
        return $this;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function setMeta(array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $link = ['href' => $this->href];

        if ($this->rel !== null) {
            $link['rel'] = $this->rel;
        }
        if ($this->describedBy !== null) {
            $link['describedby'] = $this->describedBy;
        }
        if ($this->title !== null) {
            $link['title'] = $this->title;
        }
        if ($this->type !== null) {
            $link['type'] = $this->type;
        }
        if ($this->hreflang !== null) {
            $link['hreflang'] = $this->hreflang;
        }
        if ($this->meta !== []) {
            $link['meta'] = $this->meta;
        }

        return $link;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
