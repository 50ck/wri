<?php

namespace Latex2MathML;

use DOMDocument;
use DOMElement;

/**
 * Converts LaTeX to OMML (Office Math Markup Language) for Microsoft Word
 */
class OmmlConverter
{
    private const OMML_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/math';

    /**
     * Converts a LaTeX string to OMML string.
     *
     * @param string $latex The LaTeX expression to convert.
     * @param string $display The display mode: "inline" or "block".
     * @return string The resulting OMML string.
     */
    public static function convert(string $latex, string $display = "inline"): string
    {
        $latex = self::preprocess($latex);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $nodes = Walker::walk($latex, $display);

        if ($display === "block") {
            // Block equations use oMathPara wrapper
            $oMathPara = $dom->createElementNS(self::OMML_NS, 'm:oMathPara');
            $dom->appendChild($oMathPara);
            $oMath = $dom->createElementNS(self::OMML_NS, 'm:oMath');
            $oMathPara->appendChild($oMath);
            self::convertGroup($nodes, $oMath, $dom);
        } else {
            // Inline equations use oMath directly
            $oMath = $dom->createElementNS(self::OMML_NS, 'm:oMath');
            $dom->appendChild($oMath);
            self::convertGroup($nodes, $oMath, $dom);
        }

        return $dom->saveXML($dom->documentElement);
    }

