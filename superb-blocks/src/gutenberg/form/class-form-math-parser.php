<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

/**
 * Safe math parser for calculated form fields.
 * Recursive-descent parser — NO eval().
 *
 * Supports: +, -, *, /, parentheses, numbers, round(expr, decimals)
 * Field references: {fieldId} resolved at evaluation time.
 *
 * PHP 5.6 compatible: no const class constants, no ??, no short array in const.
 */
class FormMathParser
{
    /**
     * Evaluate a formula string with field values.
     *
     * @param string $formula Formula with {fieldId} references
     * @param array $field_values Map of fieldId => raw value string
     * @param int $round_result Decimal places to round final result (-1 = no rounding)
     * @return float
     */
    public static function Evaluate($formula, $field_values, $round_result = -1)
    {
        if (!is_string($formula) || $formula === '') {
            return 0;
        }

        $tokens = self::Tokenize($formula);
        if (empty($tokens)) {
            return 0;
        }

        $pos = 0;
        $result = self::ParseExpr($tokens, $pos, $field_values);

        // Handle NaN, Infinity
        if (!is_finite($result) || is_nan($result)) {
            return 0;
        }

        // Apply global rounding
        if (is_numeric($round_result) && $round_result >= 0) {
            $result = round($result, intval($round_result));
        }

        return $result;
    }

