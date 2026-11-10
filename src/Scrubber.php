<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

/**
 * Replaces the values of secret and personal fields in the data of one event.
 *
 * @internal
 */
final class Scrubber
{
    /**
     * @var FieldMatcher|null secret fields, or null when secrets are sent
     */
    private $secrets;
    /**
     * @var array<string, true> exact names that hold secrets, e.g. Yii's session cookie
     */
    private $secretNames;
    /**
     * @var FieldMatcher|null personal data fields, or null when personal data is sent
     */
    private $personal;
    /**
     * @var string|null
     */
    private $replacement;

    /**
     * @param string[] $secretNames
     */
    public function __construct(?FieldMatcher $secrets, array $secretNames, ?FieldMatcher $personal, ?string $replacement)
    {
        $this->secrets = $secrets;
        $this->secretNames = $secrets !== null ? array_fill_keys($secretNames, true) : [];
        $this->personal = $personal;
        $this->replacement = $replacement;
    }

    public function hidesPersonalData(): bool
    {
        return $this->personal !== null;
    }

    public function replacement(): ?string
    {
        return $this->replacement;
    }

    /**
     * @param int|string $name
     */
    public function isSecret($name): bool
    {
        return $this->secrets !== null && (isset($this->secretNames[(string)$name]) || $this->secrets->matches($name));
    }

    /**
     * @param int|string $name
     */
    public function isPersonal($name): bool
    {
        return $this->personal !== null && $this->personal->matches($name);
    }

    /**
     * Replaces the values of secret and personal fields, at any depth.
     *
     * @param array<array-key, mixed> $data
     * @param FieldMatcher|null $more more fields to replace
     *
     * @return array<array-key, mixed>
     */
    public function scrub(array $data, ?FieldMatcher $more = null): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSecret($key) || $this->isPersonal($key) || ($more !== null && $more->matches($key))) {
                $data[$key] = $this->replacement;
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value, $more);
            }
        }

        return $data;
    }

    /**
     * Replaces the values of secret and personal parameters, keeping the others exactly as they were sent.
     */
    public function scrubQueryString(string $queryString): string
    {
        $parameters = explode('&', $queryString);

        foreach ($parameters as $index => $parameter) {
            $separator = strpos($parameter, '=');
            if ($separator === false) {
                continue;
            }

            $encodedName = substr($parameter, 0, $separator);
            $name = urldecode($encodedName);
            if ($this->isSecret($name) || $this->isPersonal($name)) {
                $parameters[$index] = $encodedName . '=' . $this->replacement;
            }
        }

        return implode('&', $parameters);
    }

    /**
     * Scrubs the query string of a URL, e.g. of the "Referer" header.
     */
    public function scrubUrl(string $url): string
    {
        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        $fragmentStart = strpos($url, '#', $queryStart);
        $queryEnd = $fragmentStart === false ? strlen($url) : $fragmentStart;
        $queryString = substr($url, $queryStart + 1, $queryEnd - $queryStart - 1);

        return substr($url, 0, $queryStart + 1) . $this->scrubQueryString($queryString) . substr($url, $queryEnd);
    }
}
