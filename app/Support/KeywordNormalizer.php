<?php

namespace App\Support;

final class KeywordNormalizer
{
    /**
     * @param mixed $keywords
     * @return array<int, string>
     */
    public static function normalize(mixed $keywords): array
    {
        if ($keywords === null || $keywords === '') {
            return [];
        }

        if (is_string($keywords)) {
            $keywords = preg_split('/[,\n;]+/', $keywords) ?: [];
        }

        if (! is_array($keywords)) {
            return [];
        }

        $values = [];

        foreach ($keywords as $keyword) {
            if (is_array($keyword)) {
                $values = array_merge($values, self::normalize($keyword));
                continue;
            }

            $parts = preg_split('/[,\n;]+/', (string) $keyword) ?: [];
            foreach ($parts as $part) {
                $part = mb_strtolower(trim($part));
                if ($part !== '') {
                    $values[] = $part;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return array<int, string>
     */
    public static function tokensFromText(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $tokens = preg_split('/[\s,;|\/\-_]+/', mb_strtolower($text)) ?: [];

        $tokens = array_filter(array_map('trim', $tokens), fn ($token) => $token !== '' && mb_strlen($token) >= 2);

        return array_values(array_unique($tokens));
    }

    /**
     * @param array<int, mixed> $sources
     * @return array<int, string>
     */
    public static function mergeMany(array $sources): array
    {
        $merged = [];

        foreach ($sources as $source) {
            if (is_array($source)) {
                $merged = array_merge($merged, self::normalize($source));
            } else {
                $merged = array_merge($merged, self::tokensFromText((string) $source));
            }
        }

        return array_values(array_unique($merged));
    }
}
