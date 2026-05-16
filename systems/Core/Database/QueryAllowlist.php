<?php

declare(strict_types=1);

namespace Core\Database;

final class QueryAllowlist
{
    public static function sanitizeDirection(?string $direction): string
    {
        return strtoupper(trim((string) $direction)) === 'DESC' ? 'DESC' : 'ASC';
    }

    /**
     * @param string[] $allowedColumns
     */
    public static function assertSortable(?string $table, string $column, array $allowedColumns): string
    {
        return self::resolveAllowedColumn($table, $column, $allowedColumns, 'sortable');
    }

    /**
     * @param string[] $allowedColumns
     */
    public static function assertFilterable(?string $table, string $column, array $allowedColumns): string
    {
        return self::resolveAllowedColumn($table, $column, $allowedColumns, 'filterable');
    }

    /**
     * @param string[] $allowedColumns
     */
    private static function resolveAllowedColumn(?string $table, string $column, array $allowedColumns, string $type): string
    {
        $requested = self::normalizeIdentifier($column);
        if ($requested === '') {
            throw new \InvalidArgumentException('Column cannot be empty.');
        }

        if ($allowedColumns === []) {
            throw new \InvalidArgumentException('No ' . $type . ' columns are configured for this query.');
        }

        $normalizedTable = self::normalizeIdentifier((string) $table, true);
        $matches = [];

        foreach ($allowedColumns as $allowedColumn) {
            if (!is_string($allowedColumn) || trim($allowedColumn) === '') {
                continue;
            }

            $normalizedAllowed = self::normalizeIdentifier($allowedColumn);
            if ($normalizedAllowed === '') {
                continue;
            }

            if ($normalizedAllowed === $requested) {
                $matches[] = $allowedColumn;
                continue;
            }

            $allowedTail = self::identifierTail($normalizedAllowed);
            $requestedTail = self::identifierTail($requested);

            if ($allowedTail === $requestedTail) {
                if (strpos($requested, '.') === false || ($normalizedTable !== '' && str_starts_with($normalizedAllowed, $normalizedTable . '.'))) {
                    $matches[] = $allowedColumn;
                }
            }
        }

        $matches = array_values(array_unique($matches));

        if (count($matches) > 1) {
            throw new \InvalidArgumentException(
                "Column '{$column}' is ambiguous for {$type} allowlist. Use a qualified column name."
            );
        }

        if ($matches === []) {
            throw new \InvalidArgumentException(
                "Column '{$column}' is not in the {$type} allowlist: [" . implode(', ', $allowedColumns) . ']'
            );
        }

        return $matches[0];
    }

    private static function identifierTail(string $identifier): string
    {
        $parts = explode('.', $identifier);
        return (string) end($parts);
    }

    private static function normalizeIdentifier(string $identifier, bool $allowEmpty = false): string
    {
        $identifier = trim(str_replace('`', '', $identifier));
        if ($identifier === '') {
            return $allowEmpty ? '' : throw new \InvalidArgumentException('Column cannot be empty.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier) !== 1) {
            throw new \InvalidArgumentException("Invalid column identifier: {$identifier}");
        }

        return strtolower($identifier);
    }
}