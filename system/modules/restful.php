<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 13/10/2016
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

class UW_Restful extends UW_Module {
	/** Private **/

	private $_codes = array(
		/* 2xx codes ... */
		'200' => 'OK',
		'201' => 'Created',
		'204' => 'No Content',
		/* 4xx codes ... */
		'403' => 'Forbidden',
		'404' => 'Not Found',
		'405' => 'Method Not Allowed',
		'406' => 'Not Acceptable',
		'409' => 'Conflict'
	);

	private $_methods = array(
		'GET',
		'POST',
		'PUT',
		'PATCH',
		'DELETE'
	);

	private $_headers = array();

	private function _headers_collect() {
		if (count($this->_headers))
			return $this->_headers;

		foreach ($_SERVER as $header => $value) {
			if (substr($header, 0, 5) != 'HTTP_')
				continue;

			$this->_headers[ucwords(strtolower(str_replace('_', '-', substr($header, 5))), '-')] = $value;
		}

		return $this->_headers;
	}


	/** Public **/

	public function method() {
		return request_method();
	}

	public function header($key = NULL, $value = NULL, $replace = true) {
		/* If a value is set... add this to the response headers */
		if ($value !== NULL) {
			header($key . ': ' . $value, $replace);
		} else if ($key !== NULL) { /* If a $key is set, but not a $value, return the value of $key */
			$this->_headers_collect();

			if (isset($this->_headers[$header]))
				return $this->_headers[$header];

			return NULL;
		} else { /* If not $key nor $value is set, return all the headers */
			$this->_headers_collect();

			return $this->_headers;
		}
	}

	public function input() {
		/* If the content type isn't set as application/json, we'll not accept this request */
		if (header('Content-Type') != 'application/json')
			$this->output('406');

		/* Fetch raw data */
		$raw_data = file_get_contents('php://input');

		/* Decode json data */
		$json_data = json_decode($raw_data, true);

		/* If we're unable to decode the JSON data, this is a bad request */
		if ($json_data === NULL)
			$this->output('400');

		/* Return the decoded data */
		return $json_data;
	}

	public function code($code, $protocol = 'HTTP/1.1') {
		header($protocol . ' ' . $code . ' ' . $this->_codes[$code]);
	}

	public function output($code, $entity = NULL) {
		$this->code($code);

		/* Check if there's data to be sent as the response body */
		if ($entity !== NULL) {
			$this->header('Content-Type', 'application/json');
			echo($entity);
		}

		/* Terminate execution */
		exit();
	}

	public function process(&$ctrl, $arg = NULL) {
		/* Check if this is an allowed method */
		if (!in_array($this->method(), $this->_methods))
			$this->output('405'); /* Method not allowed */

		switch ($this->method()) {
			case 'GET': {
				if ($arg == NULL) {
					/* If no argument, we'll target the collection */
					if (method_exists($ctrl, 'listing')) {
						$ctrl->listing();
					} else {
						/* Method is not implemented */
						$this->output('404');
					}
				} else {
					/* Otherwise, we'll target the collection item identified by the argument */
					if (method_exists($ctrl, 'view')) {
						$ctrl->view($arg);
					} else {
						/* Method is not implemented */
						$this->output('404');
					}
				}
			} break;

			case 'POST': {
				if ($arg == NULL) {
					if (method_exists($ctrl, 'insert')) {
						$ctrl->insert();
					} else {
						/* Method is not implemented */
						$this->output('404');
					}
				} else {
					/* We don't allow inserts of a specific id */
					$this->output('404');
				}
			} break;

			case 'PATCH': {
				if ($arg == NULL) {
					/* We don't allow modifications on entire collections */
					$this->output('404');
				} else {
					if (method_exists($ctrl, 'modify')) {
						$ctrl->modify($arg);
					} else {
						/* Method is not implemented */
						$this->output('404');
					}
				}
			} break;

			case 'PUT': {
				if ($arg == NULL) {
					/* We don't allow updates on entire collections */
					$this->output('404');
				} else {
					if (method_exists($ctrl, 'update')) {
						$ctrl->update($arg);
					} else {
						/* Method is not implemented */
						$this->output('404');
					}
				}
			} break;

			case 'DELETE': {
				if ($arg == NULL) {
					/* We don't allow deletes on entire collections */
					$this->output('404');
				} else {
					if (method_exists($ctrl, 'delete')) {
						$ctrl->delete($arg);
					} else {
						/* Method is not implemented */
						$this->output('404');
					}
				}
					
			} break;
		}
	}
}
