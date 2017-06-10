<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 01/06/2017
 * License: GPLv3
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

class UW_Restful extends UW_Model {
	/** Private **/

	private $_debug = false;

	private $_codes = array(
		/* 2xx codes ... */
		'200' => 'OK',
		'201' => 'Created',
		'202' => 'Accepted',
		'204' => 'No Content',
		/* 3xx codes ... */
		'304' => 'Not Modified',
		/* 4xx codes ... */
		'400' => 'Bad Request',
		'401' => 'Unauthorized',
		'403' => 'Forbidden',
		'404' => 'Not Found',
		'405' => 'Method Not Allowed',
		'406' => 'Not Acceptable',
		'409' => 'Conflict',
		'410' => 'Gone',
		'412' => 'Precondition Failed',
		/* 5xx codes ... */
		'500' => 'Internal Server Error',
		'502' => 'Bad Gateway',
		'503' => 'Service Unavailable'
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
		'code' => '400'
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
		$this->_info['code'] = intval($code);

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

		/* Check if debug is enabled. */
		if ($this->_debug) {
			/* If so, dump input contents to error log */
			error_log($raw_data);
		}

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

		/* Encode response to JSON */
		if (($output = json_encode($body)) === false) {
			$this->error('Unable to encode content.');
			$this->output('500'); /* Recursive */
		}

		/* Set Content-Length to avoid chunked transfer encodings */
		$this->header('Content-Length', strlen($output));

		/* Check if debug is enabled. */
		if ($this->_debug) {
			/* If so, dump output contents to error log */
			error_log($output);
		}

		/* Send the body contents and terminate execution */
		exit($output);
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
				if (($argv == NULL) || (count($argv) > 1)) {
					/* If no argument, we'll target the collection */
					if (method_exists($ctrl, 'listing')) {
						$ctrl->listing($argv);
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

	public function request($method, $url, $data = NULL, $headers = NULL) {
            /* Set required request headers */
			if ($headers === NULL) {
				$req_headers = array(
					'Accept: application/json',
					'Content-Type: application/json'
				);
			} else {
				$req_headers = $headers;
			}

            /* Forward request to the underlying layer (notify) */
            $ch = curl_init();

            /* Set the request URL */
            curl_setopt($ch, CURLOPT_URL, $url);

            /* Set cURL request headers */
            curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);

			/* Process method */
			switch (strtoupper($method)) {
				case 'DELETE': {
					/* Set DELETE method */
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
				} break;

				case 'GET': {
					/* Default */
				} break;

				case 'OPTIONS': {
					/* Set OPTIONS method */
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
				} break;

				case 'PATCH': {
					/* Set PATCH method */
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
				} break;

				case 'POST': {
					/* Set request method to POST */
					curl_setopt($ch, CURLOPT_POST, true);
				} break;

				case 'PUT': {
					/* Set PUT method */
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
				} break;

				default: {
					/* Set custom method (experimental). NOTE: In the future, this may be the default, less verbose way to all the other explicit cases */
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
				}
			}

			if ($data !== NULL) {
				/* Set request body data */
				curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
			}

            /* Grant that cURL will return the response output */
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            /* Execute the request */
            $output = curl_exec($ch);

            /* Close the cURL handler */
            curl_close($ch);

            /* All good */
            return $output;
	}

	public function doc_init($object) {
		$this->_doc_object = $object;
		$this->_doc = array();
	}

	public function doc_fields($types = array(), $defaults = array(), $options = array(), $descriptions = array()) {
		/* Field types */
		$this->_doc['fields']['types'] = $types;

		/* Default values */
		$this->_doc['fields']['defaults'] = $defaults;

		/* Field options */
		$this->_doc['fields']['options'] = $options;

		/* Field descriptions */
		$this->_doc['fields']['descriptions'] = $descriptions;
	}

	public function doc_method_get_request(
		$headers_single = array(), $headers_collection = array(),
		$uri_single = NULL, $uri_collection = NULL,
		$notes_single = array(), $notes_collection = array())
	{
		/* Doc - GET request */

		/* URI */
		if ($uri_single === NULL || $uri_single === false) {
			if (method_exists($this->_doc_object, 'view') && $uri_single !== false)
				$this->_doc['method']['GET']['request']['uri']['single'] = '/' . $this->_doc_object . '/<id:{integer}>';
		} else {
			$this->_doc['method']['GET']['request']['uri']['single'] = $uri_single;
		}

		if ($uri_collection === NULL || $uri_collection === false) {
			if (method_exists($this->_doc_object, 'listing') && $uri_collection !== false)
				$this->_doc['method']['GET']['request']['uri']['collection'] = '/' . $this->_doc_object . '/<limit:{integer}>/<offset:{integer}>[/<order_field:{string}>/<ordering:{asc|desc}>/<totals:{0|1}>]';
		} else {
			$this->_doc['method']['GET']['request']['uri']['collection'] = $uri_collection;
		}

		/* Headers */
		if ($headers_single !== false)
			$this->_doc['method']['GET']['request']['headers']['single'] = $headers_single;

		if ($headers_collection !== false)
			$this->_doc['method']['GET']['request']['headers']['collection'] = $headers_collection;

		/* Additional Notes */
		if ($notes_single !== false)
			$this->_doc['method']['GET']['request']['notes']['single'] = $notes_single;
		if ($notes_collection !== false)
			$this->_doc['method']['GET']['request']['notes']['collection'] = $notes_collection;
	}

	public function doc_method_get_response(
		$headers_single = array(), $headers_collection = array(),
		$codes_single_success = array(), $codes_single_failure = array(),
		$codes_collection_success = array(), $codes_collection_failure = array(),
		$body_visible_single = array(), $body_visible_collection = array(),
		$types_single = NULL, $types_collection = NULL,
		$notes_single = array(), $notes_collection = array())
	{
		/* Doc - GET response */

		/* Headers */
		if ($headers_single !== false)
			$this->_doc['method']['GET']['response']['headers']['single'] = $headers_single;
		if ($headers_collection !== false)
			$this->_doc['method']['GET']['response']['headers']['collection'] = $headers_collection;

		/* Status Codes */
		if ($codes_single_success !== false)
			$this->_doc['method']['GET']['response']['codes']['single']['success'] = $codes_single_success;
		if ($codes_single_failure !== false)
			$this->_doc['method']['GET']['response']['codes']['single']['failure'] = $codes_single_failure;
		if ($codes_collection_success !== false)
			$this->_doc['method']['GET']['response']['codes']['collection']['success'] = $codes_collection_success;
		if ($codes_collection_failure !== false)
			$this->_doc['method']['GET']['response']['codes']['collection']['failure'] = $codes_collection_failure;

		/* Visible Fields */
		if ($body_visible_collection !== false)
			$this->_doc['method']['GET']['response']['body']['collection']['visible'] = $body_visible_collection;
		if ($body_visible_single !== false)
			$this->_doc['method']['GET']['response']['body']['single']['visible'] = $body_visible_single;

		/* Return Types */
		if ($types_single === NULL || $types_single === false) {
			if (method_exists($this->_doc_object, 'view') && $types_single !== false)
				$this->_doc['method']['GET']['response']['types']['single'] = '"data": { "<key>": <value>, ... }';
		} else {
			$this->_doc['method']['GET']['response']['types']['single'] = $types_single;
		}

		if ($types_collection === NULL || $types_collection === false) {
			if (method_exists($this->_doc_object, 'listing') && $types_collection !== false)
				$this->_doc['method']['GET']['response']['types']['collection'] = '"data": { "count": <integer>, "total": <integer>, "result": [ { "<key>": <value>, ... }, ... ] }';
		} else {
			$this->_doc['method']['GET']['response']['types']['collection'] = $types_collection;
		}

		/* Additional Notes */
		if ($notes_single !== false)
			$this->_doc['method']['GET']['response']['notes']['single'] = $notes_single;
		if ($notes_collection !== false)
			$this->_doc['method']['GET']['response']['notes']['collection'] = $notes_collection;
	}

	public function doc_method_delete_request(
		$headers_single = array(), $headers_collection = array(),
		$uri_single = NULL, $uri_collection = NULL,
		$notes_single = array(), $notes_collection = array())
	{
		/* Doc - DELETE request */
		if ($uri_single === NULL || $uri_single === false) {
			if (method_exists($this->_doc_object, 'delete') && $uri_single !== false)
				$this->_doc['method']['DELETE']['request']['uri']['single'] = '/' . $this->_doc_object . '/<id:{integer}>';
		} else {
			$this->_doc['method']['DELETE']['request']['uri']['single'] = $uri_single;
		}

		/* URI */
		if ($uri_collection === NULL || $uri_collection === false) {
			if (method_exists($this->_doc_object, 'delete') && $uri_collection !== false)
				$this->_doc['method']['DELETE']['request']['uri']['collection'] = '/' . $this->_doc_object;
		} else {
			$this->_doc['method']['DELETE']['request']['uri']['collection'] = $uri_collection;
		}

		/* Headers */
		if ($headers_single !== false)
			$this->_doc['method']['DELETE']['request']['headers']['single'] = $headers_single;

		if ($headers_collection !== false)
			$this->_doc['method']['DELETE']['request']['headers']['collection'] = $headers_collection;

		/* Additional Notes */
		if ($notes_single !== false)
			$this->_doc['method']['DELETE']['request']['notes']['single'] = $notes_single;

		if ($notes_collection !== false)
			$this->_doc['method']['DELETE']['request']['notes']['collection'] = $notes_collection;
	}

	public function doc_method_delete_response(
		$headers_single = array(), $headers_collection = array(),
		$codes_single_success = array(), $codes_single_failure = array(),
		$codes_collection_success = array(), $codes_collection_failure = array(),
		$types_single = NULL, $types_collection = NULL,
		$notes_single = array(), $notes_collection = array())
	{
		/* Doc - DELETE response */

		/* Headers */
		if ($headers_single !== false)
			$this->_doc['method']['DELETE']['response']['headers']['single'] = $headers_single;
		if ($headers_collection !== false)
			$this->_doc['method']['DELETE']['response']['headers']['collection'] = $headers_collection;

		if ($codes_single_success !== false)
			$this->_doc['method']['DELETE']['response']['codes']['single']['success'] = $codes_single_success;
		if ($codes_single_failure !== false)
			$this->_doc['method']['DELETE']['response']['codes']['single']['failure'] = $codes_single_failure;
		if ($codes_collection_success !== false)
			$this->_doc['method']['DELETE']['response']['codes']['collection']['success'] = $codes_collection_success;
		if ($codes_collection_failure !== false)
			$this->_doc['method']['DELETE']['response']['codes']['collection']['failure'] = $codes_collection_failure;

		/* Return Types */
		if ($types_single === NULL || $types_single === false) {
			if (method_exists($this->_doc_object, 'delete') && isset($this->_doc['method']['DELETE']['request']['uri']['single']) && $types_single !== false)
				$this->_doc['method']['DELETE']['response']['types']['single'] = NULL;
		} else {
			$this->_doc['method']['DELETE']['response']['types']['single'] = $types_single;
		}

		if ($types_collection === NULL || $types_collection === false) {
			if (method_exists($this->_doc_object, 'delete') && isset($this->_doc['method']['DELETE']['request']['uri']['collection']) && $types_collection !== false)
				$this->_doc['method']['DELETE']['response']['types']['collection'] = NULL;
		} else {
			$this->_doc['method']['DELETE']['response']['types']['collection'] = $types_collection;
		}

		/* Additional Notes */
		if ($notes_single !== false)
			$this->_doc['method']['DELETE']['response']['notes']['single'] = $notes_single;

		if ($notes_collection !== false)
			$this->_doc['method']['DELETE']['response']['notes']['collection'] = $notes_collection;
	}

	public function doc_method_patch_request(
		$headers_single = array(), $headers_collection = array(),
		$uri_single = NULL, $uri_collection = NULL,
		$accepted_single = array(), $accepted_collection = array(),
		$notes_single = array(), $notes_collection = array())
	{
		/* Doc - PATCH request */

		/* URI */
		if ($uri_single === NULL || $uri_single === false) {
			if (method_exists($this->_doc_object, 'modify') && $accepted_single && $uri_single !== false)
				$this->_doc['method']['PATCH']['request']['uri']['single'] = '/' . $this->_doc_object . '/<id:{integer}>';
		} else {
			$this->_doc['method']['PATCH']['request']['uri']['single'] = $uri_single;
		}

		if ($uri_collection === NULL || $uri_collection === false) {
			if (method_exists($this->_doc_object, 'modify') && $accepted_collection && $uri_collection !== false)
				$this->_doc['method']['PATCH']['request']['uri']['collection'] = '/' . $this->_doc_object;
		} else {
			$this->_doc['method']['PATCH']['request']['uri']['collection'] = $uri_collection;
		}

		/* Headers */
		if ($headers_single !== false)
			$this->_doc['method']['PATCH']['request']['headers']['single'] = $headers_single;

		if ($headers_collection !== false)
			$this->_doc['method']['PATCH']['request']['headers']['collection'] = $headers_collection;

		/* Accepted Fields */
		if ($accepted_single !== false)
			$this->_doc['method']['PATCH']['request']['body']['single']['accepted'] = $accepted_single;
		
		if ($accepted_collection !== false)
			$this->_doc['method']['PATCH']['request']['body']['collection']['accepted'] = $accepted_collection;

		/* Additional Notes */
		if ($notes_single !== false)
			$this->_doc['method']['PATCH']['request']['notes']['single'] = $notes_single;

		if ($notes_collection !== false)
			$this->_doc['method']['PATCH']['request']['notes']['collection'] = $notes_collection;
	}

	public function doc_method_patch_response(
		$headers_single = array(), $headers_collection = array(),
		$codes_single_success = array(), $codes_single_failure = array(),
		$codes_collection_success = array(), $codes_collection_failure = array(),
		$types_single = NULL, $types_collection = NULL,
		$notes_single = array(), $notes_collection = array())
	{
		/* Doc - PATCH response */

		/* Headers */
		if ($headers_single !== false)
			$this->_doc['method']['PATCH']['response']['headers']['single'] = $headers_single;
		if ($headers_collection !== false)
			$this->_doc['method']['PATCH']['response']['headers']['collection'] = $headers_collection;

		/* Status Codes */
		if ($codes_single_success !== false)
			$this->_doc['method']['PATCH']['response']['codes']['single']['success'] = $codes_single_success;
		if ($codes_single_failure !== false)
			$this->_doc['method']['PATCH']['response']['codes']['single']['failure'] = $codes_single_failure;
		if ($codes_collection_success !== false)
			$this->_doc['method']['PATCH']['response']['codes']['collection']['success'] = $codes_collection_success;
		if ($codes_collection_failure !== false)
			$this->_doc['method']['PATCH']['response']['codes']['collection']['failure'] = $codes_collection_failure;

		/* Return Types */
		if ($types_single === NULL || $types_single === false) {
			if (method_exists($this->_doc_object, 'modify') && isset($this->_doc['method']['PATCH']['request']['uri']['single']) && $types_single !== false)
				$this->_doc['method']['PATCH']['response']['types']['single'] = NULL;
		} else {
			$this->_doc['method']['PATCH']['response']['types']['single'] = $types_single;
		}

		if ($types_collection === NULL || $types_collection === false) {
			if (method_exists($this->_doc_object, 'modify') && isset($this->_doc['method']['PATCH']['request']['uri']['collection']) && $types_collection !== false)
				$this->_doc['method']['PATCH']['response']['types']['collection'] = NULL;
		} else {
			$this->_doc['method']['PATCH']['response']['types']['collection'] = $types_collection;
		}

		/* Additional Notes */
		if ($notes_single !== false)
			$this->_doc['method']['PATCH']['response']['notes']['single'] = $notes_single;

		if ($notes_collection !== false)
			$this->_doc['method']['PATCH']['response']['notes']['collection'] = $notes_collection;
	}

	public function doc_method_post_request(
		$headers_single = array(), $headers_collection = array(),
		$uri_single = NULL, $uri_collection = NULL,
		$accepted_single = array(), $accepted_collection = array(),
		$required_single = array(), $required_collection = array(),
		$notes_single = array(), $notes_collection = array())
	{
		/* Doc - POST request */

		/* URI */
		if ($uri_single === NULL || $uri_single === false) {
			if (method_exists($this->_doc_object, 'insert') && $accepted_single && $uri_single !== false)
				$this->_doc['method']['POST']['request']['uri']['single'] = '/' . $this->_doc_object . '/<id:{integer}>';
		} else {
			$this->_doc['method']['POST']['request']['uri']['single'] = $uri_single;
		}

		if ($uri_collection === NULL || $uri_collection === false) {
			if (method_exists($this->_doc_object, 'insert') && $accepted_collection && $uri_single !== false)
				$this->_doc['method']['POST']['request']['uri']['collection'] = '/' . $this->_doc_object;
		} else {
			$this->_doc['method']['POST']['request']['uri']['collection'] = $uri_collection;
		}

		/* Headers */
		if ($headers_single !== false)
			$this->_doc['method']['POST']['request']['headers']['single'] = $headers_single;

		if ($headers_collection !== false)
			$this->_doc['method']['POST']['request']['headers']['collection'] = $headers_collection;

		/* Accepted Fields */
		if ($accepted_single !== false)
			$this->_doc['method']['POST']['request']['body']['single']['accepted'] = $accepted_single;
		
		if ($accepted_collection !== false)
			$this->_doc['method']['POST']['request']['body']['collection']['accepted'] = $accepted_collection;

		/* Required Fields */
		if ($required_single !== false)
			$this->_doc['method']['POST']['request']['body']['single']['required'] = $required_single;

		if ($required_collection !== false)
			$this->_doc['method']['POST']['request']['body']['collection']['required'] = $required_collection;

		/* Additional Notes */
		if ($notes_single !== false)
			$this->_doc['method']['POST']['request']['notes']['single'] = $notes_single;

		if ($notes_collection !== false)
			$this->_doc['method']['POST']['request']['notes']['collection'] = $notes_collection;
	}

	public function doc_method_post_response(
		$headers_single = array(), $headers_collection = array(),
		$codes_single_success = array(), $codes_single_failure = array(),
		$codes_collection_success = array(), $codes_collection_failure = array(),
		$types_single = NULL, $types_collection = NULL,
		$notes_single = array(), $notes_collection = array())
	{
		/* Doc - POST response */

		/* Headers */
		if ($headers_single !== false)
			$this->_doc['method']['POST']['response']['headers']['single'] = $headers_single;
		if ($headers_collection !== false)
			$this->_doc['method']['POST']['response']['headers']['collection'] = $headers_collection;

		/* Status Codes */
		if ($codes_single_success !== false)
			$this->_doc['method']['POST']['response']['codes']['single']['success'] = $codes_single_success;
		if ($codes_single_failure !== false)
			$this->_doc['method']['POST']['response']['codes']['single']['failure'] = $codes_single_failure;
		if ($codes_collection_success !== false)
			$this->_doc['method']['POST']['response']['codes']['collection']['success'] = $codes_collection_success;
		if ($codes_collection_failure !== false)
			$this->_doc['method']['POST']['response']['codes']['collection']['failure'] = $codes_collection_failure;

		/* Return Types */
		if ($types_single === NULL || $types_single === false) {
			if (method_exists($this->_doc_object, 'insert') && isset($this->_doc['method']['POST']['request']['uri']['single']) && $types_single !== false)
				$this->_doc['method']['POST']['response']['types']['single'] = '"data": { "id": <integer> }';
		} else {
			$this->_doc['method']['POST']['response']['types']['single'] = $types_single;
		}

		if ($types_collection === NULL || $types_collection === false) {
			if (method_exists($this->_doc_object, 'insert') && isset($this->_doc['method']['POST']['request']['uri']['collection']) && $types_collection !== false)
				$this->_doc['method']['POST']['response']['types']['collection'] = '"data": { "id": <integer> }';
		} else {
			$this->_doc['method']['POST']['response']['types']['collection'] = $types_collection;
		}

		/* Additional Notes */
		if ($notes_single !== false)
			$this->_doc['method']['POST']['response']['notes']['single'] = $notes_single;

		if ($notes_collection !== false)
			$this->_doc['method']['POST']['response']['notes']['collection'] = $notes_collection;
	}

	public function doc_method_custom(
		$method,
		$func, $uri_args = '',
		$request_body_args = array(),
		$response_body_args = array(), $response_types = '',
		$codes_success = array(), $codes_failure = array(),
		$request_headers = array(), $response_headers = array(),
		$request_notes = array(), $response_notes = array())
	{
		/* Doc - Custom request */

		/* URI */
		$this->_doc['method'][$method]['request']['uri'][$func] = '/' . $this->_doc_object . '/' . $func . $uri_args;
		
		/* Headers */
		if ($request_headers !== false)
			$this->_doc['method'][$method]['request']['headers'][$func] = $request_headers;

		/* Body */
		if ($request_body_args !== false) {
			foreach ($request_body_args as $k => $v) {
				$this->_doc['method'][$method]['request']['body'][$func][$k] = $v;
			}
		}

		/* Additional Notes */
		if ($request_notes !== false)
			$this->_doc['method'][$method]['request']['notes'][$func] = $request_notes;


		/* Doc - Custom response */

		/* Headers */
		if ($response_headers !== false)
			$this->_doc['method'][$method]['response']['headers'][$func] = $response_headers;

		/* Return Types */
		$this->_doc['method'][$method]['response']['types'][$func] = $response_types;

		/* Body */
		foreach ($response_body_args as $k => $v) {
			$this->_doc['method'][$method]['response']['body'][$func][$k] = $v;
		}

		/* Status Codes */
		if ($codes_success !== false)
			$this->_doc['method'][$method]['response']['codes'][$func]['success'] = $codes_success;
		
		if ($codes_failure !== false)
			$this->_doc['method'][$method]['response']['codes'][$func]['failure'] = $codes_failure;

		/* Additional Notes */
		if ($response_notes !== false)
			$this->_doc['method'][$method]['response']['notes'][$func] = $response_notes;
	}

	public function doc_generate() {
		return $this->_doc;
	}
}

