<?php

namespace Latex2MathML;

class Commands
{
    public const OPENING_BRACE = "{";
    public const CLOSING_BRACE = "}";
    public const BRACES = "{}";

    public const OPENING_BRACKET = "[";
    public const CLOSING_BRACKET = "]";
    public const BRACKETS = "[]";

    public const OPENING_PARENTHESIS = "(";
    public const CLOSING_PARENTHESIS = ")";
    public const PARENTHESES = "()";

    public const SUBSUP = "_^";
    public const SUBSCRIPT = "_";
    public const SUPERSCRIPT = "^";
    public const APOSTROPHE = "'";
    public const PRIME = "\\prime";
    public const DPRIME = "\\dprime";

    public const LEFT = "\\left";
    public const MIDDLE = "\\middle";
    public const RIGHT = "\\right";

    public const ABOVE = "\\above";
    public const ABOVEWITHDELIMS = "\\abovewithdelims";
    public const ATOP = "\\atop";
    public const ATOPWITHDELIMS = "\\atopwithdelims";
    public const BINOM = "\\binom";
    public const BRACE = "\\brace";
    public const BRACK = "\\brack";
    public const CFRAC = "\\cfrac";
    public const CHOOSE = "\\choose";
    public const DBINOM = "\\dbinom";
    public const DFRAC = "\\dfrac";
    public const FRAC = "\\frac";
    public const GENFRAC = "\\genfrac";
    public const OVER = "\\over";
    public const TBINOM = "\\tbinom";
    public const TFRAC = "\\tfrac";

    public const ROOT = "\\root";
    public const SQRT = "\\sqrt";

    public const OVERSET = "\\overset";
    public const UNDERSET = "\\underset";

    public const ACUTE = "\\acute";
    public const BAR = "\\bar";
    public const BREVE = "\\breve";
    public const CHECK = "\\check";
    public const DOT = "\\dot";
    public const DDOT = "\\ddot";
    public const DDDOT = "\\dddot";
    public const DDDDOT = "\\ddddot";
    public const GRAVE = "\\grave";
    public const HAT = "\\hat";
    public const MATHRING = "\\mathring";
    public const OVERBRACE = "\\overbrace";
    public const OVERLEFTARROW = "\\overleftarrow";
    public const OVERLEFTRIGHTARROW = "\\overleftrightarrow";
    public const OVERLINE = "\\overline";
    public const OVERPAREN = "\\overparen";
    public const OVERRIGHTARROW = "\\overrightarrow";
    public const TILDE = "\\tilde";
    public const UNDERBRACE = "\\underbrace";
    public const UNDERLEFTARROW = "\\underleftarrow";
    public const UNDERLINE = "\\underline";
    public const UNDERPAREN = "\\underparen";
    public const UNDERRIGHTARROW = "\\underrightarrow";
    public const UNDERLEFTRIGHTARROW = "\\underleftrightarrow";
    public const VEC = "\\vec";
    public const WIDEHAT = "\\widehat";
    public const WIDETILDE = "\\widetilde";
    public const XLEFTARROW = "\\xleftarrow";
    public const XRIGHTARROW = "\\xrightarrow";

    public const HREF = "\\href";
    public const TEXT = "\\text";
    public const TEXTBF = "\\textbf";
    public const TEXTIT = "\\textit";
    public const TEXTRM = "\\textrm";
    public const TEXTSF = "\\textsf";
    public const TEXTTT = "\\texttt";

    public const BEGIN = "\\begin";
    public const END = "\\end";

    public const LIMITS = "\\limits";
    public const INTEGRAL = "\\int";
    public const SUMMATION = "\\sum";
    public const PRODUCT = "\\prod";
    public const LIMIT = ["\\lim", "\\sup", "\\inf", "\\max", "\\min"];

    public const OPERATORNAME = "\\operatorname";

    public const LBRACE = "\\{";

