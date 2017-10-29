<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Modified: 07/10/2017
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

class UW_Cache extends UW_Base {
	private $_c = NULL;
	private $_kp = array();
	private $_context = 'default';

	public function __construct() {
		global $config;

		parent::__construct();

		/* Initialize all caching contexts */
		foreach ($config['cache'] as $context => $parameters)
			$this->_init($context);

		/* Set default context */
		$this->_context = 'default';
	}

	private function _init($context) {
		global $config;

		/* Set cache context */
		$this->_context = $context;

		/* If no cache system is configured, do not try to load it */
		if (!isset($config['cache'][$this->_context]) || !count($config['cache'][$this->_context])) {
			$config['cache'][$this->_context] = array();
			$config['cache'][$this->_context]['active'] = false;
			return;
		}

		/* If it is configured, but disabled, do not proceed */
		if ($config['cache'][$this->_context]['active'] !== true)
			return;

		/* Currently, only a single instance of memcached is supported */
		if ($config['cache'][$this->_context]['driver'] == 'memcached') {
			$this->_c[$this->_context] = new Memcached($config['cache'][$this->_context]['key_prefix']);
			$this->_c[$this->_context]->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);

			/* Only add servers if the list is empty.
			 * TODO: FIXME: Instead of counting, check for differences and change the server list accordingly.
			 */
			if (!count($this->_c[$this->_context]->getServerList()))
				$this->_c[$this->_context]->addServer($config['cache'][$this->_context]['host'], intval($config['cache'][$this->_context]['port']));
		}

		$this->_kp[$this->_context] = $config['cache'][$this->_context]['key_prefix'];
	}

	public function context() {
		return $this->_context;
	}

	public function load($context) {
		$this->_context = $context;

		return $this;
	}

	public function is_active() {
		global $config;

		return $config['cache'][$this->_context]['active'];
	}

	public function add($k, $v, $expiration = 0) {
		if ($this->is_active() !== true)
			return false;

		return $this->_c[$this->_context]->add($this->_kp[$this->_context] . $k, $v, $expiration);
	}

	public function set($k, $v, $expiration = 0) {
		if ($this->is_active() !== true)
			return false;

		return $this->_c[$this->_context]->set($this->_kp[$this->_context] . $k, $v, $expiration);
	}

	public function get($k) {
		if ($this->is_active() !== true)
			return false;

		return $this->_c[$this->_context]->get($this->_kp[$this->_context] . $k);
	}

	public function delete($k, $time = 0) {
		if ($this->is_active() !== true)
			return false;

		return $this->_c[$this->_context]->delete($this->_kp[$this->_context] . $k, $time);
	}

	public function flush($delay = 0) {
		if ($this->is_active() !== true)
			return false;

		return $this->_c[$this->_context]->flush($delay);
	}

	public function result() {
		if ($this->is_active() !== true)
			return false;

		return $this->_c[$this->_context]->getResultCode();
	}

	public function increment($k, $offset = 1, $initial_value = 0, $expiry = 0) {
		if ($this->is_active() !== true)
			return false;
		
		return $this->_c[$this->_context]->increment($this->_kp[$this->_context] . $k, $offset, $initial_value, $expiry);
	}

	public function decrement($k, $offset = 1, $initial_value = 0, $expiry = 0) {
		if ($this->is_active() !== true)
			return false;
		
		return $this->_c[$this->_context]->decrement($this->_kp[$this->_context] . $k, $offset, $initial_value, $expiry);
	}
}
