<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 28/10/2018
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

function uri_remove_extra_slashes($value) {
	while (strpos($value, '//') !== false)
		$value = str_replace('//', '/', $value);

	return $value;
}

function base_dir() {
	global $__uri, $__a_koffset, $config;

	return (isset($config['base']['path']) && ($config['base']['path'] !== NULL))
		? uri_remove_extra_slashes($config['base']['path'] . '/')
		: uri_remove_extra_slashes(implode('/', array_slice($__uri, 0, $__a_koffset)) . '/');
}

function base_url($with_index = false) {
	global $config;

	if (isset($config['base']['url']) && ($config['base']['url'] !== NULL))
		return $config['base']['url'];

	$server_port = '';

	if (!isset($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != '80') {
		$server_port = ':' . $_SERVER['SERVER_PORT'];
	} else if (isset($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != '443') {
		$server_port = ':' . $_SERVER['SERVER_PORT'];
	}

	return 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . $server_port . uri_remove_extra_slashes('/' . base_dir()) . ((($with_index === true) && isset($config['base']['fallback_resource']) && ($config['base']['fallback_resource'] !== false)) ? '' : 'index.php/');
}

function current_url() {
	$server_port = '';

	if (!isset($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != '80') {
		$server_port = ':' . $_SERVER['SERVER_PORT'];
	} else if (isset($_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != '443') {
		$server_port = ':' . $_SERVER['SERVER_PORT'];
	}

	return 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . $server_port . $_SERVER['REQUEST_URI'];
}

function current_controller() {
	global $__controller;

	return $__controller;
}

function current_config() {
	global $config;

	return $config;
}

function redirect($directory, $with_index = true, $full_url = false) {
	if ($full_url) {
		header('location: ' . $directory);
	} else {
		header('location: ' . base_url() . uri_remove_extra_slashes(($with_index ? 'index.php/' : '') . $directory));
	}
}

function request_method() {
	return $_SERVER['REQUEST_METHOD'];
}

function remote_addr() {
	if (isset($_SERVER['HTTP_X_CLIENT_IP']) && !empty($_SERVER['HTTP_X_CLIENT_IP']))
		return $_SERVER['HTTP_X_CLIENT_IP'];

	if (isset($_SERVER['HTTP_X_REAL_IP']) && !empty($_SERVER['HTTP_X_REAL_IP']))
		return $_SERVER['HTTP_X_REAL_IP'];

	if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR']))
		return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);

	return $_SERVER['REMOTE_ADDR'];
}

function is_linear_array($array) {
	if ($array === NULL)
		return NULL;

	if (gettype($array) != 'array')
		return false;

	if (count(array_filter(array_keys($array), 'is_string')) > 0)
		return false;
	
	/* NOTE: Empty arrays are considered linear */
	return true;
}

function is_castable_integer($value) {
	/* If $value is of integer type, this is a strict integer */
	if (gettype($value) == 'integer')
		return true;

	/* If $value is not of integer type and there is no string to process, this is not a castable integer */
	if (gettype($value) != 'string') /* NULL types are caught here */
		return false;

	/* If the string is empty, this is not a castable integer */
	if (!strlen($value))
		return false;

	/* Attempt to convert string to integer */
	$i = intval($value);

	/* In the event the result is 0, grant that the string actually contained a single character "0" in it, otherwise this is not a strictly castable integer */
	if (!$i) {
		if (strlen($value) > 1)
			return false;

		if ($value[0] === '0')
			return true;

		return false;
	}

	/* This is a castable integer */
	return true;
}