    /**
     * Extract field IDs referenced in a formula.
     *
     * @param string $formula
     * @return array Array of fieldId strings
     */
    public static function ExtractFieldRefs($formula)
    {
        if (!is_string($formula) || $formula === '') {
            return array();
        }

        $refs = array();
        if (preg_match_all('/\{([^}]+)\}/', $formula, $matches)) {
            foreach ($matches[1] as $ref) {
                if (!in_array($ref, $refs, true)) {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }

    /**
     * Clean a field value: strip everything except digits, decimal points, minus signs.
     *
     * @param mixed $val
     * @return string
     */
    private static function CleanFieldValue($val)
    {
        if ($val === null || $val === '') {
            return '0';
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', strval($val));
        if ($cleaned === '' || $cleaned === '-' || $cleaned === '.') {
            return '0';
        }

        return $cleaned;
    }

    /**
     * Tokenize a formula string.
     *
     * @param string $formula
     * @return array Array of token arrays: array('type' => string, 'value' => mixed)
     */
    private static function Tokenize($formula)
    {
        $tokens = array();
        $len = strlen($formula);
        $i = 0;

        while ($i < $len) {
            $ch = $formula[$i];

            // Whitespace
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $i++;
                continue;
            }

            // Field reference: {fieldId}
            if ($ch === '{') {
                $end = strpos($formula, '}', $i);
                if ($end === false) {
                    $i++;
                    continue;
                }
                $tokens[] = array('type' => 'FIELD_REF', 'value' => substr($formula, $i + 1, $end - $i - 1));
                $i = $end + 1;
                continue;
            }

            // Number (including negative preceded by operator or start)
            if (
                ($ch >= '0' && $ch <= '9') ||
                $ch === '.' ||
                ($ch === '-' && (
                    empty($tokens) ||
                    $tokens[count($tokens) - 1]['type'] === 'OP' ||
                    $tokens[count($tokens) - 1]['type'] === 'LPAREN' ||
                    $tokens[count($tokens) - 1]['type'] === 'COMMA'
                ))
            ) {
                $start = $i;
                if ($ch === '-') {
                    $i++;
                }
                while ($i < $len && (($formula[$i] >= '0' && $formula[$i] <= '9') || $formula[$i] === '.')) {
                    $i++;
                }
                $num_str = substr($formula, $start, $i - $start);
                $num = floatval($num_str);
                $tokens[] = array('type' => 'NUMBER', 'value' => $num);
                continue;
            }

            // Function: round
            if (substr($formula, $i, 5) === 'round' && $i + 5 < $len && $formula[$i + 5] === '(') {
                $tokens[] = array('type' => 'FUNC', 'value' => 'round');
                $i += 5;
                continue;
            }

            // Operators
            if ($ch === '+' || $ch === '-' || $ch === '*' || $ch === '/') {
                $tokens[] = array('type' => 'OP', 'value' => $ch);
                $i++;
                continue;
            }

            // Parentheses
            if ($ch === '(') {
                $tokens[] = array('type' => 'LPAREN', 'value' => '(');
                $i++;
                continue;
            }
            if ($ch === ')') {
                $tokens[] = array('type' => 'RPAREN', 'value' => ')');
                $i++;
                continue;
            }

            // Comma
            if ($ch === ',') {
                $tokens[] = array('type' => 'COMMA', 'value' => ',');
                $i++;
                continue;
            }

            // Unknown — skip
            $i++;
        }

        return $tokens;
    }

    /**
     * Parse expression: term (('+' | '-') term)*
     */
    private static function ParseExpr(&$tokens, &$pos, $field_values)
    {
        $left = self::ParseTerm($tokens, $pos, $field_values);

        while (true) {
            if ($pos >= count($tokens)) {
                break;
            }
            $t = $tokens[$pos];
            if ($t['type'] !== 'OP' || ($t['value'] !== '+' && $t['value'] !== '-')) {
                break;
            }
            $pos++;
            $right = self::ParseTerm($tokens, $pos, $field_values);
            if ($t['value'] === '+') {
                $left = $left + $right;
            } else {
                $left = $left - $right;
            }
        }

        return $left;
    }

    /**
     * Parse term: factor (('*' | '/') factor)*
     */
    private static function ParseTerm(&$tokens, &$pos, $field_values)
    {
        $left = self::ParseFactor($tokens, $pos, $field_values);

        while (true) {
            if ($pos >= count($tokens)) {
                break;
            }
            $t = $tokens[$pos];
            if ($t['type'] !== 'OP' || ($t['value'] !== '*' && $t['value'] !== '/')) {
                break;
            }
            $pos++;
            $right = self::ParseFactor($tokens, $pos, $field_values);
            if ($t['value'] === '*') {
                $left = $left * $right;
            } else {
                // Division by zero returns 0
                $left = $right == 0 ? 0 : $left / $right;
            }
        }

        return $left;
    }

    /**
     * Parse factor: NUMBER | FIELD_REF | '(' expr ')' | round '(' expr ',' expr ')'
     */
    private static function ParseFactor(&$tokens, &$pos, $field_values)
    {
        if ($pos >= count($tokens)) {
            return 0;
        }

        $t = $tokens[$pos];

        // Number literal
        if ($t['type'] === 'NUMBER') {
            $pos++;
            return $t['value'];
        }

        // Field reference
        if ($t['type'] === 'FIELD_REF') {
            $pos++;
            $raw = isset($field_values[$t['value']]) ? $field_values[$t['value']] : '';
            $cleaned = self::CleanFieldValue($raw);
            $num = floatval($cleaned);
            return is_nan($num) ? 0 : $num;
        }

        // Parenthesized expression
        if ($t['type'] === 'LPAREN') {
            $pos++; // (
            $val = self::ParseExpr($tokens, $pos, $field_values);
            if ($pos < count($tokens) && $tokens[$pos]['type'] === 'RPAREN') {
                $pos++; // )
            }
            return $val;
        }

        // round(expr, decimals)
        if ($t['type'] === 'FUNC' && $t['value'] === 'round') {
            $pos++; // round
            if ($pos < count($tokens) && $tokens[$pos]['type'] === 'LPAREN') {
                $pos++; // (
            }
            $expr = self::ParseExpr($tokens, $pos, $field_values);
            $decimals = 0;
            if ($pos < count($tokens) && $tokens[$pos]['type'] === 'COMMA') {
                $pos++; // ,
                $decimals = self::ParseExpr($tokens, $pos, $field_values);
            }
            if ($pos < count($tokens) && $tokens[$pos]['type'] === 'RPAREN') {
                $pos++; // )
            }
            return round($expr, max(0, intval($decimals)));
        }

        // Unknown token — skip and return 0
        $pos++;
        return 0;
    }
}
