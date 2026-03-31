<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Helper;

/**
 * Utility class for decoding WoCK delivery keys.
 *
 * WoCK can return keys in two formats:
 *   - text/plain  → $key['key'] is a plain-text string (e.g. "XXXX-YYYY-ZZZZ")
 *   - image/*     → $key['key'] is a base64-encoded image binary
 *
 * Always check $key['subKeys'] for DLC / bundle linked keys — these follow
 * the same mime-type rules.
 */
class KeyDecoder
{
    /**
     * Return the key value as a plain UTF-8 string.
     * For image keys this returns the raw base64 string; use getImageData() instead.
     */
    public function getValue(array $key): string
    {
        return (string) ($key['key'] ?? '');
    }

    /**
     * Returns true when the key is a text key (most common type).
     */
    public function isTextKey(array $key): bool
    {
        return ($key['mimeType'] ?? '') === 'text/plain';
    }

    /**
     * Returns true when the key is an image key (e.g. boxart, scratch card).
     */
    public function isImageKey(array $key): bool
    {
        return str_starts_with((string) ($key['mimeType'] ?? ''), 'image/');
    }

    /**
     * For image keys: returns the raw binary image data decoded from base64.
     *
     * @throws \RuntimeException when called on a non-image key
     */
    public function getImageData(array $key): string
    {
        if (!$this->isImageKey($key)) {
            throw new \RuntimeException(
                sprintf('Key %s is not an image key (mimeType: %s)', $key['id'] ?? '?', $key['mimeType'] ?? '?')
            );
        }

        $decoded = base64_decode((string) ($key['key'] ?? ''), true);
        if ($decoded === false) {
            throw new \RuntimeException(sprintf('Failed to base64-decode image key %s', $key['id'] ?? '?'));
        }

        return $decoded;
    }

    /**
     * Returns the file extension that matches the mime-type.
     */
    public function getFileExtension(array $key): string
    {
        return match ($key['mimeType'] ?? '') {
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/gif'  => 'gif',
            'image/bmp'  => 'bmp',
            default      => 'txt',
        };
    }

    /**
     * Collect the key itself and all sub-keys into a flat list.
     * Useful for iterating all deliverable keys for a single product line.
     *
     * @param  array<string, mixed> $key
     * @return array<int, array<string, mixed>>
     */
    public function flatten(array $key): array
    {
        $all = [$key];

        foreach ($key['subKeys'] ?? [] as $subKey) {
            // Recurse to support arbitrarily nested sub-keys
            $all = array_merge($all, $this->flatten($subKey));
        }

        return $all;
    }

    /**
     * Return true when the key has already been downloaded (downloadedAt is set).
     */
    public function isAlreadyDownloaded(array $key): bool
    {
        return !empty($key['downloadedAt']);
    }
}
