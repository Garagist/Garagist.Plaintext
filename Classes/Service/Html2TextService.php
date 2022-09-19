<?php

declare(strict_types=1);

namespace Garagist\Plaintext\Service;

use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_merge;
use function array_search;
use function chr;
use function count;
use function html_entity_decode;
use function htmlspecialchars;
use function ltrim;
use function mb_internal_encoding;
use function mb_strlen;
use function mb_strtolower;
use function mb_strtoupper;
use function mb_substr;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function preg_split;
use function sprintf;
use function str_repeat;
use function str_replace;
use function strip_tags;
use function substr;
use function trim;
use function wordwrap;

/*
 * Copyright (c) 2005-2007 Jon Abernathy <jon@chuggnutt.com>,
 * adjusted for this package for Neos by Jon Uhlmann
 *
 * This script is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

/**
 *
 * @Flow\Scope("singleton")
 */
class Html2TextService
{
    const ENCODING = 'UTF-8';

    /**
     * @Flow\Inject(name="Garagist.Plaintext:PlaintextLogger")
     * @var LoggerInterface
     */
    protected $logger;

    protected $htmlFuncFlags = ENT_COMPAT | ENT_HTML5;

    /**
     * Contains the HTML content to convert.
     *
     * @var string $html
     */
    protected $html;

    /**
     * Contains the converted, formatted text.
     *
     * @var string $text
     */
    protected $text;

    /**
     * configuration options
     *
     * @var array $options
     */
    protected $options = [
        'links' => 'inline',
        'width' => 70,
        'imageAltText' => true,
    ];


    /**
     * Convert the html to plaintext
     *
     * @param string $html source HTML
     * @param array|null $options configuration options
     * @param string|null $url for log output
     * @return string
     */
    public function convert(string $html, ?array $options = null, ?string $url = null): string
    {
        $this->html = $html;
        if (isset($options)) {
            $this->options = array_merge($this->options, $options);
        }

        try {
            $origEncoding = mb_internal_encoding();
            mb_internal_encoding(self::ENCODING);
            $this->doConvert();
            mb_internal_encoding($origEncoding);
        } catch (Throwable $th) {
            $logMessage = 'Convert HTML to plaintext failed';
            if (isset($url)) {
                $logMessage .= sprintf(' (%s)', $url);
            }

            $this->logger->error($logMessage);
        }

        $logMessage = 'Successfully converted HTML to plaintext';
        if (isset($url)) {
            $logMessage .= sprintf(' (%s)', $url);
        }

        $this->logger->debug($logMessage);

        return $this->text;
    }

