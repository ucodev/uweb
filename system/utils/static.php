<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 24/09/2014
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

function static_base_dir() {
	return base_dir() . '/static';
}

function static_base_url() {
	return base_url() . '/static';
}

function static_css_dir() {
	return static_dir() . '/css';
}

function static_css_url() {
	return static_url() . '/css';
}

function static_images_dir() {
	return static_dir() . '/images';
}

function static_images_url() {
	return static_url() . '/images';
}

function static_js_dir() {
	return static_dir() . '/js';
}

function static_js_url() {
	return static_url() . '/js';
}

