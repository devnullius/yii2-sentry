<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

/**
 * Replaces the values written into SQL statements (quoted strings and numbers) with "?", so that logged queries can
 * be sent to Sentry without the data they carry. Identifiers, keywords and parameter placeholders are kept.
 *
 * @internal
 */
final class SqlMasker
{
    /**
     * Single-quoted strings, with '' and backslash escapes.
     */
    private const STRING = "/'(?:[^'\\\\]++|\\\\.|'')*+'/s";

    /**
     * Hexadecimal numbers, not part of an identifier.
     */
    private const HEX = '/(?<![\w$])0x[0-9a-fA-F]+(?![\w$])/';

    /**
     * Integers and decimals, not part of an identifier (t1, :qp0, $1).
     */
    private const NUMBER = '/(?<![\w$.])\d+(?:\.\d+)?(?:[eE][-+]?\d+)?(?![\w$])/';

    public static function mask(string $sql): string
    {
        $masked = preg_replace([self::STRING, self::HEX, self::NUMBER], '?', $sql);

        // On a regular expression failure, show nothing rather than the values.
        return $masked ?? '[query not shown: its values could not be masked]';
    }
}