    /**
     * List of preg* regular expression patterns to search for,
     * used in conjunction with $replace.
     *
     * @var array $search
     * @see $replaceList
     */
    protected $searchList = [
        "/\r/",                                                                  // Non-legal carriage return
        "/[\n\t]+/",                                                             // Newlines and tabs
        '/<head\b[^>]*>.*?<\/head>/i',                                           // <head>
        '/<script\b[^>]*>.*?<\/script>/i',                                       // <script>s -- which strip_tags supposedly has problems with
        '/<style\b[^>]*>.*?<\/style>/i',                                         // <style>s -- which strip_tags supposedly has problems with
        '/<mj-[^>]*?css-class="[^"]*?plaintext:hidden[^>]*?>.+?<\/mj-[^>]*>/i', // MJML tags with class plaintext:hidden
        '/<p[^>]*?class="[^"]*?plaintext:hidden[^>]*?>.+?<\/p>/i',               // <p> with class plaintext:hidden
        '/<span[^>]*?class="[^"]*?plaintext:hidden[^>]*?>.+?<\/span>/i',         // <span> with class plaintext:hidden
        '/<div[^>]*?class="[^"]*?plaintext:hidden[^>]*?>.+?<\/div>/i',           // <div> with class plaintext:hidden
        '/<table[^>]*?class="[^"]*?plaintext:hidden[^>]*?>.+?<\/table>/i',       // <table> with class plaintext:hidden
        '/<tr[^>]*?class="[^"]*?plaintext:hidden[^>]*?>.+?<\/tr>/i',             // <tr> with class plaintext:hidden
        '/<td[^>]*?class="[^"]*?plaintext:hidden[^>]*?>.*?<\/td>/i',             // <td> with class plaintext:hidden
        '/<i\b[^>]*>(.*?)<\/i>/i',                                               // <i>
        '/<em\b[^>]*>(.*?)<\/em>/i',                                             // <em>
        '/<ins\b[^>]*>(.*?)<\/ins>/i',                                           // <ins>
        '/(<ul\b[^>]*>|<\/ul>)/i',                                               // <ul> and </ul>
        '/(<ol\b[^>]*>|<\/ol>)/i',                                               // <ol> and </ol>
        '/(<dl\b[^>]*>|<\/dl>)/i',                                               // <dl> and </dl>
        '/<li\b[^>]*>(.*?)<\/li>/i',                                             // <li> and </li>
        '/<dd\b[^>]*>(.*?)<\/dd>/i',                                             // <dd> and </dd>
        '/<dt\b[^>]*>(.*?)<\/dt>/i',                                             // <dt> and </dt>
        '/<li\b[^>]*>/i',                                                        // <li>
        '/<hr\b[^>]*>/i',                                                        // <hr>
        '/<div\b[^>]*>/i',                                                       // <div>
        '/(<table\b[^>]*>|<\/table>)/i',                                         // <table> and </table>
        '/(<tr\b[^>]*>|<\/tr>)/i',                                               // <tr> and </tr>
        '/<td\b[^>]*>(.*?)<\/td>/i',                                             // <td> and </td>
        '/<(img)\b[^>]*alt=\"([^>"]+)\"[^>]*>/i',                                // <img> with alt tag
        '/<outlook\b[^>]*>.*?<\/outlook>/i',                                     // <outlook>
        '/(<mj-table\b[^>]*>|<\/mj-table>)/i',                                   // <mj-table> and </mj-table>
        '/<mj-divider\b[^>]*>/i',                                                // <mj-divider>
        '/<mj-spacer\b[^>]*>/i',                                                 // <mj-spacer>
        '/<mj-social\b[^>]*>.*?<\/mj-social>/i',                                 // <mj-social>
    ];

    /**
     * Returns list of pattern replacements corresponding to patterns searched.
     *
     * @param array $options
     * @return array
     * @see $searchList
     */
    protected function replaceList(): array
    {
        $width = $this->options['width'];
        if ($width === 0) {
            $width = 25;
        }
        $imageReplace = $this->options['imageAltText'] ? '[\\2]' : '';
        return [
            '',                                        // Non-legal carriage return
            ' ',                                       // Newlines and tabs
            '',                                        // <head>
            '',                                        // <script>s -- which strip_tags supposedly has problems with
            '',                                        // <style>s -- which strip_tags supposedly has problems with
            "",                                        // MJML with class plaintext:hidden
            "",                                        // <p> with class plaintext:hidden
            "",                                        // <span> with class plaintext:hidden
            "",                                        // <div > with class plaintext:hidden
            "",                                        // <table> with class plaintext:hidden
            "",                                        // <tr> with class plaintext:hidden
            "",                                        // <td> with class plaintext:hidden
            '_\\1_',                                   // <i>
            '_\\1_',                                   // <em>
            '_\\1_',                                   // <ins>
            "\n\n",                                    // <ul> and </ul>
            "\n\n",                                    // <ol> and </ol>
            "\n\n",                                    // <dl> and </dl>
            "\t* \\1\n",                               // <li> and </li>
            " \\1\n",                                  // <dd> and </dd>
            "\t* \\1",                                 // <dt> and </dt>
            "\n\t* ",                                  // <li>
            "\n\n" . str_repeat("-", $width) . "\n\n", // <hr>
            "<div>\n",                                 // <div>
            "\n\n",                                    // <table> and </table>
            "\n",                                      // <tr> and </tr>
            "\t\t\\1\n",                               // <td> and </td>
            $imageReplace,                             // <img> with alt tag
            "",                                        // <outlook> tag
            "\n\n",                                    // <mj-table> and </mj-table>
            "\n\n" . str_repeat("-", $width) . "\n\n", // <mj-divider>
            "&nbsp;\n\n&nbsp;",                        // <mj-spacer>
            "",                                        // <mj-social>
        ];
    }


