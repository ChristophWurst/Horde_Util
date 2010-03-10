<?php
/**
 * The Horde_String:: class provides static methods for charset and locale
 * safe string manipulation.
 *
 * Copyright 2003-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Jan Schneider <jan@horde.org>
 * @package Horde_Util
 */
class Horde_String
{
    /**
     * Default charset.
     *
     * @var string
     */
    static protected $_charset = 'iso-8859-1';

    /**
     * lower() cache.
     *
     * @var array
     */
    static protected $_lowers = array();

    /**
     * upper() cache.
     *
     * @var array
     */
    static protected $_uppers = array();

    /**
     * Sets a default charset that the methods will use if none is explicitly
     * specified.
     *
     * @param string $charset  The charset to use as the default one.
     */
    static public function setDefaultCharset($charset)
    {
        self::$_charset = $charset;
        if (Horde_Util::extensionExists('mbstring')) {
            $old_error = error_reporting(0);
            mb_regex_encoding(self::_mbstringCharset($charset));
            error_reporting($old_error);
        }
    }

    /**
     * Converts a string from one charset to another.
     *
     * Works only if either the iconv or the mbstring extension
     * are present and best if both are available.
     * The original string is returned if conversion failed or none
     * of the extensions were available.
     *
     * @param mixed $input  The data to be converted. If $input is an an array,
     *                      the array's values get converted recursively.
     * @param string $from  The string's current charset.
     * @param string $to    The charset to convert the string to. If not
     *                      specified, the global variable $_charset will
     *                      be used.
     *
     * @return mixed  The converted input data.
     */
    static public function convertCharset($input, $from, $to = null)
    {
        /* Don't bother converting numbers. */
        if (is_numeric($input)) {
            return $input;
        }

        /* Get the user's default character set if none passed in. */
        if (is_null($to)) {
            $to = self::$_charset;
        }

        /* If the from and to character sets are identical, return now. */
        if ($from == $to) {
            return $input;
        }
        $from = self::lower($from);
        $to = self::lower($to);
        if ($from == $to) {
            return $input;
        }

        if (is_array($input)) {
            $tmp = array();
            reset($input);
            while (list($key, $val) = each($input)) {
                $tmp[self::_convertCharset($key, $from, $to)] = self::convertCharset($val, $from, $to);
            }
            return $tmp;
        }

        if (is_object($input)) {
            // PEAR_Error objects are almost guaranteed to contain recursion,
            // which will cause a segfault in PHP.  We should never reach
            // this line, but add a check and a log message to help the devs
            // track down and fix this issue.
            if (is_a($input, 'PEAR_Error')) {
                Horde::logMessage('Called convertCharset() on a PEAR_Error object. ' . print_r($input, true), __FILE__, __LINE__, PEAR_LOG_DEBUG);
                return '';
            }
            $vars = get_object_vars($input);
            while (list($key, $val) = each($vars)) {
                $input->$key = self::convertCharset($val, $from, $to);
            }
            return $input;
        }

        if (!is_string($input)) {
            return $input;
        }

        return self::_convertCharset($input, $from, $to);
    }

    /**
     * Internal function used to do charset conversion.
     *
     * @param string $input  See self::convertCharset().
     * @param string $from   See self::convertCharset().
     * @param string $to     See self::convertCharset().
     *
     * @return string  The converted string.
     */
    static protected function _convertCharset($input, $from, $to)
    {
        /* Use utf8_[en|de]code() if possible and if the string isn't too
         * large (less than 16 MB = 16 * 1024 * 1024 = 16777216 bytes) - these
         * functions use more memory. */
        if (Horde_Util::extensionExists('xml') &&
            ((strlen($input) < 16777216) ||
             !Horde_Util::extensionExists('iconv') ||
             !Horde_Util::extensionExists('mbstring'))) {
            if (($to == 'utf-8') &&
                in_array($from, array('iso-8859-1', 'us-ascii'))) {
                return utf8_encode($input);
            }

            if (($from == 'utf-8') &&
                in_array($to, array('iso-8859-1', 'us-ascii'))) {
                return utf8_decode($input);
            }
        }

        /* Try UTF7-IMAP conversions. */
        if (($from == 'utf7-imap') || ($to == 'utf7-imap')) {
            try {
                if ($from == 'utf7-imap') {
                    return self::convertCharset(Horde_Imap_Client_Utf7imap::Utf7ImapToUtf8($input), 'UTF-8', $to);
                } else {
                    if ($from == 'utf-8') {
                        $conv = $input;
                    } else {
                        $conv = self::convertCharset($input, $from, 'UTF-8');
                    }
                    return Horde_Imap_Client_Utf7imap::Utf8ToUtf7Imap($conv);
                }
            } catch (Horde_Imap_Client_Exception $e) {
                return $input;
            }
        }

        /* Try iconv with transliteration. */
        if (Horde_Util::extensionExists('iconv')) {
            /* We need to tack an extra character temporarily because of a bug
             * in iconv() if the last character is not a 7 bit ASCII
             * character. */
            $oldTrackErrors = ini_set('track_errors', 1);
            unset($php_errormsg);
            $out = @iconv($from, $to . '//TRANSLIT', $input . 'x');
            $errmsg = isset($php_errormsg);
            ini_set('track_errors', $oldTrackErrors);
            if (!$errmsg) {
                return self::substr($out, 0, -1, $to);
            }
        }

        /* Try mbstring. */
        if (Horde_Util::extensionExists('mbstring')) {
            $old_error = error_reporting(0);
            $out = mb_convert_encoding($input, $to, self::_mbstringCharset($from));
            error_reporting($old_error);
            if (!empty($out)) {
                return $out;
            }
        }

        return $input;
    }

