<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Yii;
use yii\db\Connection;

/**
 * Replaces the values written into SQL statements (quoted strings and numbers) and database error messages with "?",
 * so that they can be sent to Sentry without the data they carry. Identifiers, keywords and parameter placeholders
 * are kept. Text in double quotes is a string in MySQL and MariaDB, and a name elsewhere: the application's `db`
 * connection decides.
 *
 * @internal
 */
final class SqlMasker
{
    /**
     * The drivers of MySQL, also used for MariaDB, whose SQL takes text in double quotes as a string.
     */
    private const MYSQL_DRIVERS = ['mysql', 'mysqli'];

    /**
     * A name in backticks, as MySQL, MariaDB and SQLite write them.
     */
    private const BACKTICK_NAME = '`(?:[^`]++|``)*+`';

    /**
     * A string in single quotes, with '' and backslash escapes.
     */
    private const STRING = "'(?:[^'\\\\]++|\\\\.|'')*+'";

    /**
     * Text in double quotes: in MySQL and MariaDB a string, with "" and backslash escapes, elsewhere a name, with "".
     */
    private const DOUBLE_QUOTED_STRING = '"(?:[^"\\\\]++|\\\\.|"")*+"';
    private const DOUBLE_QUOTED_NAME = '"(?:[^"]++|"")*+"';

    /**
     * PostgreSQL's dollar-quoted strings, which may hold any quote: $$text$$ or $tag$text$tag$.
     */
    private const DOLLAR_QUOTED = '(?<![\w$])\$(?<tag>(?:[A-Za-z_]\w*)?)\$.*?\$\k<tag>\$';

    /**
     * Hexadecimal numbers, integers and decimals, not part of an identifier (t1, :qp0, $1).
     */
    private const NUMBER = '(?<![\w$])0x[0-9a-fA-F]+(?![\w$])|(?<![\w$.])\d+(?:\.\d+)?(?:[eE][-+]?\d+)?(?![\w$])';

    /**
     * A statement read from left to right, as MySQL and MariaDB read it, then as other databases do: names, kept;
     * values, masked; and a quote that doesn't pair up, which means that the statement can't be read right.
     */
    private const MYSQL_TOKENS = '/(?<name>' . self::BACKTICK_NAME . ')'
        . '|(?<value>' . self::STRING . '|' . self::DOUBLE_QUOTED_STRING . '|' . self::DOLLAR_QUOTED
        . '|' . self::NUMBER . ')'
        . '|(?<unpaired>[\'"])/s';
    private const TOKENS = '/(?<name>' . self::BACKTICK_NAME . '|' . self::DOUBLE_QUOTED_NAME . ')'
        . '|(?<value>' . self::STRING . '|' . self::DOLLAR_QUOTED . '|' . self::NUMBER . ')'
        . "|(?<unpaired>')/s";

    /**
     * The text Yii puts between a database's error message and the statement that failed.
     */
    private const SQL_MARKERS = ["\nThe SQL being executed was: ", "\nFailed to prepare SQL: "];

    /**
     * Values at known places in the messages of MySQL, MariaDB, PostgreSQL and SQL Server. A value is matched up to
     * the text that follows it, so that quotes inside the value don't end it.
     */
    private const MESSAGE_VALUES = [
        // Duplicate entry 'ann@example.com' for key 'email'
        "/(Duplicate entry )'.*'( for key )/" => '$1?$2',
        // Incorrect integer value: 'abc' for column 'age' at row 1
        "/(Incorrect [\\w ]+ value: )'.*'( for column )/" => '$1?$2',
        // Truncated incorrect DOUBLE value: 'abc'
        "/(Truncated incorrect [\\w ]+ value: )'.*'$/m" => '$1?',
        // You have an error in your SQL syntax; ... near 'ann@example.com')' at line 1
        "/( near )'.*'( at line \\d+)/" => '$1?$2',
        // DETAIL:  Key (email)=(ann@example.com) already exists.
        '/(\bKey \((?:[^()]++|\([^()]*\))*\)=\().*\)/' => '$1?)',
        // DETAIL:  Failing row contains (7, Ann, ann@example.com).
        '/(Failing row contains \().*\)/' => '$1?)',
        // ERROR:  invalid input syntax for type integer: "abc"
        '/(: )"(?:[^"]|"")*"/' => '$1?',
        // DETAIL:  Token "abc" is invalid. CONTEXT:  JSON data, line 1: {"password": ...
        '/(Token )"(?:[^"]|"")*"/' => '$1?',
        '/(JSON data, line \d+: ).*$/m' => '$1?',
        // The duplicate key value is (ann@example.com).
        '/(duplicate key value is \().*\)/' => '$1?)',
    ];