    /**
     * List of preg* regular expression patterns to search for,
     * used in conjunction with $entReplace.
     *
     * @var array $entSearch
     * @see $entReplace
     */
    protected $entSearch = [
        '/&#153;/i',      // TM symbol in win-1252
        '/&#151;/i',      // m-dash in win-1252
        '/&(amp|#38);/i', // Ampersand: see converter()
        '/[ ]{2,}/',      // Runs of spaces, post-handling
        '/&#39;/i',       // The apostrophe symbol
    ];

    /**
     * List of pattern replacements corresponding to patterns searched.
     *
     * @var array $entReplace
     * @see $entSearch
     */
    protected $entReplace = [
        '™',         // TM symbol
        '—',         // m-dash
        '|+|amp|+|', // Ampersand: see converter()
        ' ',         // Runs of spaces, post-handling
        '\'',        // Apostrophe
    ];

    /**
     * List of preg* regular expression patterns to search for
     * and replace using callback function.
     *
     * @var array $callbackSearch
     */
    protected $callbackSearch = [
        '/<(h)[123456]( [^>]*)?>(.*?)<\/h[123456]>/i',                                  // h1 - h6
        '/<(mj)-[^>]*?css-class="[^"]*?plaintext:uppercase[^>]*?>(.*?)<\/mj-[^>]*>/i', // MJML tags with class plaintext:uppercase
        '/[ ]*<(p)( [^>]*)?>(.*?)<\/p>[ ]*/si',                                         // <p> with surrounding whitespace.
        '/<(br)[^>]*>[ ]*/i',                                                           // <br> with leading whitespace after the newline.
        '/<(b)( [^>]*)?>(.*?)<\/b>/i',                                                  // <b>
        '/<(strong)( [^>]*)?>(.*?)<\/strong>/i',                                        // <strong>
        '/<(del)( [^>]*)?>(.*?)<\/del>/i',                                              // <del>
        '/<(th)( [^>]*)?>(.*?)<\/th>/i',                                                // <th> and </th>
        '/<(a) [^>]*href=("|\')([^"\']+)\2([^>]*)>(.*?)<\/a>/i',                        // <a href="">
        '/<(mj-button) [^>]*href=("|\')([^"\']+)\2([^>]*)>(.*?)<\/mj-button>/i',       // <mj-button href="">
        '/<(mj-navbar) [^>]*base-url=["|\']([^"\']+)"[^>]*>(.*?)<\/mj-navbar>/i',      // <mj-navbar base-url>
        '/<(mj-navbar)( )[^>]*>(.*?)<\/mj-navbar>/i',                                  // <mj-navbar>
        '/<(mj-image) ([^>]*)?>/i',                                                    // <mj-image>
        '/<(mj-carousel-image) ([^>]*)?>/i',                                           // <mj-carousel-image>
    ];

    /**
     * List of preg* regular expression patterns to search for in PRE body,
     * used in conjunction with $preReplace.
     *
     * @var array $preSearch
     * @see $preReplace
     */
    protected $preSearch = [
        "/\n/",
        "/\t/",
        '/ /',
        '/<pre[^>]*>/',
        '/<\/pre>/'
    ];