    /**
     * Makes a string lowercase.
     *
     * @param string $string   The string to be converted.
     * @param boolean $locale  If true the string will be converted based on
     *                         a given charset, locale independent else.
     * @param string $charset  If $locale is true, the charset to use when
     *                         converting. If not provided the current
     *                         charset.
     *
     * @return string  The string with lowercase characters.
     */
    static public function lower($string, $locale = false, $charset = null)
    {
        if ($locale) {
            if (Horde_Util::extensionExists('mbstring')) {
                if (is_null($charset)) {
                    $charset = self::$_charset;
                }
                $old_error = error_reporting(0);
                $ret = mb_strtolower($string, self::_mbstringCharset($charset));
                error_reporting($old_error);
                if (!empty($ret)) {
                    return $ret;
                }
            }
            return strtolower($string);
        }

        if (!isset(self::$_lowers[$string])) {
            $language = setlocale(LC_CTYPE, 0);
            setlocale(LC_CTYPE, 'C');
            self::$_lowers[$string] = strtolower($string);
            setlocale(LC_CTYPE, $language);
        }

        return self::$_lowers[$string];
    }

    /**
     * Makes a string uppercase.
     *
     * @param string $string   The string to be converted.
     * @param boolean $locale  If true the string will be converted based on a
     *                         given charset, locale independent else.
     * @param string $charset  If $locale is true, the charset to use when
     *                         converting. If not provided the current charset.
     *
     * @return string  The string with uppercase characters.
     */
    static public function upper($string, $locale = false, $charset = null)
    {
        if ($locale) {
            if (Horde_Util::extensionExists('mbstring')) {
                if (is_null($charset)) {
                    $charset = self::$_charset;
                }
                $old_error = error_reporting(0);
                $ret = mb_strtoupper($string, self::_mbstringCharset($charset));
                error_reporting($old_error);
                if (!empty($ret)) {
                    return $ret;
                }
            }
            return strtoupper($string);
        }

        if (!isset(self::$_uppers[$string])) {
            $language = setlocale(LC_CTYPE, 0);
            setlocale(LC_CTYPE, 'C');
            self::$_uppers[$string] = strtoupper($string);
            setlocale(LC_CTYPE, $language);
        }

        return self::$_uppers[$string];
    }

    /**
     * Returns a string with the first letter capitalized if it is
     * alphabetic.
     *
     * @param string $string   The string to be capitalized.
     * @param boolean $locale  If true the string will be converted based on a
     *                         given charset, locale independent else.
     * @param string $charset  The charset to use, defaults to current charset.
     *
     * @return string  The capitalized string.
     */
    static public function ucfirst($string, $locale = false, $charset = null)
    {
        if ($locale) {
            $first = self::substr($string, 0, 1, $charset);
            if (self::isAlpha($first, $charset)) {
                $string = self::upper($first, true, $charset) . self::substr($string, 1, null, $charset);
            }
        } else {
            $string = self::upper(substr($string, 0, 1), false) . substr($string, 1);
        }

        return $string;
    }

