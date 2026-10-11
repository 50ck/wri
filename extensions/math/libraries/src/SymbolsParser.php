<?php

namespace Latex2MathML;

class SymbolsParser
{
    private static ?array $SYMBOLS = null;
    private static string $SYMBOLS_FILE = __DIR__ . '/unimathsymbols.txt';

    public static function convert_symbol(string $symbol): ?string
    {
        if (self::$SYMBOLS === null) {
            self::$SYMBOLS = self::parse_symbols();
        }
        return self::$SYMBOLS[$symbol] ?? null;
    }

    private static function parse_symbols(): array
    {
        $symbols = [];
        if (!file_exists(self::$SYMBOLS_FILE)) {
            // In case it's in a different location during porting or installation
            self::$SYMBOLS_FILE = __DIR__ . '/../latex2mathml/unimathsymbols.txt';
        }
        
        $handle = fopen(self::$SYMBOLS_FILE, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (str_starts_with($line, "#")) {
                    continue;
                }
                $columns = explode("^", trim($line));
                if (count($columns) < 4) continue;

                $unicode = $columns[0];
                $latex = $columns[2];
                $unicode_math = $columns[3];

                if ($latex && !isset($symbols[$latex])) {
                    $symbols[$latex] = $unicode;
                }
                if ($unicode_math && !isset($symbols[$unicode_math])) {
                    $symbols[$unicode_math] = $unicode;
                }

                // Equivalents in the last column
                if (isset($columns[count($columns) - 1])) {
                    preg_match_all('/[=#]\s*(\\\\[^,^ ]+),?/', $columns[count($columns) - 1], $matches);
                    foreach ($matches[1] as $equivalent) {
                        if (!isset($symbols[$equivalent])) {
                            $symbols[$equivalent] = $unicode;
                        }
                    }
                }
            }
            fclose($handle);
        }

        $symbols = array_merge($symbols, [
            "\\And" => $symbols["\\ampersand"] ?? null,
            "\\bigcirc" => $symbols["\\lgwhtcircle"] ?? null,
            "\\Box" => $symbols["\\square"] ?? null,
            "\\circledS" => "024C8",
            "\\diagdown" => "02572",
            "\\diagup" => "02571",
            "\\dots" => "02026",
            "\\dotsb" => $symbols["\\cdots"] ?? null,
            "\\dotsc" => "02026",
            "\\dotsi" => $symbols["\\cdots"] ?? null,
            "\\dotsm" => $symbols["\\cdots"] ?? null,
            "\\dotso" => "02026",
            "\\emptyset" => "02205",
            "\\gggtr" => "022D9",
            "\\gvertneqq" => "02269",
            "\\gt" => $symbols["\\greater"] ?? null,
            "\\ldotp" => $symbols["\\period"] ?? null,
            "\\llless" => $symbols["\\lll"] ?? null,
            "\\lt" => $symbols["\\less"] ?? null,
            "\\lvert" => $symbols["\\vert"] ?? null,
            "\\lVert" => $symbols["\\Vert"] ?? null,
            "\\lvertneqq" => $symbols["\\lneqq"] ?? null,
            "\\ngeqq" => $symbols["\\ngeq"] ?? null,
            "\\nshortmid" => $symbols["\\nmid"] ?? null,
            "\\nshortparallel" => $symbols["\\nparallel"] ?? null,
            "\\nsubseteqq" => $symbols["\\nsubseteq"] ?? null,
            "\\omicron" => $symbols["\\upomicron"] ?? null,
            "\\rvert" => $symbols["\\vert"] ?? null,
            "\\rVert" => $symbols["\\Vert"] ?? null,
            "\\shortmid" => $symbols["\\mid"] ?? null,
            "\\smallfrown" => $symbols["\\frown"] ?? null,
            "\\smallint" => "0222B",
            "\\smallsmile" => $symbols["\\smile"] ?? null,
            "\\surd" => $symbols["\\sqrt"] ?? null,
            "\\thicksim" => "0223C",
            "\\thickapprox" => $symbols["\\approx"] ?? null,
            "\\varsubsetneqq" => $symbols["\\subsetneqq"] ?? null,
            "\\varsupsetneq" => "0228B",
            "\\varsupsetneqq" => $symbols["\\supsetneqq"] ?? null,
        ]);
        unset($symbols["\\mathring"]);
        return $symbols;
    }
}
