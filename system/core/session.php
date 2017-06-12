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

class UW_SessionHandlerDb implements SessionHandlerInterface {
	private $db = NULL;
	private $cache = NULL;

	public function __construct($db = NULL, $cache = NULL) {
		$this->db = $db;
		$this->cache = $cache;
	}

	public function open($save_path, $name) {
		global $config;

		$this->db->load($config['session']['sssh_db_alias']);

		return true;
	}

	public function close() {
		return true;
	}

	public function read($session_id) {
		global $config;

		/* Check if session data is cached */
		if ($this->cache->is_active()) {
			if ($this->cache->get('s_session_' . $session_id)) {
				return $this->cache->get('d_session_' . $session_id);
			}
		}

		/* Otherwise, fetch session data from database */
		$this->db->select($config['session']['sssh_db_field_session_data'] . ' AS session_data');

		$this->db->from($config['session']['sssh_db_table']);
		$this->db->where($config['session']['sssh_db_field_session_id'], $session_id);
		$this->db->where($config['session']['sssh_db_field_session_valid'], true);

		$q = $this->db->get();

		if (!$q->num_rows())
			return '';

		$row = $q->row_array();

		/* Refresh cache */
		if ($this->cache->is_active()) {
			$this->cache->set('s_session_' . $session_id, true);
			$this->cache->set('d_session_' . $session_id, $row['session_data']);
		}

		return $row['session_data'];
	}

	public function write($session_id, $session_data) {
		global $config;

		/* Invalidate cache entry, if any */
		if ($this->cache->is_active())
			$this->cache->delete('s_session_' . $session_id);

		$this->db->trans_begin();

		/* Check if session id already exists */
		$this->db->select($config['session']['sssh_db_field_session_valid'] . ',' . $config['session']['sssh_db_field_session_end_time']);
		$this->db->from($config['session']['sssh_db_table']);
		$this->db->where($config['session']['sssh_db_field_session_id'], $session_id);
		$q = $this->db->get();

		if (!$q->num_rows()) {
			/* Create the session */
			$this->db->insert($config['session']['sssh_db_table'], array(
				$config['session']['sssh_db_field_session_id'] => $session_id,
				$config['session']['sssh_db_field_session_valid'] => true,
				$config['session']['sssh_db_field_session_start_time'] => date('Y-m-d H:i:s'),
				$config['session']['sssh_db_field_session_change_time'] => date('Y-m-d H:i:s'),
				$config['session']['sssh_db_field_session_data'] => $session_data
			));
		} else {
			$row = $q->row_array();

			/* If the session is not valid or was already destroyed, return false */
			if (!$row[$config['session']['sssh_db_field_session_valid']]) {
				$this->db->trans_rollback();
				return false;
			}

			/* Update the current session data */
			$this->db->where($config['session']['sssh_db_field_session_id'], $session_id);
			$this->db->where($config['session']['sssh_db_field_session_valid'], true);

			$uq = $this->db->update($config['session']['sssh_db_table'], array(
				$config['session']['sssh_db_field_session_change_time'] => date('Y-m-d H:i:s'),
				$config['session']['sssh_db_field_session_data'] => $session_data
			));

			/* If no rows were affected, this means that session is invalid... so return false */
			if (!$uq->num_rows()) {
				$this->db->trans_rollback();
				return false;
			}
		}

		if ($this->db->trans_status() === false) {
			$this->db->trans_rollback();
			return false;
		}

		$this->db->trans_commit();

		/* Refresh cache */
		if ($this->cache->is_active()) {
			$this->cache->set('s_session_' . $session_id, true);
			$this->cache->set('d_session_' . $session_id, $session_data);
		}

		return true;
	}

	public function destroy($session_id) {
		global $config;

		/* Invalidate cache entry, if any */
		if ($this->cache->is_active())
			$this->cache->delete('s_session_' . $session_id);

		$this->db->trans_begin();

		$this->db->where($config['session']['sssh_db_field_session_id'], $session_id);

		$this->db->update($config['session']['sssh_db_table'], array(
			$config['session']['sssh_db_field_session_end_time'] => date('Y-m-d H:i:s'),
			$config['session']['sssh_db_field_session_data'] => ''
		));

		if ($this->db->trans_status() === false) {
			$this->db->trans_rollback();
			return false;
		}

		$this->db->trans_commit();

		return true;
	}

	public function gc($maxlifetime) {
		global $config;

		$this->db->trans_begin();

		$this->db->where($config['session']['sssh_db_field_session_change_time'] . ' <', date('Y-m-d H:i:s', time() + $maxlifetime));

		$this->db->update($config['session']['sssh_db_table'], array(
			$config['session']['sssh_db_field_session_end_time'] => date('Y-m-d H:i:s'),
			$config['session']['sssh_db_field_session_data'] => ''
		));

		if ($this->db->trans_status() === false) {
			$this->db->trans_rollback();
			return false;
		}

		$this->db->trans_commit();

		return true;
	}
}

class UW_Session extends UW_Base {
	private $_session_id = NULL;
	private $_session_data = array();
	private $_encryption = false;

