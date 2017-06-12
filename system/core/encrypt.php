<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 11/06/2017
 * License: GPLv3
 */

/*
 * This file is part of uweb.
 *
 * uWeb - uCodev Low Footprint Web Framework (https://github.com/ucodev/uweb)
 * Copyright (C) 2014-2016  Pedro A. Hortas
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

 class UW_Encrypt extends UW_Base {
	private $_n_size;
	
	public function __construct() {
		global $config;
		$this->_n_size = mcrypt_get_iv_size($config['encrypt']['cipher'], $config['encrypt']['mode']);
	}
	
	public function encrypt($m, $k, $b64_encode = true) {
		global $config;

		/* Pad key */
		$k = substr(str_pad($k, $this->_n_size, "\0"), 0, $this->_n_size);

		$n = mcrypt_create_iv($this->_n_size, MCRYPT_RAND);
		$c = mcrypt_encrypt($config['encrypt']['cipher'], $k, $m, $config['encrypt']['mode'], $n);

		return ($b64_encode === true) ? base64_encode($n . $c) : ($n . $c);
	}
	
	public function encode($m, $b64_encode = false) {
		global $config;

		return $this->encrypt($m, $config['encrypt']['key'], $b64_encode);
	}

	public function decrypt($c, $k, $b64_decode = true) {
		global $config;

		/* Pad key */
		$k = substr(str_pad($k, $this->_n_size, "\0"), 0, $this->_n_size);

		if ($b64_decode === true)
			$c = base64_decode($c);

		$n = substr($c, 0, $this->_n_size);
		$c = substr($c, $this->_n_size);

		return mcrypt_decrypt($config['encrypt']['cipher'], $k, $c, $config['encrypt']['mode'], $n);
	}

	public function decode($c, $b64_decode = false) {
		global $config;

		return $this->decrypt($c, $config['encrypt']['key'], $b64_decode);
	}
}
