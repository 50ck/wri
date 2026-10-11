<?php

namespace Latex2MathML;

use Latex2MathML\Exceptions\DenominatorNotFoundError;
use Latex2MathML\Exceptions\DoubleSubscriptsError;
use Latex2MathML\Exceptions\DoubleSuperscriptsError;
use Latex2MathML\Exceptions\ExtraLeftOrMissingRightError;
use Latex2MathML\Exceptions\InvalidAlignmentError;
use Latex2MathML\Exceptions\InvalidStyleForGenfracError;
use Latex2MathML\Exceptions\InvalidWidthError;
use Latex2MathML\Exceptions\LimitsMustFollowMathOperatorError;
use Latex2MathML\Exceptions\MissingEndError;
use Latex2MathML\Exceptions\MissingSuperScriptOrSubscriptError;
use Latex2MathML\Exceptions\NoAvailableTokensError;
use Latex2MathML\Exceptions\NumeratorNotFoundError;

class Walker
{
    public static function walk(string $data, string $display = "inline"): array
    {
        $tokens = Tokenizer::tokenize($data);
        $block = $display === "block";
        $iterator = new \ArrayIterator($tokens);
        return self::_walk($iterator, null, 0, $block);
    }

    private static function _walk(\Iterator $tokens, ?string $terminator = null, int $limit = 0, bool $block = false): array
    {
        $group = [];
        $has_available_tokens = false;

        while ($tokens->valid()) {
            $token = $tokens->current();
            $tokens->next();
            $has_available_tokens = true;

            if ($token === $terminator) {
                $delimiter = null;
                if ($terminator === Commands::RIGHT) {
                    if ($tokens->valid()) {
                        $delimiter = $tokens->current();
                        $tokens->next();
                    }
                }
                $group[] = new Node(token: $token, delimiter: $delimiter);
                break;
            } elseif (($token === Commands::RIGHT && $token !== $terminator) || ($token === Commands::MIDDLE && $terminator !== Commands::RIGHT)) {
                throw new ExtraLeftOrMissingRightError();
            } elseif ($token === Commands::LEFT) {
                $delimiter = null;
                if ($tokens->valid()) {
                    $delimiter = $tokens->current();
                    $tokens->next();
                }
                $children = self::_walk($tokens, Commands::RIGHT);
                if (empty($children) || end($children)->token !== Commands::RIGHT) {
                    throw new ExtraLeftOrMissingRightError();
                }
                $node = new Node(token: $token, children: $children, delimiter: $delimiter);
            } elseif ($token === Commands::OPENING_BRACE) {
                $children = self::_walk($tokens, Commands::CLOSING_BRACE);
                if (!empty($children) && end($children)->token === Commands::CLOSING_BRACE) {
                    array_pop($children);
                }
                $node = new Node(token: Commands::BRACES, children: $children);
            } elseif ($token === Commands::SUBSCRIPT || $token === Commands::SUPERSCRIPT) {
                $previous = !empty($group) ? array_pop($group) : new Node(token: "");

                if ($token === Commands::SUBSCRIPT && $previous->token === Commands::SUBSCRIPT) {
                    throw new DoubleSubscriptsError();
                }
                if ($token === Commands::SUPERSCRIPT && $previous->token === Commands::SUPERSCRIPT && $previous->children !== null && count($previous->children) >= 2 && $previous->children[1]->token !== Commands::PRIME) {
                    throw new DoubleSuperscriptsError();
                }

                $modifier = null;
                if ($previous->token === Commands::LIMITS) {
                    $modifier = Commands::LIMITS;
                    if (empty($group)) {
                        throw new LimitsMustFollowMathOperatorError();
                    }
                    $previous = array_pop($group);
                    if (!str_starts_with($previous->token, "\\")) {
                        throw new LimitsMustFollowMathOperatorError();
                    }
                } elseif ($block && ($previous->token === Commands::SUMMATION || $previous->token === Commands::PRODUCT)) {
                    $modifier = Commands::LIMITS;
                }

                if ($token === Commands::SUBSCRIPT && $previous->token === Commands::SUPERSCRIPT && $previous->children !== null) {
                    $children = self::_walk($tokens, $terminator, 1);
                    $node = new Node(
                        token: Commands::SUBSUP,
                        children: [$previous->children[0], ...$children, $previous->children[1]],
                        modifier: $previous->modifier
                    );
                } elseif ($token === Commands::SUPERSCRIPT && $previous->token === Commands::SUBSCRIPT && $previous->children !== null) {
                    $children = self::_walk($tokens, $terminator, 1);
                    $node = new Node(token: Commands::SUBSUP, children: [...$previous->children, ...$children], modifier: $previous->modifier);
                } elseif ($token === Commands::SUPERSCRIPT && $previous->token === Commands::SUPERSCRIPT && $previous->children !== null && $previous->children[1]->token === Commands::PRIME) {
                    $children = self::_walk($tokens, $terminator, 1);
                    $node = new Node(
                        token: Commands::SUPERSCRIPT,
                        children: [
                            $previous->children[0],
                            new Node(token: Commands::BRACES, children: [$previous->children[1], ...$children])
                        ],
                        modifier: $previous->modifier
                    );
                } else {
                    try {
                        $children = self::_walk($tokens, $terminator, 1);
                    } catch (NoAvailableTokensError) {
                        throw new MissingSuperScriptOrSubscriptError();
                    }
                    if ($previous->token === Commands::OVERBRACE || $previous->token === Commands::UNDERBRACE) {
                        $modifier = $previous->token;
                    }
                    $node = new Node(token: $token, children: [$previous, ...$children], modifier: $modifier);
                }
            } elseif ($token === Commands::APOSTROPHE) {
                $previous = !empty($group) ? array_pop($group) : new Node(token: "");

                if ($previous->token === Commands::SUPERSCRIPT && $previous->children !== null && count($previous->children) >= 2 && $previous->children[1]->token !== Commands::PRIME) {
                    throw new DoubleSuperscriptsError();
                }

                if ($previous->token === Commands::SUPERSCRIPT && $previous->children !== null && count($previous->children) >= 2 && $previous->children[1]->token === Commands::PRIME) {
                    $node = new Node(token: Commands::SUPERSCRIPT, children: [$previous->children[0], new Node(token: Commands::DPRIME)]);
                } elseif ($previous->token === Commands::SUBSCRIPT && $previous->children !== null) {
                    $node = new Node(
                        token: Commands::SUBSUP,
                        children: [...$previous->children, new Node(token: Commands::PRIME)],
                        modifier: $previous->modifier
                    );
                } else {
                    $node = new Node(token: Commands::SUPERSCRIPT, children: [$previous, new Node(token: Commands::PRIME)]);
                }
            } elseif (in_array($token, Commands::COMMANDS_WITH_TWO_PARAMETERS)) {
                $children = self::_walk($tokens, $terminator, 2);
                if ($token === Commands::OVERSET || $token === Commands::UNDERSET) {
                    $children = array_reverse($children);
                }
                $node = new Node(token: $token, children: $children);
            } elseif (in_array($token, Commands::COMMANDS_WITH_ONE_PARAMETER) || str_starts_with($token, Commands::MATH)) {
                $children = self::_walk($tokens, $terminator, 1);
                $node = new Node(token: $token, children: $children);
            } elseif ($token === Commands::NOT) {
                try {
                    $next_nodes = self::_walk($tokens, $terminator, 1);
                    if (!empty($next_nodes)) {
                        $next_node = $next_nodes[0];
                        if (str_starts_with($next_node->token, "\\")) {
                            $negated_symbol = "\\n" . substr($next_node->token, 1);
                            $symbol = SymbolsParser::convert_symbol($negated_symbol);
                            if ($symbol) {
                                $group[] = new Node(token: $negated_symbol);
                                continue;
                            }
                        }
                        $group[] = new Node(token: $token);
                        $group[] = $next_node;
                        continue;
                    }
                } catch (NoAvailableTokensError) {}
                $node = new Node(token: $token);
            } elseif ($token === Commands::XLEFTARROW || $token === Commands::XRIGHTARROW) {
                $children = self::_walk($tokens, $terminator, 1);
                if (!empty($children) && $children[0]->token === Commands::OPENING_BRACKET) {
                    $braces_children = self::_walk($tokens, Commands::CLOSING_BRACKET);
                    if (!empty($braces_children)) {
                        array_pop($braces_children);
                    }
                    $children = [
                        new Node(token: Commands::BRACES, children: $braces_children),
                        ...self::_walk($tokens, $terminator, 1)
                    ];
                }
                $node = new Node(token: $token, children: $children);
            } elseif (in_array($token, [Commands::HSKIP, Commands::HSPACE, Commands::KERN, Commands::MKERN, Commands::MSKIP, Commands::MSPACE])) {
                $children = self::_walk($tokens, $terminator, 1);
                $attr_children = $children;
                if (!empty($children) && $children[0]->token === Commands::BRACES && $children[0]->children !== null) {
                    $attr_children = $children[0]->children;
                }
                $node = new Node(token: $token, attributes: ["width" => $attr_children[0]->token]);
            } elseif ($token === Commands::COLOR) {
                if ($tokens->valid()) {
                    $color = $tokens->current();
                    $tokens->next();
                    $attributes = ["mathcolor" => $color];
                    $children = self::_walk($tokens, $terminator);
                    $sibling = null;
                    if (!empty($children) && end($children)->token === $terminator) {
                        $sibling = array_pop($children);
                    }
                    $group[] = new Node(token: $token, children: $children, attributes: $attributes);
                    if ($sibling) {
                        $group[] = $sibling;
                    }
                    break;
                }
            } elseif ($token === Commands::LABEL || $token === Commands::TAG) {
                self::_walk($tokens, $terminator, 1);
                continue;
            } elseif ($token === Commands::NOTAG || $token === Commands::NONUMBER) {
                continue;
            } elseif ($token === Commands::STYLE) {
                if ($tokens->valid()) {
                    $style = $tokens->current();
                    $tokens->next();
                    $next_nodes = self::_walk($tokens, $terminator, 1);
                    if (!empty($next_nodes)) {
                        $node = $next_nodes[0]->with(['attributes' => ['style' => $style]]);
                    } else {
                        $node = new Node(token: $token);
                    }
                }
            } elseif (in_array($token, array_merge(array_keys(Commands::$BIG), array_keys(Commands::$BIG_OPEN_CLOSE), [Commands::FBOX, Commands::HBOX, Commands::MBOX, Commands::MIDDLE, Commands::TEXT, Commands::TEXTBF, Commands::TEXTIT, Commands::TEXTRM, Commands::TEXTSF, Commands::TEXTTT]))) {
                if ($tokens->valid()) {
                    $text = $tokens->current();
                    $tokens->next();
                    $node = new Node(token: $token, text: $text);
                } else {
                    $node = new Node(token: $token);
                }
            } elseif ($token === Commands::HREF) {
                if ($tokens->valid()) {
                    $href = $tokens->current();
                    $tokens->next();
                    $children = self::_walk($tokens, $terminator, 1);
                    $node = new Node(token: $token, children: $children, attributes: ["href" => $href]);
                }
            } elseif (in_array($token, [Commands::ABOVE, Commands::ATOP, Commands::ABOVEWITHDELIMS, Commands::ATOPWITHDELIMS, Commands::BRACE, Commands::BRACK, Commands::CHOOSE, Commands::OVER])) {
                $attributes = null;
                $delimiter = null;

                if ($token === Commands::ABOVEWITHDELIMS) {
                    $d1 = ltrim($tokens->current(), "\\"); $tokens->next();
                    $d2 = ltrim($tokens->current(), "\\"); $tokens->next();
                    $delimiter = $d1 . $d2;
                } elseif ($token === Commands::ATOPWITHDELIMS) {
                    $attributes = ["linethickness" => "0"];
                    $d1 = ltrim($tokens->current(), "\\"); $tokens->next();
                    $d2 = ltrim($tokens->current(), "\\"); $tokens->next();
                    $delimiter = $d1 . $d2;
                } elseif ($token === Commands::BRACE) {
                    $delimiter = "{}";
                } elseif ($token === Commands::BRACK) {
                    $delimiter = "[]";
                } elseif ($token === Commands::CHOOSE) {
                    $delimiter = "()";
                }

                if ($token === Commands::ABOVE || $token === Commands::ABOVEWITHDELIMS) {
                    $dim_nodes = self::_walk($tokens, $terminator, 1);
                    $dimension = self::_get_dimension($dim_nodes[0]);
                    $attributes = ["linethickness" => $dimension];
                } elseif (in_array($token, [Commands::ATOP, Commands::BRACE, Commands::BRACK, Commands::CHOOSE])) {
                    $attributes = ["linethickness" => "0"];
                }

                $denominator = self::_walk($tokens, $terminator);
                $sibling = null;
                if (!empty($denominator) && end($denominator)->token === $terminator) {
                    $sibling = array_pop($denominator);
                }

                if (empty($denominator)) {
                    if ($token === Commands::BRACE || $token === Commands::BRACK) {
                        $denominator = [new Node(token: Commands::BRACES, children: [])];
                    } else {
                        throw new DenominatorNotFoundError();
                    }
                }
                if (empty($group)) {
                    if ($token === Commands::BRACE || $token === Commands::BRACK) {
                        $group = [new Node(token: Commands::BRACES, children: [])];
                    } else {
                        throw new NumeratorNotFoundError();
                    }
                }
                
                if (count($denominator) > 1) {
                    $denominator = [new Node(token: Commands::BRACES, children: $denominator)];
                }

                if (count($group) === 1) {
                    $children = [$group[0], ...$denominator];
                } else {
                    $children = [new Node(token: Commands::BRACES, children: $group), ...$denominator];
                }
                $group = [new Node(token: Commands::FRAC, children: $children, attributes: $attributes, delimiter: $delimiter)];
                if ($sibling !== null) {
                    $group[] = $sibling;
                }
                break;
            } elseif ($token === Commands::SQRT) {
                $root_nodes = null;
                $next_nodes = self::_walk($tokens, null, 1);
                $next_node = $next_nodes[0];
                if ($next_node->token === Commands::OPENING_BRACKET) {
                    $root_nodes = self::_walk($tokens, Commands::CLOSING_BRACKET);
                    if (!empty($root_nodes)) {
                        array_pop($root_nodes);
                    }
                    $next_nodes = self::_walk($tokens, null, 1);
                    $next_node = $next_nodes[0];
                    if (count($root_nodes) > 1) {
                        $root_nodes = [new Node(token: Commands::BRACES, children: $root_nodes)];
                    }
                }

                if ($root_nodes) {
                    $node = new Node(token: Commands::ROOT, children: [$next_node, ...$root_nodes]);
                } else {
                    $node = new Node(token: $token, children: [$next_node]);
                }
            } elseif ($token === Commands::ROOT) {
                $root_nodes = self::_walk($tokens, "\\of");
                if (!empty($root_nodes)) {
                    array_pop($root_nodes);
                }
                $next_nodes = self::_walk($tokens, null, 1);
                $next_node = $next_nodes[0];
                if (count($root_nodes) > 1) {
                    $root_nodes = [new Node(token: Commands::BRACES, children: $root_nodes)];
                }
                if (!empty($root_nodes)) {
                    $node = new Node(token: $token, children: [$next_node, ...$root_nodes]);
                } else {
                    $node = new Node(token: $token, children: [$next_node, new Node(token: Commands::BRACES, children: [])]);
                }
            } elseif (in_array($token, Commands::MATRICES)) {
                $children = self::_walk($tokens, $terminator);
                $sibling = null;
                if (!empty($children) && end($children)->token === $terminator) {
                    $sibling = array_pop($children);
                }
                if (count($children) === 1 && $children[0]->token === Commands::BRACES && $children[0]->children !== null) {
                    $children = $children[0]->children;
                }
                if ($sibling !== null) {
                    $group[] = new Node(token: $token, children: $children, alignment: "");
                    $group[] = $sibling;
                    break;
                } else {
                    $node = new Node(token: $token, children: $children, alignment: "");
                }
            } elseif ($token === Commands::GENFRAC) {
                $d1 = ltrim($tokens->current(), "\\"); $tokens->next();
                $d2 = ltrim($tokens->current(), "\\"); $tokens->next();
                $delimiter = $d1 . $d2;
                $nodes = self::_walk($tokens, $terminator, 2);
                $dimension = self::_get_dimension($nodes[0]);
                $style = self::_get_style($nodes[1]);
                $attributes = ["linethickness" => $dimension];
                $children = self::_walk($tokens, $terminator, 2);
                $group[] = new Node(token: $style);
                $group[] = new Node(token: $token, children: $children, delimiter: $delimiter, attributes: $attributes);
                break;
            } elseif ($token === Commands::SIDESET) {
                $nodes = self::_walk($tokens, $terminator, 3);
                $left = $nodes[0]; $right = $nodes[1]; $operator = $nodes[2];
                [$left_token, $left_children] = self::_make_subsup($left);
                [$right_token, $right_children] = self::_make_subsup($right);
                $attributes = ["movablelimits" => "false"];
                $node = new Node(
                    token: $token,
                    children: [
                        new Node(
                            token: $left_token,
                            children: [
                                new Node(
                                    token: Commands::VPHANTOM,
                                    children: [
                                        new Node(token: $operator->token, children: $operator->children, attributes: $attributes)
                                    ]
                                ),
                                ...$left_children
                            ]
                        ),
                        new Node(
                            token: $right_token,
                            children: [
                                new Node(token: $operator->token, children: $operator->children, attributes: $attributes),
                                ...$right_children
                            ]
                        )
                    ]
                );
            } elseif ($token === Commands::SKEW) {
                $nodes = self::_walk($tokens, $terminator, 2);
                $width_node = $nodes[0]; $child = $nodes[1];
                $width = $width_node->token;
                if ($width === Commands::BRACES) {
                    if ($width_node->children === null || empty($width_node->children)) {
                        throw new InvalidWidthError();
                    }
                    $width = $width_node->children[0]->token;
                }
                if (!is_numeric($width)) {
                    throw new InvalidWidthError();
                }
                $node = new Node(token: $token, children: [$child], attributes: ["width" => sprintf("%.3fem", 0.0555 * (int)$width)]);
            } elseif (str_starts_with($token, Commands::BEGIN)) {
                $node = self::_get_environment_node($token, $tokens, $terminator);
            } else {
                $node = new Node(token: $token);
            }

            $group[] = $node;
            if ($limit > 0 && count($group) >= $limit) {
                break;
            }
        }

        if (!$has_available_tokens) {
            throw new NoAvailableTokensError();
        }
        return $group;
    }

