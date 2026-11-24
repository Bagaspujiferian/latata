<?php

namespace App\Services;

class IndonesianNameMatcher
{
    /**
     * Standalone academic and honorific tokens stripped after punctuation cleanup.
     *
     * @var list<string>
     */
    private const STANDALONE_TITLES = [
        'prof',
        'dr',
        'drg',
        'ir',
        'hj',
        'kh',
        'h',
        'drs',
        'dra',
        'apt',
        'ns',
        'skom',
        'spd',
        'spdi',
        'ssos',
        'sip',
        'ssi',
        'sfarm',
        'skep',
        'spsi',
        'skm',
        'sked',
        'sag',
        'ssn',
        'str',
        'shi',
        'mkom',
        'mm',
        'msi',
        'mpd',
        'mt',
        'mh',
        'mba',
        'msc',
        'mhum',
        'mkes',
        'mag',
        'phd',
        'amd',
    ];

    /**
     * Normalize an Indonesian legal/bank holder name by stripping honorifics, academic degrees,
     * punctuation, and extra whitespace.
     */
    public function normalize(string $rawName): string
    {
        $lower = mb_strtolower(trim($rawName), 'UTF-8');
        if ($lower === '') {
            return '';
        }

        // 1. Strip dotted/comma-prefixed multi-segment academic degrees & titles first (e.g. S.Kom., S.E., S.H., S.T., M.M., Ph.D., Dr., Ir., H., Hj.)
        $dottedPattern = '/(?:^|[\s,])(?:prof|drg|drs|dra|dr|ir|hj|kh|h|apt|ns|s\s*\.\s*kom|s\s*\.\s*pd\s*\.\s*i|s\s*\.\s*pd|s\s*\.\s*sos|s\s*\.\s*ip|s\s*\.\s*si|s\s*\.\s*farm|s\s*\.\s*kep|s\s*\.\s*psi|s\s*\.\s*ked|s\s*\.\s*km|s\s*\.\s*ag|s\s*\.\s*sn|s\s*\.\s*tr|s\s*\.\s*h\s*\.\s*i|s\s*\.\s*e|s\s*\.\s*h|s\s*\.\s*t|m\s*\.\s*kom|m\s*\.\s*pd|m\s*\.\s*si|m\s*\.\s*ba|m\s*\.\s*sc|m\s*\.\s*hum|m\s*\.\s*kes|m\s*\.\s*ag|m\s*\.\s*m|m\s*\.\s*t|m\s*\.\s*h|ph\s*\.\s*d|a\s*\.\s*md|sp\s*\.\s*[a-z]{1,3})\.?(?=$|[\s,])/iu';

        $prev = null;
        while ($prev !== $lower) {
            $prev = $lower;
            $lower = preg_replace($dottedPattern, ' ', $lower) ?? $lower;
        }

        // 2. Strip any trailing short degree token after a comma (e.g., ", SE", ", SH", ", ST", ", MM")
        $lower = preg_replace('/,\s*(?:se|sh|st|mm|mt|mh|ma)\b/iu', ' ', $lower) ?? $lower;

        // 3. Replace remaining non-alphanumeric characters with spaces
        $alphaNum = preg_replace('/[^a-z0-9\s]+/u', ' ', $lower) ?? '';
        $rawTokens = preg_split('/\s+/', trim($alphaNum)) ?: [];

        $filtered = [];
        foreach ($rawTokens as $token) {
            if ($token === '') {
                continue;
            }
            if (in_array($token, self::STANDALONE_TITLES, true)) {
                continue;
            }
            $filtered[] = $token;
        }

        return implode(' ', $filtered);
    }

    /**
     * Check whether the KYC legal name matches the bank account holder name.
     */
    public function matches(string $kycFullName, string $bankHolderName): bool
    {
        $normalizedKyc = $this->normalize($kycFullName);
        $normalizedBank = $this->normalize($bankHolderName);

        if ($normalizedKyc === '' || $normalizedBank === '') {
            return false;
        }

        return $normalizedKyc === $normalizedBank;
    }
}
