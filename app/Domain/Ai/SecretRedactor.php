<?php

namespace App\Domain\Ai;

use PhpToken;

/**
 * Removes secrets from a PHP code fragment before it is sent to an AI provider.
 * Works on tokens, so only string literals change and the rest of the code reads the same.
 * Over-redaction is preferred to a leak.
 */
final class SecretRedactor
{
    /** Known key prefixes at the start of a word, anywhere in a string ("Bearer sk-…"); sk- also covers sk-ant-. */
    private const PREFIX = '/(?<![A-Za-z0-9_])(?:sk-|ghp_|github_pat_|AKIA|xox|AIza)[^\s\'"{}$]*/';

    /** Hex keys can't reach the entropy bound (16 symbols top out at 4.0): 32+ hex digits, after an optional short prefix. */
    private const HEX = '/^(?:[A-Za-z]{1,10}[-_])?[0-9a-f]{32,}$/i';

    private const SECRET_NAME = '/pass|secret|token|key|private|credential|auth/i';

    private const ASSIGN = ['=', '=>', '.=', '??='];

    private const ARMOR = '/-----(?:BEGIN|END) /';

    public static function redact(string $code): string
    {
        // Any armored block (keys, certificates, PGP) inside one string: never across a quote, or the tokens after it would flip.
        // A string holding a BEGIN or an END line without the other (a block split across literals) is redacted whole below.
        $code = (string) preg_replace('/-----BEGIN [A-Z0-9 ]+-----[^\'"]*?-----END [A-Z0-9 ]+-----/', '[redacted]', $code);

        $tokens = PhpToken::tokenize('<?php '.$code);
        $out = '';
        foreach ($tokens as $i => $token) {
            if ($i === 0) {
                continue; // the open tag added above
            }
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $out .= self::isSecret($tokens, $i, substr($token->text, 1, -1)) ? "'[redacted]'" : $token->text;
            } elseif ($token->is(T_ENCAPSED_AND_WHITESPACE)) {
                // Interpolated strings and heredoc/nowdoc bodies: all of it when armored or passed/assigned to a secret name, else the known prefixes.
                $start = self::stringStart($tokens, $i);
                $out .= self::armored($tokens, $start) || self::namedSecret($tokens, $start) || self::secretCall($tokens, $start) ? '[redacted]' : preg_replace(self::PREFIX, '[redacted]', $token->text);
            } else {
                $out .= $token->text;
            }
        }

        return $out;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private static function isSecret(array $tokens, int $i, string $value): bool
    {
        if (preg_match(self::ARMOR, $value) === 1 || preg_match(self::PREFIX, $value) === 1 || preg_match(self::HEX, $value) === 1
            || (strlen($value) >= 24 && ! str_contains($value, ' ') && self::entropy($value) >= 4.0)
            || self::namedSecret($tokens, $i) || self::secretCall($tokens, $i)) {
            return true;
        }

        // env('X', '…') defaults, and define('API_KEY', '…')
        [$function, $comma] = self::secondArgumentOf($tokens, $i) ?? ['', 0];
        $first = $function === 'define' ? self::previous($tokens, $comma) : null;

        return $function === 'env' || ($first !== null && $tokens[$first]->is(T_CONSTANT_ENCAPSED_STRING) && preg_match(self::SECRET_NAME, $tokens[$first]->text) === 1);
    }

