<?php

namespace App\Config;

readonly class I18nConfig
{
    /**
     * @param array<string, string> $available
     */
    public function __construct(
        public string $default,
        public array $available,
    ) {}
}