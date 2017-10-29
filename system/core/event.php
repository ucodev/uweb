<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Author:   Pedro A. Hortas
 * Email:    pah@ucodev.org
 * Modified: 28/10/2017
 * License:  GPLv3
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

class UW_Event extends UW_Base {
    private $_connections = array();


	/** Construct **/

	public function __construct() {
		global $config;

		parent::__construct();

		foreach ($config['event'] as $context => $data) {
			/* Check if the driver is supported */
			if ($data['driver'] != 'redis') {
				$this->error('Event configuration only supports \'redis\' driver.');
				$this->output('500');
			}

			/* Initialize Redis object */
			$this->_connections[$context] = new Redis();

			/* Check if there's an authentication mechanism set */
			if ($data['password']) {
				if ($this->_connections[$context]->auth($data['password']) !== true) {
					$this->error('Unable to set authentication mechanism to event server: ' . $context);
					$this->output('502');
				}
			}

			/* Connect to Redis */
			if ($data['persitent']) {
				if ($this->_connections[$context]->pconnect($data['host'], $data['port']) !== true) {
					$this->error('Unable to connect to event server: ' . $context);
					$this->output('502');
				}
			} else {
				if ($this->_connections[$context]->connect($data['host'], $data['port']) !== true) {
					$this->error('Unable to connect to event server: ' . $context);
					$this->output('502');
				}
			}
		}
    }

	public function push($event, $context = 'default') {
		global $config;

		if ($this->_connections[$context]->rPush($config['event']['name'] . '_' . $context, is_array($event) ? json_encode($event) : $event) === false) {
			error_log('Failed to push event to event server: ' . $context);
			return false;
		}

		return true;
	}
}
