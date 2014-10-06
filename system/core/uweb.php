<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 06/10/2014
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

class UW_Base {
	public function __construct() {
		return;
	}
}

class UW_Encrypt extends UW_Base {
	private $_n_size;
	
	public function __construct() {
		global $config;
		$this->_n_size = mcrypt_get_iv_size($config['encrypt']['cipher'], $config['encrypt']['mode']);
	}
	
	public function encrypt($m, $k, $b64_encode = TRUE) {
		global $config;

		$n = mcrypt_create_iv($this->_n_size, MCRYPT_RAND);
		$c = mcrypt_encrypt($config['encrypt']['cipher'], $k, $m, $config['encrypt']['mode'], $n);

		return ($b64_encode === TRUE) ? base64_encode($n . $c) : ($n . $c);
	}
	
	public function decrypt($c, $k, $b64_decode = TRUE) {
		global $config;

		if ($b64_decode === TRUE)
			$c = base64_decode($c);

		$n = substr($c, 0, $this->_n_size);
		$c = substr($c, $this->_n_size);

		return mcrypt_decrypt($config['encrypt']['cipher'], $k, $c, $config['encrypt']['mode'], $n);
	}
}

class UW_Session extends UW_Base {
	private $_session_id = NULL;
	private $_session_data = array();
	private $_encryption = FALSE;

	private function _session_data_serialize($init_close = TRUE) {
		if ($init_close === TRUE)
			session_start();

		/* Encrypt session data if _encryption is enabled */
		if ($this->_encryption) {
			global $config;
			$cipher = new UW_Encrypt;

			$_SESSION['data'] = $cipher->encrypt(json_encode($this->_session_data), $config['encrypt']['key']);
		} else {
			$_SESSION['data'] = json_encode($this->_session_data);
		}

		if ($init_close === TRUE)
			session_write_close();
	}

	private function _init_load() {
		global $config;
		$this->_session_id = session_id();

		/* Evaluate if we're using encrypted sessions */
		$this->_encryption = $config['session']['encrypt'];

		/* Load user data */
		if (array_key_exists('data', $_SESSION)) {
			/* Decrypt session data if _encryption is enabled */
			if ($this->_encryption === TRUE) {
				global $config;
				$cipher = new UW_Encrypt;

				$this->_session_data = json_decode($cipher->decrypt($_SESSION['data'], $config['encrypt']['key']), TRUE);
				$this->_session_data_serialize(FALSE);
			} else {
				/* Unencrypted session */
				$this->_session_data = json_decode($_SESSION['data'], TRUE);
			}
		}
	}

	public function __construct() {
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

		/* Get the default cookie parameters */
		$cookie = session_get_cookie_params();

		/* Initialize cookie parameters */
		session_set_cookie_params(0, '/', $config['session']['cookie_domain'], FALSE, FALSE);

		/* Set custom cookie parameters */
		session_set_cookie_params(
			$config['session']['cookie_lifetime'] . ', ' . $config['session']['cookie_path'],
			$config['session']['cookie_domain'],
			$config['session']['cookie_secure'], $config['session']['cookie_httponly']);

		/* Name the session */
		session_name($config['session']['name']);
		
		/* Start the session */
		session_start();

		/* Load session data */
		$this->_init_load();

		/* Close the session */
		session_write_close();
	}

	public function set($variable, $value) {
		$this->_session_data[$variable] = $value;

		$this->_session_data_serialize();
	}

	public function get($variable) {
		if (!isset($this->_session_data[$variable]))
			return NULL;

		return $this->_session_data[$variable];
	}
	
	public function cleanup() {
		session_unset();
	}
	
	public function regenerate() {
		session_regenerate_id();
	}

	public function destroy() {
		session_unset();
		session_regenerate_id(TRUE);
		session_destroy();
	}
}

class UW_Database extends UW_Base {
	private $_db = NULL;
	private $_cur_db = NULL;
	private $_res = NULL;
	private $_stmt = NULL;

