#!/usr/bin/php
<?php
/**
 * t2tx - split XHTML pages created with txt2tags into multi-page "books"
 * 
 * @author Peeter P. Mõtsküla <peeterpaul@motskula.net>
 * @copyright (c) 2010 Peeter P. Mõtsküla
 * @version 1.0, 2010-09-06
 * @license New BSD license (http://opensource.org/licenses/bsd-license.php)
 *
 * Usage: see main block below or just call t2tx without parameters
 * 
 * Notes / to do:
 *   * internal hyperlinks are not recalculated.
 *
 * The script contains two classes (Book and Chapter), a simple error-handling
 * routine (error) and a main block that takes the arguments from the command
 * line, creates a new Book from the input file, and saves the Book.
 */

/*
 * global constants
 */
define('t2tx_PROGID',
    't2tx 1.0 by Peeter P. Mõtsküla <peeterpaul@motskula.net>');
define('t2tx_FNFORNOHTML', 1);
define('t2tx_NOT2TXHTML', 2);
define('t2tx_NOBODY', 3);
define('t2tx_BADLEVEL', 4);
define('t2tx_BADCHAPTER', 5);
define('t2tx_NOCHAPTERS', 6);
define('t2tx_BADSECTION', 7);

/**
 * Chapter of a book
 */
class Chapter {
    protected $_body;
    protected $_level;
    protected $_title;
    protected $_anchor;

    /**
     * @param string $body HTML snippet containing the chapter body
     */
    public function __construct($body) {
        $this->_body = $body;
    }

    /**
     * @return string HTML snippet containing the chapter body
     */
    public function body() {
        return $this->_body;
    }

    /**
     * return int chapter level -- the level of the first heading
     */
    public function level() {
        if (! isset($this->_level)) {
            if (preg_match('#<h([1-6])#s', $this->_body, $matches)) {
                $this->_level = $matches[1];
            } else {
                $this->_level = 0;
            }
        }
        return $this->_level;
    }

    /**
     * return string chapter title -- the content of the first heading
     */
    public function title() {
        if (! isset($this->_title)) {
            if (preg_match('#<h[1-6].*?>(.*?)</h[1-6]>#s',
                    $this->_body, $matches)) {
                $this->_title = $matches[1];
            } else {
                $this->_title = '';
            }
        }
        return $this->_title;
    }

    /**
     * return string the anchor string immediately preceding the first heading
     */
    public function anchor() {
        if (! $this->_anchor) {
            if (preg_match('#^<a.*? name="(.*?)".*?></a>#s',
                    $this->_body, $matches)) {
                $this->_anchor = $matches[1];
            } else {
                $this->_anchor = '';
            }
        }
        return $this->_anchor;
    }

}

/**
 * Book containing the chapters
 */
class Book {
    protected $_html;
    protected $_filename;
    protected $_maxLevel;
    protected $_bookName;
    protected $_htmlHead;
    protected $_title;
    protected $_header;
    protected $_body;
    protected $_toc;
    protected $_chapters;

    /*
     * @property string $content input filename or contents thereof
     * @property int $maxLevel
     *           smallest heading to break document into chapters
     */
    public function __construct($content = NULL, $maxLevel = 1) {
        // check $maxLevel
        if ($maxLevel < 1 || $maxLevel > 6 || intval($maxLevel) != $maxLevel) {
            error(t2tx_BADLEVEL);
        } else {
            $this->_maxLevel = $maxLevel;
        }

        // do we have a file?
        if (file_exists($content)) {
            $this->_filename = $content;
            $html = file_get_contents($this->_filename);
        } else {
            $html = $content;
        }

        // do we have HTML content?
        if (preg_match('#<html.*?>.*</html>#s', $html)) {
            $this->_html = $html;
        } else {
            error(t2tx_FNFORNOHTML);
        }

        // do we have something in document body?
        if (! $body = trim($this->body())) {
            error(t2tx_NOBODY);
        }
        
        // extract chapters
        $this->chapters();

    }

    /**
     * @return string name of input file without .html extension
     */
    public function bookName() {
        if (! $this->_bookName) {
            $this->_bookName = preg_replace('#\.html$#', '', $this->_filename);
        }
        return $this->_bookName;
    }

