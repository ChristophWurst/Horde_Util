--TEST--
Horde_String::length() tests
--FILE--
<?php

require_once dirname(__FILE__) . '/../../lib/Horde/Util.php';
require_once dirname(__FILE__) . '/../../lib/Horde/String.php';

echo Horde_String::length('Welcome', 'Big5'). "\n";
echo Horde_String::length('Welcome', 'Big5'). "\n";
echo Horde_String::length('�w��', 'Big5') . "\n";
echo Horde_String::length('歡��', 'utf-8') . "\n";

?>
--EXPECT--
7
7
2
3
