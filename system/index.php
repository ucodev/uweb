<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 17/03/2016
 * License: GPLv3
 */

/*
 * This file is part of uweb.
 *
 * uweb is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * uweb is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with uweb.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

ob_start();

/* Load configuration */
include('system/config/index.php');

/* Initialize globals */
$__controller = NULL;
$__function = NULL;
$__args = NULL;
$__args_list = '';

/* Get request URI */
$__uri = explode('/', $_SERVER['REQUEST_URI']);

/* Try to get the controller offset through index.php */
$__a_koffset = array_search('index.php', $__uri);

/* If index.php isn't present in the URI, assume the base dir as the offset */
if ($__a_koffset === false) {
	$__a_koffset = count($__uri);
	$__a_count = 0;
} else {
	$__a_count = count($__uri) - ($__a_koffset + 1);
}

/* Extract controller */
if (($__a_count >= 1) && $__uri[$__a_koffset + 1]) {
	$__controller = strtolower($__uri[$__a_koffset + 1]);

	if (!preg_match('/^[a-z0-9_]+$/', $__controller)) {
		header('HTTP/1.1 403 Forbidden');
		die('Controller name contains invalid characters.');
	}
}

/* Extract function */
if (($__a_count >= 2) && $__uri[$__a_koffset + 2]) {
	$__function = strtolower($__uri[$__a_koffset + 2]);

	if (!preg_match('/^[a-z0-9_]+$/', $__function)) {
		header('HTTP/1.1 403 Forbidden');
		die('Function name contains invalid characters.');
	}
}

/* Extract args */
if (($__a_count >= 3) && $__uri[$__a_koffset + 3]) {
	$__args = array_slice($__uri, $__a_koffset + 3);

	foreach ($__args as $__arg)
		$__args_list .= '\'' . str_replace('\'', '\\\'', $__arg) . '\',';

	$__args_list = rtrim($__args_list, ',');
}

/* Load system utilities */
include('system/utils/index.php');

/* Load libraries */
include('libraries/index.php');

/* Load system core controllers */
include('system/core/index.php');

/* Load user extensions */
include('user/index.php');

/* Set the configured default controller, if none was defined */
if (!$__controller)
	$__controller = $config['base']['controller'];

/* Load requested controller, if any */
if ($__controller) {
	include('application/controllers/' . $__controller . '.php');

	eval('$__r_ = new UW_' . ucfirst($__controller) . ';');

	/* Call requested function, if any */
	if (!$__function)
		$__function = 'index';

	eval('$__r_->' . $__function . '(' . $__args_list . ');');
} else {
	header('HTTP/1.1 400 Bad Request');
	die('No controller defined in the request. Nothing to process.<br />');
}

ob_flush();