    /**
     * @return string start of input file until the end of <head> tag
     *         with an extra <meta name="generator"... added
     */
    public function htmlHead() {
        if (! $this->_htmlHead) {
            if (preg_match('#(^.*?</head>)#s', $this->_html, $matches)) {
                $this->_htmlHead = preg_replace(
                    "#</title>\n#",
                    "</title>\n" .
                    '<meta name="generator" content="' .
                    t2tx_PROGID .
                    "\" />\n",
                    $matches[0]);
            } else {
                error(t2tx_NOT2TXHTML);
            }
        }
        return $this->_htmlHead;
    }

    /**
     * @return string book title taken from the <title> of input file
     */
    public function title() {
        if (! $this->_title) {
            if (preg_match('#<title>(.*?)</title>#s', $this->htmlHead(),
                    $matches)) {
                $this->_title = $matches[1];
            } else {
                error(t2tx_NOT2TXHTML);
            }
        }
        return $this->_title;
    }

    /**
     * @return string content of input file's div#header
     */
    public function header() {
        if (! $this->_header) {
            if (preg_match('#<div class="header" id="header">(.*?)</div>#s',
                    $this->_html, $matches)) {
                $this->_header = $matches[1];
            } else {
                error(t2tx_NOT2TXHTML);
            }
        }
        return $this->_header;
    }

    /**
     * @return string content of input file's div#body
     */
    public function body() {
        if (! $this->_body) {
            if (preg_match('#<div\ class="body"\ id="body">' . "\n" .
                    '(.*?)</div>' . "\n\n" .
                    '<!-- xhtml code generated by txt2tags#s',
                    $this->_html, $matches)) {
                $this->_body = $matches[1];
            } else {
                error(t2tx_NOT2TXHTML);
            }
        }
        return $this->_body;
    }

    /**
     * @return array list of chapters in the book
     */
    public function chapters() {
        if (! is_array($this->_chapters)) {
            $this->_getSections($this->body(), $this->_chapters,
                $this->_maxLevel);
        }
        return $this->_chapters;
    }        

    /**
     * Break input file into chapters
     * 
     * @param string $body content of input file's div#body
     * @param array $chapters chapter list to be populated
     * @param int $maxLevel smallest heading to split input into chapters at
     * @return void
     */
    protected function _getSections($body, &$chapters, $maxLevel = NULL) {
        // set up nextHead;
        if (! $maxLevel) {
            $maxLevel = $this->_maxLevel;
        }
        $nextHead = '(?:<a[^>]+></a>\n)?<h';
        if ($maxLevel == 1) {
            $nextHead .= '1';
        } else {
            $nextHead .= "[1-$maxLevel]";
        }

        // extract chapter 0
        $chapters = array();
        preg_match("#^(.*?)(?=$nextHead|$)#s", $body, $matches);
        $chapters[] = new Chapter($matches[0]);
        $body = trim(str_replace($matches[0], '', $body));

        // extract chapters 1..n
        while ($body) {
            preg_match("#^($nextHead.*?)(?=$nextHead|$)#s",
                $body, $matches);
            $chapters[] = new Chapter($matches[0]);
            ### print_r($matches); readline("hit enter"); ###
            $body = trim(str_replace($matches[0], '', $body));
        }
    }

    /**
     * Create table of contents
     *
     * @return string HTML-formatted table of contents
     */
    public function toc() {
        if (! $this->_toc) {
            // bail out if no chapters found
            if (! count($this->_chapters)) {
                error(t2tx_NOCHAPTERS);
            }

            // build table of contents
            $level = 1;
            $toc = '<div class="toc" id="toc">' . "\n<ul>\n";
            $chapNum = 0;
            foreach ($this->_chapters as $chapter) {
                if ($chapter->level() == 0) {
                    continue;
                }
                $chapNum++;
                $this->_getSections($chapter->body(), $_chapters, 6);
                foreach ($_chapters as $_chapter) {
                    if ($_chapter->level() == 0) {
                        continue;
                    }
                    while($_chapter->level() > $level) {
                        $toc .= str_repeat("  ", $level++) . "<li><ul>\n";
                    }
                    while($_chapter->level() < $level) {
                        $toc .= str_repeat("  ", --$level) . "</ul></li>\n";
                    }
                    $toc .= str_repeat("  ", $level) .
                        '<li><a href="' . $this->bookName() . "-$chapNum.html" .
                        ($_chapter->anchor() ? "#{$_chapter->anchor()}" : '') .
                        "\">{$_chapter->title()}</a></li>\n";
                }
            }
            $toc .= "</ul>\n</div>\n";
            $this->_toc = $toc;        
        }
        return $this->_toc;
    }