    /**
     * List of pattern replacements corresponding to patterns searched for PRE body.
     *
     * @var array $preReplace
     * @see $preSearch
     */
    protected $preReplace = [
        '<br>',
        '&nbsp;&nbsp;&nbsp;&nbsp;',
        '&nbsp;',
        '',
        '',
    ];

    /**
     * Temporary workspace used during PRE processing.
     *
     * @var string $preContent
     */
    protected $preContent = '';

    /**
     * Contains URL addresses from links to be rendered in plain text.
     *
     * @var array $linkList
     * @see buildLinkList()
     */
    protected $linkList = [];


    protected function doConvert()
    {
        $this->linkList = [];

        $text = trim($this->html);

        $this->converter($text);

        if ($this->linkList) {
            $text .= "\n\nLinks:\n------\n";
            foreach ($this->linkList as $i => $url) {
                $text .= '[' . ($i + 1) . '] ' . $url . "\n";
            }
        }

        $this->text = $text;
    }

    protected function converter(&$text)
    {
        $this->convertBlockquotes($text);
        $this->convertPre($text);
        $text = preg_replace($this->searchList, $this->replaceList(), $text);
        $text = preg_replace_callback($this->callbackSearch, [$this, 'pregCallback'], $text);
        $text = strip_tags($text);
        $text = preg_replace($this->entSearch, $this->entReplace, $text);
        $text = html_entity_decode($text, $this->htmlFuncFlags, self::ENCODING);

        // Remove unknown/unhandled entities (this cannot be done in search-and-replace block)
        $text = preg_replace('/&([a-zA-Z0-9]{2,6}|#[0-9]{2,4});/', '', $text);

        // Convert "|+|amp|+|" into "&", need to be done after handling of unknown entities
        // This properly handles situation of "&amp;quot;" in input string
        $text = str_replace('|+|amp|+|', '&', $text);

        // Normalise empty lines
        $text = preg_replace("/\n\s+\n/", "\n\n", $text);
        $text = preg_replace("/[\n]{3,}/", "\n\n", $text);

        // remove leading empty lines (can be produced by eg. P tag on the beginning)
        $text = ltrim($text, "\n");

        if ($this->options['width'] > 0) {
            $text = wordwrap($text, $this->options['width']);
        }
    }

    /**
     * Helper function called by preg_replace() on link replacement.
     *
     * Maintains an internal list of links to be displayed at the end of the
     * text, with numeric indices to the original point in the text they
     * appeared. Also makes an effort at identifying and handling absolute
     * and relative links.
     *
     * @param  string $link          URL of the link
     * @param  string $display       Part of the text to associate number with
     * @param  null   $linkOverride
     * @return string
     */
    protected function buildLinkList($link, $display, $linkOverride = null, $newLine = false)
    {
        $linkMethod = ($linkOverride) ? $linkOverride : $this->options['links'];
        if ($linkMethod == false) {
            return $display;
        }

        // Add optional new line
        $newLine = $newLine ? "\n" : '';

        // Ignored link types
        if (preg_match('!^(javascript:|mailto:|tel:|#)!i', html_entity_decode($link))) {
            return $display . $newLine;
        }

        if ($linkMethod == 'table') {
            if (($index = array_search($link, $this->linkList)) === false) {
                $index = count($this->linkList);
                $this->linkList[] = $link;
            }

            return $display . ' [' . ($index + 1) . ']' . $newLine;
        } elseif ($linkMethod == 'nextline') {
            if ($link === $display) {
                return $display . $newLine;
            }
            return $display . "\n[" . $link . ']' . $newLine;
        } elseif ($linkMethod == 'bbcode') {
            return sprintf('[url=%s]%s[/url]%s', $link, $display, $newLine);
        } else { // link_method defaults to inline
            if ($link === $display) {
                return $display . $newLine;
            }
            return $display . ' [' . $link . ']' . $newLine;
        }
    }