    /**
     * Returns part of a string.
     *
     * @param string $string   The string to be converted.
     * @param integer $start   The part's start position, zero based.
     * @param integer $length  The part's length.
     * @param string $charset  The charset to use when calculating the part's
     *                         position and length, defaults to current
     *                         charset.
     *
     * @return string  The string's part.
     */
    static public function substr($string, $start, $length = null,
                                  $charset = null)
    {
        if (is_null($length)) {
            $length = self::length($string, $charset) - $start;
        }

        if ($length == 0) {
            return '';
        }

        /* Try iconv. */
        if (Horde_Util::extensionExists('iconv')) {
            if (is_null($charset)) {
                $charset = self::$_charset;
            }

            $old_error = error_reporting(0);
            $ret = iconv_substr($string, $start, $length, $charset);
            error_reporting($old_error);

            /* iconv_substr() returns false on failure. */
            if ($ret !== false) {
                return $ret;
            }
        }

        /* Try mbstring. */
        if (Horde_Util::extensionExists('mbstring')) {
            if (is_null($charset)) {
                $charset = self::$_charset;
            }
            $old_error = error_reporting(0);
            $ret = mb_substr($string, $start, $length, self::_mbstringCharset($charset));
            error_reporting($old_error);

            /* mb_substr() returns empty string on failure. */
            if (strlen($ret)) {
                return $ret;
            }
        }

        return substr($string, $start, $length);
    }

    /**
     * Returns the character (not byte) length of a string.
     *
     * @param string $string  The string to return the length of.
     * @param string $charset The charset to use when calculating the string's
     *                        length.
     *
     * @return integer  The string's length.
     */
    static public function length($string, $charset = null)
    {
        if (is_null($charset)) {
            $charset = self::$_charset;
        }
        $charset = self::lower($charset);

        if ($charset == 'utf-8' || $charset == 'utf8') {
            return strlen(utf8_decode($string));
        }

        if (Horde_Util::extensionExists('mbstring')) {
            $old_error = error_reporting(0);
            $ret = mb_strlen($string, self::_mbstringCharset($charset));
            error_reporting($old_error);
            if (!empty($ret)) {
                return $ret;
            }
        }

        return strlen($string);
    }

    /**
     * Returns the numeric position of the first occurrence of $needle
     * in the $haystack string.
     *
     * @param string $haystack  The string to search through.
     * @param string $needle    The string to search for.
     * @param integer $offset   Allows to specify which character in haystack
     *                          to start searching.
     * @param string $charset   The charset to use when searching for the
     *                          $needle string.
     *
     * @return integer  The position of first occurrence.
     */
    static public function pos($haystack, $needle, $offset = 0,
                               $charset = null)
    {
        if (Horde_Util::extensionExists('mbstring')) {
            if (is_null($charset)) {
                $charset = self::$_charset;
            }
            $track_errors = ini_set('track_errors', 1);
            $old_error = error_reporting(0);
            $ret = mb_strpos($haystack, $needle, $offset, self::_mbstringCharset($charset));
            error_reporting($old_error);
            ini_set('track_errors', $track_errors);
            if (!isset($php_errormsg)) {
                return $ret;
            }
        }

        return strpos($haystack, $needle, $offset);
    }

    /**
     * Returns a string padded to a certain length with another string.
     * This method behaves exactly like str_pad() but is multibyte safe.
     *
     * @param string $input    The string to be padded.
     * @param integer $length  The length of the resulting string.
     * @param string $pad      The string to pad the input string with. Must
     *                         be in the same charset like the input string.
     * @param const $type      The padding type. One of STR_PAD_LEFT,
     *                         STR_PAD_RIGHT, or STR_PAD_BOTH.
     * @param string $charset  The charset of the input and the padding
     *                         strings.
     *
     * @return string  The padded string.
     */
    static public function pad($input, $length, $pad = ' ',
                               $type = STR_PAD_RIGHT, $charset = null)
    {
        $mb_length = self::length($input, $charset);
        $sb_length = strlen($input);
        $pad_length = self::length($pad, $charset);

        /* Return if we already have the length. */
        if ($mb_length >= $length) {
            return $input;
        }

        /* Shortcut for single byte strings. */
        if ($mb_length == $sb_length && $pad_length == strlen($pad)) {
            return str_pad($input, $length, $pad, $type);
        }

        switch ($type) {
        case STR_PAD_LEFT:
            $left = $length - $mb_length;
            $output = self::substr(str_repeat($pad, ceil($left / $pad_length)), 0, $left, $charset) . $input;
            break;

        case STR_PAD_BOTH:
            $left = floor(($length - $mb_length) / 2);
            $right = ceil(($length - $mb_length) / 2);
            $output = self::substr(str_repeat($pad, ceil($left / $pad_length)), 0, $left, $charset) .
                $input .
                self::substr(str_repeat($pad, ceil($right / $pad_length)), 0, $right, $charset);
            break;

        case STR_PAD_RIGHT:
            $right = $length - $mb_length;
            $output = $input . self::substr(str_repeat($pad, ceil($right / $pad_length)), 0, $right, $charset);
            break;
        }

        return $output;
    }

