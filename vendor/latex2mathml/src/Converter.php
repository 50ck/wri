<?php

namespace Latex2MathML;

use DOMDocument;
use DOMElement;

enum Mode
{
    case TEXT;
    case MATH;
}

class Converter
{
    public const COLUMN_ALIGNMENT_MAP = ["r" => "right", "l" => "left", "c" => "center"];
    public const OPERATORS = [
        "+", "-", "*", "/", "(", ")", "=", ",", "?", "[", "]", "|", "\\|", "!", "\\{", "\\}", ">", "<", ".",
        "\\bigotimes", "\\centerdot", "\\dots", "\\dotsc", "\\dotso", "\\gt", "\\ldotp", "\\lt", "\\lvert", "\\lVert",
        "\\lvertneqq", "\\ngeqq", "\\omicron", "\\rvert", "\\rVert", "\\S", "\\smallfrown", "\\smallint", "\\smallsmile",
        "\\surd", "\\varsubsetneqq", "\\varsupsetneqq"
    ];
    public const MATH_MODE_PATTERN = "/\\\\\\$|\\$|\\\\?[^\\\\$]+/";

    /**
     * Converts a LaTeX string to a MathML string.
     *
     * @param string $latex The LaTeX expression to convert.
     * @param string $display The display mode: "inline" (default) or "block".
     * @param string $xmlns The XML namespace for the <math> element.
     * @return string The resulting MathML string.
     */
    public static function convert(string $latex, string $display = "inline", string $xmlns = "http://www.w3.org/1998/Math/MathML"): string
    {
        $latex = self::preprocess($latex);
        $dom = new DOMDocument();
        $math = self::convert_to_element($latex, $dom, $display, $xmlns);
        $dom->appendChild($math);
        return self::_convert($dom);
    }

    /**
     * Converts a LaTeX string to OMML (Office Math Markup Language) for Microsoft Word.
     *
     * @param string $latex The LaTeX expression to convert.
     * @param string $display The display mode: "inline" (default) or "block".
     * @return string The resulting OMML string.
     */
    public static function convertToOmml(string $latex, string $display = "inline"): string
    {
        return OmmlConverter::convert($latex, $display);
    }