    private static function preprocess(string $latex): string
    {
        $latex = preg_replace_callback('/\\\\specialChar\s*\{(\d+)\}/', function ($matches) {
            return mb_chr((int)$matches[1], 'UTF-8');
        }, $latex);

        $latex = html_entity_decode($latex, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return html_entity_decode($latex, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Group delimiters into pairs for proper OMML rendering
     * Finds \left...\right, \bigg...\bigg, etc. and groups them
     */
    private static function groupDelimiters(array $nodes): array
    {
        $result = [];
        $i = 0;
        $length = count($nodes);

        while ($i < $length) {
            $node = $nodes[$i];

            // Check if this is a delimiter opener
            $isLeftDelim = $node->token === Commands::LEFT ||
                          isset(Commands::$BIG[$node->token]) ||
                          isset(Commands::$BIG_OPEN_CLOSE[$node->token]);

            if ($isLeftDelim && self::isOpeningDelimiter($node)) {
                // Look for the matching closing delimiter
                $delimGroup = ['left' => $node, 'content' => [], 'right' => null];
                $i++;
                $depth = 1;

                while ($i < $length && $depth > 0) {
                    $currentNode = $nodes[$i];

                    // Check for closing delimiter
                    if ($currentNode->token === Commands::RIGHT ||
                        (isset(Commands::$BIG[$currentNode->token]) && self::isClosingDelimiter($currentNode))) {
                        $depth--;
                        if ($depth === 0) {
                            $delimGroup['right'] = $currentNode;
                            break;
                        }
                    }

                    // Check for nested opening delimiter
                    if ($currentNode->token === Commands::LEFT ||
                        isset(Commands::$BIG[$currentNode->token])) {
                        if (self::isOpeningDelimiter($currentNode)) {
                            $depth++;
                        }
                    }

                    $delimGroup['content'][] = $currentNode;
                    $i++;
                }

                // Create a special delimiter node
                $delimNode = new Node(
                    token: '__DELIMITER_GROUP__',
                    children: $delimGroup['content'],
                    delimiter: self::getDelimiterChars($delimGroup['left'], $delimGroup['right'])
                );
                $result[] = $delimNode;
            } else {
                $result[] = $node;
            }

            $i++;
        }

        return $result;
    }

    /**
     * Check if a delimiter node is an opening delimiter
     */
    private static function isOpeningDelimiter(Node $node): bool
    {
        if ($node->delimiter !== null) {
            return in_array($node->delimiter, ['(', '[', '{', '\\{', '\\lbrace', '|', '\\|', '\\langle']);
        }
        if ($node->text !== null) {
            return in_array($node->text, ['(', '[', '{', '|']);
        }
        return false;
    }

    /**
     * Check if a delimiter node is a closing delimiter
     */
    private static function isClosingDelimiter(Node $node): bool
    {
        if ($node->delimiter !== null) {
            return in_array($node->delimiter, [')', ']', '}', '\\}', '\\rbrace', '|', '\\|', '\\rangle']);
        }
        if ($node->text !== null) {
            return in_array($node->text, [')', ']', '}', '|']);
        }
        return false;
    }

    /**
     * Extract delimiter characters from left and right nodes
     */
    private static function getDelimiterChars(?Node $left, ?Node $right): ?string
    {
        $leftChar = '';
        $rightChar = '';

        if ($left !== null) {
            if ($left->delimiter !== null && $left->delimiter !== '.') {
                $symbol = SymbolsParser::convert_symbol($left->delimiter);
                $leftChar = ($symbol === null) ? $left->delimiter : mb_chr(hexdec($symbol), 'UTF-8');
            } elseif ($left->text !== null) {
                $symbol = SymbolsParser::convert_symbol($left->text);
                $leftChar = ($symbol === null) ? $left->text : mb_chr(hexdec($symbol), 'UTF-8');
            }
        }

        if ($right !== null) {
            if ($right->delimiter !== null && $right->delimiter !== '.') {
                $symbol = SymbolsParser::convert_symbol($right->delimiter);
                $rightChar = ($symbol === null) ? $right->delimiter : mb_chr(hexdec($symbol), 'UTF-8');
            } elseif ($right->text !== null) {
                $symbol = SymbolsParser::convert_symbol($right->text);
                $rightChar = ($symbol === null) ? $right->text : mb_chr(hexdec($symbol), 'UTF-8');
            }
        }

        return $leftChar . '|' . $rightChar;
    }

    /**
     * Convert a group of nodes to OMML
     */
    private static function convertGroup(array $nodes, DOMElement $parent, DOMDocument $dom): void
    {
        // First pass: find delimiter pairs (\left...\right, \bigg...\bigg, etc.)
        $nodes = self::groupDelimiters($nodes);

        $iterator = new \ArrayIterator($nodes);

        while ($iterator->valid()) {
            $node = $iterator->current();
            $token = $node->token;

            // Handle delimiter groups
            if ($token === '__DELIMITER_GROUP__') {
                self::createDelimiterGroup($node, $parent, $dom);
            }
            // Handle subscript/superscript
            elseif ($token === Commands::SUBSUP && $node->children !== null && count($node->children) >= 3) {
                $base = $node->children[0];
                if ($base->token === "" && empty($base->children)) {
                    // Empty base - just render sub and sup separately
                    self::convertGroup([$node->children[1]], $parent, $dom);
                    self::convertGroup([$node->children[2]], $parent, $dom);
                } else {
                    self::createSubSup($node, $parent, $dom);
                }
            } elseif ($token === Commands::SUBSCRIPT && $node->children !== null && count($node->children) >= 2) {
                $base = $node->children[0];
                if ($base->token === "" && empty($base->children)) {
                    self::convertGroup([$node->children[1]], $parent, $dom);
                } else {
                    self::createSubscript($node, $parent, $dom);
                }
            } elseif ($token === Commands::SUPERSCRIPT && $node->children !== null && count($node->children) >= 2) {
                $base = $node->children[0];
                if ($base->token === "" && empty($base->children)) {
                    self::convertGroup([$node->children[1]], $parent, $dom);
                } else {
                    self::createSuperscript($node, $parent, $dom);
                }
            } elseif (isset(Commands::$CONVERSION_MAP[$token])) {
                self::convertCommand($node, $parent, $dom);
            } elseif ($node->children !== null) {
                self::convertGroup($node->children, $parent, $dom);
            } else {
                self::convertSymbol($node, $parent, $dom);
            }

            $iterator->next();
        }
    }

    /**
     * Convert a command node to OMML
     */
    private static function convertCommand(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        $command = $node->token;

        // Fractions
        if (in_array($command, [Commands::FRAC, Commands::DFRAC, Commands::TFRAC, Commands::CFRAC])) {
            self::createFraction($node, $parent, $dom);
        }
        // Square root
        elseif ($command === Commands::SQRT) {
            self::createRadical($node, $parent, $dom);
        }
        // \left and \right delimiters
        elseif ($command === Commands::LEFT) {
            self::handleLeftDelimiter($node, $parent, $dom);
        }
        // \bigg, \Big, etc.
        elseif (isset(Commands::$BIG[$command]) || isset(Commands::$BIG_OPEN_CLOSE[$command])) {
            self::createDelimiter($node, $parent, $dom);
        }
        // Matrices
        elseif (in_array($command, Commands::MATRICES)) {
            self::createMatrix($node, $parent, $dom, $command);
        }
        // Default: convert children
        elseif ($node->children !== null) {
            self::convertGroup($node->children, $parent, $dom);
        }
    }

    /**
     * Create OMML fraction (m:f)
     */
    private static function createFraction(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        if ($node->children === null || count($node->children) < 2) return;

        $f = $dom->createElementNS(self::OMML_NS, 'm:f');
        $parent->appendChild($f);

        // Numerator
        $num = $dom->createElementNS(self::OMML_NS, 'm:num');
        $f->appendChild($num);
        self::convertGroup([$node->children[0]], $num, $dom);

        // Denominator
        $den = $dom->createElementNS(self::OMML_NS, 'm:den');
        $f->appendChild($den);
        self::convertGroup([$node->children[1]], $den, $dom);
    }

    /**
     * Create OMML subscript (m:sSub)
     */
    private static function createSubscript(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        if ($node->children === null || count($node->children) < 2) return;

        $sSub = $dom->createElementNS(self::OMML_NS, 'm:sSub');
        $parent->appendChild($sSub);

        // Base
        $e = $dom->createElementNS(self::OMML_NS, 'm:e');
        $sSub->appendChild($e);
        self::convertGroup([$node->children[0]], $e, $dom);

        // Subscript
        $sub = $dom->createElementNS(self::OMML_NS, 'm:sub');
        $sSub->appendChild($sub);
        self::convertGroup([$node->children[1]], $sub, $dom);
    }

    /**
     * Create OMML superscript (m:sSup)
     */
    private static function createSuperscript(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        if ($node->children === null || count($node->children) < 2) return;

        $sSup = $dom->createElementNS(self::OMML_NS, 'm:sSup');
        $parent->appendChild($sSup);

        // Base
        $e = $dom->createElementNS(self::OMML_NS, 'm:e');
        $sSup->appendChild($e);
        self::convertGroup([$node->children[0]], $e, $dom);

        // Superscript
        $sup = $dom->createElementNS(self::OMML_NS, 'm:sup');
        $sSup->appendChild($sup);
        self::convertGroup([$node->children[1]], $sup, $dom);
    }

    /**
     * Create OMML subscript-superscript (m:sSubSup)
     */
    private static function createSubSup(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        if ($node->children === null || count($node->children) < 3) return;

        $sSubSup = $dom->createElementNS(self::OMML_NS, 'm:sSubSup');
        $parent->appendChild($sSubSup);

        // Base
        $e = $dom->createElementNS(self::OMML_NS, 'm:e');
        $sSubSup->appendChild($e);
        self::convertGroup([$node->children[0]], $e, $dom);

        // Subscript
        $sub = $dom->createElementNS(self::OMML_NS, 'm:sub');
        $sSubSup->appendChild($sub);
        self::convertGroup([$node->children[1]], $sub, $dom);

        // Superscript
        $sup = $dom->createElementNS(self::OMML_NS, 'm:sup');
        $sSubSup->appendChild($sup);
        self::convertGroup([$node->children[2]], $sup, $dom);
    }

    /**
     * Create OMML radical/square root (m:rad)
     */
    private static function createRadical(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        if ($node->children === null) return;

        $rad = $dom->createElementNS(self::OMML_NS, 'm:rad');
        $parent->appendChild($rad);

        // Properties - hide degree for square root
        $radPr = $dom->createElementNS(self::OMML_NS, 'm:radPr');
        $rad->appendChild($radPr);
        $degHide = $dom->createElementNS(self::OMML_NS, 'm:degHide');
        $degHide->setAttribute('m:val', 'on');
        $radPr->appendChild($degHide);

        // Degree (empty for square root)
        $deg = $dom->createElementNS(self::OMML_NS, 'm:deg');
        $rad->appendChild($deg);

        // Base
        $e = $dom->createElementNS(self::OMML_NS, 'm:e');
        $rad->appendChild($e);
        self::convertGroup($node->children, $e, $dom);
    }

    /**
     * Handle \left delimiter and find matching \right
     */
    private static function handleLeftDelimiter(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        // For now, just render the delimiter and children
        // A full implementation would need to match \left with \right
        if ($node->delimiter !== null && $node->delimiter !== ".") {
            self::createText($node->delimiter, $parent, $dom);
        }
        if ($node->children !== null) {
            self::convertGroup($node->children, $parent, $dom);
        }
    }

    /**
     * Create OMML delimiter (m:d) for \bigg, \left, etc.
     */
    private static function createDelimiter(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        // For \bigg[, \Big(, etc., create a stretchy delimiter
        if ($node->text !== null) {
            $symbol = SymbolsParser::convert_symbol($node->text);
            $text = ($symbol === null) ? $node->text : mb_chr(hexdec($symbol), 'UTF-8');
            self::createText($text, $parent, $dom);
        }
    }

    /**
     * Create OMML matrix (m:m)
     */
    private static function createMatrix(Node $node, DOMElement $parent, DOMDocument $dom, string $command): void
    {
        if ($node->children === null) return;

        // Group nodes into rows
        $rows = [];
        $currentRow = [];

        foreach ($node->children as $child) {
            if ($child->token === "&") {
                // Column separator - continue current row
                continue;
            } elseif ($child->token === Commands::DOUBLEBACKSLASH || $child->token === '\\\\') {
                // Row separator
                if (!empty($currentRow)) {
                    $rows[] = $currentRow;
                    $currentRow = [];
                }
            } else {
                $currentRow[] = $child;
            }
        }
        if (!empty($currentRow)) {
            $rows[] = $currentRow;
        }

        // Create matrix element
        $m = $dom->createElementNS(self::OMML_NS, 'm:m');
        $parent->appendChild($m);

        foreach ($rows as $rowNodes) {
            $mr = $dom->createElementNS(self::OMML_NS, 'm:mr');
            $m->appendChild($mr);

            // For now, treat the whole row as a single cell
            $e = $dom->createElementNS(self::OMML_NS, 'm:e');
            $mr->appendChild($e);
            self::convertGroup($rowNodes, $e, $dom);
        }
    }

    /**
     * Convert a symbol/token to OMML
     */
    private static function convertSymbol(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        $token = $node->token;
        $symbol = SymbolsParser::convert_symbol($token);

        // Convert symbol to character
        if ($symbol !== null) {
            $char = mb_chr(hexdec($symbol), 'UTF-8');
        } else {
            $char = $token;
        }

        // Remove backslashes from LaTeX commands for display
        if (str_starts_with($char, '\\') && in_array($token, Commands::FUNCTIONS)) {
            $char = substr($token, 1);
        }

        self::createText($char, $parent, $dom);
    }

    /**
     * Create OMML text run (m:r with m:t)
     */
    private static function createText(string $text, DOMElement $parent, DOMDocument $dom): void
    {
        if (empty($text)) return;

        $r = $dom->createElementNS(self::OMML_NS, 'm:r');
        $parent->appendChild($r);

        $t = $dom->createElementNS(self::OMML_NS, 'm:t');
        $t->nodeValue = $text;
        $r->appendChild($t);
    }

    /**
     * Create OMML delimiter group (m:d) - stretchy delimiters
     */
    private static function createDelimiterGroup(Node $node, DOMElement $parent, DOMDocument $dom): void
    {
        if ($node->delimiter === null) {
            // No delimiter info, just render content
            if ($node->children !== null) {
                self::convertGroup($node->children, $parent, $dom);
            }
            return;
        }

        // Parse delimiter characters
        [$leftChar, $rightChar] = explode('|', $node->delimiter);

        // Create delimiter element
        $d = $dom->createElementNS(self::OMML_NS, 'm:d');
        $parent->appendChild($d);

        // Delimiter properties
        $dPr = $dom->createElementNS(self::OMML_NS, 'm:dPr');
        $d->appendChild($dPr);

        // Set begin character
        if (!empty($leftChar)) {
            $begChr = $dom->createElementNS(self::OMML_NS, 'm:begChr');
            $begChr->setAttribute('m:val', $leftChar);
            $dPr->appendChild($begChr);
        }

        // Set end character
        if (!empty($rightChar)) {
            $endChr = $dom->createElementNS(self::OMML_NS, 'm:endChr');
            $endChr->setAttribute('m:val', $rightChar);
            $dPr->appendChild($endChr);
        }

        // Enable growing/stretchy behavior
        $grow = $dom->createElementNS(self::OMML_NS, 'm:grow');
        $grow->setAttribute('m:val', '1');
        $dPr->appendChild($grow);

        // Content
        $e = $dom->createElementNS(self::OMML_NS, 'm:e');
        $d->appendChild($e);

        if ($node->children !== null) {
            self::convertGroup($node->children, $e, $dom);
        }
    }
}
