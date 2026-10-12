<?php

namespace Latex2MathML;

class Tokenizer
{
    public const UNITS = ["in", "mm", "cm", "pt", "em", "ex", "pc", "bp", "dd", "cc", "sp", "mu"];

    public static function tokenize(string $latex_string, bool $skip_comments = true): array
    {
        $units_regex = implode('|', self::UNITS);
        $pattern = "/(%[^\\n]+)|([a-zA-Z])|([_^])(\\d)|(-?\\d+(?:\\.\\d+)?\\s*(?:{$units_regex}))|(\\d+(?:\\.\\d+)?)|(\\.\\d*)|(\\\\[\\\\\\[\\]{} \\s!,:>;|_%#$&])|(\\\\(?:begin|end)\\s*\\{[a-zA-Z]+\\*?\\})|(\\\\operatorname\\s*\\{[a-zA-Z\\s*]+\\*?\\s*\\})|(\\\\(?:cite|color|fbox|hbox|href|label|mbox|ref|style|tag|text|textbf|textit|textrm|textsf|texttt))\\s*\\{([^\\}]*)\\}|(\\\\[cdt]?frac)\\s*([.\\d])\\s*([.\\d])?|(\\\\math[a-z]+)(\\{)([a-zA-Z])(\\})|(\\\\[a-zA-Z]+)|(\\S)/ux";

        preg_match_all($pattern, $latex_string, $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            // match[0] is the full match, match[1..N] are groups
            $filtered_groups = [];
            foreach (array_slice($match, 1) as $k => $v) {
                if ($v !== '') {
                    $filtered_groups[] = $v;
                } elseif ($k === 11 && str_contains($match[0], '{')) {
                    if (preg_match('/^\\\\(?:color|fbox|hbox|href|mbox|style|text|textbf|textit|textrm|textsf|texttt)/', $match[0])) {
                        $filtered_groups[] = '';
                    }
                }
            }
            
            if (empty($filtered_groups)) continue;

            if (str_starts_with($filtered_groups[0], Commands::MATH) && count($filtered_groups) >= 4 && $filtered_groups[1] === '{' && $filtered_groups[3] === '}') {
                $full_math = implode('', array_slice($filtered_groups, 0, 4));
                $symbol = SymbolsParser::convert_symbol($full_math);
                if ($symbol) {
                    $tokens[] = mb_chr(hexdec($symbol), 'UTF-8');
                    continue;
                }
            }

            foreach ($filtered_groups as $captured) {
                if ($skip_comments && str_starts_with($captured, "%")) {
                    break;
                }
                
                $is_unit = false;
                foreach (self::UNITS as $unit) {
                    if (str_ends_with($captured, $unit)) {
                        $is_unit = true;
                        break;
                    }
                }
                if ($is_unit) {
                    $tokens[] = str_replace(" ", "", $captured);
                    continue;
                }

                if (str_starts_with($captured, Commands::BEGIN) || str_starts_with($captured, Commands::END) || str_starts_with($captured, Commands::OPERATORNAME)) {
                    $tokens[] = str_replace(" ", "", $captured);
                    continue;
                }
                $tokens[] = $captured;
            }
        }
        return $tokens;
    }
}
