<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 20/05/2017
 * License: GPLv3
 */

/*
 * This file is part of uweb.
 *
 * uWeb - uCodev Low Footprint Web Framework (https://github.com/ucodev/uweb)
 * Copyright (C) 2014-2017  Pedro A. Hortas
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

ob_start();

/* Include configuration */
include('system/config/index.php');
include('user/config/index.php');

/* Initialize globals */
$__objects = array();
$__objects['enabled'] = true;
$__objects['autoload'] = array();
$__objects['adhoc'] = array();
$__controller = NULL;
$__function = NULL;
$__args = NULL;
$__args_list = '';
$__argv = NULL;
$__path_dir = '';

/* Grant URI match the acceptable regex */
if (!preg_match($config['base']['acceptable_uri_regex'], $_SERVER['REQUEST_URI'])) {
	header('HTTP/1.1 400 Bad Request');
	die('URI contains invalid characters.');
}

/* Get request URI */
$__uri = explode('/', $_SERVER['REQUEST_URI']);

/* Check if a fallback resource is being used by webserver */
if (isset($config['base']['fallback_resource']) && ($config['base']['fallback_resource'] !== false)) {
	/* Check if a base path was configured (required when fallback resource is enabled) */
	if (isset($config['base']['path']) && ($config['base']['path'] !== NULL)) {
		/* Grant that the fallback resource isn't present in the request URI */
		if ($config['base']['fallback_enforce'] === true) {
			$fb_prefix = rtrim($config['base']['path'], '/') . '/' . $config['base']['fallback_resource'];

			if (substr($_SERVER['REQUEST_URI'], 0, strlen($fb_prefix)) == $fb_prefix) {
				header('HTTP/1.1 400 Bad Request');
				die('Fallback resource detected in the request URI');
			}
		} /* TODO: When fallback enforce is disabled, we should look for index.php on the URI */

		/* Get offset to controller segment based on configured base path */
		$__base_path = explode('/', rtrim($config['base']['path'], '/'));

		if (!count($__base_path)) {
			$__a_koffset = 0;
		} else {
			$__a_koffset = array_search(end($__base_path), $__uri);
		}
	} else {
		header('HTTP/1.1 500 Internal Server Error');
		die('Improper configuration detected: Fallback resrouce set, but no base path was configured.');
	}
} else {
	/* If no fallback resource is being used, check for an index.php on request URI */
	$__a_koffset = array_search('index.php', $__uri);

	if ($__a_koffset === false) {
		header('HTTP/1.1 500 Internal Server Error');
		die('Improper configuration detected: No fallback resource set and no index file present in the request URI');
	}
}

/* Count the number of segments */
$__a_count = count($__uri) - ($__a_koffset + 1);

/* Extract controller */
if (($__a_count >= 1) && $__uri[$__a_koffset + 1]) {
	$__controller = strtolower($__uri[$__a_koffset + 1]);

	if (!preg_match('/^[a-z0-9_]+$/', $__controller)) {
		header('HTTP/1.1 400 Bad Request');
		die('Controller name contains invalid characters.');
	}
}

/* Extract function */
if (($__a_count >= 2) && $__uri[$__a_koffset + 2]) {
	$__function = strtolower($__uri[$__a_koffset + 2]);

	if (!preg_match('/^[a-z0-9_]+$/', $__function)) {
		header('HTTP/1.1 400 Bad Request');
		die('Function name contains invalid characters.');
	}
}

/* Extract args */
if (($__a_count >= 3) && isset($__uri[$__a_koffset + 3])) {
	$__args = array_slice($__uri, $__a_koffset + 3);
}

/* Include system utilities */
include('system/utils/index.php');

/* Include libraries */
include('system/libraries/index.php');

/* Include system core controllers */
include('system/core/index.php');

/* Include user extensions */
include('user/index.php');

/* Set the configured default controller, if none was defined */
if (!$__controller)
	$__controller = $config['base']['controller'];

/* Load requested controller, if any */
if ($__controller) {
	/* There is a special "controller" (it doesn't really exists) named _static, which handles application/static/ files */
	if ($__controller == '_static') {
		/* Glue the path if there's any */
		if ($__args) {
			foreach ($__args as $__arg) {
				/* Check for .. on all arguments to avoid ../ paths */
				if (strstr($__arg, '..')) {
					header('HTTP/1.1 400 Bad Request');
					die('Static path contains ../ references, which are invalid.');
				}

				$__path_dir .= $__arg . '/';
			}

			/* Minor fix */
			$__path_dir = rtrim($__path_dir, '/');
		}

		/* If the requested path ends with .php extension, then we shall process the file as PHP */
		if (substr(end($__args), -4) == '.php') {
			require_once('application/static/' . $__function . '/' . $__path_dir);
		} else {
			/* Otherwise, just redirect to the real path */
			redirect('application/static/' . $__function . '/' . $__path_dir, false);
		}
	} else {
		/* This is a real controller */
		if (!file_exists('application/controllers/' . $__controller . '.php')) {
			header('HTTP/1.1 404 Not Found');
			die('No such controller: ' . $__controller);
		} else {
			include('application/controllers/' . $__controller . '.php');
		}

		eval('$__r_ = new ' . ucfirst($__controller) . ';');

		/* Call requested function, if any */
		if (!$__function) {
			$__function = 'index';
		} else if ($__function == '__construct') {
			header('HTTP/1.1 403 Forbidden');
			die('Calling __construct() methods directly from HTTP requests is not allowed.');
		} else if (ctype_digit($__function[0])) {
			/* If the first character of function name string is a digit, assume index as the function and prepend the argument
			 * to the argv. This is useful for RESTful interfaces, when omitting 'index' function name is preferable in order
			 * to minimize the URL.
			 */
			$__argv = array_merge(array($__function), $__args ? $__args : array()); /* Use the argument vector */
			$__function = 'index';
		}

		/* Glue the args if there's any */
		if ($__args && $__argv === NULL) {
			foreach ($__args as $__arg)
				$__args_list .= '\'' . str_replace('\'', '\\\'', $__arg) . '\',';

			/* Minor fix */
			$__args_list = rtrim($__args_list, ',');
		}

		/* Try to process the request */
		if ($__argv === NULL) {
			/* Invoke the function with the multiple arguments */
			eval('if (method_exists($__r_, \'' . $__function . '\')) { $__r_->' . $__function . '(' . $__args_list . '); } else { error_log(\'Undefined method: ' . ucfirst($__controller) . '::' . $__function . '()\'); header(\'HTTP/1.1 404 Not Found\'); die(\'No such function.\'); }');
		} else {
			/* Invoke the function wht the argument vector */
			eval('if (method_exists($__r_, \'' . $__function . '\')) { $__r_->' . $__function . '($__argv); } else { error_log(\'Undefined method: ' . ucfirst($__controller) . '::' . $__function . '()\'); header(\'HTTP/1.1 404 Not Found\'); die(\'No such function.\'); }');
		}

		/* Check if there were any errors and log them */
		if (($error = error_get_last())) {
			error_log('Type: ' . $error['type'] . ', Message: ' . $error['message'] . ', File: ' . $error['file'] . ', Line: ' . $error['line']);
			header('HTTP/1.1 500 Internal Server Error');
			die('An unhandled error occurred.');
		}
	}
} else {
	header('HTTP/1.1 400 Bad Request');
	die('No controller defined in the request. Nothing to process.<br />');
}

ob_flush();

