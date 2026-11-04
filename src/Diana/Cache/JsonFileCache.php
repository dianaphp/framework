<?php

namespace Diana\Cache;

use JsonException;
use UnexpectedValueException;

class JsonFileCache extends FileCache
{
    public function getCacheExtension(): string
    {
        return '.cache.json';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            return parent::get($key, $default);
        } catch (JsonException|UnexpectedValueException) {
            // Old files were not JSON-serialized; discard incompatible entries.
            $this->delete($key);
            return $default;
        }
    }

    protected function encode(mixed $value): string
    {
        // Use native JSON whenever it preserves PHP types and values exactly.
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (serialize(json_decode($json, true, flags: JSON_THROW_ON_ERROR)) === serialize($value)) {
                return json_encode(['encoding' => 'json', 'value' => $value], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            }
        } catch (JsonException) {
            // Binary strings and other non-JSON values still need a lossless roundtrip.
        }

        return json_encode(['encoding' => 'php', 'value' => base64_encode(serialize($value))], JSON_THROW_ON_ERROR);
    }

    protected function decode(string $content): mixed
    {
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !in_array($data['encoding'] ?? null, ['json', 'php'], true) || !array_key_exists('value', $data)) {
            throw new UnexpectedValueException('Unsupported JSON cache format.');
        }

        return $data['encoding'] === 'json' ? $data['value'] : unserialize(base64_decode($data['value']));
    }
}