    public const FUNCTIONS = [
        "\\arccos",
        "\\arcsin",
        "\\arctan",
        "\\cos",
        "\\cosh",
        "\\cot",
        "\\coth",
        "\\csc",
        "\\deg",
        "\\dim",
        "\\exp",
        "\\hom",
        "\\ker",
        "\\ln",
        "\\lg",
        "\\log",
        "\\sec",
        "\\sin",
        "\\sinh",
        "\\tan",
        "\\tanh",
    ];
    public const DETERMINANT = "\\det";
    public const GCD = "\\gcd";
    public const INTOP = "\\intop";
    public const INJLIM = "\\injlim";
    public const LIMINF = "\\liminf";
    public const LIMSUP = "\\limsup";
    public const PR = "\\Pr";
    public const PROJLIM = "\\projlim";
    public const MOD = "\\mod";
    public const PMOD = "\\pmod";
    public const BMOD = "\\bmod";

    public const HDASHLINE = "\\hdashline";
    public const HLINE = "\\hline";
    public const HFIL = "\\hfil";

    public const CASES = "\\cases";
    public const DISPLAYLINES = "\\displaylines";
    public const SMALLMATRIX = "\\smallmatrix";
    public const SUBSTACK = "\\substack";
    public const SPLIT = "\\split";
    public const ALIGN = "\\align";
    public const ALIGN_STAR = "\\align*";
    public const EQUATION = "\\equation";
    public const EQUATION_STAR = "\\equation*";
    public const GATHER = "\\gather";
    public const GATHER_STAR = "\\gather*";
    public const MULTLINE = "\\multline";
    public const MULTLINE_STAR = "\\multline*";
    public const FLALIGN = "\\flalign";
    public const FLALIGN_STAR = "\\flalign*";
    public const ALIGNAT = "\\alignat";
    public const ALIGNAT_STAR = "\\alignat*";
    public const ALIGNED = "\\aligned";
    public const ALIGNED_STAR = "\\aligned*";
    public const ALIGNEDAT = "\\alignedat";
    public const ALIGNEDAT_STAR = "\\alignedat*";
    public const GATHERED = "\\gathered";
    public const SUBEQUATIONS = "\\subequations";
    public const EQNARRAY = "\\eqnarray";
    public const EQNARRAY_STAR = "\\eqnarray*";
    public const MATRICES = [
        "\\matrix",
        "\\matrix*",
        "\\pmatrix",
        "\\pmatrix*",
        "\\bmatrix",
        "\\bmatrix*",
        "\\Bmatrix",
        "\\Bmatrix*",
        "\\vmatrix",
        "\\vmatrix*",
        "\\Vmatrix",
        "\\Vmatrix*",
        "\\array",
        self::SUBSTACK,
        self::CASES,
        self::DISPLAYLINES,
        self::SMALLMATRIX,
        self::SPLIT,
        self::ALIGN,
        self::ALIGN_STAR,
        self::GATHER,
        self::GATHER_STAR,
        self::MULTLINE,
        self::MULTLINE_STAR,
        self::FLALIGN,
        self::FLALIGN_STAR,
        self::ALIGNAT,
        self::ALIGNAT_STAR,
        self::ALIGNED,
        self::ALIGNED_STAR,
        self::ALIGNEDAT,
        self::ALIGNEDAT_STAR,
        self::GATHERED,
        self::SUBEQUATIONS,
        self::EQNARRAY,
        self::EQNARRAY_STAR,
    ];

    public const BACKSLASH = "\\";
    public const CARRIAGE_RETURN = "\\cr";

    public const COLON = "\\:";
    public const COMMA = "\\,";
    public const DOUBLEBACKSLASH = "\\\\";
    public const ENSPACE = "\\enspace";
    public const EXCLAMATION = "\\!";
    public const GREATER_THAN = "\\>";
    public const HSKIP = "\\hskip";
    public const HSPACE = "\\hspace";
    public const KERN = "\\kern";
    public const MKERN = "\\mkern";
    public const MSKIP = "\\mskip";
    public const MSPACE = "\\mspace";
    public const NEGTHINSPACE = "\\negthinspace";
    public const NEGMEDSPACE = "\\negmedspace";
    public const NEGTHICKSPACE = "\\negthickspace";
    public const NOBREAKSPACE = "\\nobreakspace";
    public const SPACE = "\\space";
    public const THINSPACE = "\\thinspace";
    public const QQUAD = "\\qquad";
    public const QUAD = "\\quad";
    public const SEMICOLON = "\\;";