    /**
     * Any other single-quoted text, optionally after a word. Quotes next to a letter or digit are apostrophes
     * ("doesn't") or inside the text ("O'Brien").
     */
    private const QUOTED = "/(?:\\b([A-Za-z]+) )?(?<![A-Za-z0-9])'.*?'(?![A-Za-z0-9])/";

    /**
     * Quoted text after these words is a name, and kept: "for key 'email'", "Unknown column 'x' in 'field list'".
     */
    private const NAME_WORDS = ['column', 'constraint', 'database', 'field', 'in', 'index', 'key', 'object', 'schema', 'table'];

    public static function mask(string $sql): string
    {
        $unpaired = false;
        $masked = preg_replace_callback(
            self::usesMysql() ? self::MYSQL_TOKENS : self::TOKENS,
            static function (array $match) use (&$unpaired): string {
                if (isset($match['unpaired'])) {
                    $unpaired = true;
                }

                return isset($match['value']) ? '?' : $match[0];
            },
            $sql,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL
        );

        // On a regular expression failure, or a quote that doesn't pair up, show nothing rather than the values.
        return $masked === null || $unpaired ? '[query not shown: its values could not be masked]' : $masked;
    }

    /**
     * Masks the values in a database error message, e.g. the one of a `yii\db\IntegrityException`: the quoted
     * values in the database's own message (names of columns, keys and tables are kept), and the values in the SQL
     * statement Yii adds to it.
     */
    public static function maskErrorMessage(string $message): string
    {
        $statement = '';
        foreach (self::SQL_MARKERS as $marker) {
            $position = strpos($message, $marker);
            if ($position !== false) {
                $statement = $marker . self::mask(substr($message, $position + strlen($marker)));
                $message = substr($message, 0, $position);
                break;
            }
        }

        $masked = preg_replace(array_keys(self::MESSAGE_VALUES), array_values(self::MESSAGE_VALUES), $message);
        if ($masked !== null) {
            // PostgreSQL quotes the statement: LINE 1: INSERT INTO "user" ("email") VALUES ('ann@example.com')
            $masked = preg_replace_callback('/^(LINE \d+: )(.*)$/m', static function (array $match): string {
                return $match[1] . self::mask($match[2]);
            }, $masked);
        }
        if ($masked !== null) {
            $masked = preg_replace_callback(self::QUOTED, static function (array $match): string {
                $word = $match[1] ?? '';
                if (in_array(strtolower($word), self::NAME_WORDS, true)) {
                    return $match[0];
                }

                return $word !== '' ? "$word ?" : '?';
            }, $masked);
        }

        return ($masked ?? '[message not shown: its values could not be masked]') . $statement;
    }

    /**
     * @param string $class the class of an exception
     */
    public static function isDatabaseError(string $class): bool
    {
        return is_a($class, \yii\db\Exception::class, true) || is_a($class, \PDOException::class, true);
    }

    /**
     * Whether the application's `db` connection is to MySQL or MariaDB: as the connection says, or its configuration
     * while it hasn't been created.
     */
    private static function usesMysql(): bool
    {
        $app = Yii::$app;
        if ($app === null) {
            return false;
        }

        if ($app->has('db', true)) {
            $db = $app->get('db');

            // The DSN names the driver; without it, getDriverName() would connect to ask.
            return $db instanceof Connection && strpos((string)$db->dsn, ':') !== false
                && in_array($db->getDriverName(), self::MYSQL_DRIVERS, true);
        }

        $config = $app->getComponents()['db'] ?? null;
        if (!is_array($config)) {
            return false;
        }
        $driver = $config['driverName'] ?? strstr((string)($config['dsn'] ?? ''), ':', true);

        return is_string($driver) && in_array(strtolower($driver), self::MYSQL_DRIVERS, true);
    }
}
