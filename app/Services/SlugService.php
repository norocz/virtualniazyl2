<?php

declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Repository\AzylRepository;

class SlugService
{
    public function __construct(private AzylRepository $azylRepository)
    {
    }

    public function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        $translitMap = [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
            'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's',
            'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
        ];
        $text = strtr($text, $translitMap);

        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        return $text;
    }

    public function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base = $this->slugify($name);
        if ($base === '') {
            $base = 'azyl';
        }

        $slug = $base;
        $counter = 2;

        while (true) {
            $existing = $this->azylRepository->findBySlug($slug);
            if ($existing === null || ($excludeId !== null && $existing->getId() === $excludeId)) {
                return $slug;
            }
            $slug = $base . '-' . $counter;
            $counter++;
        }
    }
}
