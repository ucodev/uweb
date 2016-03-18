<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 18/03/2016
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
	
	public function encrypt($m, $k, $b64_encode = true) {
		global $config;

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

class UW_Session extends UW_Base {
	private $_session_id = NULL;
	private $_session_data = array();
	private $_encryption = false;

	private function _session_data_serialize($session_start = true, $session_close = true) {
		if ($session_start === true)
			session_start();

		/* Encrypt session data if _encryption is enabled */
		if ($this->_encryption) {
			global $config;
			$cipher = new UW_Encrypt;
			$_SESSION['data'] = $cipher->encrypt(json_encode($this->_session_data), $config['encrypt']['key']);
		} else {
			$_SESSION['data'] = json_encode($this->_session_data);
		}

		if ($session_close === true)
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
			if ($this->_encryption === true) {
				global $config;
				$cipher = new UW_Encrypt;

				$this->_session_data = json_decode($cipher->decrypt($_SESSION['data'], $config['encrypt']['key']), true);
				$this->_session_data_serialize(false, false); /* Do not start nor close the session as it was/will be performed by __construct() */
			} else {
				/* Unencrypted session */
				$this->_session_data = json_decode($_SESSION['data'], true);
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
		session_set_cookie_params(0, '/', $config['session']['cookie_domain'], false, false);

		/* Set custom cookie parameters */
		session_set_cookie_params(
			$config['session']['cookie_lifetime'], $config['session']['cookie_path'],
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

	public function set_userdata($variable, $value = NULL) {
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
		session_regenerate_id(true);
		session_destroy();
	}
}

class UW_Database extends UW_Base {
	private $_db = NULL;
	private $_cur_db = NULL;
	private $_res = NULL;
	private $_stmt = NULL;
	private $_cfg_use_stmt = true;
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
	private $_trans_status_invoked = false; /* Used to indicate if trans_status() function was used prior to trans_commit() [Old API] */

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

	private function _has_special($value) {
		/* TODO: Should a better approach (Prehaps regex? Or now we have two problems?) be implemented here? */
		if (strpos($value, '#') || strpos($value, '(') || strpos($value, ')') || strpos($value, ',') ||
			    strpos($value, '/*') || strpos($value, '--') ||
				strpos($value, ';')  || strpos($value, '`')  ||
				strpos($value, '\'') || strpos($value, '"'))
			return true;

		return false;
	}

	private function _query_aggregate_args($query, $data = NULL) {
		if (!$data)
			return $query;

		$q = explode('?', $query);

		if ((count($q) - 1) != count($data)) {
			header('HTTP/1.1 500 Internal Server Error');
			die('_query_aggregate_args(): Query and Data counts do not match.');
		}

		$aggregate = '';

		for ($i = 0; $i < count($q); $i ++) {
			/* Check if we've reached the last slice */
			if ($i == (count($q) - 1)) {
				$aggregate .= $q[$i];
				break;
			}

			/* Aggregate data value to query */
			$aggregate .= $q[$i] . '\'' . $data[$i] . '\'';
		}

		return $aggregate;
	}

	private function _convert_boolean($value) {
		if (gettype($value) == "boolean") {
			return $value === true ? 1 : 0;
		}

		return $value;
	}

	public function select($fields = NULL, $enforce = true) {
		if (!$fields) {
			header('HTTP/1.1 500 Internal Server Error');
			die('select(): No fields selected.');
		}

		/* Escape field if enforce is set */
		if ($enforce) {
			/* $fields shall not contain any spaces ' ' */
			$fields = str_replace(' ', '', $fields);

			/* Boom */
			$field_parsed = explode(',', $fields);

			/* $field_parsed shall not contain any comments */
			foreach ($field_parsed as $field) {
				if ($this->_has_special($field)) {
					header('HTTP/1.1 500 Internal Server Error');
					die('select(): Enforced select() shall not contain any comments');
				}
			}

			/* Glue it */
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
		if (!$table) {
			header('HTTP/1.1 500 Internal Server Error');
			die('from(): No table specified.');
		}

		if ($enforce) {
			/* $table shall not contain any whitespaces nor comments */
			if ($this->_has_special($table) || strstr($table, ' ')) {
				header('HTTP/1.1 500 Internal Server Error');
				die('select(): Enforced select() shall not contain any comments');
			}

			$this->_q_from = ' FROM `' . $table . '` ';
		} else {
			$this->_q_from = ' FROM ' . $table . ' ';
		}

		return $this;
	}

	public function join($table = NULL, $on = NULL, $type = 'INNER', $enforce = true) {
		if (!$table || !$on) {
			header('HTTP/1.1 500 Internal Server Error');
			die('join(): Missing required arguments.');
		}

		if (!$this->_q_join)
			$this->_q_join = '';

		$type = strtoupper($type);

		if ($type != "INNER" || $type != "LEFT" || $type != "RIGHT") {
			header('HTTP/1.1 500 Internal Server Error');
			die('join(): $type must be one of INNER, LEFT or RIGHT.');
		}

		/* Escape and filter on enforce */
		if ($enforce) {
			/* $table shall not contain any whitespaces nor comments */
			if ($this->_has_special($table) || strstr($table, ' ')) {
				header('HTTP/1.1 500 Internal Server Error');
				die('join(): Enforced join() shall not contain any comments nor whitespaces on $table name.');
			}

			/* $on shall not contain any comments */
			if ($this->_has_special($on)) {
				header('HTTP/1.1 500 Internal Server Error');
				die('join(): Enforced join() shall not contain any comments on $on clause.');
			}

			$this->_q_join .= ' ' . strtoupper($type) . ' JOIN `' . $table . '` ON ' . $on . ' ';
		} else {
			$this->_q_join .= ' ' . strtoupper($type) . ' JOIN ' . $table . ' ON ' . $on . ' ';
		}

		return $this;
	}

	public function where($field_cond = NULL, $value = NULL, $enforce = true, $or = false, $in = false, $like = false, $not = false, $is_null = false, $is_not_null = false, $between = false) {
		/* Sanity checks */
		if ($in && $like) {
			header('HTTP/1.1 500 Internal Server Error');
			die('where(): IN and LIKE are mutual exclusive.');
		}

		if ($not && !$in && !$like) {
			header('HTTP/1.1 500 Internal Server Error');
			die('where(): NOT only accepted when IN or LIKE are used.');
		}

		if (!$field_cond) {
			header('HTTP/1.1 500 Internal Server Error');
			die('where(): No fields were specified.');
		}

		if (!$this->_q_where)
			$this->_q_where = ' WHERE ';
		else if ($or)
			$this->_q_where = ' OR ';
		else
			$this->_q_where = ' AND ';

		/* Escape field if enforce is set */
		if ($enforce) {
			if ($this->_has_special($field_cond)) {
				header('HTTP/1.1 500 Internal Server Error');
				die('where(): Field names cannot contain comments when enfoce is used.');
			}

			$field_cond_parsed = explode(' ', $field_cond); /* Expected to have 2 arguments (field name and comparator) */
			$field_cond = '`' . $field_cond_parsed[0] . '` ' . implode(' ', array_slice($field_cond_parsed, 1));
		}

		$this->_q_where .= ' ' . $field_cond;

		/* Check the special cases of [ IS NULL / IS NOT NULL ] */
		if ($value === NULL) {
			if ($is_null && !strpos($field_cond, ' ')) {
				$this->_q_where .= ' IS NULL ';
			} else if ($is_not_null && !strpos($field_cond, ' ')) {
				$this->_q_where .= ' IS NOT NULL ';
			} else {
				header('HTTP/1.1 500 Internal Server Error');
				die('where(): For NULL comparations, use is_null(), is_not_null(), or_is_null() and or_is_not_null() functions and do not use any comparators on first parameter.');
			}

			return $this;
		}

		if ($not)
			$this->_q_where .= ' NOT ';

		if ($between) {
			$this->_q_where .= ' BETWEEN ? AND ? ';

			$value[0] = $this->_convert_boolean($value[0]);
			$value[1] = $this->_convert_boolean($value[1]);
		} else if ($in) {
			$this->_q_where .= ' IN (';
			for ($i = 0; $i < count($value); $i ++) {
				/* Convert booleans */
				$value[$i] = $this->_convert_boolean($value[$i]);

				/* Add prepared statement argument indicator */
				$this->_q_where .= '?,';
			}
			$this->_q_where = rtrim($this->_q_where, ',') . ') ';
		} else if ($like) {
			$this->_q_where .= ' LIKE ? ';
		} else if (strpos($field_cond, '=') || strpos($field_cond, '>') || strpos($field_cond, '<')) {
			$this->_q_where .= ' ? ';
		} else {
			$this->_q_where .= ' = ? ';
		}

		/* Push value into data array */
		if ($in || $between) {
			/* $value is an array */
			$this->_q_args = array_merge($this->_q_args, $value);
		} else {
			/* $value is a string */
			array_push($this->_q_args, $this->_convert_boolean($value));
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

	public function is_null($field_cond = NULL, $enforce = true) {
		return $this->where($field_cond, NULL, $enforce, false /* or */, false /* in */, false /* like */, false /* not */, true /* IS NULL */);
	}

	public function or_is_null($field_cond = NULL, $enforce = true) {
		return $this->where($field_cond, NULL, $enforce, true /* OR */, false /* in */, false /* like */, false /* not */, true /* IS NULL */);
	}

	public function is_not_null($field_cond = NULL, $enforce = true) {
		return $this->where($field_cond, NULL, $enforce, false /* or */, false /* in */, false /* like */, false /* not */, false /* is null */, true /* IS NOT NULL */);
	}

	public function or_is_not_null($field_cond = NULL, $enforce = true) {
		return $this->where($field_cond, NULL, $enforce, true /* OR */, false /* in */, false /* like */, false /* not */, false /* is null */, true /* IS NOT NULL */);
	}

	public function between($field_cond = NULL, $value1 = NULL, $value2 = NULL, $enforce = true) {
		return $this->where($field_cond, array($value1, $value2), $enforce, false /* or */, false /* in */, false /* like */, false /* not */, false /* is null */, false /* is not null */, true /* BETWEEN */);
	}

	public function not_between($field_cond = NULL, $value1 = NULL, $value2 = NULL, $enforce = true) {
		return $this->where($field_cond, array($value1, $value2), $enforce, false /* or */, false /* in */, false /* like */, true /* NOT */, false /* is null */, false /* is not null */, true /* BETWEEN */);
	}

	public function or_between($field_cond = NULL, $value1 = NULL, $value2 = NULL, $enforce = true) {
		return $this->where($field_cond, array($value1, $value2), $enforce, true /* OR */, false /* in */, false /* like */, false /* not */, false /* is null */, false /* is not null */, true /* BETWEEN */);
	}

	public function or_not_between($field_cond = NULL, $value1 = NULL, $value2 = NULL, $enforce = true) {
		return $this->where($field_cond, array($value1, $value2), $enforce, true /* OR */, false /* in */, false /* like */, true /* NOT */, false /* is null */, false /* is not null */, true /* BETWEEN */);
	}

	public function group_by($fields, $enforce = true) {
		if (!$fields) {
			header('HTTP/1.1 500 Internal Server Error');
			die('group_by(): No fields were specified.');
		}

		if (gettype($fields) == "string") {
			$fields_list = explode(',', $fields);

			if ($enforce) {
				$fields = '`' . implode('`,`', $fields) . '`';
			} else {
				$fields = implode(',', $fields);
			}

			$this->_q_group_by = ' GROUP BY ' . $fields . ' ';
		} else if (gettype($fields) == "array") {
			if ($enforce) {
				/* Grant that there aren't any comments to block */
				if (gettype($fields) == "string") {
					if ($this->_has_special($fields)) {
						header('HTTP/1.1 500 Internal Server Error');
						die('group_by(): Enforced function shall not contain comments in it.');
					}
				} else if (gettype($fields) == "array") {
					foreach ($fields as $field) {
						if ($this->_has_special($field)) {
							header('HTTP/1.1 500 Internal Server Error');
							die('group_by(): Enforced function shall not contain comments in it.');
						}
					}
				} else {
					header('HTTP/1.1 500 Internal Server Error');
					die('group_by(): Illegal type for $fields.');
				}

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

	public function having($fields_cond, $value = null, $enforce = true, $or = false) {
		if (!$fields_cond) {
			header('HTTP/1.1 500 Internal Server Error');
			die('having(): No fields were specified.');
		}

		$data = array();

		if (gettype($fields_cond) == "string") {
			if ($value) {
				/* Value is not part of first argument */
				if (strpos($fields_cond, '=') || strpos($fields_cond, '>') || strpos($fields_cond, '<')) {
					if ($enforce) {
						/* Check if there are any comments to block */
						if ($enforce) {
							if ($this->_has_special($fields_cond)) {
								header('HTTP/1.1 500 Internal Server Error');
								die('having(): Enforced function shall not contain comments in it.');
							}
						}

						/* Separate fields by ' ' (only 2 fields expected) and rejoin them by escaping the first */
						$field = explode(' ', $fields_cond);
						$fields_cond = ' `' . $field[0] . '` ' . $field[1];
					}

					$this->_q_having = ' ' . $fields_cond . ' ? ';
					array_push($this->_q_args, $this->_convert_boolean($value));
				} else {
					if ($enforce) {
						/* Check if there are any comments to block */
						if ($enforce) {
							if ($this->_has_special($fields_cond)) {
								header('HTTP/1.1 500 Internal Server Error');
								die('having(): Enforced function shall not contain comments in it.');
							}
						}

						$this->_q_having = ' `' . $fields_cond . '` = ? ';
						array_push($this->_q_args, $this->_convert_boolean($value));
					} else
						$this->_q_having = ' ' . $fields_cond . ' = ? ';
						array_push($this->_q_args, $this->_convert_boolean($value));
				}
			} else {
				/* Value is part of the first argument and enforce value is disregarded */
				$this->_q_having = ' ' . $fields_cond . ' ';
			}
		} else if (gettype($fields_cond) == "array") {
			$this->_q_having = '';
			foreach ($fields_cond as $k => $v) {
				/* Check if there are any comments to block */
				if ($enforce) {
					if ($this->_has_special($k)) {
						header('HTTP/1.1 500 Internal Server Error');
						die('having(): Enforced function shall not contain comments in it.');
					}
				}

				if ($this->_q_having) {
					if ($or) {
						$this->_q_having .= ' OR ';
					} else {
						$this->_q_having .= ', ';
					}
				}

				/* Check if there's already a comparator */
				if (strpos($k, '=') || strpos($k, '>') || strpos($k, '<')) {
					/* Escape fields if enforce is set */
					if ($enforce) {
						/* NOTE: $k must have a space separating field name from comparator */
						$c = explode(' ', $k);

						$k = '`' . $c[0] . '`' . ' ' . $c[1];
					}

					$this->_q_having .= ' ' . $k . ' ? ';
					array_push($this->_q_args, $this->_convert_boolean($v));
				} else {
					/* Escape fields if enforce is set */
					if ($enforce)
						$this->_q_having .= ' `' . $k . '` = ? '; /* If no comparator, assume = as default */
					else
						$this->_q_having .= ' ' . $k . ' = ? '; /* If no comparator, assume = as default */

					array_push($this->_q_args, $this->_convert_boolean($v));
				}
			}

			$this->_q_having .= ' HAVING ' . $this->_q_having;
		}

		return $this;
	}

	public function or_having($fields_cond, $value = null, $enforce = true) {
		return $this->having($fields_cond, $value, $enforce, true);
	}

	public function order_by($field, $order = 'ASC', $enforce = true) {
		if (!$field) {
			header('HTTP/1.1 500 Internal Server Error');
			die('order_by(): No fields were specified.');
		}

		/* Validate $order */
		if (!$order != 'ASC' || $order != 'DESC') {
			header('HTTP/1.1 500 Internal Server Error');
			die('order_by(): $order shall only assume ASC or DESC.');
		}

		/* Include keyword or separator, based on previous _q_order_by value */
		if ($this->_q_order_by) {
			$this->_q_order_by .= ', ';
		} else {
			$this->_q_order_by = ' ORDER BY ';
		}

		/* Extra validation and escaping if $enforce is set */
		if ($enforce) {
			if ($this->_has_special($field)) {
				header('HTTP/1.1 500 Internal Server Error');
				die('order_by(): Enforced function shall not contain comments in it.');
			}
			$this->_q_order_by .= ' `' . $field . '` ' . $order;
		} else {
			$this->_q_order_by .= ' ' . $field . ' ' . $order;
		}

		return $this;
	}

	public function limit($limit, $offset = NULL, $enforce = true) {
		if (!$limit) {
			header('HTTP/1.1 500 Internal Server Error');
			die('limit(): No limit was specified.');
		}

		if ($enforce) {
			/* Validate fields */
			if ($this->_has_special($limit) || $this->_has_special($offset)) {
				header('HTTP/1.1 500 Internal Server Error');
				die('limit(): Enforced function shall not contain comments in it.');
			}
		}
		
		/* Grant that parameters are integers */
		$limit = intval($limit);
		$offset = intval($offset);

		if ($offset) {
			$this->_q_limit = ' LIMIT ' . $offset . ', ' . $limit . ' ';
		} else {
			$this->_q_limit = ' LIMIT ' . $limit . ' ';
		}

		return $this;
	}

	public function get_compiled_select($table = NULL, $enforce = true) {
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
			/* $table shall not contain any whitespaces nor comments */
			if ($enforce) {
				if ($this->_has_special($table) || strstr($table, ' ')) {
					header('HTTP/1.1 500 Internal Server Error');
					die('get_compiled_select(): Enforced functions shall not contain any comments in their protected arguments.');
				}

				$query = 'SELECT * FROM `' . $table . '`';
			} else {
				$query = 'SELECT * FROM ' . $table;
			}
		}

		/* Store query objects */
		$this->_q_objects = array($query, $data);

		/* Return the prepared statement objects */
		return $this->_q_objects;
	}

	public function get_compiled_select_str($table = NULL, $enforce = true) {
		$query_obj = $this->get_compiled_select($table, $enforce);

		return $this->_query_aggregate_args($query_obj[0], $query_obj[1]);
	}

	public function get($table = NULL, $enforce = true) {
		$query_data = $this->get_compiled_select($table, $enforce);

		/* Reset all stored query elements */
		$this->_q_reset_all();

		/* Execute Query */
		return $this->query($query_data[0], $query_data[1]);
	}

	public function get_where($table, $fields_cond, $limit = NULL, $offset = NULL, $enforce = true) {
		$query = NULL;
		$data = array();

		/* SELECT */
		if ($enforce) {
			/* $table shall not contain any whitespaces nor comments */
			if ($this->_has_special($table) || strstr($table, ' ')) {
				header('HTTP/1.1 500 Internal Server Error');
				die('get_where(): Enforced functions shall not contain any comments in their protected arguments.');
			}

			$query = 'SELECT * FROM `' . $table . '` ';
		} else {
			$query = 'SELECT * FROM ' . $table . ' ';
		}

		/* WHERE */
		foreach ($fields_cond as $k => $v)
			$this->where($k, $v, $enforce);

		/* Merge args and query */
		$data = array_merge($data, $this->_q_args);
		$query .= ' ' . $this->_q_where . ' ';

		/* LIMIT */
		$this->limit($limit, $offset);

		$query .= ' ' . $this->_q_limit . ' ';

		/* Reset all stored query elements */
		$this->_q_reset_all();

		/* Execute Query */
		return $this->query($query, $data);
	}

	public function count_all_results($table) {
		$this->get($table);
		return $this->num_rows();
	}

	public function count_all($table) {
		return $this->count_all_results($table);
	}

	public function insert($table, $kv, $enforce = true) {
		if (!$table) {
			header('HTTP/1.1 500 Internal Server Error');
			die('insert(): No table was specified.');
		}

		if (!$kv) {
			header('HTTP/1.1 500 Internal Server Error');
			die('insert(): No K/V pairs were specified.');
		}

		if ($enforce) {
			/* $table shall not contain any whitespaces nor comments */
			if ($this->_has_special($table) || strstr($table, ' ')) {
				header('HTTP/1.1 500 Internal Server Error');
				die('insert(): Enforced functions shall not contain any comments in their protected arguments.');
			}
		}

		$values = '(';
		$data = array();

		if ($enforce) {
			$query = 'INSERT INTO `' . $table . '` (';
		} else {
			$query = 'INSERT INTO ' . $table . ' (';
		}

		/* Iterate k/v */
		foreach ($kv as $k => $v) {
			if ($enforce && $this->_has_special($k)) {
				header('HTTP/1.1 500 Internal Server Error');
				die('insert(): Enforced functions shall not contain any comments in their protected arguments (K/V).');
			}

			$query .= '`' . $k . '`,';
			$values .= '?,';
			array_push($data, $this->_convert_boolean($v));
		}

		$values = rtrim($values, ',') . ')';
		$query = rtrim($query, ',') . ') VALUES ' . $values;

		/* Reset all stored query elements */
		$this->_q_reset_all();

		return $this->query($query, $data);
	}

	public function update($table, $kv, $enforce = true) {
		if (!$table) {
			header('HTTP/1.1 500 Internal Server Error');
			die('update(): No table was specified.');
		}

		if (!$kv) {
			header('HTTP/1.1 500 Internal Server Error');
			die('update(): No K/V pairs were specified.');
		}

		if ($enforce) {
			/* $table shall not contain any whitespaces nor comments */
			if ($this->_has_special($table) || strstr($table, ' ')) {
				header('HTTP/1.1 500 Internal Server Error');
				die('update(): Enforced functions shall not contain any comments in their protected arguments.');
			}
		}

		$data = array();

		if ($enforce) {
			$query = 'UPDATE `' . $table . '` SET ';
		} else {
			$query = 'UPDATE ' . $table . ' SET ';
		}

		foreach ($kv as $k => $v) {
			if ($enforce && $this->_has_special($k)) {
				header('HTTP/1.1 500 Internal Server Error');
				die('update(): Enforced functions shall not contain any comments in their protected arguments (K/V).');
			}

			$query .= ' `' . $k . '` = ?,';
			array_push($data, $this->_convert_boolean($v));
		}

		$query = rtrim($query, ',');

		/* WHERE */
		$query .= ' ' . $this->_q_where;

		/* Aggregate args */
		$data = array_merge($data, $this->_q_args);

		/* Reset all stored query elements */
		$this->_q_reset_all();

		/* Execute query */
		return $this->query($query, $data);
	}

	public function delete($table, $fields_cond = NULL, $enforce = true) {
		if (!$table) {
			header('HTTP/1.1 500 Internal Server Error');
			die('delete(): No table was specified.');
		}

		if ($enforce) {
			/* $table shall not contain any whitespaces nor comments */
			if ($this->_has_special($table) || strstr($table, ' ')) {
				header('HTTP/1.1 500 Internal Server Error');
				die('delete(): Enforced functions shall not contain any comments in their protected arguments.');
			}
		}

		$data = array();

		if ($enforce) {
			$query = 'DELETE FROM `' . $table . '` ';
		} else {
			$query = 'DELETE FROM ' . $table . ' ';
		}

		/* WHERE */
		if ($fields_cond) {
			foreach ($fields_cond as $k => $v)
				$this->where($k, $v, $enforce);
		}

		$query .= ' ' . $this->_q_where;
		$data = array_merge($data, $this->_q_args);

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

			return true;
		} else {
			error_log('$this->db->load(): Attempting to load a database that is not properly configured: ' . $dbname);
			return false;
		}
	}

	public function query($query, $data = NULL) {
		if (!$query) {
			header('HTTP/1.1 500 Internal Server Error');
			die('query(): No query was specified.');
		}

		/* Wipe out any previous statement */
		if ($this->_stmt) {
			$this->_stmt->closeCursor();
			$this->_stmt = null;
		}

		if ($this->_cfg_use_stmt) {
			try {
				$this->_stmt = $this->_db[$this->_cur_db]->prepare($query);
				
				if (!$this->_stmt) {
					error_log('$this->db->query(): PDOStatement::prepare(): Failed.');
					header('HTTP/1.1 500 Internal Server Error');
					die('query(): PDOStatement::prepare(): Failed.');
				}
			} catch (PDOException $e) {
				error_log('$this->db->query(): PDOStatement::prepare(): ' . $e);
				header('HTTP/1.1 500 Internal Server Error');
				die('query(): PDOStatement::prepare(): Failed.');
			}

			if ($data) {
				if (!$this->_stmt->execute($data)) {
					header('HTTP/1.1 500 Internal Server Error');
					die('query(): Failed to execute prepared statement.');
				}
			} else {
				if (!$this->_stmt->execute()) {
					header('HTTP/1.1 500 Internal Server Error');
					die('query(): Failed to execute prepared statement.');
				}
			}
		} else {
			/* Execute query without prepared statement allocation */
			if (!($this->_stmt = $this->_db[$this->_cur_db]->query($this->_query_aggregate_args($query, $data)))) {
				header('HTTP/1.1 500 Internal Server Error');
				die('query(): Failed to execute query.');
			}
		}

		/* Reset query objects */
		$this->_q_objects = NULL;

		/* Return this object */
		return $this;
	}
	
	public function fetchone($assoc = true) {
		return ($assoc == true) ? $this->_stmt->fetch(PDO::FETCH_ASSOC) : $this->_stmt->fetch();
	}

	public function row() {
		return $this->fetchone(false);
	}

	public function row_array() {
		return $this->fetchone(true);
	}

	public function fetchall($assoc = true) {
		return ($assoc == true) ? $this->_stmt->fetchAll(PDO::FETCH_ASSOC) : $this->_stmt->fetchAll();
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
		/* Compatibility with old interface trans_status() */
		if ($this->_trans_status_invoked) {
			$this->_trans_status_invoked = false;
			return true;
		}

		try {
			$this->_db[$this->_cur_db]->commit();
			
			return true;
		} catch (PDOException $e) {
			error_log('$this->db->trans_commit(): PDO::commit(): ' . $e);
			return false;
		}
	}

	public function trans_status() { /* Old interface. Backward compatibility */
		$this->_trans_status_invoked = false;

		$ret = $this->trans_commit();

		$this->_trans_status_invoked = true;

		return $ret;
	}

	public function trans_rollback() {
		/* Compatibility with old interface trans_status() */
		$this->_trans_status_invoked = false;

		/* Perform the rollback */
		$this->_db[$this->_cur_db]->rollBack();
	}

	public function stmt_disable() {
		/* Disable prepared statements */
		$this->_cfg_use_stmt = false;
	}

	public function stmt_enable() {
		/* Enable prepared statements (enabled by default) */
		$this->_cfg_use_stmt = true;
	}
}

class UW_View extends UW_Base {
	public function load($file, $data = NULL, $export_content = false, $enforce = true) {
		/* If enforce is set, grant that no potential harmful tags are exported to the view */
		if ($enforce) {
			foreach ($data as $k => $v) {
				/* NOTE: This is only effective for string type values. Any other object won't be checked */
				if (gettype($v) == "string" && strpos(str_replace(' ', '', strtolower($v)), '<script')) {
					header('HTTP/1.1 500 Internal Server Error');
					die('load(): Unable to load views with <script> tags on their $data strings when $enforce is set to true (default).');
				}
			}
		}

		/* Check if there's anything to extract */
		if ($data !== NULL)
			extract($data, EXTR_PREFIX_SAME, "wddx");

		/* Unset $data variable as it's no longer required */
		unset($data);

		/* Validate filename */
		if ($enforce) {
			if (strpos($file, '../')) {
				header('HTTP/1.1 500 Internal Server Error');
				die('load(): Unable to load view files with \'../\' string on their names.');
			}
		}

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
	public $encrypt = NULL;

	public function __construct() {
		/* Initialize system database controller */
		$this->db = new UW_Database;
		
		/* Initialize system session controller */
		$this->session = new UW_Session;

		/* Initialize system encryption controller */
		$this->encrypt = new UW_Encrypt;
	}
	
	public function load($model, $is_library = false, $tolower = false) {
		if (!preg_match('/^[a-zA-Z0-9_]+$/', $model))
			return false;

		if ($is_library === true) {
			/* We're loading a library */
			eval('$this->' . ($tolower ? strtolower($model) : $model) . ' = new ' . $model . ';');
		} else {
			eval('$this->' . $model . ' = new UW_' . ucfirst($model) . ';');
		}

		return true;
	}
}

/* Alias class for loading methods (Old API compatibility) */
class UW_Load extends UW_Model {
	private $_db = NULL;
	private $_view = NULL;
	private $_model = NULL;
	private $_extension = NULL;
	private $_library = NULL;

	public function __construct($database, $model, $view, $extension, $library) {
		/* Initialize system database controller */
		$this->_database = $database;
		
		/* Initialize model class */
		$this->_model = $model;

		/* Initialize system view controller */
		$this->_view = $view;

		/* Initialize system extensions */
		$this->_extension = $extension;

		/* Initialize libraries */
		$this->_library = $library;
	}

	public function view($file, $data = NULL, $export_content = false) {
		return $this->_view->load($file, $data, $export_content);
	}

	public function model($model) {
		return $this->_model->load($model);
	}

	public function database($database, $return_self = false) {
		return $this->_database->load($database, $return_self);
	}

	public function extension($extension) {
		/* Extensions loading are treated as models, just a different name and a different directory */
		return $this->_model->load($extension);
	}

	public function library($library, $tolower = true) {
		/* Libraries loading are treated as models, with some minor changes (no UW_ prefix required on library main class and optional $tolower parameter) */
		return $this->_model->load($library, true, $tolower);
	}
}

class UW_Controller extends UW_Model {
	public $view = NULL;
	public $model = NULL;
	public $extension = NULL;
	public $library = NULL;
	public $load = NULL;

	public function __construct() {
		global $config;

		parent::__construct();
		
		/* Initialize model class */
		$this->model = $this;

		/* Initialize system view controller */
		$this->view = new UW_View;

		/* Initialize system extension class */
		$this->extension = $this; /* Extensions loading are treated as models, just a different name and a different directory */

		/* Initialize library class */
		$this->library = $this; /* Libraries loading are treated as models, just a different name and a different directory */

		/* Initialize load class */
		$this->load = new UW_Load($this->db, $this->model, $this->view, $this->extension, $this->library);

		/* Autoload configured libraries */
		foreach ($config['autoload']['libraries'] as $_lib)
			$this->load->library($_lib);

		/* Autoload configured extensions */
		foreach ($config['autoload']['extensions'] as $_ext)
			$this->load->extension($_ext);

		/* Autoload configured models */
		foreach ($config['autoload']['models'] as $_model)
			$this->load->model($_model);
	}
}

