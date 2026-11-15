<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

/**
 * Tells whether a field name matches a list of terms, ignoring case and separators.
 *
 * Terms of five characters or more match anywhere in a name: "password" matches "newPassword" and "password_hash".
 * Shorter terms only match a whole word, alone or with a plural "s": "key" matches "api_key", "apiKey" and "keys",
 * but not "monkey" or "keyword". Words are separated by anything other than letters and digits, by a change from
 * lower to upper case, and by a change between letters and digits.
 *
 * @internal
 */
final class FieldMatcher
{
    /**
     * @var string[] terms matched anywhere, lower case and without separators
     */
    private $anywhere = [];
    /**
     * @var array<string, true> terms matched as whole words
     */
    private $wholeWords = [];

    /**
     * @param string[] $terms
     */
    public function __construct(array $terms)
    {
        foreach ($terms as $term) {
            $normalized = self::normalize((string)$term);

            if (mb_strlen($normalized, 'UTF-8') >= 5) {
                $this->anywhere[] = $normalized;
            } elseif ($normalized !== '') {
                $this->wholeWords[$normalized] = true;
            }
        }
    }

    /**
     * @param int|string $name
     */
    public function matches($name): bool
    {
        $name = (string)$name;

        if ($this->anywhere !== []) {
            $normalized = self::normalize($name);
            foreach ($this->anywhere as $term) {
                if (strpos($normalized, $term) !== false) {
                    return true;
                }
            }
        }

        if ($this->wholeWords !== []) {
            $words = self::words($name);
            $count = count($words);

            // Consecutive words are joined too, so that a term split by the rules above still matches ("2fa").
            for ($first = 0; $first < $count; $first++) {
                $joined = '';
                for ($last = $first; $last < $count; $last++) {
                    $joined .= $words[$last];
                    if ($this->isWholeWordTerm($joined)) {
                        return true;
                    }
                    // Whole-word terms are shorter than five characters, or five with the plural "s".
                    if (mb_strlen($joined, 'UTF-8') >= 5) {
                        break;
                    }
                }
            }
        }

        return false;
    }

    private function isWholeWordTerm(string $word): bool
    {
        return isset($this->wholeWords[$word]) || (substr($word, -1) === 's' && isset($this->wholeWords[substr($word, 0, -1)]));
    }

    /**
     * "X-Api_Key" becomes "xapikey".
     */
    private static function normalize(string $name): string
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
        if ($normalized === null) {
            // Not valid UTF-8 (names come from the client): compare the ASCII letters and digits.
            $normalized = (string)preg_replace('/[^A-Za-z0-9]+/', '', $name);
        }

        return mb_strtolower($normalized, 'UTF-8');
    }

    /**
     * "newPassword" becomes ["new", "password"], "SAMLResponse" ["saml", "response"] and "cvv2" ["cvv", "2"].
     *
     * @return string[]
     */
    private static function words(string $name): array
    {
        $spaced = preg_replace(
            ['/(\p{Ll})(\p{Lu})/u', '/(\p{Lu})(\p{Lu}\p{Ll})/u', '/(\p{L})(\p{N})/u', '/(\p{N})(\p{L})/u'],
            '$1 $2',
            $name
        );
        if ($spaced === null) {
            $spaced = (string)preg_replace(['/([a-z])([A-Z])/', '/([A-Z])([A-Z][a-z])/', '/([A-Za-z])([0-9])/', '/([0-9])([A-Za-z])/'], '$1 $2', $name);
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($spaced, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

        return $words !== false ? $words : (preg_split('/[^a-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