    /**
     * Is the value at $i assigned to a secret-looking name? Walks back over its expression (ternaries, ??, concatenation)
     * to the nearest assignment at the same depth: `$apiKey = …`, `'password' => …`, `$config['token'] = …`, `.=`, `??=`,
     * `const TOKEN = …`, `->secret = …`, or a named argument `password: …`.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function namedSecret(array $tokens, int $i): bool
    {
        for ($depth = 0, $j = self::previous($tokens, $i); $j !== null; $j = self::previous($tokens, $j)) {
            $text = $tokens[$j]->text;
            if ($text === ')' || $text === ']') {
                $depth++;
            } elseif ($text === '(' || $text === '[') {
                if ($depth-- === 0) {
                    return false; // left the enclosing call or array: the value is an argument or a list item
                }
            } elseif ($depth === 0) {
                if (in_array($text, [';', ',', '{', '}'], true)) {
                    return false;
                }
                $label = $text === ':' ? self::previous($tokens, $j) : null;
                $namedArgument = $label !== null && $tokens[$label]->is(T_STRING) && in_array($tokens[self::previous($tokens, $label) ?? 0]->text, ['(', ','], true);
                if ($namedArgument || in_array($text, self::ASSIGN, true)) {
                    $name = self::previous($tokens, $j);
                    $name !== null && $tokens[$name]->text === ']' && $name = self::previous($tokens, $name);

                    return $name !== null && $tokens[$name]->is([T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING]) && preg_match(self::SECRET_NAME, $tokens[$name]->text) === 1;
                }
            }
        }

        return false;
    }

    /**
     * Is the value at $i an argument (at any position, or inside an array argument) of a call whose name looks secret:
     * `withToken('…')`, `withBasicAuth('user', '…')`, `setApiKey('…')`? Over-redacts calls like `authorize('update')`.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function secretCall(array $tokens, int $i): bool
    {
        for ($depth = 0, $j = self::previous($tokens, $i); $j !== null; $j = self::previous($tokens, $j)) {
            $text = $tokens[$j]->text;
            if ($text === ')' || $text === ']') {
                $depth++;
            } elseif ($text === '[') {
                $depth > 0 && $depth--; // at depth 0: leaving an array literal, keep looking for its call
            } elseif ($text === '(') {
                if ($depth-- === 0) {
                    $name = self::previous($tokens, $j);

                    return $name !== null && $tokens[$name]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && preg_match(self::SECRET_NAME, $tokens[$name]->text) === 1;
                }
            } elseif ($depth === 0 && in_array($text, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Does any part of the interpolated string or heredoc starting at $start hold a BEGIN or END armor line?
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function armored(array $tokens, int $start): bool
    {
        for ($i = $start + 1; isset($tokens[$i]) && ! $tokens[$i]->is(T_END_HEREDOC) && ! in_array($tokens[$i]->text, ['"', '`'], true); $i++) {
            if ($tokens[$i]->is(T_ENCAPSED_AND_WHITESPACE) && preg_match(self::ARMOR, $tokens[$i]->text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The function whose second argument the literal at $i is (lowercased), and the comma before it.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array{string, int}|null
     */
    private static function secondArgumentOf(array $tokens, int $i): ?array
    {
        $comma = self::previous($tokens, $i);
        if ($comma === null || $tokens[$comma]->text !== ',') {
            return null;
        }
        for ($depth = 0, $j = $comma - 1; $j > 0; $j--) {
            $text = $tokens[$j]->text;
            if ($text === ')' || $text === ']') {
                $depth++;
            } elseif (($text === '(' || $text === '[') && $depth-- === 0) {
                $name = self::previous($tokens, $j);

                return $text === '(' && $name !== null ? [strtolower(ltrim($tokens[$name]->text, '\\')), $comma] : null;
            } elseif ($text === ',' && $depth === 0) {
                return null; // a third or later argument
            }
        }

        return null;
    }

    /**
     * The opening token (`"`, backtick or heredoc start) of the string holding the part at $i.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function stringStart(array $tokens, int $i): int
    {
        while ($i > 0 && ! $tokens[$i]->is(T_START_HEREDOC) && ! in_array($tokens[$i]->text, ['"', '`'], true)) {
            $i--;
        }

        return $i;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private static function previous(array $tokens, int $i): ?int
    {
        for ($i--; $i > 0; $i--) {
            if (! $tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    private static function entropy(string $value): float
    {
        $length = strlen($value);
        $sum = 0.0;
        foreach (count_chars($value, 1) as $count) {
            $p = $count / $length;
            $sum -= $p * log($p, 2);
        }

        return $sum;
    }
}
