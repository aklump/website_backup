<?php

/**
 * @file Set the version in the Symfony Console controller.
 */
$controller = "bin/website_backup";
$content = file_get_contents($controller);
$content = preg_replace('#setVersion\((.+?)\)#', 'setVersion(\'' . $argv[2] . '\')', $content);
file_put_contents($controller, $content);
