<?php

namespace App\Config;

readonly class OpenAiConfig
{
    public function __construct(
        public string $apiKey,
        public string $model,
        public string $endpoint,
        public bool $enabled,
        public int $timeout,
        public string $sourceLanguage,
    ) {}
}