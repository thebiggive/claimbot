<?php

declare(strict_types=1);

namespace ClaimBot;

use JetBrains\PhpStorm\ArrayShape;

/**
 * Houses fallback validators & formatters for where we need distinct logic from the more
 * durable, externally maintained libraries.
 */
class Format
{
    private static string $crownDepRegexp = '/^((?:GY|IM|JE)\\d[A-Z\\d]?) ?(\\d[A-Z]{2})$/i';

    /**
     * Called when UK postcode validation 'fails' to check if one of the codes
     * for Guernsey, the Isle of Man or Jersey is being used.
     *
     * @link https://en.wikipedia.org/wiki/Postcodes_in_the_United_Kingdom#Crown_dependencies
     * @link https://stackoverflow.com/a/51885364/2803757
     */
    public static function isCrownDependencyPseudoPostcode(string $postcode): bool
    {
        return preg_match(static::$crownDepRegexp, $postcode) === 1;
    }

    /**
     * @link https://en.wikipedia.org/wiki/Postcodes_in_the_United_Kingdom#Crown_dependencies
     * @link https://stackoverflow.com/a/51885364/2803757
     */
    public static function formatCrownDependencyPseudoPostcode(string $postcode): string
    {
        preg_match(static::$crownDepRegexp, $postcode, $matches);

        if (empty($matches)) {
            return '';
        }

        return sprintf('%s %s', strtoupper($matches[1]), strtoupper($matches[2]));
    }

    /**
     * @param string $fullAddressWithWeirdEscapes
     * @return array    with keys:
     *                  * 'line' containing a string[] array
     *                  * 'postcode' (string)
     *                  * 'country' (string: "United Kingdom")
     */
    #[ArrayShape(['line' => "string[]", 'postcode' => "string", 'country' => "string"])]
    public static function agentAddressFromEnvVar(string $fullAddressWithWeirdEscapes): array
    {
        // At this point, `$lines` includes the postcode as its final string element.
        $lines = explode(
            ',',
            // Passing literal spaces in the S3 secret loader in a way that doesn't break with xargs
            // + export was super involved, so we just pass and replace literal '\s' for now.
            str_replace('\\s', ' ', $fullAddressWithWeirdEscapes),
        );

        // This modifies `$lines` in place, leaving just the non-postcode strings.
        $postcode = array_pop($lines);

        return [
            'line' => $lines,
            'postcode' => $postcode,
            'country' => 'United Kingdom',
        ];
    }
}
