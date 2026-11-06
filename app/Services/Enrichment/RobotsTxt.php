<?php

namespace App\Services\Enrichment;

/**
 * Minimal robots.txt (RFC 9309): picks the group for our bot token (else "*"),
 * longest-match wins, Allow wins ties, supports "*" and "$".
 */
final class RobotsTxt
{
    /** @param list<array{allow: bool, pattern: string}> $rules */
    private function __construct(private readonly array $rules) {}

    public static function allowAll(): self
    {
        return new self([]);
    }

    public static function disallowAll(): self
    {
        return new self([['allow' => false, 'pattern' => '/']]);
    }

    public static function parse(string $content, string $botToken): self
    {
        $groups = [];      // list of [agents[], rules[]]
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $lastWasAgent || $current === null) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $current = array_key_last($groups);
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }

            $lastWasAgent = false;
            if ($current !== null && in_array($field, ['allow', 'disallow'], true) && $value !== '') {
                $groups[$current]['rules'][] = ['allow' => $field === 'allow', 'pattern' => $value];
            }
        }

        $token = strtolower($botToken);
        $specific = array_filter($groups, fn ($g) => collect($g['agents'])->contains(fn ($a) => $a !== '*' && str_contains($token, $a)));
        $chosen = $specific ?: array_filter($groups, fn ($g) => in_array('*', $g['agents'], true));

        $rules = [];
        foreach ($chosen as $group) {
            array_push($rules, ...$group['rules']);
        }

        return new self($rules);
    }

    public function allows(string $url): bool
    {
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($q = parse_url($url, PHP_URL_QUERY)) ? "?{$q}" : '');
        $path = rawurldecode($path);

        $best = null;
        $bestLength = -1;
        foreach ($this->rules as $rule) {
            if ($this->matches($rule['pattern'], $path)) {
                $length = strlen($rule['pattern']);
                if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                    [$best, $bestLength] = [$rule['allow'], $length];
                }
            }
        }

        return $best ?? true;
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = rawurldecode($anchored ? substr($pattern, 0, -1) : $pattern);
        $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).($anchored ? '$' : '').'#u';

        return (bool) @preg_match($regex, $path);
    }
}
