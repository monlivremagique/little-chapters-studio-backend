<?php

declare(strict_types=1);

namespace App\Support;

final class JsonExtractor
{
    /**
     * Extract a JSON object from raw text using multiple fallback strategies.
     * @return array<string, mixed>|null
     */
    public static function extract(string $rawText): ?array
    {
        $result = self::tryDirectDecode($rawText);
        if (null !== $result) {
            return $result;
        }

        $result = self::tryStripMarkdownFences($rawText);
        if (null !== $result) {
            return $result;
        }

        $result = self::tryExtractBraceBlock($rawText);
        if (null !== $result) {
            return $result;
        }

        $result = self::tryFixCommonErrors($rawText);
        if (null !== $result) {
            return $result;
        }

        $result = self::tryAggressiveCleanup($rawText);
        if (null !== $result) {
            return $result;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function tryDirectDecode(string $text): ?array
    {
        $trimmed = trim($text);
        if ('' === $trimmed) {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function tryStripMarkdownFences(string $text): ?array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/im', '', $text) ?? $text;
        $cleaned = preg_replace('/\s*```\s*$/m', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        if ($cleaned === $text) {
            return null;
        }

        try {
            $decoded = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function tryExtractBraceBlock(string $text): ?array
    {
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');
        if (false === $firstBrace || false === $lastBrace || $lastBrace <= $firstBrace) {
            return null;
        }

        $candidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);

        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function tryFixCommonErrors(string $text): ?array
    {
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');
        if (false === $firstBrace || false === $lastBrace || $lastBrace <= $firstBrace) {
            return null;
        }

        $candidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);

        // Fix unquoted keys
        $candidate = preg_replace('/(\{|\,)\s*(\w+)\s*:\s*/', '$1"$2":', $candidate) ?? $candidate;
        // Fix trailing commas
        $candidate = preg_replace('/,\s*([}\]])/', '$1', $candidate) ?? $candidate;
        // Fix boolean case
        $candidate = preg_replace_callback('/\b(True|False)\b/', static fn (array $m): string => strtolower($m[1]), $candidate);
        // Fix Null
        $candidate = str_replace('Null', 'null', $candidate);

        if ($candidate === substr($text, (int) $firstBrace, $lastBrace - $firstBrace + 1)) {
            return null;
        }

        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function tryAggressiveCleanup(string $text): ?array
    {
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');
        if (false === $firstBrace || false === $lastBrace || $lastBrace <= $firstBrace) {
            return null;
        }

        $candidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
        $candidate = preg_replace('/[^\x20-\x7E\x0A\x0D\xC0-\xFF{},\[\]:"]/', '', $candidate) ?? $candidate;

        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