    private static function _make_subsup(Node $node): array
    {
        if ($node->token !== Commands::BRACES) {
            return ["", []];
        }
        try {
            if ($node->children !== null && !empty($node->children) && count($node->children[0]->children) >= 2 && count($node->children[0]->children) <= 3 && in_array($node->children[0]->token, [Commands::SUBSUP, Commands::SUBSCRIPT, Commands::SUPERSCRIPT])) {
                return [$node->children[0]->token, array_slice($node->children[0]->children, 1)];
            }
        } catch (\Exception) {}
        return ["", []];
    }

    private static function _get_dimension(Node $node): string
    {
        $dimension = $node->token;
        if ($node->token === Commands::BRACES && $node->children !== null && !empty($node->children)) {
            $dimension = $node->children[0]->token;
        }
        return $dimension;
    }

    private static function _get_style(Node $node): string
    {
        $style = $node->token;
        if ($node->token === Commands::BRACES && $node->children !== null && !empty($node->children)) {
            $style = $node->children[0]->token;
        }
        return match ($style) {
            "0" => Commands::DISPLAYSTYLE,
            "1" => Commands::TEXTSTYLE,
            "2" => Commands::SCRIPTSTYLE,
            "3" => Commands::SCRIPTSCRIPTSTYLE,
            default => throw new InvalidStyleForGenfracError(),
        };
    }

