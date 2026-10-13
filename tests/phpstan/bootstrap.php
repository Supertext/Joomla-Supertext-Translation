<?php

/**
 * PHPStan bootstrap: the constants Joomla defines before it loads the plugin.
 *
 * Without _JEXEC, PHPStan stops silently at the plugin's `\defined('_JEXEC') or die;`
 * whenever it loads one of those files, and the files after it aren't analysed.
 */

\defined('_JEXEC') || \define('_JEXEC', 1);
\defined('JPATH_ROOT') || \define('JPATH_ROOT', \dirname(__DIR__, 2) . '/.joomla');
\defined('JPATH_SITE') || \define('JPATH_SITE', JPATH_ROOT);
\defined('JPATH_ADMINISTRATOR') || \define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
