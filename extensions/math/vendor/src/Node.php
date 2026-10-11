<?php

namespace Latex2MathML;

class Node
{
    public function __construct(
        public string $token,
        public ?array $children = null,
        public ?string $delimiter = null,
        public ?string $alignment = null,
        public ?string $text = null,
        public ?array $attributes = null,
        public ?string $modifier = null
    ) {}

    public function with(array $properties): self
    {
        return new self(
            $properties['token'] ?? $this->token,
            $properties['children'] ?? $this->children,
            $properties['delimiter'] ?? $this->delimiter,
            $properties['alignment'] ?? $this->alignment,
            $properties['text'] ?? $this->text,
            $properties['attributes'] ?? $this->attributes,
            $properties['modifier'] ?? $this->modifier
        );
    }
}