	private function _session_start() {
		session_start();
	}

	private function _session_close() {
		session_write_close();
	}

	private function _session_data_serialize($session_start = true, $session_close = true) {
		/* Start the session */
		if ($session_start === true)
			$this->_session_start();

		/* Encrypt session data if _encryption is enabled */
		if ($this->_encryption) {
			global $config;
			$cipher = new UW_Encrypt;
			$_SESSION['data'] = $cipher->encrypt(json_encode($this->_session_data), $config['encrypt']['key']);
		} else {
			$_SESSION['data'] = json_encode($this->_session_data);
		}

		if ($session_close === true)
			$this->_session_close();
	}

	private function _session_data_unserialize($session_start = true, $session_close = true, $session_abort = false) {
		global $config;

		/* Start the session */
		if ($session_start === true)
			$this->_session_start();

		$this->_session_id = session_id();

		/* Evaluate if we're using encrypted sessions */
		$this->_encryption = $config['session']['encrypt'];

		/* Load user data */
		if (array_key_exists('data', $_SESSION)) {
			/* Decrypt session data if _encryption is enabled */
			if ($this->_encryption === true) {
				$cipher = new UW_Encrypt;

				/* NOTE: mcrypt_decrypt() returns a padded $m with trailing \0 to match $k length...
				 *       We need to rtrim() those \0, but only when we're sure they weren't there
				 *		 in the first place (which in this case, they were not because it's an JSON
				 *		 encoded string).
				 */
				$this->_session_data = json_decode(rtrim($cipher->decrypt($_SESSION['data'], $config['encrypt']['key']), "\0"), true);
			} else {
				/* Unencrypted session */
				$this->_session_data = json_decode($_SESSION['data'], true);
			}
		}

		/* Abort session? */
		if ($session_abort === true && $session_close === false)
			session_abort();

		/* Close the session */
		if ($session_close === true && $session_abort === false)
			$this->_session_close();
	}

	public function __construct($db = NULL, $cache = NULL) {
		global $config;

		/* Call the parent constructor */
		parent::__construct();

		/* Check if we're using sessions */
		if (!$config['session']['enable'])
			return ;

		/* Check if we can use sessions */
		if (session_status() == PHP_SESSION_DISABLED) {
			header("HTTP/1.1 403 Forbbiden");
			die("PHP Sessions are disabled.");
		}

		/* Change session handlers if database session data is enabled */
		if ($config['session']['sssh_db_enabled']) {
			$sssh = new UW_SessionHandlerDb($db, $cache);

			if (session_set_save_handler($sssh, true) === false) {
				header('HTTP/1.1 500 Internal Server Error');
				die('Unable to set session handler interface.');
			}
		}

		/* Get the default cookie parameters */
		$cookie = session_get_cookie_params();

		/* Initialize cookie parameters */
		session_set_cookie_params(0, '/', $config['session']['cookie_domain'], false, false);

		/* Set custom cookie parameters */
		session_set_cookie_params(
			$config['session']['cookie_lifetime'], $config['session']['cookie_path'],
			$config['session']['cookie_domain'],
			$config['session']['cookie_secure'], $config['session']['cookie_httponly']);

		/* Name the session */
		session_name($config['session']['name']);
	}

	public function set($variable, $value) {
		$this->_session_data_unserialize(true, false); /* Start the session, but dont close it */

		$this->_session_data[$variable] = $value;

		$this->_session_data_serialize(false, true); /* Close the session, without starting it */
	}

	public function set_userdata($variable, $value = NULL) {
		if ($value !== NULL) {
			$this->set($variable, $value);
		} else if (gettype($variable) == "array") {
			$this->_session_data_unserialize(true, false); /* Start the session, but dont close it */

			$this->_session_data = $variable; /* $variable should be an array */

			$this->_session_data_serialize(false, true); /* Close the session, without starting it */
		} else {
			header("HTTP/1.1 500 Internal Server Error");
			die("set_userdata(): First argument should be an array when no value is specified on second argument.");
		}
	}

	public function get($variable) {
		$this->_session_data_unserialize(true, false, true);

		if (!isset($this->_session_data[$variable]))
			return NULL;

		return $this->_session_data[$variable];
	}
	
	public function userdata($variable) {
		return $this->get($variable);
	}

	public function all_userdata() {
		$this->_session_data_unserialize(true, false, true);

		return $this->_session_data;
	}

	public function clear($variable) {
		$this->_session_data_unserialize(true, false); /* Start the session, but dont close it */

		unset($this->_session_data[$variable]);

		$this->_session_data_serialize(false, true); /* Close the session, without starting it */
	}

	public function unset_userdata($variable) {
		$this->clear($variable);
	}

	public function cleanup() {
		session_start();
		$_SESSION = array();
		session_unset(); /* Probably not required for newer versions of PHP */
		session_write_close();
	}
	
	public function regenerate($destroy_old_session = false) {
		session_start();
		session_regenerate_id($destroy_old_session);
		session_write_close();
	}

	public function destroy() {
		session_start();
		session_destroy();
		session_write_close();
	}
}