    public const BLACKBOARD_BOLD = "\\Bbb";
    public const BOLD_SYMBOL = "\\boldsymbol";
    public const MIT = "\\mit";
    public const OLDSTYLE = "\\oldstyle";
    public const SCR = "\\scr";
    public const TT = "\\tt";

    public const MATH = "\\math";
    public const MATHBB = "\\mathbb";
    public const MATHBF = "\\mathbf";
    public const MATHCAL = "\\mathcal";
    public const MATHFRAK = "\\mathfrak";
    public const MATHIT = "\\mathit";
    public const MATHRM = "\\mathrm";
    public const MATHSCR = "\\mathscr";
    public const MATHSF = "\\mathsf";
    public const MATHTT = "\\mathtt";

    public const BOXED = "\\boxed";
    public const FBOX = "\\fbox";
    public const HBOX = "\\hbox";
    public const MBOX = "\\mbox";

    public const COLOR = "\\color";
    public const DISPLAYSTYLE = "\\displaystyle";
    public const TEXTSTYLE = "\\textstyle";
    public const SCRIPTSTYLE = "\\scriptstyle";
    public const SCRIPTSCRIPTSTYLE = "\\scriptscriptstyle";
    public const STYLE = "\\style";

    public const HPHANTOM = "\\hphantom";
    public const PHANTOM = "\\phantom";
    public const VPHANTOM = "\\vphantom";

    public const IDOTSINT = "\\idotsint";
    public const LATEX = "\\LaTeX";
    public const TEX = "\\TeX";

    public const SIDESET = "\\sideset";
    public const LABEL = "\\label";
    public const TAG = "\\tag";
    public const CITE = "\\cite";
    public const REF = "\\ref";
    public const NOTAG = "\\notag";
    public const NONUMBER = "\\nonumber";

    public const SPECIAL_CHAR = "\\specialChar";

    public const SKEW = "\\skew";
    public const NOT = "\\not";

    public static function font_factory(?string $default, array $replacement): array
    {
        return ['default' => $default, 'replacements' => $replacement];
    }

    public static function get_font(array $font_config, string $key): ?string
    {
        return $font_config['replacements'][$key] ?? $font_config['default'];
    }

    public static array $LOCAL_FONTS;
    public static array $OLD_STYLE_FONTS;
    public static array $GLOBAL_FONTS;

    public const COMMANDS_WITH_ONE_PARAMETER = [
        self::ACUTE,
        self::BAR,
        self::BLACKBOARD_BOLD,
        self::BOLD_SYMBOL,
        self::BOXED,
        self::BREVE,
        self::CHECK,
        self::DOT,
        self::DDOT,
        self::DDDOT,
        self::DDDDOT,
        self::GRAVE,
        self::HAT,
        self::HPHANTOM,
        self::LABEL,
        self::TAG,
        self::CITE,
        self::REF,
        self::MATHRING,
        self::MIT,
        self::MOD,
        self::OLDSTYLE,
        self::OVERBRACE,
        self::OVERLEFTARROW,
        self::OVERLEFTRIGHTARROW,
        self::OVERLINE,
        self::OVERPAREN,
        self::OVERRIGHTARROW,
        self::PHANTOM,
        self::PMOD,
        self::SCR,
        self::TILDE,
        self::TT,
        self::UNDERBRACE,
        self::UNDERLEFTARROW,
        self::UNDERLINE,
        self::UNDERPAREN,
        self::UNDERRIGHTARROW,
        self::UNDERLEFTRIGHTARROW,
        self::VEC,
        self::VPHANTOM,
        self::WIDEHAT,
        self::WIDETILDE,
    ];

    public const COMMANDS_WITH_TWO_PARAMETERS = [
        self::BINOM,
        self::CFRAC,
        self::DBINOM,
        self::DFRAC,
        self::FRAC,
        self::OVERSET,
        self::TBINOM,
        self::TFRAC,
        self::UNDERSET,
    ];

    public static array $BIG;
    public static array $BIG_OPEN_CLOSE;
    public static array $MSTYLE_SIZES;
    public static array $STYLES;
    public static array $CONVERSION_MAP;
    public static array $DIACRITICS;