    /**
     * Wraps the text of a message.
     *
     * @param string $string         String containing the text to wrap.
     * @param integer $width         Wrap the string at this number of
     *                               characters.
     * @param string $break          Character(s) to use when breaking lines.
     * @param boolean $cut           Whether to cut inside words if a line
     *                               can't be wrapped.
     * @param string $charset        Character set to use when breaking lines.
     * @param boolean $line_folding  Whether to apply line folding rules per
     *                               RFC 822 or similar. The correct break
     *                               characters including leading whitespace
     *                               have to be specified too.
     *
     * @return string  String containing the wrapped text.
     */
    static public function wordwrap($string, $width = 75, $break = "\n",
                                    $cut = false, $charset = null,
                                    $line_folding = false)
    {
        /* Get the user's default character set if none passed in. */
        if (is_null($charset)) {
            $charset = self::$_charset;
        }

        $charset = self::_mbstringCharset($charset);
        $string = self::convertCharset($string, $charset, 'utf-8');
        $wrapped = '';

        while (self::length($string, 'utf-8') > $width) {
            $line = self::substr($string, 0, $width, 'utf-8');
            $string = self::substr($string, self::length($line, 'utf-8'), null, 'utf-8');

            // Make sure didn't cut a word, unless we want hard breaks anyway.
            if (!$cut && preg_match('/^(.+?)((\s|\r?\n).*)/us', $string, $match)) {
                $line .= $match[1];
                $string = $match[2];
            }

            // Wrap at existing line breaks.
            if (preg_match('/^(.*?)(\r?\n)(.*)$/u', $line, $match)) {
                $wrapped .= $match[1] . $match[2];
                $string = $match[3] . $string;
                continue;
            }

            // Wrap at the last colon or semicolon followed by a whitespace if
            // doing line folding.
            if ($line_folding &&
                preg_match('/^(.*?)(;|:)(\s+.*)$/u', $line, $match)) {
                $wrapped .= $match[1] . $match[2] . $break;
                $string = $match[3] . $string;
                continue;
            }

            // Wrap at the last whitespace of $line.
            $sub = $line_folding
                ? '(.+[^\s])'
                : '(.*)';

            if (preg_match('/^' . $sub . '(\s+)(.*)$/u', $line, $match)) {
                $wrapped .= $match[1] . $break;
                $string = ($line_folding ? $match[2] : '') . $match[3] . $string;
                continue;
            }

            // Hard wrap if necessary.
            if ($cut) {
                $wrapped .= $line . $break;
                continue;
            }

            $wrapped .= $line;
        }

        return self::convertCharset($wrapped . $string, 'utf-8', $charset);
    }

    /**
     * Wraps the text of a message.
     *
     * @param string $text        String containing the text to wrap.
     * @param integer $length     Wrap $text at this number of characters.
     * @param string $break_char  Character(s) to use when breaking lines.
     * @param string $charset     Character set to use when breaking lines.
     * @param boolean $quote      Ignore lines that are wrapped with the '>'
     *                            character (RFC 2646)? If true, we don't
     *                            remove any padding whitespace at the end of
     *                            the string.
     *
     * @return string  String containing the wrapped text.
     */
    static public function wrap($text, $length = 80, $break_char = "\n",
                                $charset = null, $quote = false)
    {
        $paragraphs = array();

        foreach (preg_split('/\r?\n/', $text) as $input) {
            if ($quote && (strpos($input, '>') === 0)) {
                $line = $input;
            } else {
                /* We need to handle the Usenet-style signature line
                 * separately; since the space after the two dashes is
                 * REQUIRED, we don't want to trim the line. */
                if ($input != '-- ') {
                    $input = rtrim($input);
                }
                $line = self::wordwrap($input, $length, $break_char, false, $charset);
            }

            $paragraphs[] = $line;
        }

        return implode($break_char, $paragraphs);
    }

    /**
     * Return a truncated string, suitable for notifications.
     *
     * @param string $text     The original string.
     * @param integer $length  The maximum length.
     *
     * @return string  The truncated string, if longer than $length.
     */
    static public function truncate($text, $length = 100)
    {
        return (self::length($text) > $length)
            ? self::substr($text, 0, $length - 3) . ' ...'
            : $text;
    }

