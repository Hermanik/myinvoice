<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Per-user starred sidebar items. The list holds internal route paths
 * ("/invoices", "/clients?role=vendors"); which of them are actually shown is
 * decided by the client against the menu available to the user, so a stale
 * or no longer permitted path is harmless.
 */
final class NavFavoritesService
{
    public const MAX_ITEMS = 50;
    public const MAX_PATH_LENGTH = 200;

    public function __construct(private readonly Connection $db) {}

    /** @return list<string> */
    public function get(int $userId): array
    {
        if (!$this->db->hasColumn('users', 'nav_favorites')) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT nav_favorites FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return self::decode($stmt->fetchColumn());
    }

    /** @return list<string> */
    public function update(int $userId, mixed $paths): array
    {
        $items = self::normalize($paths);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET nav_favorites = ? WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([
            $items === []
                ? null
                : json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $userId,
        ]);

        return $items;
    }

    /**
     * Validates the submitted list and removes duplicates (first occurrence wins).
     *
     * @return list<string>
     */
    private static function normalize(mixed $paths): array
    {
        if (!is_array($paths) || !array_is_list($paths)) {
            throw new \InvalidArgumentException('items musí být pole cest položek menu.');
        }
        if (count($paths) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(sprintf(
                'Oblíbených položek může být nejvýše %d.',
                self::MAX_ITEMS,
            ));
        }
        $unique = [];
        foreach ($paths as $path) {
            if (!is_string($path) || !self::isValidPath($path)) {
                throw new \InvalidArgumentException(
                    'Každá oblíbená položka musí být interní cesta začínající „/".',
                );
            }
            $unique[$path] = true;
        }

        return array_map('strval', array_keys($unique));
    }

    /** @return list<string> */
    private static function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    /** Internal path only: one leading slash, no scheme/host, no whitespace or control chars. */
    private static function isValidPath(string $path): bool
    {
        return strlen($path) <= self::MAX_PATH_LENGTH
            && preg_match('~^/(?![/\\\\])[^\s\\\\\x00-\x1F\x7F]*\z~', $path) === 1;
    }
}