    private static function _get_environment_node(string $token, \Iterator $tokens, ?string $terminator_context = null): Node
    {
        $start = strpos($token, "{") + 1;
        $environment = substr($token, $start, -1);
        $terminator = Commands::END . "{" . $environment . "}";

        if ($environment === 'alignat' || $environment === 'alignat*' || $environment === 'alignedat' || $environment === 'alignedat*') {
            // These environments have a mandatory argument for the number of pairs
            self::_walk($tokens, $terminator_context, 1);
        }

        $children = self::_walk($tokens, $terminator);
        if (!empty($children) && end($children)->token !== $terminator) {
            throw new MissingEndError();
        }
        if (!empty($children)) {
            array_pop($children);
        }
        $alignment = "";
        $environments_with_optional_alignment = [
            'array',
            'matrix*', 'pmatrix*', 'bmatrix*', 'Bmatrix*', 'vmatrix*', 'Vmatrix*',
            'aligned', 'gathered'
        ];

        if (in_array($environment, $environments_with_optional_alignment) && !empty($children) && $children[0]->token === Commands::OPENING_BRACKET) {
            array_shift($children);
            while (!empty($children)) {
                $c = array_shift($children);
                if ($c->token === Commands::CLOSING_BRACKET) {
                    break;
                } elseif (!str_contains("lcr|", $c->token)) {
                    throw new InvalidAlignmentError();
                }
                $alignment .= $c->token;
            }
        } elseif (!empty($children) && $children[0]->children !== null && ($children[0]->token === Commands::BRACES || (str_ends_with($environment, "*") && $children[0]->token === Commands::BRACKETS)) && self::all_match_lcr($children[0]->children)) {
            $alignment = implode('', array_map(fn($c) => $c->token, $children[0]->children));
            array_shift($children);
        }

        return new Node(token: "\\" . $environment, children: $children, alignment: $alignment);
    }

    private static function all_match_lcr(array $children): bool
    {
        foreach ($children as $c) {
            if (!str_contains("lcr|", $c->token)) return false;
        }
        return true;
    }
}