    /**
     * Return an abbreviated string, with characters in the middle of the
     * excessively long string replaced by '...'.
     *
     * @param string $text     The original string.
     * @param integer $length  The length at which to abbreviate.
     *
     * @return string  The abbreviated string, if longer than $length.
     */
    static public function abbreviate($text, $length = 20)
    {
        return (self::length($text) > $length)
            ? self::substr($text, 0, round(($length - 3) / 2)) . '...' . self::substr($text, (($length - 3) / 2) * -1)
            : $text;
    }

    /**
     * Returns true if the every character in the parameter is an alphabetic
     * character.
     *
     * @param string $string   The string to test.
     * @param string $charset  The charset to use when testing the string.
     *
     * @return boolean  True if the parameter was alphabetic only.
     */
    static public function isAlpha($string, $charset = null)
    {
        if (!Horde_Util::extensionExists('mbstring')) {
            return ctype_alpha($string);
        }

        $charset = self::_mbstringCharset($charset);
        $old_charset = mb_regex_encoding();
        $old_error = error_reporting(0);

        if ($charset != $old_charset) {
            mb_regex_encoding($charset);
        }
        $alpha = !mb_ereg_match('[^[:alpha:]]', $string);
        if ($charset != $old_charset) {
            mb_regex_encoding($old_charset);
        }

        error_reporting($old_error);

        return $alpha;
    }

    /**
     * Returns true if ever character in the parameter is a lowercase letter in
     * the current locale.
     *
     * @param string $string   The string to test.
     * @param string $charset  The charset to use when testing the string.
     *
     * @return boolean  True if the parameter was lowercase.
     */
    static public function isLower($string, $charset = null)
    {
        return ((self::lower($string, true, $charset) === $string) &&
                self::isAlpha($string, $charset));
    }

    /**
     * Returns true if every character in the parameter is an uppercase letter
     * in the current locale.
     *
     * @param string $string   The string to test.
     * @param string $charset  The charset to use when testing the string.
     *
     * @return boolean  True if the parameter was uppercase.
     */
    static public function isUpper($string, $charset = null)
    {
        return ((self::upper($string, true, $charset) === $string) &&
                self::isAlpha($string, $charset));
    }

    /**
     * Performs a multibyte safe regex match search on the text provided.
     *
     * @param string $text     The text to search.
     * @param array $regex     The regular expressions to use, without perl
     *                         regex delimiters (e.g. '/' or '|').
     * @param string $charset  The character set of the text.
     *
     * @return array  The matches array from the first regex that matches.
     */
    static public function regexMatch($text, $regex, $charset = null)
    {
        if (!empty($charset)) {
            $regex = self::convertCharset($regex, $charset, 'utf-8');
            $text = self::convertCharset($text, $charset, 'utf-8');
        }

        $matches = array();
        foreach ($regex as $val) {
            if (preg_match('/' . $val . '/u', $text, $matches)) {
                break;
            }
        }

        if (!empty($charset)) {
            $matches = self::convertCharset($matches, 'utf-8', $charset);
        }

        return $matches;
    }

    /**
     * Workaround charsets that don't work with mbstring functions.
     *
     * @param string $charset  The original charset.
     *
     * @return string  The charset to use with mbstring functions.
     */
    static protected function _mbstringCharset($charset)
    {
        /* mbstring functions do not handle the 'ks_c_5601-1987' &
         * 'ks_c_5601-1989' charsets. However, these charsets are used, for
         * example, by various versions of Outlook to send Korean characters.
         * Use UHC (CP949) encoding instead. See, e.g.,
         * http://lists.w3.org/Archives/Public/ietf-charsets/2001AprJun/0030.html */
        if (in_array(self::lower($charset), array('ks_c_5601-1987', 'ks_c_5601-1989'))) {
            $charset = 'UHC';
        }

        return $charset;
    }

    /**
     * Convert a variable to UTF-8. Recursively handles inner variables.
     *
     * @param mixed $in  The variable to convert.
     *
     * @return mixed  The converted variable.
     */
    static public function convertToUtf8($in)
    {
        if (is_string($in)) {
            if (Horde_Util::extensionExists('xml')) {
                $in = utf8_encode($in);
            }
        } elseif (is_array($in)) {
            reset($in);
            while (list($key, $val) = each($in)) {
                $in[$key] = self::convertToUtf8($in[$key]);
            }
        } elseif (is_object($in)) {
            $in = self::cloneObject($in);
            foreach (get_object_vars($in) as $key => $val) {
                $in->$key = self::convertToUtf8($in->$key);
            }
        }

        return $in;
    }

}
