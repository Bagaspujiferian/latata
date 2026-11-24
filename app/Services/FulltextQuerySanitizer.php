<?php

namespace App\Services;

class FulltextQuerySanitizer
{
    public const MIN_TOKEN_LENGTH = 3;

    /**
     * Sanitize raw user search input into a safe MySQL InnoDB FULLTEXT Boolean Mode query ('+token1* +token2*').
     * Returns null when no token has length >= 3 (e.g. 'ML', 'UC', 'FF', or only special characters),
     * signaling the caller to fall back to an escaped LIKE query.
     */
    public function buildBooleanQuery(string $rawSearch): ?string
    {
        // Strip all MySQL FULLTEXT Boolean Mode special operator characters: + - > < ( ) ~ * " @
        $stripped = preg_replace('/[+\-><()~*"@\'`]+/u', ' ', trim($rawSearch)) ?? '';

        // Replace any remaining non-letter/non-number punctuation with spaces
        $alphaNum = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $stripped) ?? '';

        $rawTokens = preg_split('/\s+/u', mb_strtolower(trim($alphaNum), 'UTF-8')) ?: [];

        $validTokens = [];
        foreach ($rawTokens as $token) {
            if ($token === '' || mb_strlen($token, 'UTF-8') < self::MIN_TOKEN_LENGTH) {
                continue;
            }
            if (! in_array($token, $validTokens, true)) {
                $validTokens[] = $token;
            }
        }

        if ($validTokens === []) {
            return null;
        }

        return implode(' ', array_map(
            static fn (string $token): string => '+'.$token.'*',
            $validTokens,
        ));
    }

    /**
     * Escape LIKE wildcard characters (%, _, \) after stripping MySQL Boolean operators for safe fallback search.
     */
    public function escapeLike(string $rawSearch): string
    {
        $cleaned = preg_replace('/[+\-><()~*"@\'`]+/u', ' ', trim($rawSearch)) ?? '';
        $collapsed = preg_replace('/\s+/u', ' ', trim($cleaned)) ?? '';

        return addcslashes($collapsed, '\\%_');
    }
}