	public function __construct() {
		global $config;

		parent::__construct();

		/* Iterate over the configured databases */
		foreach ($config['database'] as $dbname => $dbdata) {
			/* Set default database (first ocurrence) */
			if (!$this->_cur_db)
				$this->_cur_db = $dbname;

			/* Try to connect to the database */
			try {
				/* FIXME: For MySQL and PostgreSQL drivers the following code will work fine.
				 *        Currently unsupported drivers: SQLServer and Oracle
				 */
				$this->_db[$dbname] =
					new PDO(
						$config['database'][$dbname]['driver'] . ':' .
						'host=' . $config['database'][$dbname]['host'] . ';' .
						'port=' . $config['database'][$dbname]['port'] . ';' .
						'dbname=' . $dbname,
						$config['database'][$dbname]['username'],
						$config['database'][$dbname]['password']
					);
			} catch (PDOException $e) {
				/* Something went wrong ... */
				error_log('Database connection error (dbname: ' . $dbname . '): ' . $e);
				header('HTTP/1.1 503 Service Unavailable');
				die('Unable to connect to database.');
			}
		}
	}
	
	public function __destruct() {
		/* Close connections */
		foreach ($this->_db as $dbname => $dbconn) {
			$this->_db[$dbname] = NULL;
		}
	}

	public function load($dbname) {
		if (in_array($dbname, $this->_db)) {
			$this->_cur_db = $dbname;
			return TRUE;
		} else {
			error_log('$this->db->load(): Attempting to load a database that is not properly configured: ' . $dbname);
			return FALSE;
		}
	}

	public function query($query, $data = NULL) {
		try {
			$this->_stmt = $this->_db[$this->_cur_db]->prepare($query);
			
			if (!$this->_stmt) {
				error_log('$this->db->query(): PDOStatement::prepare(): Failed.');
				return FALSE;
			}
		} catch (PDOException $e) {
			error_log('$this->db->query(): PDOStatement::prepare(): ' . $e);
			return FALSE;
		}

		return $this->_stmt->execute($data);
	}
	
	public function fetchone($assoc = TRUE) {
		return ($assoc == TRUE) ? $this->_stmt->fetch(PDO::FETCH_ASSOC) : $this->_stmt->fetch();
	}

	public function fetchall($assoc = TRUE) {
		return ($assoc == TRUE) ? $this->_stmt->fetchAll(PDO::FETCH_ASSOC) : $this->_stmt->fetchAll();
	}

	public function num_rows() {
		return $this->_stmt->rowCount();
	}
	
	public function last_insert_id() {
		return $this->_db[$this->_cur_db]->lastInsertId();
	}
	
	public function trans_begin() {
		$this->_db[$this->_cur_db]->beginTransaction();
	}
	
	public function trans_commit() {
		try {
			$this->_db[$this->_cur_db]->commit();
			
			return TRUE;
		} catch (PDOException $e) {
			error_log('$this->db->trans_commit(): PDO::commit(): ' . $e);
			return FALSE;
		}
	}
	
	public function trans_rollback() {
		$this->_db[$this->_cur_db]->rollBack();
	}
}

class UW_View extends UW_Base {
	public function load($file, $data) {
		/* Check if there's anything to extract */
		if ($data)
			extract($data, EXTR_PREFIX_SAME, "wddx");

		/* Unset $data variable as it's no longer required */
		unset($data);

		/* Load view from file */
		include('views/' . $file . '.php');
	}
}

class UW_Model {
	public $db = NULL;
	public $session = NULL;

	public function __construct() {
		/* Initialize system database controller */
		$this->db = new UW_Database;
		
		/* Initialize system session controller */
		$this->session = new UW_Session;
	}
	
	public function load($model) {
		if (!preg_match('/^[a-z0-9_]+$/', $model))
			return FALSE;

		eval('$this->' . $model . ' = new UW_' . ucfirst($model) . ';');
	}
}

class UW_Controller extends UW_Model {
	public $view = NULL;
	public $model = NULL;

	public function __construct() {
		parent::__construct();
		
		$this->model = $this;

		/* Initialize system view controller */
		$this->view = new UW_View;
	}
}

