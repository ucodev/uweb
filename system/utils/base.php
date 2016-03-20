<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 20/03/2016
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

function uri_remove_extra_slashes($value) {
	while (strpos($value, '//') !== false)
		$value = str_replace('//', '/', $value);

	return $value;
}

function base_dir() {
	global $__uri, $__a_koffset;

	return uri_remove_extra_slashes(implode('/', array_slice($__uri, 0, $__a_koffset)) . '/');
}

function base_url() {
	$server_port = '';

	if (!isset($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != '80') {
		$server_port = ':' . $_SERVER['SERVER_PORT'];
	} else if (isset($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != '443') {
		$server_port = ':' . $_SERVER['SERVER_PORT'];
	}
	
	return 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . $server_port . uri_remove_extra_slashes('/' . base_dir());
}

function redirect($directory, $with_index = true) {
	header('Location: ' . base_url() . uri_remove_extra_slashes(($with_index ? 'index.php/' : '') . $directory));
}