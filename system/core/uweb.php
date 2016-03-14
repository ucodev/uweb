<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 14/03/2016
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

	public function set_userdata($variable, $value = null) {
		if ($value) {
			$this->set($variable, $value);
		} else if (gettype($variable) == "array") {
			$this->_session_data = $variable; /* $variable should be an array */
			$this->_session_data_serialize();
		} else {
			header("HTTP/1.1 500 Internal Server Error");
			die("set_userdata(): First argument should be an array when no value is specified on second argument.");
		}
	}

	public function get($variable) {
		if (!isset($this->_session_data[$variable]))
			return NULL;

		return $this->_session_data[$variable];
	}
	
	public function userdata($variable) {
		return $this->get($variable);
	}

	public function all_userdata() {
		return $this->_session_data;
	}

	public function clear($variable) {
		unset($this->_session_data[$variable]);
		$this->_session_data_serialize();
	}

	public function unset_userdata($variable) {
		$this->clear($variable);
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
	private $_q_select = NULL;
	private $_q_distinct = false;
	private $_q_from = NULL;
	private $_q_join = NULL;
	private $_q_where = NULL;
	private $_q_group_by = NULL;
	private $_q_having = NULL;
	private $_q_order_by = NULL;
	private $_q_limit = NULL;
	private $_q_args = array();
	private $_q_objects = NULL;

	private function _q_reset_all() {
		/* Reset query data */
		$this->_q_select = NULL;
		$this->_q_distinct = false;
		$this->_q_from = NULL;
		$this->_q_join = NULL;
		$this->_q_where = NULL;
		$this->_q_group_by = NULL;
		$this->_q_having = NULL;
		$this->_q_order_by = NULL;
		$this->_q_limit = NULL;
		$this->_q_args = array();
	}

	public function select($fields = NULL, $enforce = true) {
		/* TODO: enforce not currently supported */
		if (!$fields)
			return;

		/* Escape field if enforce is set */
		if ($enforce) {
			$field_parsed = explode(',', $fields);
			$fields = '`' . implode('`,`', $field_parsed) . '`';
		}

		$this->_q_select = 'SELECT ' . $fields . ' ';

		return $this;
	}

	public function distinct() {
		$this->_q_distinct = true;

		return $this;
	}

	public function from($table = NULL, $enforce = true) {
		if (!$table)
			return;

		if ($enforce) {
			$this->_q_from = ' FROM `' . $table . '` ';
		} else {
			$this->_q_from = ' FROM ' . $table . ' ';
		}

		return $this;
	}

	public function join($table = NULL, $on = NULL, $type = 'inner') {
		if (!$table || !$on)
			return;

		if (!$this->_q_join)
			$this->_q_join = '';

		$this->_q_join .= ' ' . strtoupper($type) . ' JOIN `' . $table . '` ON ' . $on . ' ';

		return $this;
	}

	public function where($field_cond = NULL, $value = NULL, $enforce = true, $or = false, $in = false, $like = false, $not = false) {
		/* Sanity checks */
		if ($in && $like) {
			header('HTTP/1.1 500 Internal Server Error');
			die('where(): IN and LIKE are mutual exclusive.');
		}

		if ($not && !$in && !$like) {
			header('HTTP/1.1 500 Internal Server Error');
			die('where(): NOT only accepted when IN or LIKE are used.');
		}

		if (!$field_cond)
			return;

		if (!$this->_q_where)
			$this->_q_where = ' WHERE ';
		else if ($or)
			$this->_q_where = ' OR ';
		else
			$this->_q_where = ' AND ';

		/* Escape field if enforce is set */
		if ($enforce) {
			$field_cond_parsed = explode(' ', $field_cond);
			$field_cond = '`' . $field_cond_parsed[0] . '` ' . implode(' ', array_slice($field_cond_parsed, 1));
		}

		$this->_q_where .= ' ' . $field_cond;

		if ($not)
			$this->_q_where .= ' NOT ';

		if ($in) {
			$this->_q_where .= ' IN (';
			for ($i = 0; $i < count($value); $i ++) {
				$this->_q_where .= '?,';
			}
			$this->_q_where = rtrim($this->_q_where, ',') . ') ';
		} else if ($like) {
			$this->_q_where .= ' LIKE ? ';
		} else if (strpos($field_cond, '=') || strpos($field_cond, '>') || strpos($field_cond, '<')) {
			$this->_q_where .= ' ? ';
		} else {
			$this->_q_where .= ' = ' . $value;
		}

		/* Push value into data array */
		if ($in) {
			$this->_q_args = array_merge($this->_q_args, $value);
		} else {
			array_push($this->_q_args, $value);
		}

		return $this;
	}

	public function or_where($field_cond = NULL, $value = NULL, $enforce = true) {
		return $this->where($field_cond, $value, $enforce, true /* OR */);
	}

	public function or_where_in($field_cond = NULL, $value = NULL, $enforce = true) {
		return $this->where($field_cond, $value, $enforce, true /* OR */, true /* IN */);
	}

	public function or_where_not_in($field_cond = NULL, $value = NULL, $enforce = true) {
		return $this->where($field_cond, $value, $enforce, true /* OR */, true /* IN */, false /* like */, true /* NOT */);
	}

	public function where_in($field_cond = NULL, $value = NULL, $enforce = true) {
		return $this->where($field_cond, $value, $enforce, false /* or */, true /* IN */);
	}

	public function where_not_in($field_cond = NULL, $value = NULL, $enforce = true) {
		return $this->where($field_cond, $value, $enforce, false /* or */, true /* IN */, false /* like */, true /* NOT */);
	}

	public function like($field_cond = NULL, $value = NULL, $enforce = true) {
		return $this->where($field_cond, $value, $enforce, false /* or */, false /* in */, true /* LIKE */);
	}

	public function or_like($field_cond = NULL, $value = NULL, $enforce = true) {
		return $this->where($field_cond, $value, $enforce, true /* OR */, false /* in */, true /* LIKE */);
	}

	public function not_like($field_cond = NULL, $value = NULL, $enforce = true) {
		return $this->where($field_cond, $value, $enforce, false /* or */, false /* in */, true /* LIKE */, true /* NOT */);
	}

	public function or_not_like($field_cond = NULL, $value = NULL, $enforce = true) {
		return $this->where($field_cond, $value, $enforce, true /* OR */, false /* in */, true /* LIKE */, true /* NOT */);
	}

	public function group_by($fields, $enforce = true) {
		if (gettype($fields) == "string") {
			$this->_q_group_by = ' GROUP BY `' . $fields . '` ';
		} else if (gettype($fields) == "array") {
			if ($enforce) {
				/* Escape each field during implode (enforce) */
				$this->_q_group_by = ' GROUP BY `' . implode('`,`', $fields) . '`';
			} else {
				$this->_q_group_by = ' GROUP BY ' . implode(',', $fields);
			}
		} else {
			header('HTTP/1.1 500 Internal Server Error');
			die('group_by(): Invalid argument type.');
		}

		return $this;
	}

	public function having($fields_cond, $enforce = true, $or = false) {
		if (!$fields_cond)
			return;

		if (gettype($fields) == "string") {
			$this->_q_having = ' HAVING ' . $fields_cond . ' ';
		} else if (gettype($fields) == "array") {
			$this->_q_having = '';
			foreach ($fields_cond as $k => $v) {
				if ($this->_q_having) {
					if ($or) {
						$this->_q_having .= ' OR ';
					} else {
						$this->_q_having .= ', ';
					}
				}

				/* TODO: Escape fields if enforce is set */

				/* Check if there's already a comparator */
				if (strpos($fields_cond, '=') || strpos($fields_cond, '>') || strpos($fields_cond, '<'))
					$this->_q_having .= ' ' . $k . ' \'' . $v . '\' ';
				else
					$this->_q_having .= ' ' . $k . ' = \'' . $v . '\' '; /* If not, assume = as default */
			}

			$this->_q_having .= ' HAVING ' . $this->_q_having;
		}

		return $this;
	}

	public function or_having($fields_cond, $enforce = true) {
		return $this->having($fields_cond, $enforce, true);
	}

	public function order_by($field, $order, $enforce = true) {
		/* TODO: Check whether enforce is set and disable field escape accordingly */
		if (!$this->_q_order_by)
			$this->_q_order_by = ' ORDER BY `' . $field . '` ' . $order;
		else
			$this->_q_order_by .= ', `' . $field . '` ' . $order;

		return $this;
	}

	public function limit($limit, $offset = NULL) {
		/* TODO: These arguments should also be merged into the prepared statement data */
		if ($offset)
			$this->_q_limit = ' LIMIT ' . $offset . ', ' . $limit;
		else
			$this->_q_limit = ' LIMIT ' . $limit;

		return $this;
	}

	public function get_compiled_select($table = NULL) {
		$query = NULL;
		$data = NULL;

		if ($this->_q_objects)
			return $this->_q_objects;

		if (!$table) {
			/* SELECT */
			if (!$this->_q_select) {
				$query = 'SELECT * ';
			} else {
				$query  = $this->_q_select . ' ';
			}
			
			/* DISTINCT */
			if ($this->_q_distinct)
				$query .= ' DISTINCT ';

			/* FROM */
			if (!$this->_q_from) {
				header('HTTP/1.1 500 Internal Server Error');
				die('get_compiled_select(): No argument supplied ($table) and no from() was called.');
			} else {
				$query .= ' ' . $this->_q_from . ' ';
			}

			/* JOIN */
			if ($this->_q_join)
				$query .= ' ' . $this->_q_join . ' ';

			/* WHERE */
			if ($this->_q_where)
				$query .= ' ' . $this->_q_where . ' ';

			/* GROUP BY */
			if ($this->_q_group_by)
				$query .= ' ' . $this->_q_group_by . ' ';

			/* HAVING */
			if ($this->_q_having)
				$query .= ' ' . $this->_q_having . ' ';

			/* ORDER BY */
			if ($this->_q_order_by)
				$query .= ' ' . $this->_q_order_by . ' ';

			/* LIMIT */
			if ($this->_q_limit)
				$query .= ' ' . $this->_q_limit . ' ';

			$data = $this->_q_args;

			/* Reset query data */
			$this->_q_reset_all();
		} else {
			$query = 'SELECT * FROM `' . $table . '`';
		}

		/* Store query objects */
		$this->_q_objects = array($query, $data);

		/* Return the prepared statement objects */
		return $this->_q_objects;
	}

	public function get($table = NULL) {
		$query_data = $this->get_compiled_select($table);

		/* Reset all stored query elements */
		$this->_q_reset_all();

		/* Perform Query with Prepared Statement */
		return $this->query($query_data[0], $query_data[1]);
	}

	public function get_where($table, $fields_cond, $limit = NULL, $offset = NULL) {
		$query = NULL;
		$data = array();

		/* SELECT */
		$query = 'SELECT * FROM `' . $table . '` WHERE ';

		/* WHERE */
		$where_cond = '';
		foreach ($fields_cond as $k => $v) {
			if ($where_cond) {
				$where_cond .= ' AND ';
			}

			/* TODO: Escape fields if enforce is set */

			/* Check if there's already a comparator */
			if (strpos($fields_cond, '=') || strpos($fields_cond, '>') || strpos($fields_cond, '<'))
				$where_cond .= ' ' . $k . ' ? ';
			else
				$where_cond .= ' ' . $k . ' = ? '; /* If not, assume = as default */

			/* Push value into data array */
			array_push($data, $v);
		}

		$query .= $where_cond;

		/* LIMIT */
		if ($offset)
			$query .= ' LIMIT ' . $offset . ', ' . $limit;
		else
			$query .= ' LIMIT ' . $limit;

		/* Reset all stored query elements */
		$this->_q_reset_all();

		/* Perform Query with Prepared Statement */
		return $this->query($query, $data);
	}

	public function count_all_results($table = NULL) {
		$this->get($table);
		return $this->num_rows();
	}

	public function count_all($table) {
		return $this->count_all_results($table);
	}

	public function insert($table, $data) {
		$values = '(';
		$query = 'INSERT INTO `' . $table . '` (';

		foreach ($data as $k => $v) {
			$query .= '`' . $k . '`,';
			$values .= $v . ',';
		}

		$values = rtrim($values, ',') . ')';
		$query = rtrim($query, ',') . ') VALUES ' . $values;

		/* Reset all stored query elements */
		$this->_q_reset_all();

		return $this->query($query);
	}

	public function update($table, $data) {
		$data = array();
		$query = 'UPDATE `' . $table . '` SET ';

		foreach ($data as $k => $v) {
			$query .= ' `' . $k . '` = ?, ';
			array_push($data, $v);
		}

		$query = rtrim($query, ',');

		/* WHERE */
		$query .= ' ' . $this->_q_where;

		/* Reset all stored query elements */
		$this->_q_reset_all();

		/* Aggregate args */
		array_push($data, $this->_q_args);

		/* Execute query */
		return $this->query($query, $data);
	}

	public function delete($table, $fields_cond = NULL, $enforce = true) {
		$data = array();
		$query = 'DELETE FROM `' . $table . '`';

		/* WHERE */
		if ($fields_cond) {
			$where_cond = '';
			foreach ($fields_cond as $k => $v) {
				if ($where_cond) {
					$where_cond .= ' AND ';
				}

				/* TODO: Escape fields if enforce is set */

				/* Check if there's already a comparator */
				if (strpos($fields_cond, '=') || strpos($fields_cond, '>') || strpos($fields_cond, '<'))
					$where_cond .= ' ' . $k . ' ? ';
				else
					$where_cond .= ' ' . $k . ' = ? '; /* If not, assume = as default */

				/* Push value into data array */
				array_push($data, $v);
			}

			$query .= ' ' . $where_cond;
		} else {
			$query .= ' ' . $this->_q_where;
			$data = $this->_q_args;
		}

		/* Reset all stored query elements */
		$this->_q_reset_all();

		return $this->query($query, $data);
	}

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

	public function close() {
		$this->__destruct();
	}

	public function load($dbname, $return_self = false) {
		if (in_array($dbname, $this->_db)) {
			$this->_cur_db = $dbname;

			if ($return_self === true)
				return $this;

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

		if ($data)
			$this->_stmt->execute($data);
		else
			$this->_stmt->execute();

		/* Reset query objects */
		$this->_q_objects = NULL;

		/* Return this object */
		return $this;
	}
	
	public function fetchone($assoc = TRUE) {
		return ($assoc == TRUE) ? $this->_stmt->fetch(PDO::FETCH_ASSOC) : $this->_stmt->fetch();
	}

	public function row() {
		return $this->fetchone(FALSE);
	}

	public function row_array() {
		return $this->fetchone(TRUE);
	}

	public function fetchall($assoc = TRUE) {
		return ($assoc == TRUE) ? $this->_stmt->fetchAll(PDO::FETCH_ASSOC) : $this->_stmt->fetchAll();
	}

	public function result() {
		return $this->fetchall(false);
	}

	public function result_array() {
		return $this->fetchall();
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
	public function load($file, $data = NULL, $export_content = false) {
		/* Check if there's anything to extract */
		if ($data !== NULL)
			extract($data, EXTR_PREFIX_SAME, "wddx");

		/* Unset $data variable as it's no longer required */
		unset($data);

		/* Load view from file */
		if ($export_content) {
			ob_start();
			include('application/views/' . $file . '.php');
			$content = ob_get_contents();
			ob_end_clean();
			return $content;
		} else {
			include('application/views/' . $file . '.php');
			return true;
		}
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

		return true;
	}
}

/* Alias class for loading methods */
class UW_Load extends UW_Model {
	public $db = NULL;
	public $view = NULL;
	public $model = NULL;

	public function __construct($db, $model, $view) {
		/* Initialize system database controller */
		$this->db = $db;
		
		/* Initialize model class */
		$this->model = $model;

		/* Initialize system view controller */
		$this->view = $view;
	}

	public function view($file, $data = NULL, $export_content = false) {
		return $this->view->load($file, $data, $export_content);
	}

	public function model($model) {
		return $this->model->load($model);
	}

	public function database($database, $return_self = false) {
		return $this->database->load($database, $return_self);
	}
}

class UW_Controller extends UW_Model {
	public $view = NULL;
	public $model = NULL;

	public function __construct() {
		parent::__construct();
		
		/* Initialize model class */
		$this->model = $this;

		/* Initialize system view controller */
		$this->view = new UW_View;

		/* Initialize load class */
		$this->load = new UW_Load($this->db, $this->model, $this->view);
	}
}