    public static function init(): void
    {
        self::$LOCAL_FONTS = [
            self::BLACKBOARD_BOLD => self::font_factory("double-struck", ["fence" => null]),
            self::BOLD_SYMBOL => self::font_factory("bold", ["mi" => "bold-italic", "mtext" => null]),
            self::MATHBB => self::font_factory("double-struck", ["fence" => null]),
            self::MATHBF => self::font_factory("bold", ["fence" => null]),
            self::MATHCAL => self::font_factory("script", ["fence" => null]),
            self::MATHFRAK => self::font_factory("fraktur", ["fence" => null]),
            self::MATHIT => self::font_factory("italic", ["fence" => null]),
            self::MATHRM => self::font_factory(null, ["mi" => "normal"]),
            self::MATHSCR => self::font_factory("script", ["fence" => null]),
            self::MATHSF => self::font_factory(null, ["mi" => "sans-serif"]),
            self::MATHTT => self::font_factory("monospace", ["fence" => null]),
            self::MIT => self::font_factory("italic", ["fence" => null, "mi" => null]),
            self::OLDSTYLE => self::font_factory("normal", ["fence" => null]),
            self::SCR => self::font_factory("script", ["fence" => null]),
            self::TT => self::font_factory("monospace", ["fence" => null]),
        ];

        self::$OLD_STYLE_FONTS = [
            "\\rm" => self::font_factory(null, ["mi" => "normal"]),
            "\\bf" => self::font_factory(null, ["mi" => "bold"]),
            "\\it" => self::font_factory(null, ["mi" => "italic"]),
            "\\sf" => self::font_factory(null, ["mi" => "sans-serif"]),
            "\\tt" => self::font_factory(null, ["mi" => "monospace"]),
        ];

        self::$GLOBAL_FONTS = array_merge(self::$OLD_STYLE_FONTS, [
            "\\cal" => self::font_factory("script", ["fence" => null]),
            "\\frak" => self::font_factory("fraktur", ["fence" => null]),
        ]);

        self::$BIG = [
            "\\Bigg" => ["mo", ["stretchy" => "true", "symmetric" => "true"]],
            "\\bigg" => ["mo", ["stretchy" => "true", "symmetric" => "true"]],
            "\\Big" => ["mo", ["stretchy" => "true", "symmetric" => "true"]],
            "\\big" => ["mo", ["stretchy" => "true", "symmetric" => "true"]],
        ];

        self::$BIG_OPEN_CLOSE = [];
        foreach (self::$BIG as $command => $data) {
            foreach (['l', 'm', 'r'] as $postfix) {
                self::$BIG_OPEN_CLOSE[$command . $postfix] = [
                    $data[0],
                    array_merge(["fence" => "true"], $data[1])
                ];
            }
        }

        self::$MSTYLE_SIZES = [
            "\\Huge" => ["mstyle", ["mathsize" => "2.49em"]],
            "\\huge" => ["mstyle", ["mathsize" => "2.07em"]],
            "\\LARGE" => ["mstyle", ["mathsize" => "1.73em"]],
            "\\Large" => ["mstyle", ["mathsize" => "1.44em"]],
            "\\large" => ["mstyle", ["mathsize" => "1.2em"]],
            "\\normalsize" => ["mstyle", ["mathsize" => "1em"]],
            "\\scriptsize" => ["mstyle", ["mathsize" => "0.7em"]],
            "\\small" => ["mstyle", ["mathsize" => "0.85em"]],
            "\\tiny" => ["mstyle", ["mathsize" => "0.5em"]],
            "\\Tiny" => ["mstyle", ["mathsize" => "0.6em"]],
        ];

        self::$STYLES = [
            self::DISPLAYSTYLE => ["mstyle", ["displaystyle" => "true", "scriptlevel" => "0"]],
            self::TEXTSTYLE => ["mstyle", ["displaystyle" => "false", "scriptlevel" => "0"]],
            self::SCRIPTSTYLE => ["mstyle", ["displaystyle" => "false", "scriptlevel" => "1"]],
            self::SCRIPTSCRIPTSTYLE => ["mstyle", ["displaystyle" => "false", "scriptlevel" => "2"]],
        ];

        self::$CONVERSION_MAP = array_merge(
            array_fill_keys(self::MATRICES, ["mtable", []]),
            [
                self::DISPLAYLINES => ["mtable", ["rowspacing" => "0.5em", "columnspacing" => "1em", "displaystyle" => "true"]],
                self::SMALLMATRIX => ["mtable", ["rowspacing" => "0.1em", "columnspacing" => "0.2778em"]],
                self::SPLIT => ["mtable", ["displaystyle" => "true", "columnspacing" => "0em", "rowspacing" => "3pt"]],
                self::ALIGN => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::ALIGN_STAR => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::ALIGNED => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::ALIGNED_STAR => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::GATHER => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::GATHER_STAR => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::MULTLINE => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::MULTLINE_STAR => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::FLALIGN => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::FLALIGN_STAR => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::ALIGNAT => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::ALIGNAT_STAR => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::ALIGNEDAT => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::ALIGNEDAT_STAR => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::EQUATION => ["mrow", ["displaystyle" => "true"]],
                self::EQUATION_STAR => ["mrow", ["displaystyle" => "true"]],
                self::SUBEQUATIONS => ["mrow", []],
                self::GATHERED => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::EQNARRAY => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::EQNARRAY_STAR => ["mtable", ["displaystyle" => "true", "rowspacing" => "3pt"]],
                self::SUBSCRIPT => ["msub", []],
                self::SUPERSCRIPT => ["msup", []],
                self::SUBSUP => ["msubsup", []],
                self::BINOM => ["mfrac", ["linethickness" => "0"]],
                self::CFRAC => ["mfrac", []],
                self::DBINOM => ["mfrac", ["linethickness" => "0"]],
                self::DFRAC => ["mfrac", []],
                self::FRAC => ["mfrac", []],
                self::GENFRAC => ["mfrac", []],
                self::TBINOM => ["mfrac", ["linethickness" => "0"]],
                self::TFRAC => ["mfrac", []],
                self::ACUTE => ["mover", []],
                self::BAR => ["mover", []],
                self::BREVE => ["mover", []],
                self::CHECK => ["mover", []],
                self::DOT => ["mover", []],
                self::DDOT => ["mover", []],
                self::DDDOT => ["mover", []],
                self::DDDDOT => ["mover", []],
                self::GRAVE => ["mover", []],
                self::HAT => ["mover", []],
                self::LIMITS => ["munderover", []],
                self::MATHRING => ["mover", []],
                self::OVERBRACE => ["mover", []],
                self::OVERLEFTARROW => ["mover", []],
                self::OVERLEFTRIGHTARROW => ["mover", []],
                self::OVERLINE => ["mover", []],
                self::OVERPAREN => ["mover", []],
                self::OVERRIGHTARROW => ["mover", []],
                self::TILDE => ["mover", []],
                self::OVERSET => ["mover", []],
                self::UNDERBRACE => ["munder", []],
                self::UNDERLEFTARROW => ["munder", []],
                self::UNDERLINE => ["munder", []],
                self::UNDERPAREN => ["munder", []],
                self::UNDERRIGHTARROW => ["munder", []],
                self::UNDERLEFTRIGHTARROW => ["munder", []],
                self::UNDERSET => ["munder", []],
                self::VEC => ["mover", []],
                self::WIDEHAT => ["mover", []],
                self::WIDETILDE => ["mover", []],
                self::COLON => ["mspace", ["width" => "0.222em"]],
                self::COMMA => ["mspace", ["width" => "0.167em"]],
                self::DOUBLEBACKSLASH => ["mspace", ["linebreak" => "newline"]],
                self::ENSPACE => ["mspace", ["width" => "0.5em"]],
                self::EXCLAMATION => ["mspace", ["width" => "negativethinmathspace"]],
                self::GREATER_THAN => ["mspace", ["width" => "0.222em"]],
                self::HSKIP => ["mspace", []],
                self::HSPACE => ["mspace", []],
                self::KERN => ["mspace", []],
                self::MKERN => ["mspace", []],
                self::MSKIP => ["mspace", []],
                self::MSPACE => ["mspace", []],
                self::NEGTHINSPACE => ["mspace", ["width" => "negativethinmathspace"]],
                self::NEGMEDSPACE => ["mspace", ["width" => "negativemediummathspace"]],
                self::NEGTHICKSPACE => ["mspace", ["width" => "negativethickmathspace"]],
                self::THINSPACE => ["mspace", ["width" => "thinmathspace"]],
                self::QQUAD => ["mspace", ["width" => "2em"]],
                self::QUAD => ["mspace", ["width" => "1em"]],
                self::SEMICOLON => ["mspace", ["width" => "0.278em"]],
                self::BOXED => ["menclose", ["notation" => "box"]],
                self::FBOX => ["menclose", ["notation" => "box"]],
            ],
            self::$BIG,
            self::$BIG_OPEN_CLOSE,
            self::$MSTYLE_SIZES,
            array_fill_keys(self::LIMIT, ["mo", []]),
            [
                self::LEFT => ["mo", ["stretchy" => "true", "fence" => "true", "form" => "prefix"]],
                self::MIDDLE => ["mo", ["stretchy" => "true", "fence" => "true", "lspace" => "0.05em", "rspace" => "0.05em"]],
                self::RIGHT => ["mo", ["stretchy" => "true", "fence" => "true", "form" => "postfix"]],
                self::COLOR => ["mstyle", []],
            ],
            self::$STYLES,
            [
                self::LABEL => ["mrow", []],
                self::TAG => ["mrow", []],
                self::CITE => ["mrow", []],
                self::REF => ["mrow", []],
                self::SPECIAL_CHAR => ["mi", []],
                self::SQRT => ["msqrt", []],
                self::ROOT => ["mroot", []],
                self::HREF => ["mtext", []],
                self::TEXT => ["mtext", []],
                self::TEXTBF => ["mtext", ["mathvariant" => "bold"]],
                self::TEXTIT => ["mtext", ["mathvariant" => "italic"]],
                self::TEXTRM => ["mtext", []],
                self::TEXTSF => ["mtext", ["mathvariant" => "sans-serif"]],
                self::TEXTTT => ["mtext", ["mathvariant" => "monospace"]],
                self::HBOX => ["mtext", []],
                self::MBOX => ["mtext", []],
                self::HPHANTOM => ["mphantom", []],
                self::PHANTOM => ["mphantom", []],
                self::VPHANTOM => ["mphantom", []],
                self::SIDESET => ["mrow", []],
                self::SKEW => ["mrow", []],
                self::MOD => ["mi", []],
                self::PMOD => ["mi", []],
                self::BMOD => ["mo", []],
                self::XLEFTARROW => ["mover", []],
                self::XRIGHTARROW => ["mover", []],
            ]
        );

        self::$DIACRITICS = [
            self::ACUTE => ["&#x000B4;", []],
            self::BAR => ["&#x000AF;", ["stretchy" => "true"]],
            self::BREVE => ["&#x002D8;", []],
            self::CHECK => ["&#x002C7;", []],
            self::DOT => ["&#x002D9;", []],
            self::DDOT => ["&#x000A8;", []],
            self::DDDOT => ["&#x020DB;", []],
            self::DDDDOT => ["&#x020DC;", []],
            self::GRAVE => ["&#x00060;", []],
            self::HAT => ["&#x0005E;", ["stretchy" => "false"]],
            self::MATHRING => ["&#x002DA;", []],
            self::OVERBRACE => ["&#x23DE;", []],
            self::OVERLEFTARROW => ["&#x02190;", []],
            self::OVERLEFTRIGHTARROW => ["&#x02194;", []],
            self::OVERLINE => ["&#x02015;", ["accent" => "true"]],
            self::OVERPAREN => ["&#x23DC;", []],
            self::OVERRIGHTARROW => ["&#x02192;", []],
            self::TILDE => ["&#x0007E;", ["stretchy" => "false"]],
            self::UNDERBRACE => ["&#x23DF;", []],
            self::UNDERLEFTARROW => ["&#x02190;", []],
            self::UNDERLEFTRIGHTARROW => ["&#x02194;", []],
            self::UNDERLINE => ["&#x02015;", ["accent" => "true"]],
            self::UNDERPAREN => ["&#x23DD;", []],
            self::UNDERRIGHTARROW => ["&#x02192;", []],
            self::VEC => ["&#x02192;", ["stretchy" => "true"]],
            self::WIDEHAT => ["&#x0005E;", []],
            self::WIDETILDE => ["&#x0007E;", []],
        ];
    }
}

Commands::init();