    /**
     * Helper function for PRE body conversion.
     *
     * @param string &$text HTML content
     */
    protected function convertPre(&$text)
    {
        // get the content of PRE element
        while (preg_match('/<pre[^>]*>(.*)<\/pre>/ismU', $text, $matches)) {
            // Replace br tags with newlines to prevent the search-and-replace callback from killing whitespace
            $this->preContent = preg_replace('/(<br\b[^>]*>)/i', "\n", $matches[1]);

            // Run our defined tags search-and-replace with callback
            $this->preContent = preg_replace_callback(
                $this->callbackSearch,
                [$this, 'pregCallback'],
                $this->preContent
            );

            // convert the content
            $this->preContent = sprintf(
                '<div><br>%s<br></div>',
                preg_replace($this->preSearch, $this->preReplace, $this->preContent)
            );

            // replace the content (use callback because content can contain $0 variable)
            $text = preg_replace_callback(
                '/<pre[^>]*>.*<\/pre>/ismU',
                [$this, 'pregPreCallback'],
                $text,
                1
            );

            // free memory
            $this->preContent = '';
        }
    }

    /**
     * Helper function for BLOCKQUOTE body conversion.
     *
     * @param string &$text HTML content
     */
    protected function convertBlockquotes(&$text)
    {
        if (preg_match_all('/<\/*blockquote[^>]*>/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            $originalText = $text;
            $start = 0;
            $taglen = 0;
            $level = 0;
            $diff = 0;
            foreach ($matches[0] as $m) {
                $m[1] = mb_strlen(substr($originalText, 0, $m[1]));
                if ($m[0][0] == '<' && $m[0][1] == '/') {
                    $level--;
                    if ($level < 0) {
                        $level = 0; // malformed HTML: go to next blockquote
                    } elseif ($level > 0) {
                        // skip inner blockquote
                    } else {
                        $end = $m[1];
                        $len = $end - $taglen - $start;
                        // Get blockquote content
                        $body = mb_substr($text, $start + $taglen - $diff, $len);

                        // Set text width
                        $pWidth = $this->options['width'];
                        if ($this->options['width'] > 0) {
                            $this->options['width'] -= 2;
                        }
                        // Convert blockquote content
                        $body = trim($body);
                        $this->converter($body);
                        // Add citation markers and create PRE block
                        $body = preg_replace('/((^|\n)>*)/', '\\1> ', trim($body));
                        $body = '<pre>' . htmlspecialchars($body, $this->htmlFuncFlags, self::ENCODING) . '</pre>';
                        // Re-set text width
                        $this->options['width'] = $pWidth;
                        // Replace content
                        $text = mb_substr($text, 0, $start - $diff)
                            . $body
                            . mb_substr($text, $end + mb_strlen($m[0]) - $diff);

                        $diff += $len + $taglen + mb_strlen($m[0]) - mb_strlen($body);
                        unset($body);
                    }
                } else {
                    if ($level == 0) {
                        $start = $m[1];
                        $taglen = mb_strlen($m[0]);
                    }
                    $level++;
                }
            }
        }
    }

    /**
     * Callback function for preg_replace_callback use.
     *
     * @param  array  $matches PREG matches
     * @return string
     */
    protected function pregCallback($matches)
    {
        $case = mb_strtolower($matches[1]);
        switch ($case) {
            case 'p':
                // Replace newlines with spaces.
                $para = str_replace("\n", " ", $matches[3]);

                // Trim trailing and leading whitespace within the tag.
                $para = trim($para);

                // Add trailing newlines for this para.
                return "\n" . $para . "\n";
            case 'br':
                return "\n";
            case 'b':
            case 'strong':
                return $this->toupper($matches[3]);
            case 'del':
                return $this->toStrike($matches[3]);
            case 'th':
                return $this->toupper("\t\t" . $matches[3] . "\n");
            case 'h':
                return $this->toupper("\n\n" . $matches[3] . "\n\n");
            case 'mj':
                return $this->toupper("\n\n" . $matches[2] . "\n\n");
            case 'mj-image':
            case 'mj-carousel-image':
                $showAltText = $this->options['imageAltText'];
                $showLinks = !!$this->options['links'];
                $alt = preg_match('/alt="([^"]*)"/', $matches[2], $altMatches) ? $altMatches[1] : '';
                $href = preg_match('/href="([^"]*)"/', $matches[2], $hrefMatches) ? $hrefMatches[1] : '';
                $display = $alt ? '[' . $alt . ']' : $href;
                if ($href) {
                    if ($showAltText || $showLinks) {
                        return $this->handleLink($href, $display, true);
                    }
                }
                if (!$alt || !$showAltText) {
                    return '';
                }
                return  $display;
            case 'mj-navbar':
                $baseUrl = trim($matches[2]);
                $links = explode('</mj-navbar-link>', $matches[3]);
                $hasLinks = false;
                $result = "\n\n";
                foreach ($links as $value) {
                    preg_match('/<mj-navbar-link[^>]*href=("|\')([^"\']+)\1([^>]*)>(.*)/i', $value, $link);
                    if ($link) {
                        $hasLinks = true;
                        $url = $baseUrl . $link[2];
                        $display = $link[4];
                        $result .= $this->handleLink($url, $display, true);
                    }
                }
                if (!$hasLinks) {
                    return '';
                }
                return $result . "\n\n";

            case 'a':
            case 'mj-button':
                $newLine = $case != 'a';
                return $this->handleLink($matches[3], $matches[5], $newLine, $matches[4]);
        }

        return '';
    }

    /**
     * Handle links.
     *
     * @param string $url
     * @param string $display
     * @param string $propertyAfterHref
     * @param boolean $newline
     * @return string
     */
    protected function handleLink($url, $display, $newline = false, $propertyAfterHref = '')
    {
        // override the link method
        $linkOverride = null;
        if ($propertyAfterHref && preg_match('/_html2text_link_(\w+)/', $propertyAfterHref, $linkOverrideMatch)) {
            $linkOverride = $linkOverrideMatch[1];
        }
        // Remove spaces in URL (#1487805)
        $url = str_replace(' ', '', $url);
        return $this->buildLinkList($url, $display, $linkOverride, $newline);
    }

    /**
     * Callback function for preg_replace_callback use in PRE content handler.
     *
     * @param  array  $matches PREG matches
     * @return string
     */
    protected function pregPreCallback(
        /** @noinspection PhpUnusedParameterInspection */
        $matches
    ) {
        return $this->preContent;
    }

    /**
     * Strtoupper function with HTML tags and entities handling.
     *
     * @param  string $str Text to convert
     * @return string Converted text
     */
    protected function toupper($str)
    {
        // string can contain HTML tags
        $chunks = preg_split('/(<[^>]*>)/', $str, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

        // convert toupper only the text between HTML tags
        foreach ($chunks as $i => $chunk) {
            if ($chunk[0] != '<') {
                $chunks[$i] = $this->strtoupper($chunk);
            }
        }

        return implode($chunks);
    }

    /**
     * Strtoupper multibyte wrapper function with HTML entities handling.
     *
     * @param  string $str Text to convert
     * @return string Converted text
     */
    protected function strtoupper($str)
    {
        $str = html_entity_decode($str, $this->htmlFuncFlags, self::ENCODING);
        $str = mb_strtoupper($str);
        $str = htmlspecialchars($str, $this->htmlFuncFlags, self::ENCODING);

        return $str;
    }

    /**
     * Helper function for DEL conversion.
     *
     * @param  string $text HTML content
     * @return string Converted text
     */
    protected function toStrike($str)
    {
        $rtn = '';
        for ($i = 0; $i < mb_strlen($str); $i++) {
            $chr = mb_substr($str, $i, 1);
            $combiningChr = chr(0xC0 | 0x336 >> 6) . chr(0x80 | 0x336 & 0x3F);
            $rtn .= $chr . $combiningChr;
        }
        return $rtn;
    }
}