    /**
     * Create chapter-specific navigation bars
     *
     * @param int $section section number (0 - TOC/preamble, 1..n - chapters)
     * @return string HTML-formatted navigation bar for given chapter
     */
    public function navbar($section) {
        // do we have a valid section?
        if ($section < 1 || $section >= count($this->_chapters) ||
                $section != intval($section)) {
            error(t2tx_BADSECTION);
        }

        // build navbar
        $navbar = '<div class="navbar" id="navbar">' . "\n" .
            '<table width="100%"><tr>' . "\n" .
            '  <td align="left" width="33%">';
        if ($section > 1) { # link to previous if exists
            $navbar .= '<a href="' .
                $this->bookName() . '-' . ($section - 1) . '.html">' .
                '&lt;&lt;</a>';
        }
        $navbar .= "</td>\n" . '  <td align="center" width="34%"><a href="' .
            $this->bookName() . '-0.html">' . $this->title() . "</a></td>\n" .
            '  <td align="right" width="33%">';
        if ($section < count($this->_chapters) -1) { # link to next if exists
            $navbar .= '<a href="' .
                $this->bookName() . '-' . ($section + 1) . '.html">' .
                '&gt;&gt;</a>';
        }
        $navbar .= "</td>\n</tr></table>\n</div>\n";
        
        return $navbar;
    }

    /**
     * Save the current book into set of files
     *
     * @return void
     */
    public function save() {
        $chapNum = 0;
        foreach ($this->_chapters as $chapter) {
            if ($chapNum == 0) {
                $content = $this->htmlHead() . "<body>\n" .
                    $this->header() . $this->toc() .
                    '<div class="body" id="body">' . "\n" .
                    $chapter->body() .
                    "</div>\n</body>\n</html>\n";
            } else {
                $content = $this->htmlHead() . "<body>\n" .
                    $this->navbar($chapNum) .
                    '<div class="body" id="body">' . "\n" .
                    $chapter->body() .
                    "</div>\n" .
                    $this->navbar($chapNum) .
                    "</body>\n</html>\n";
            }
            file_put_contents($this->bookName() . "-$chapNum.html", $content);
            $chapNum++;
        }
    }
    
}

/**
 * Exit with error code, optionally displaying an error message
 *
 * @param int $exitCode
 * @param bool $showMessage set to FALSE for quiet operation
 */
function error($exitCode, $showMessage = TRUE) {
    if ($showMessage) {
        echo 'ERROR: ';
        switch($exitCode) {
            case t2tx_FNFORNOHTML:
                echo 'not html or file not found';
                break;
            case t2tx_NOT2TXHTML:
                echo 'not txt2tags-generated xhtml';
                break;
            case t2tx_NOBODY:
                echo 'empty document body';
                break;
            case t2tx_BADLEVEL:
                echo 'invalid maxLevel';
                break;
            case t2tx_BADCHAPTER:
                echo 'invalid chapter content';
                break;
            case t2tx_NOCHAPTERS:
                echo 'no chapters found';
                break;
            case t2tx_BADSECTION:
                echo 'invalid section specified';
                break;
            default:
                echo "undefined error $exitCode";
        }
        echo "\n";
    }
    exit ($exitCode);
}

/**
 * main block
 */
if ($argc < 2 || $argc > 3) {
    echo <<<END
t2tx - split XHTML pages created with txt2tags into multi-page "books"

Usage:
    t2tx docName.html [splitLevel]

Files created:
    docName-0.html      # table of contents and preamble if any
    docName-1.html      # first chapter (input file is split into
        ...             # chapters at headings no smaller than splitLevel)
    docName-n.html      # last chapter

END;
    exit;
}

$input = $argv[1];
$level = ($argc == 3 ? $argv[2] : 1);
$book = new Book($input, $level);
$book->save();
echo "Done, ", count($book->chapters()) - 1, " chapters.\n";