    private static function preprocess(string $latex): string
    {
        $latex = preg_replace_callback('/\\\\specialChar\s*\{(\d+)\}/', function ($matches) {
            return mb_chr((int)$matches[1], 'UTF-8');
        }, $latex);

        $latex = html_entity_decode($latex, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Handle double-escaped entities if they exist (common in some HTML exporters)
        return html_entity_decode($latex, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Converts a LaTeX string to a MathML DOMElement.
     *
     * @param string $latex The LaTeX expression to convert.
     * @param DOMDocument $dom The DOMDocument instance to use for creating the element.
     * @param string $display The display mode: "inline" (default) or "block".
     * @param string $xmlns The XML namespace for the <math> element.
     * @return DOMElement The resulting <math> element.
     */
    public static function convert_to_element(string $latex, DOMDocument $dom, string $display = "inline", string $xmlns = "http://www.w3.org/1998/Math/MathML"): DOMElement
    {
        if (empty($xmlns)) {
            $math = $dom->createElement("math");
        } else {
            $math = $dom->createElementNS($xmlns, "math");
        }
        $math->setAttribute("display", $display);
        $row = $dom->createElement("mrow");
        $math->appendChild($row);
        self::_convert_group(Walker::walk($latex, $display), $row, $dom);
        return $math;
    }

    private static function _convert(DOMDocument $dom): string
    {
        $dom->formatOutput = false;
        return $dom->saveXML($dom->documentElement);
    }

    private static function _convert_matrix(array $nodes, DOMElement $parent, string $command, DOMDocument $dom, ?string $alignment = null): void
    {
        $rows = [];
        $currentRow = [];
        $hfil_indexes = [];
        $row_hfil_indexes = [];

        $col_index = 0;
        $max_col_size = 0;
        $row_lines = [];

        foreach ($nodes as $node) {
            if ($node->token === Commands::BRACES) {
                $currentRow[] = [$node];
                $row_hfil_indexes[] = [false];
            } elseif ($node->token === "&") {
                $col_index++;
                if (!isset($currentRow[$col_index])) {
                    $currentRow[$col_index] = [];
                    $row_hfil_indexes[$col_index] = [];
                }
            } elseif ($node->token === Commands::DOUBLEBACKSLASH || $node->token === Commands::CARRIAGE_RETURN || $node->token === '\\\\') {
                $rows[] = [$currentRow, $row_hfil_indexes];
                if ($col_index + 1 > $max_col_size) $max_col_size = $col_index + 1;
                $currentRow = [];
                $row_hfil_indexes = [];
                $col_index = 0;
            } elseif ($node->token === Commands::HLINE) {
                $row_lines[] = "solid";
            } elseif ($node->token === Commands::HDASHLINE) {
                $row_lines[] = "dashed";
            } elseif ($node->token === Commands::HFIL) {
                if (!isset($row_hfil_indexes[$col_index])) $row_hfil_indexes[$col_index] = [];
                $row_hfil_indexes[$col_index][] = true;
            } else {
                if (!isset($currentRow[$col_index])) $currentRow[$col_index] = [];
                if (!isset($row_hfil_indexes[$col_index])) $row_hfil_indexes[$col_index] = [];
                $currentRow[$col_index][] = $node;
                $row_hfil_indexes[$col_index][] = false;
            }
        }
        if (!empty($currentRow)) {
            $rows[] = [$currentRow, $row_hfil_indexes];
            if ($col_index + 1 > $max_col_size) $max_col_size = $col_index + 1;
        }

        if (in_array("solid", $row_lines)) {
            $parent->setAttribute("rowlines", implode(" ", $row_lines));
        }

        foreach ($rows as $idx => [$rowNodes, $rowHfils]) {
            $mtr = $dom->createElement("mtr");
            $parent->appendChild($mtr);

            for ($i = 0; $i < $max_col_size; $i++) {
                $col_alignment = null;
                if ($alignment) {
                    $char = $alignment[$i] ?? $alignment[$i % strlen($alignment)];
                    $col_alignment = self::COLUMN_ALIGNMENT_MAP[$char] ?? null;
                }

                // Special alignment for multline
                if ($command === Commands::MULTLINE || $command === Commands::MULTLINE_STAR) {
                    if ($idx === 0) {
                        $col_alignment = "left";
                    } elseif ($idx === count($rows) - 1) {
                        $col_alignment = "right";
                    } else {
                        $col_alignment = "center";
                    }
                }

                $mtd = $dom->createElement("mtd");
                if ($col_alignment) {
                    $mtd->setAttribute("columnalign", $col_alignment);
                }

                // Check hfil alignment
                if (isset($rowHfils[$i])) {
                    $hfils = $rowHfils[$i];
                    if (!empty($hfils) && in_array(true, $hfils, true) && count($hfils) > 1) {
                        if ($hfils[0] && !$hfils[count($hfils) - 1]) {
                            $mtd->setAttribute("columnalign", "right");
                        } elseif (!$hfils[0] && $hfils[count($hfils) - 1]) {
                            $mtd->setAttribute("columnalign", "left");
                        }
                    }
                }

                $mtr->appendChild($mtd);

                // Empty mspace injection for align-like environments
                if (in_array($command, [Commands::SPLIT, Commands::ALIGN, Commands::ALIGN_STAR, Commands::ALIGNED, Commands::ALIGNED_STAR, Commands::FLALIGN, Commands::FLALIGN_STAR, Commands::ALIGNAT, Commands::ALIGNAT_STAR, Commands::ALIGNEDAT, Commands::ALIGNEDAT_STAR]) && ($i + 1) % 2 === 0) {
                    $mspace = $dom->createElement("mspace");
                    $mspace->setAttribute("width", "0em");
                    $mtd->appendChild($mspace);
                }

                if (isset($rowNodes[$i])) {
                    self::_convert_group($rowNodes[$i], $mtd, $dom);
                }
            }
        }

        if ($max_col_size && in_array($command, [Commands::ALIGN, Commands::ALIGN_STAR, Commands::ALIGNED, Commands::ALIGNED_STAR, Commands::FLALIGN, Commands::FLALIGN_STAR, Commands::ALIGNAT, Commands::ALIGNAT_STAR, Commands::ALIGNEDAT, Commands::ALIGNEDAT_STAR])) {
            $spacing = ["0em", "2em"];
            $multiplier = (int)($max_col_size / count($spacing));
            $combined_spacing = [];
            for ($i = 0; $i < $multiplier; $i++) {
                $combined_spacing = array_merge($combined_spacing, $spacing);
            }
            $parent->setAttribute("columnspacing", implode(" ", $combined_spacing));
        }
    }

    private static function _set_cell_alignment(DOMElement $cell, array $hfil_indexes): void
    {
        if (!empty($hfil_indexes) && in_array(true, $hfil_indexes, true) && count($hfil_indexes) > 1) {
            if ($hfil_indexes[0] && !$hfil_indexes[count($hfil_indexes) - 1]) {
                $cell->setAttribute("columnalign", "right");
            } elseif (!$hfil_indexes[0] && $hfil_indexes[count($hfil_indexes) - 1]) {
                $cell->setAttribute("columnalign", "left");
            }
        }
    }

    private static function _get_column_alignment(?string $alignment, ?string $column_alignment, int $column_index): array
    {
        if ($alignment) {
            $char = $alignment[$column_index] ?? $alignment[$column_index % strlen($alignment)];
            $column_alignment = self::COLUMN_ALIGNMENT_MAP[$char] ?? null;
            $column_index++;
        }
        return [$column_alignment, $column_index];
    }

    private static function _make_matrix_cell(DOMElement $row, ?string $column_alignment, DOMDocument $dom): DOMElement
    {
        $mtd = $dom->createElement("mtd");
        if ($column_alignment) {
            $mtd->setAttribute("columnalign", $column_alignment);
        }
        $row->appendChild($mtd);
        return $mtd;
    }

    private static function _convert_group(array $nodes, DOMElement $parent, DOMDocument $dom, ?array $font = null): void
    {
        $_font = $font;
        $iterator = new \ArrayIterator($nodes);
        while ($iterator->valid()) {
            $node = $iterator->current();
            $token = $node->token;
            $is_big_operator = self::_is_big_operator($node);

            if (isset(Commands::$MSTYLE_SIZES[$token]) || isset(Commands::$STYLES[$token])) {
                $children = [];
                $iterator->next();
                while ($iterator->valid()) {
                    $children[] = $iterator->current();
                    $iterator->next();
                }
                $newNode = new Node(token: $token, children: $children);
                self::_convert_command($newNode, $parent, $dom, $_font);
                break;
            } elseif (isset(Commands::$CONVERSION_MAP[$token]) || $token === Commands::MOD || $token === Commands::PMOD) {
                if ($token === Commands::SUBSUP && $node->children !== null && count($node->children) >= 3) {
                    $base = $node->children[0];
                    if ($base->token === "" && empty($base->children)) {
                        self::_convert_group([$node->children[1]], $parent, $dom, $_font);
                        self::_convert_group([$node->children[2]], $parent, $dom, $_font);
                    } else {
                        $tag = "msubsup";
                        if (in_array($node->modifier, [Commands::LIMITS, Commands::OVERBRACE, Commands::UNDERBRACE]) || $node->children[0]->token === Commands::GCD) {
                            $tag = "munderover";
                        }
                        $element = $dom->createElement($tag);
                        $parent->appendChild($element);
                        self::_convert_group([$node->children[0]], $element, $dom, $_font);
                        self::_convert_group([$node->children[1]], $element, $dom, $_font);
                        self::_convert_group([$node->children[2]], $element, $dom, $_font);
                    }
                } elseif ($token === Commands::SUBSCRIPT && $node->children !== null && count($node->children) >= 2) {
                    $base = $node->children[0];
                    if ($base->token === "" && empty($base->children)) {
                        self::_convert_group([$node->children[1]], $parent, $dom, $_font);
                    } else {
                        $tag = "msub";
                        if (in_array($node->modifier, [Commands::LIMITS, Commands::UNDERBRACE])) {
                            $tag = "munder";
                        }
                        $element = $dom->createElement($tag);
                        $parent->appendChild($element);
                        self::_convert_group([$node->children[0]], $element, $dom, $_font);
                        self::_convert_group([$node->children[1]], $element, $dom, $_font);
                    }
                } elseif ($token === Commands::SUPERSCRIPT && $node->children !== null && count($node->children) >= 2) {
                    $base = $node->children[0];
                    if ($base->token === "" && empty($base->children)) {
                        self::_convert_group([$node->children[1]], $parent, $dom, $_font);
                    } else {
                        $tag = "msup";
                        if (in_array($node->modifier, [Commands::LIMITS, Commands::OVERBRACE])) {
                            $tag = "mover";
                        }
                        $element = $dom->createElement($tag);
                        $parent->appendChild($element);
                        self::_convert_group([$node->children[0]], $element, $dom, $_font);
                        self::_convert_group([$node->children[1]], $element, $dom, $_font);
                    }
                } else {
                    self::_convert_command($node, $parent, $dom, $_font);
                }
            } elseif (isset(Commands::$LOCAL_FONTS[$token]) && $node->children !== null) {
                self::_convert_group($node->children, $parent, $dom, Commands::$LOCAL_FONTS[$token]);
            } elseif (str_starts_with($token, Commands::MATH) && $node->children !== null) {
                self::_convert_group($node->children, $parent, $dom, $_font);
            } elseif (isset(Commands::$GLOBAL_FONTS[$token])) {
                $_font = Commands::$GLOBAL_FONTS[$token];
            } elseif ($node->children === null) {
                self::_convert_symbol($node, $parent, $dom, $_font);
            } elseif ($node->children !== null) {
                $attributes = $node->attributes ?? [];
                $mrow = $dom->createElement("mrow");
                foreach ($attributes as $k => $v) {
                    $mrow->setAttribute($k, $v);
                }
                $parent->appendChild($mrow);
                self::_convert_group($node->children, $mrow, $dom, $_font);
            }

            if ($is_big_operator) {
                $remaining = [];
                $iterator->next();
                while ($iterator->valid()) {
                    $remaining[] = $iterator->current();
                    $iterator->next();
                }
                if (!empty($remaining)) {
                    $mrow = $dom->createElement("mrow");
                    $parent->appendChild($mrow);
                    self::_convert_group($remaining, $mrow, $dom, $_font);
                }
                break;
            }

            $iterator->next();
        }
    }

    private static function _is_big_operator(Node $node): bool
    {
        $token = $node->token;
        if ($token === Commands::INTEGRAL || $token === Commands::INTOP || $token === Commands::IDOTSINT) {
            return true;
        }
        if (in_array($token, [Commands::SUBSCRIPT, Commands::SUPERSCRIPT, Commands::SUBSUP]) && $node->children !== null) {
            return self::_is_big_operator($node->children[0]);
        }
        $symbol = SymbolsParser::convert_symbol($token);
        if ($symbol) {
            $code = hexdec($symbol);
            return ($code >= 0x222B && $code <= 0x2233) // integrals
                || $code === 0x2211 // \sum
                || $code === 0x220F // \prod
                || $code === 0x2210 // \coprod
                || $code === 0x22C0 // \bigwedge
                || $code === 0x22C1 // \bigvee
                || $code === 0x22C2 // \bigcap
                || $code === 0x22C3 // \bigcup
                || $code === 0x2295 // \bigoplus
                || $code === 0x2297 // \bigotimes
                || $code === 0x2299 // \bigodot
                || $code === 0x2294 // \bigsqcup
                || $code === 0x228E; // \biguplus
        }
        return false;
    }

    private static function _get_alignment_and_column_lines(?string $alignment = null): array
    {
        if ($alignment === null) return [null, null];
        if (!str_contains($alignment, "|")) return [$alignment, null];

        $_alignment = "";
        $column_lines = [];
        for ($i = 0; $i < strlen($alignment); $i++) {
            $c = $alignment[$i];
            if ($c === "|") {
                $column_lines[] = "solid";
            } else {
                $_alignment .= $c;
            }
            if (strlen($_alignment) - count($column_lines) === 2) {
                $column_lines[] = "none";
            }
        }
        return [$_alignment, implode(" ", $column_lines)];
    }

    public static function separate_by_mode(string $text): array
    {
        preg_match_all(self::MATH_MODE_PATTERN, $text, $matches);
        $results = [];
        $string = "";
        $is_math_mode = false;
        foreach ($matches[0] as $match) {
            if ($match === "$") {
                $results[] = [$string, $is_math_mode ? Mode::MATH : Mode::TEXT];
                $string = "";
                $is_math_mode = !$is_math_mode;
            } else {
                $string .= $match;
            }
        }
        if (strlen($string) > 0) {
            $results[] = [$string, $is_math_mode ? Mode::MATH : Mode::TEXT];
        }
        return $results;
    }

    private static function _convert_command(Node $node, DOMElement $parent, DOMDocument $dom, ?array $font = null): void
    {
        $command = $node->token;
        $modifier = $node->modifier;

        if (in_array($command, [Commands::SUBSTACK, Commands::SMALLMATRIX])) {
            $mstyle = $dom->createElement("mstyle");
            $mstyle->setAttribute("scriptlevel", "1");
            $parent->appendChild($mstyle);
            $parent = $mstyle;
        } elseif ($command === Commands::CASES) {
            $mrow = $dom->createElement("mrow");
            $parent->appendChild($mrow);
            $parent = $mrow;
            $lbrace = $dom->createElement("mo");
            $lbrace->nodeValue = mb_chr(hexdec(SymbolsParser::convert_symbol(Commands::LBRACE)), 'UTF-8');
            $lbrace->setAttribute("stretchy", "true");
            $lbrace->setAttribute("fence", "true");
            $lbrace->setAttribute("form", "prefix");
            $parent->appendChild($lbrace);
        } elseif (in_array($command, [Commands::DBINOM, Commands::DFRAC])) {
            $mstyle = $dom->createElement("mstyle");
            $mstyle->setAttribute("displaystyle", "true");
            $mstyle->setAttribute("scriptlevel", "0");
            $parent->appendChild($mstyle);
            $parent = $mstyle;
        } elseif ($command === Commands::HPHANTOM) {
            $mpadded = $dom->createElement("mpadded");
            $mpadded->setAttribute("height", "0");
            $mpadded->setAttribute("depth", "0");
            $parent->appendChild($mpadded);
            $parent = $mpadded;
        } elseif ($command === Commands::VPHANTOM) {
            $mpadded = $dom->createElement("mpadded");
            $mpadded->setAttribute("width", "0");
            $parent->appendChild($mpadded);
            $parent = $mpadded;
        } elseif (in_array($command, [Commands::TBINOM, Commands::HBOX, Commands::MBOX, Commands::TFRAC])) {
            $mstyle = $dom->createElement("mstyle");
            $mstyle->setAttribute("displaystyle", "false");
            $mstyle->setAttribute("scriptlevel", "0");
            $parent->appendChild($mstyle);
            $parent = $mstyle;
        } elseif (in_array($command, [Commands::MOD, Commands::PMOD])) {
            $mspace = $dom->createElement("mspace");
            $mspace->setAttribute("width", "1em");
            $parent->appendChild($mspace);
        }

        $conv = Commands::$CONVERSION_MAP[$command] ?? ["mrow", []];
        $tag = $conv[0];
        $attributes = $conv[1];

        if ($node->attributes !== null && $node->token !== Commands::SKEW) {
            $attributes = array_merge($attributes, $node->attributes);
        }

        self::_append_prefix_element($node, $parent, $dom);

        [$alignment, $column_lines] = self::_get_alignment_and_column_lines($node->alignment);
        if ($column_lines) {
            $attributes["columnlines"] = $column_lines;
        }

        if ($command === Commands::SUBSUP && $node->children !== null && $node->children[0]->token === Commands::GCD) {
            $tag = "munderover";
        } elseif ($command === Commands::SUPERSCRIPT && in_array($modifier, [Commands::LIMITS, Commands::OVERBRACE])) {
            $tag = "mover";
        } elseif ($command === Commands::SUBSCRIPT && in_array($modifier, [Commands::LIMITS, Commands::UNDERBRACE])) {
            $tag = "munder";
        } elseif ($command === Commands::SUBSUP && in_array($modifier, [Commands::LIMITS, Commands::OVERBRACE, Commands::UNDERBRACE])) {
            $tag = "munderover";
        } elseif (in_array($command, [Commands::XLEFTARROW, Commands::XRIGHTARROW])) {
            $has_bottom = false;
            $has_top = false;
            if ($node->children !== null) {
                if (count($node->children) === 1) {
                    $has_top = !($node->children[0]->token === Commands::BRACES && empty($node->children[0]->children));
                } elseif (count($node->children) === 2) {
                    $has_bottom = !($node->children[0]->token === Commands::BRACES && empty($node->children[0]->children));
                    $has_top = !($node->children[1]->token === Commands::BRACES && empty($node->children[1]->children));
                }
            }
            if ($has_bottom && $has_top) {
                $tag = "munderover";
            } elseif ($has_bottom) {
                $tag = "munder";
            } elseif ($has_top) {
                $tag = "mover";
            } else {
                $tag = "mo";
                $attributes["stretchy"] = "true";
            }
        }

        // Special handling for \left command
        if ($command === Commands::LEFT) {
            $mrow = $dom->createElement("mrow");
            $parent->appendChild($mrow);

            // Render the left delimiter with stretchy="true"
            if ($node->delimiter !== null && $node->delimiter !== ".") {
                $symbol = SymbolsParser::convert_symbol($node->delimiter);
                $mo = $dom->createElement("mo");
                $mo->nodeValue = ($symbol === null) ? $node->delimiter : mb_chr(hexdec($symbol), 'UTF-8');
                $mo->setAttribute("stretchy", "true");
                $mo->setAttribute("fence", "true");
                $mo->setAttribute("form", "prefix");
                $mrow->appendChild($mo);
            }

            $element = $mrow; // Use mrow as the parent element for children
        } else {
            $element = $dom->createElement($tag);
            foreach ($attributes as $k => $v) {
                $element->setAttribute($k, $v);
            }
            $parent->appendChild($element);
        }

        if (in_array($command, Commands::LIMIT)) {
            $element->nodeValue = substr($command, 1);
        } elseif (in_array($command, [Commands::MOD, Commands::PMOD])) {
            $element->nodeValue = "mod";
            $mspace = $dom->createElement("mspace");
            $mspace->setAttribute("width", "0.333em");
            $parent->appendChild($mspace);
        } elseif ($command === Commands::BMOD) {
            $element->nodeValue = "mod";
        } elseif (in_array($command, [Commands::XLEFTARROW, Commands::XRIGHTARROW])) {
            if ($tag === "mo") {
                $element->nodeValue = ($command === Commands::XLEFTARROW) ? mb_chr(0x2190, 'UTF-8') : mb_chr(0x2192, 'UTF-8');
            } else {
                $mstyle = $dom->createElement("mstyle");
                $mstyle->setAttribute("scriptlevel", "0");
                $element->appendChild($mstyle);
                $arrow = $dom->createElement("mo");
                $arrow->nodeValue = ($command === Commands::XLEFTARROW) ? mb_chr(0x2190, 'UTF-8') : mb_chr(0x2192, 'UTF-8');
                $mstyle->appendChild($arrow);
            }
        } elseif ($command === Commands::LABEL) {
            $element->setAttribute("id", $node->children[0]->token);
            return;
        } elseif ($command === Commands::TAG) {
            $element->setAttribute("id", $node->children[0]->token);
            return;
        } elseif ($command === Commands::CITE) {
            $element->nodeValue = "[" . $node->children[0]->token . "]";
            return;
        } elseif ($command === Commands::REF) {
            $element->nodeValue = $node->children[0]->token;
            return;
        } elseif ($node->text !== null) {
            if ($command === Commands::MIDDLE || isset(Commands::$BIG[$command]) || isset(Commands::$BIG_OPEN_CLOSE[$command])) {
                $symbol = SymbolsParser::convert_symbol($node->text);
                $element->nodeValue = ($symbol === null) ? $node->text : mb_chr(hexdec($symbol), 'UTF-8');
            } elseif ($command === Commands::HBOX) {
                $mtext = $element;
                foreach (self::separate_by_mode($node->text) as [$text, $mode]) {
                    if ($mode === Mode::TEXT) {
                        if ($mtext === null) {
                            $mtext = $dom->createElement($tag);
                            foreach ($attributes as $k => $v) $mtext->setAttribute($k, $v);
                            $parent->appendChild($mtext);
                        }
                        $mtext->nodeValue = str_replace(" ", mb_chr(0x000A0, 'UTF-8'), $text);
                        self::_set_font($mtext, "mtext", $font);
                        $mtext = null;
                    } else {
                        $mrow = $dom->createElement("mrow");
                        $parent->appendChild($mrow);
                        self::_convert_group(Walker::walk($text), $mrow, $dom);
                    }
                }
            } else {
                $target = $element;
                if ($command === Commands::FBOX) {
                    $target = $dom->createElement("mtext");
                    $element->appendChild($target);
                }
                $target->nodeValue = str_replace(" ", mb_chr(0x000A0, 'UTF-8'), $node->text);
                self::_set_font($target, "mtext", $font);
            }
        } elseif ($node->delimiter !== null && !in_array($command, [Commands::FRAC, Commands::GENFRAC, Commands::LEFT])) {
            if ($node->delimiter !== ".") {
                $symbol = SymbolsParser::convert_symbol($node->delimiter);
                $element->nodeValue = ($symbol === null) ? $node->delimiter : mb_chr(hexdec($symbol), 'UTF-8');
            }
        }

        if ($node->children !== null) {
            $_parent = $element;
            if (in_array($command, [Commands::MOD, Commands::PMOD, Commands::LABEL, Commands::TAG, Commands::CITE, Commands::REF])) {
                $_parent = $parent;
            }
            if (in_array($command, Commands::MATRICES)) {
                $mtx_alignment = $alignment;
                if ($command === Commands::CASES) {
                    $mtx_alignment = "l";
                } elseif (in_array($command, [Commands::SPLIT, Commands::ALIGN, Commands::ALIGN_STAR, Commands::ALIGNED, Commands::ALIGNED_STAR, Commands::FLALIGN, Commands::FLALIGN_STAR, Commands::ALIGNAT, Commands::ALIGNAT_STAR, Commands::ALIGNEDAT, Commands::ALIGNEDAT_STAR])) {
                    $mtx_alignment = "rl";
                } elseif (in_array($command, [Commands::EQNARRAY, Commands::EQNARRAY_STAR])) {
                    $mtx_alignment = "rcl";
                }
                self::_convert_matrix($node->children, $_parent, $command, $dom, $mtx_alignment);
            } elseif ($command === Commands::CFRAC) {
                foreach ($node->children as $child) {
                    $p = $dom->createElement("mstyle");
                    $p->setAttribute("displaystyle", "false");
                    $p->setAttribute("scriptlevel", "0");
                    $_parent->appendChild($p);
                    self::_convert_group([$child], $p, $dom, $font);
                }
            } elseif ($command === Commands::SIDESET) {
                $left = $node->children[0];
                $right = $node->children[1];
                self::_convert_group([$left], $_parent, $dom, $font);
                $fill = $dom->createElement("mstyle");
                $fill->setAttribute("scriptlevel", "0");
                $_parent->appendChild($fill);
                $mspace = $dom->createElement("mspace");
                $mspace->setAttribute("width", "-0.167em");
                $fill->appendChild($mspace);
                self::_convert_group([$right], $_parent, $dom, $font);
            } elseif ($command === Commands::SKEW) {
                $child = $node->children[0];
                $new_node = new Node(
                    token: $child->token,
                    children: [
                        new Node(
                            token: Commands::BRACES,
                            children: [...($child->children ?? []), new Node(token: Commands::MKERN, attributes: $node->attributes)]
                        )
                    ]
                );
                self::_convert_group([$new_node], $_parent, $dom, $font);
            } elseif (in_array($command, [Commands::XLEFTARROW, Commands::XRIGHTARROW])) {
                foreach ($node->children as $child) {
                    if ($child->token === Commands::BRACES && empty($child->children)) {
                        continue;
                    }
                    $padded = $dom->createElement("mpadded");
                    $padded->setAttribute("width", "+0.833em");
                    $padded->setAttribute("lspace", "0.556em");
                    $padded->setAttribute("voffset", "-.2em");
                    $padded->setAttribute("height", "-.2em");
                    $_parent->appendChild($padded);
                    self::_convert_group([$child], $padded, $dom, $font);
                    $mspace = $dom->createElement("mspace");
                    $mspace->setAttribute("depth", ".25em");
                    $padded->appendChild($mspace);
                }
            } else {
                self::_convert_group($node->children, $_parent, $dom, $font);
            }
        }

        self::_add_diacritic($command, $element, $dom);
        self::_append_postfix_element($node, $parent, $dom);
    }

    private static function _add_diacritic(string $command, DOMElement $parent, DOMDocument $dom): void
    {
        if (isset(Commands::$DIACRITICS[$command])) {
            [$text, $attributes] = Commands::$DIACRITICS[$command];
            $element = $dom->createElement("mo");
            $element->nodeValue = $text;
            foreach ($attributes as $k => $v) {
                $element->setAttribute($k, $v);
            }
            $parent->appendChild($element);
        }
    }

    private static function _convert_and_append_command(string $command, DOMElement $parent, DOMDocument $dom, array $attributes = []): void
    {
        $code_point = SymbolsParser::convert_symbol($command);
        $mo = $dom->createElement("mo");
        $mo->nodeValue = $code_point ? mb_chr(hexdec($code_point), 'UTF-8') : $command;
        foreach ($attributes as $k => $v) {
            $mo->setAttribute($k, $v);
        }
        $parent->appendChild($mo);
    }

    private static function _append_prefix_element(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        $token = $node->token;
        if (in_array($token, ["\\pmatrix", Commands::PMOD])) {
            self::_convert_and_append_command("\\lparen", $parent, $dom);
        } elseif (in_array($token, [Commands::BINOM, Commands::DBINOM, Commands::TBINOM])) {
            self::_convert_and_append_command("\\lparen", $parent, $dom, ["stretchy" => "true", "symmetric" => "true"]);
        } elseif ($token === "\\bmatrix") {
            self::_convert_and_append_command("\\lbrack", $parent, $dom);
        } elseif ($token === "\\Bmatrix") {
            self::_convert_and_append_command("\\lbrace", $parent, $dom);
        } elseif ($token === "\\vmatrix") {
            self::_convert_and_append_command("\\vert", $parent, $dom);
        } elseif ($token === "\\Vmatrix") {
            self::_convert_and_append_command("\\Vert", $parent, $dom);
        } elseif (in_array($token, [Commands::FRAC, Commands::GENFRAC]) && $node->delimiter !== null && $node->delimiter[0] !== ".") {
            self::_convert_and_append_command($node->delimiter[0], parent: $parent, dom: $dom, attributes: ["stretchy" => "true", "symmetric" => "true"]);
        }
    }

    private static function _append_postfix_element(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        $token = $node->token;
        if (in_array($token, ["\\pmatrix", Commands::PMOD])) {
            self::_convert_and_append_command("\\rparen", $parent, $dom);
        } elseif (in_array($token, [Commands::BINOM, Commands::DBINOM, Commands::TBINOM])) {
            self::_convert_and_append_command("\\rparen", $parent, $dom, ["stretchy" => "true", "symmetric" => "true"]);
        } elseif ($token === "\\bmatrix") {
            self::_convert_and_append_command("\\rbrack", $parent, $dom);
        } elseif ($token === "\\Bmatrix") {
            self::_convert_and_append_command("\\rbrace", $parent, $dom);
        } elseif ($token === "\\vmatrix") {
            self::_convert_and_append_command("\\vert", $parent, $dom);
        } elseif ($token === "\\Vmatrix") {
            self::_convert_and_append_command("\\Vert", $parent, $dom);
        } elseif (in_array($token, [Commands::FRAC, Commands::GENFRAC]) && $node->delimiter !== null && $node->delimiter[1] !== ".") {
            self::_convert_and_append_command($node->delimiter[1], parent: $parent, dom: $dom, attributes: ["stretchy" => "true", "symmetric" => "true"]);
        } elseif ($token === Commands::SKEW && $node->attributes !== null) {
            $mspace = $dom->createElement("mspace");
            $mspace->setAttribute("width", "-" . $node->attributes["width"]);
            $parent->appendChild($mspace);
        }
    }

    private static function _convert_symbol(Node $node, DOMElement $parent, DOMDocument $dom, ?array $font = null): void
    {
        $token = $node->token;
        $attributes = $node->attributes ?? [];
        $symbol = SymbolsParser::convert_symbol($token);

        if (preg_match("/^\\d+(\\.\\d+)?$/", $token)) {
            $element = $dom->createElement("mn");
            $element->nodeValue = $token;
            foreach ($attributes as $k => $v) $element->setAttribute($k, $v);
            $parent->appendChild($element);
            self::_set_font($element, "mn", $font);
        } elseif (in_array($token, self::OPERATORS)) {
            $element = $dom->createElement("mo");
            $element->nodeValue = ($symbol === null) ? $token : mb_chr(hexdec($symbol), 'UTF-8');
            foreach ($attributes as $k => $v) $element->setAttribute($k, $v);
            if ($token === "\\|") $element->setAttribute("fence", "false");
            if ($token === "\\smallint") $element->setAttribute("largeop", "false");
            if (in_array($token, ["(", ")", "[", "]", "|", "\\|", "\\{", "\\}", "\\surd"])) {
                $element->setAttribute("stretchy", "false");
                self::_set_font($element, "fence", $font);
            } else {
                self::_set_font($element, "mo", $font);
            }
            $parent->appendChild($element);
        } elseif (($symbol && ( (hexdec($symbol) >= hexdec("2200") && hexdec($symbol) <= hexdec("22FF")) || (hexdec($symbol) >= hexdec("2190") && hexdec($symbol) <= hexdec("21FF")) )) || $symbol === ".") {
            $element = $dom->createElement("mo");
            $element->nodeValue = ($symbol === ".") ? "." : mb_chr(hexdec($symbol), 'UTF-8');
            foreach ($attributes as $k => $v) $element->setAttribute($k, $v);
            $parent->appendChild($element);
            self::_set_font($element, "mo", $font);
        } elseif (in_array($token, ["\\ ", "~", Commands::NOBREAKSPACE, Commands::SPACE])) {
            $element = $dom->createElement("mtext");
            $element->nodeValue = mb_chr(0x000A0, 'UTF-8');
            foreach ($attributes as $k => $v) $element->setAttribute($k, $v);
            $parent->appendChild($element);
            self::_set_font($element, "mtext", $font);
        } elseif ($token === Commands::NOT) {
            $mpadded = $dom->createElement("mpadded");
            $mpadded->setAttribute("width", "0");
            $parent->appendChild($mpadded);
            $mtext = $dom->createElement("mtext");
            $mtext->nodeValue = mb_chr(0x029F8, 'UTF-8');
            $mpadded->appendChild($mtext);
        } elseif (in_array($token, [Commands::DETERMINANT, Commands::GCD, Commands::INTOP, Commands::INJLIM, Commands::LIMINF, Commands::LIMSUP, Commands::PR, Commands::PROJLIM])) {
            $element = $dom->createElement("mo");
            $element->setAttribute("movablelimits", "true");
            foreach ($attributes as $k => $v) $element->setAttribute($k, $v);
            $texts = [
                Commands::INJLIM => "inj" . mb_chr(0x2006, 'UTF-8') . "lim",
                Commands::INTOP => mb_chr(0x222B, 'UTF-8'),
                Commands::LIMINF => "lim" . mb_chr(0x2006, 'UTF-8') . "inf",
                Commands::LIMSUP => "lim" . mb_chr(0x2006, 'UTF-8') . "sup",
                Commands::PROJLIM => "proj" . mb_chr(0x2006, 'UTF-8') . "lim",
            ];
            $element->nodeValue = $texts[$token] ?? substr($token, 1);
            $parent->appendChild($element);
            self::_set_font($element, "mo", $font);
        } elseif ($token === Commands::IDOTSINT) {
            $mrow = $dom->createElement("mrow");
            foreach ($attributes as $k => $v) $mrow->setAttribute($k, $v);
            $parent->appendChild($mrow);
            foreach ([mb_chr(0x222B, 'UTF-8'), mb_chr(0x22EF, 'UTF-8'), mb_chr(0x222B, 'UTF-8')] as $s) {
                $mo = $dom->createElement("mo");
                $mo->nodeValue = $s;
                $mrow->appendChild($mo);
            }
        } elseif (in_array($token, [Commands::LATEX, Commands::TEX])) {
            $mrow = $dom->createElement("mrow");
            foreach ($attributes as $k => $v) $mrow->setAttribute($k, $v);
            $parent->appendChild($mrow);
            if ($token === Commands::LATEX) {
                $mi_l = $dom->createElement("mi");
                $mi_l->nodeValue = "L";
                $mrow->appendChild($mi_l);
                $mspace = $dom->createElement("mspace");
                $mspace->setAttribute("width", "-.325em");
                $mrow->appendChild($mspace);
                $mpadded = $dom->createElement("mpadded");
                $mpadded->setAttribute("height", "+.21ex");
                $mpadded->setAttribute("depth", "-.21ex");
                $mpadded->setAttribute("voffset", "+.21ex");
                $mrow->appendChild($mpadded);
                $mstyle = $dom->createElement("mstyle");
                $mstyle->setAttribute("displaystyle", "false");
                $mstyle->setAttribute("scriptlevel", "1");
                $mpadded->appendChild($mstyle);
                $mrow_inner = $dom->createElement("mrow");
                $mstyle->appendChild($mrow_inner);
                $mi_a = $dom->createElement("mi");
                $mi_a->nodeValue = "A";
                $mrow_inner->appendChild($mi_a);
                $mspace2 = $dom->createElement("mspace");
                $mspace2->setAttribute("width", "-.17em");
                $mrow->appendChild($mspace2);
                self::_set_font($mi_l, "mi", $font);
                self::_set_font($mi_a, "mi", $font);
            }
            $mi_t = $dom->createElement("mi");
            $mi_t->nodeValue = "T";
            $mrow->appendChild($mi_t);
            $mspace3 = $dom->createElement("mspace");
            $mspace3->setAttribute("width", "-.14em");
            $mrow->appendChild($mspace3);
            $mpadded2 = $dom->createElement("mpadded");
            $mpadded2->setAttribute("height", "-.5ex");
            $mpadded2->setAttribute("depth", "+.5ex");
            $mpadded2->setAttribute("voffset", "-.5ex");
            $mrow->appendChild($mpadded2);
            $mrow_e = $dom->createElement("mrow");
            $mpadded2->appendChild($mrow_e);
            $mi_e = $dom->createElement("mi");
            $mi_e->nodeValue = "E";
            $mrow_e->appendChild($mi_e);
            $mspace4 = $dom->createElement("mspace");
            $mspace4->setAttribute("width", "-.115em");
            $mrow->appendChild($mspace4);
            $mi_x = $dom->createElement("mi");
            $mi_x->nodeValue = "X";
            $mrow->appendChild($mi_x);
            self::_set_font($mi_t, "mi", $font);
            self::_set_font($mi_e, "mi", $font);
            self::_set_font($mi_x, "mi", $font);
        } elseif ($token === "") {
            $element = $dom->createElement("mspace");
            $element->setAttribute("width", "0em");
            $parent->appendChild($element);
        } elseif (str_starts_with($token, Commands::OPERATORNAME)) {
            $name = substr($token, 14, -1);
            $element = $dom->createElement("mo");
            $element->textContent = $name;
            foreach ($attributes as $k => $v) $element->setAttribute($k, $v);
            $parent->appendChild($element);
        } elseif (str_starts_with($token, Commands::BACKSLASH)) {
            $element = $dom->createElement("mi");
            foreach ($attributes as $k => $v) $element->setAttribute($k, $v);
            if ($symbol) {
                $element->nodeValue = mb_chr(hexdec($symbol), 'UTF-8');
            } elseif (in_array($token, Commands::FUNCTIONS)) {
                $element->nodeValue = substr($token, 1);
            } else {
                $element->nodeValue = $token;
            }
            $parent->appendChild($element);
            self::_set_font($element, "mi", $font);
        } else {
            $element = $dom->createElement("mi");
            $element->textContent = $token;
            foreach ($attributes as $k => $v) $element->setAttribute($k, $v);
            $parent->appendChild($element);
            self::_set_font($element, "mi", $font);
        }
    }

    private static function _set_font(DOMElement $element, string $key, ?array $font): void
    {
        if ($font === null) return;
        $font_val = Commands::get_font($font, $key);
        if ($font_val !== null) {
            $element->setAttribute("mathvariant", $font_val);
        }
    }
}
