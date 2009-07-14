<?php
/**
 * Stream filter class to convert EOL characters.
 *
 * Usage:
 *   stream_filter_register('horde_eol', 'Horde_Util_Filter_Eol');
 *   stream_filter_[app|pre]pend($stream, 'horde_eol', $params);
 *
 * $params can contain the following:
 * <pre>
 * 'eol' - The EOL string to use.
 *         DEFAULT: <CR><LF> ("\r\n")
 * </pre>
 *
 * Copyright 2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Horde_Util
 */
class Horde_Util_Filter_Eol extends php_user_filter
{
    protected $_search;
    protected $_replace;

    public function onCreate()
    {
        $eol = isset($this->params['eol']) ? $this->params['eol'] : "\r\n";
        if (!strlen($eol)) {
            $this->_search = array("\r", "\n");
            $this->_replace = '';
        } elseif (in_array($eol, array("\r", "\n"))) {
            $this->_search = array("\r\n", ($eol == "\r") ? "\n" : "\r");
            $this->_replace = $eol;
        } else {
            $this->_search = array("\r\n", "\r", "\n");
            $this->_replace = array("\n", "\n", $eol);
        }

        return true;
    }

    public function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = str_replace($this->_search, $this->_replace, $bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }

}
