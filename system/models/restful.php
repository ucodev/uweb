<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 29/10/2016
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

class UW_Restful extends UW_Model {
	/** Private **/

	private $_codes = array(
		/* 2xx codes ... */
		'200' => 'OK',
		'201' => 'Created',
		'204' => 'No Content',
		/* 4xx codes ... */
		'400' => 'Bad Request',
		'401' => 'Unauthorized',
		'403' => 'Forbidden',
		'404' => 'Not Found',
		'405' => 'Method Not Allowed',
		'406' => 'Not Acceptable',
		'409' => 'Conflict',
		'500' => 'Internal Server Error'
	);

	private $_methods = array(
		'GET',
		'POST',
		'PUT',
		'PATCH',
		'DELETE',
		'OPTIONS'
	);

	private $_info = array(
		'data' => false,
		'errors' => false,
		'method' => 'NONE',
		'code' => '500'
	);

	private $_errors = array(
		'message' => NULL
	);

	private $_headers = array();

	private function _headers_http_collect() {
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

	public function code($code, $protocol = 'HTTP/1.1') {
		$this->_info['code'] = $code;

		header($protocol . ' ' . $code . ' ' . $this->_codes[$code]);
	}

	public function error($message) {
		$this->_info['errors'] = true;
		$this->_errors['message'] = $message;
	}

	public function header($key = NULL, $value = NULL, $replace = true) {
		/* If a value is set... add this to the response headers */
		if ($value !== NULL) {
			header($key . ': ' . $value, $replace);
		} else if ($key !== NULL) { /* If a $key is set, but not a $value, return the value of $key */
			$this->_headers_http_collect();

			if (isset($this->_headers[$key])) { /* Check if the key is present in the HTTP headers*/
				return $this->_headers[$key];
			} else if (isset($_SERVER[strtoupper(str_replace('-', '_', $key))])) { /* Check if the key is present on the $_SERVER global */
				return $_SERVER[strtoupper(str_replace('-', '_', $key))];
			}

			return NULL;
		} else { /* If not $key nor $value is set, return all the headers */
			$this->_headers_http_collect();

			return $this->_headers;
		}
	}

	public function method() {
		$this->_info['method'] = request_method();

		return $this->_info['method'];
	}

	public function input() {
		/* If the content type isn't set as application/json, we'll not accept this request */
		if (strstr($this->header('Content-Type'), 'application/json') === false) {
			/* Content type is not acceptable here */
			$this->error('Only application/json is acceptable as the Content-Type.');

			/* Not acceptable */
			$this->output('406');
		}

		/* Fetch raw data */
		$raw_data = file_get_contents('php://input');

		/* Decode json data */
		$json_data = json_decode($raw_data, true);

		/* If we're unable to decode the JSON data, this is a bad request */
		if ($json_data === NULL) {
			/* Cannot decode JSON data */
			$this->error('Cannot decode JSON data.');

			/* Bad request */
			$this->output('400');
		}

		/* Return the decoded data */
		return $json_data;
	}

	public function output($code, $data = NULL) {
		/* Set status code */
		$this->code($code);

		/* Data section is present? */
		$this->_info['data'] = ($data !== NULL);

		/* Add info section to the response */
		$body['info'] = $this->_info;

		/* Add errors section if any error was set */
		if ($this->_info['errors'])
			$body['errors'] = $this->_errors;

		/* Check if there's data to be sent as the response body */
		if ($data !== NULL) {
			/* Set the response content type to JSON */
			$this->header('Content-Type', 'application/json');

			/* Add the data section */
			if (is_array($data)) {
				$body['data'] = $data;
			} else {
				/* Try to decode JSON data */
				$json_data = json_decode($data, true);

				if ($json_data !== NULL) {
					$body['data'] = $json_data; /* JSON data */
				} else {
					$body['data'] = $data; /* Raw data */
				}
			}
		}

		/* Send the body contents and terminate execution */
		exit(json_encode($body));
	}

	public function validate() {
		/* If the client does not accept application/json content, we'll not accept this request */
		if (strstr($this->header('Accept'), 'application/json') === false) {
			/* Content type is not acceptable here */
			$this->error('Accept header must contain the application/json content type.');

			/* Not acceptable */
			$this->output('406');
		}

 		/* Check if this is an allowed method */
		if (!in_array($this->method(), $this->_methods)) {
			/* Method is not present in the allowed methods array */
			$this->error('Method ' . $this->method() . ' is not allowed.');

			/* Method not allowed */
			$this->output('405');
		}
	}

	public function process(&$ctrl, $argv = NULL) {
		/* Validate RESTful request */
		$this->validate();

		/* Process method */
		switch ($this->method()) {
			case 'GET': {
				if ($argv == NULL) {
					/* If no argument, we'll target the collection */
					if (method_exists($ctrl, 'listing')) {
						$ctrl->listing();
					} else {
						/* Object method is not implemented (no handler declared) */
						$this->error('No handler declared for GET (listing).');

						/* Not found */
						$this->output('404');
					}
				} else {
					/* Otherwise, we'll target the collection item identified by the argument */
					if (method_exists($ctrl, 'view')) {
						$ctrl->view($argv);
					} else {
						/* Object method is not implemented (no handler declared) */
						$this->error('No handler declared for GET (view).');

						/* Not found */
						$this->output('404');
					}
				}
			} break;

			case 'POST': {
				if (method_exists($ctrl, 'insert')) {
					$ctrl->insert($argv);
				} else {
					/* Object method is not implemented (no handler declared) */
					$this->error('No handler declared for POST (insert).');

					/* Not found */
					$this->output('404');
				}
			} break;

			case 'PATCH': {
				if (method_exists($ctrl, 'modify')) {
					$ctrl->modify($argv);
				} else {
					/* Object method is not implemented (no handler declared) */
					$this->error('No handler declared for PATCH (modify).');

					/* Not found */
					$this->output('404');
				}
			} break;

			case 'PUT': {
				if (method_exists($ctrl, 'update')) {
					$ctrl->update($argv);
				} else {
					/* Object method is not implemented (no handler declared) */
					$this->error('No handler declared for PUT (update).');

					/* Not found */
					$this->output('404');
				}
			} break;

			case 'DELETE': {
				if (method_exists($ctrl, 'delete')) {
					$ctrl->delete($argv);
				} else {
					/* Object method is not implemented (no handler declared) */
					$this->error('No handler declared for DELETE (delete).');

					/* Not found */
					$this->output('404');
				}
			} break;

			case 'OPTIONS': {
				$allow = array();

				/* Populate allow array */

				if (method_exists($ctrl, 'listing') || method_exists($ctrl, 'view'))
					array_push($allow, 'GET');

				if (method_exists($ctrl, 'insert'))
					array_push($allow, 'POST');

				if (method_exists($ctrl, 'modify'))
					array_push($allow, 'PATCH');

				if (method_exists($ctrl, 'update'))
					array_push($allow, 'PUT');

				if (method_exists($ctrl, 'delete'))
					array_push($allow, 'DELETE');

				/* Set the Allow header with the allowed methods */
				$this->header('Allow', implode(', ', $allow));

				/* If there is a options() method defined on this controller, call it */
				if (method_exists($ctrl, 'options')) {
					$ctrl->options();
				} else {
					/* Otherwise just return 200 OK */
					$this->output('200');
				}
			}
		}
	}
}
