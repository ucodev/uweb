<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Author:   Pedro A. Hortas
 * Email:    pah@ucodev.org
 * Modified: 07/10/2017
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

class UW_Restful extends UW_Model {
	/** Protected **/

	protected $_debug = false;
	protected $_logging = false;
	protected $_event = false;


	/** Private **/

	private $_codes = array(
		/* 2xx codes ... */
		'200' => 'OK',
		'201' => 'Created',
		'202' => 'Accepted',
		'204' => 'No Content',
		'207' => 'Multi-Status',
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
		'415' => 'Unsupported Media Type',
		'416' => 'Requested Range Not Satisfiable',
		'417' => 'Expectation Failed',
		'422' => 'Unprocessable Entity',
		'423' => 'Locked',
		'424' => 'Failed Dependency',
		'428' => 'Precondition Required',
		'429' => 'Too Many Requests',
		/* 5xx codes ... */
		'500' => 'Internal Server Error',
		'502' => 'Bad Gateway',
		'503' => 'Service Unavailable',
		'504' => 'Gateway Timeout',
		'505' => 'HTTP Version Not Supported'
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
		'id' => NULL,
		'data' => false,
		'errors' => false,
		'method' => NULL,
		'code' => '400'
	);

	private $_errors = array(
		'message' => NULL
	);

	private $_headers = array();

	private $_input = NULL; /* Cached input */

	private $_call = NULL; /* Call information */

	private $_id = NULL; /* Request ID */

	/* Event processing triggers set by $this->event() (all triggers disabled by default) */
	private $_event_triggers = array(
		'request' => array('info' => false, 'data' => false),
		'response' => array('info' => false, 'data' => false, 'errors' => false),
		'context' => NULL
	);

	private $_cache_hit = false; /* Set to true if this request was cache sourced */

	/* Multi entry requests parameters */
	private $_multi_enabled = false;
	private $_multi_responses = array();
	private $_multi_errors = false;

	/* Private methods */
	private function _headers_http_collect() {
		/* Collect HTTP Headers */

		if (count($this->_headers))
			return $this->_headers;

		foreach ($_SERVER as $header => $value) {
			if (substr($header, 0, 5) != 'HTTP_')
				continue;

			$this->_headers[strtolower(str_replace('_', '-', substr($header, 5)))] = $value;
		}

		return $this->_headers;
	}

	private function _multi_init() {
		/* Initialize multiple entry request */

		$this->_multi_enabled = true; /* Setting this property to true will cause output() to queue responses until _multi_commit() is called */
		$this->_multi_responses = array();
	}

	private function _multi_commit() {
		/* Commit multiple entry responses */

		$this->_multi_enabled = false; /* Setting this property to false will cause output() to actually answer to the client */

		$this->output('207', $this->_multi_responses);
	}

	private function _request_info() {
		return array(
			'id'			=> $this->id(),
			'url'			=> current_url(),
			'fqhn'			=> $_SERVER['SERVER_NAME'],
			'port'			=> $_SERVER['SERVER_PORT'],
			'protocol'		=> strtoupper('http' . (isset($_SERVER['HTTPS']) ? 's' : '')),
			'http_method'	=> $this->method(),
			'http_version'	=> $_SERVER['SERVER_PROTOCOL'],
			'http_user_agent' => $this->header('user-agent'),
			'content_type'	=> $this->header('content-type'),
			'accept'		=> $this->header('accept'),
			'cache_control' => $this->header('cache-control'),
			'accept_encoding' => $this->header('accept-encoding'),
			'content_encoding' => $this->header('content-encoding'),
			'from'			=> $_SERVER['REMOTE_ADDR'],
			'tracker'		=> $this->header(current_config()['restful']['log']['header']['tracker'])
		);
	}

	public function _log_array_value_process(&$v, $k) {
		if (current_config()['restful']['log']['secure_fields'] && in_array($k, current_config()['restful']['log']['secure_fields'], true)) {
			$v = '[ ... ommited ... ]';
		} else if (current_config()['restful']['log']['truncate_values'] && ((gettype($v) == 'string') && (strlen($v) > current_config()['restful']['log']['truncate_values']))) {
			$v = substr($v, 0, current_config()['restful']['log']['truncate_values'] - 8) . ' [ ... ]';
		}
	}

	private function _log_request($response) {
		/* Create a log entry for the request / response and send it to the configured facility */

		/* Set log source properties */
		$log['rest']['source'] = array(
			'name'			=> current_config()['restful']['log']['source']['name'],
			'version'		=> current_config()['restful']['log']['source']['version'],
			'hostname'		=> gethostname(),
			'environment'	=> current_config()['restful']['log']['source']['environment'],
			'company'		=> current_config()['restful']['log']['source']['company']
		);

		/* Set request info */
		$log['rest']['request']['info'] = $this->_request_info();

		/* Attempt to extract user id and session id */
		if ($this->header(current_config()['restful']['log']['header']['user_id'])) {
			/* If user id is set in the header, fetch it from there */
			$log['rest']['request']['info']['users_id'] = intval($this->header(current_config()['restful']['log']['header']['user_id']));
			$log['rest']['request']['info']['sessions_id'] = sha1($this->header(current_config()['restful']['log']['header']['auth_token']) . $log['rest']['request']['info']['users_id']);
		} else if ($response['info']['call']['object'] == 'auth' && $response['info']['call']['function'] == 'insert') {
			/* If the user header is not set, and this is an authentication request, fetch the user id from response */
			if ($response['info']['code'] == 201) {
				$log['rest']['request']['info']['users_id'] = $response['data']['userid'];
				$log['rest']['request']['info']['sessions_id'] = sha1($response['data']['token'] . $response['data']['userid']);
			} else {
				/* If the authentication failed, set user id to zero */
				$log['rest']['request']['info']['users_id'] = 0;
			}
		} else if ($response['info']['call']['object'] == 'register') {
			/* If the user header is not set, and this is a user registration request, set user id to zero */
			$log['rest']['request']['info']['users_id'] = 0;
		} else {
			/* If all the above failed, this is an unauthenticated request and will fallback to the public user id */
			$log['rest']['request']['info']['users_id'] = current_config()['restful']['log']['default']['user_id'];
		}

		/* Calculate request execution time (internal) */
		$log['rest']['request']['info']['exec_time'] = round($response['info']['call']['end'] - $response['info']['call']['start'], 6);

		/* Attempt to extract geolocation */
		if ($this->header(current_config()['restful']['log']['header']['geolocation'])) {
			do {
				/* Fetch raw geolocation data from the header and try to parse it */
				$geolocation = explode(',', $this->header(current_config()['restful']['log']['header']['geolocation']));

				/* Format for geolocation must be "latitude, longitude" */
				if (count($geolocation) != 2) {
					error_log('Invalid geolocation value: ' . $this->header(current_config()['restful']['log']['header']['geolocation']));
					break;
				}

				/* Remove any extra whitespaces */
				$geo_lat  = trim($geolocation[0], ' ');
				$geo_long = trim($geolocation[1], ' ');

				/* Latitude and Longitude values must be of double type */
				if (!preg_match('/\d+\.\d+/', $geo_lat) || !preg_match('/\d+\.\d+/', $geo_long)) {
					error_log('Invalid geolocation value: ' . $this->header(current_config()['restful']['log']['header']['geolocation']));
					break;
				}

				/* Set geolocation information */
				$log['rest']['request']['info']['geolocation'] = array(
					'location'  => array($geo_lat, $geo_long),
					'latitude'  => doubleval($geo_lat),
					'longitude' => doubleval($geo_long)
				);
			} while (0);
		}

		/* Set response contents */
		$log['rest']['response'] = $response;

		/* Check if response body logging is enabled */
		if (!current_config()['restful']['log']['response_body']) {
			/* Do not send the full data if debug is not enabled */
			unset($log['rest']['response']['data']);
		} else if (isset($log['rest']['response']['data']) && $log['rest']['response']['data']) {
			/* Check if values should be truncated */
			if (current_config()['restful']['log']['truncate_values'] || current_config()['restful']['log']['secure_fields']) {
				/* Truncate values from response data */
				array_walk_recursive($log['rest']['response']['data'], array($this, '_log_array_value_process'));
			}

			/* Check if body should be encoded */
			if (current_config()['restful']['log']['encode_body'] === true) {
				$log['rest']['response']['data'] = json_encode($log['rest']['response']['data'], JSON_PRETTY_PRINT);

				/* Check if body data is too big and should be discarded */
				if (strlen($log['rest']['response']['data']) > current_config()['restful']['log']['discard_huge_body'])
					$log['rest']['response']['data'] = substr($log['rest']['response']['data'], 0, current_config()['restful']['log']['discard_huge_body'] - 22) . ' [ ... discarded ... ]';
			}
		}

		/* Check if request body logging is enabled */
		if (current_config()['restful']['log']['request_body']) {
			/* Set request data based on JSON decoded input */
			if ($this->input()) {
				/* Set request data as the current request input */
				$log['rest']['request']['data'] = $this->input();

				/* Check if values should be truncated */
				if (current_config()['restful']['log']['truncate_values'] || current_config()['restful']['log']['secure_fields']) {
					/* Truncate values from request data */
					array_walk_recursive($log['rest']['request']['data'], array($this, '_log_array_value_process'));
				}

				/* Check if body should be encoded */
				if (current_config()['restful']['log']['encode_body'] === true) {
					$log['rest']['request']['data'] = json_encode($log['rest']['request']['data'], JSON_PRETTY_PRINT);

					/* Check if body data is too big and should be discarded */
					if (strlen($log['rest']['request']['data']) > current_config()['restful']['log']['discard_huge_body'])
					$log['rest']['request']['data'] = substr($log['rest']['request']['data'], 0, current_config()['restful']['log']['discard_huge_body'] - 22) . ' [ ... discarded ... ]';
				}
			}
		}

		/* Use selected log interface */
		switch (current_config()['restful']['log']['interface']) {
			case 'http_json': {
				/* POSTs the log to an HTTP server expecting a JSON encoded payload */
				$this->request(
					'POST',
					current_config()['restful']['log']['destination']['url'],
					$log,
					array(
						'content-type: application/json'
					),
					$status_code,
					$raw_output,
					current_config()['restful']['log']['destination']['timeout']['connect'],
					current_config()['restful']['log']['destination']['timeout']['execute']
				);
			}; break;

			case 'udp_json': {
				/* Sends a json encoded string to a remote raw UDP server */
				$log_raw = json_encode($log);

				$sk = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

				socket_sendto($sk, $log_raw, strlen($log_raw), 0, current_config()['restful']['log']['destination']['host'], current_config()['restful']['log']['destination']['port']);

				socket_close($sk);
			}; break;

			case 'error_log': {
				/* Send the log to error_log */
				error_log(json_encode($log));
			}

			default: {
				error_log('Invalid logging interface: ' . current_config()['restful']['log']['interface']);
			}
		}
	}


	/** Construct **/

	public function __construct() {
		parent::__construct();

		/* Check if RESTful interface is enabled */
		if (!current_config()['restful']['enabled']) {
			$this->error('uWeb RESTful interface is disabled');
			$this->output('403');
		}

		/* Setup debug and logging parameters */
		$this->_debug = current_config()['restful']['debug']['enabled'];
		$this->_debug_level = current_config()['restful']['debug']['level'];
		$this->_logging = current_config()['restful']['log']['enabled'];
		$this->_event = current_config()['restful']['event']['enabled'];

		/* Set default status code */
		$this->_info['code'] = current_config()['restful']['response']['default']['status_code'];
	}


	/** Public **/

	public function id($id = NULL) {
		if  ($id !== NULL)
			$this->_info['id'] = $id;

		if ($this->_info['id'] === NULL)
			$this->_info['id'] = sha1(random_bytes(256));

		return $this->_info['id'];
	}

	public function event_triggers($request_info = true, $request_data = true, $response_info = true, $response_data = true, $response_errors = false, $context = NULL) {
		$this->_event_triggers['request']['info'] = $request_info;
		$this->_event_triggers['request']['data'] = $request_data;
		$this->_event_triggers['response']['info'] = $response_info;
		$this->_event_triggers['response']['data'] = $response_data;
		$this->_event_triggers['response']['errors'] = $response_errors;
		$this->_event_triggers['context'] = $context;
	}

	public function cache_hit($status = NULL) {
		if ($status !== NULL) {
			$this->_cache_hit = $status;
			return $status;
		}

		return $this->_cache_hit;
	}

	public function call_start($object = NULL, $function = NULL, $argv = NULL) {
		$this->_call = array();
		
		$this->_call['from'] = $_SERVER['REMOTE_ADDR'];
		$this->_call['to'] = $_SERVER['SERVER_NAME'];
		$this->_call['object'] = $object;
		$this->_call['function'] = $function;
		$this->_call['argv'] = $argv;
		$this->_call['start'] = microtime(true);
		$this->_call['end'] = NULL;
		$this->_call['user_agent'] = $this->header('user-agent');

		return $this->_call;
	}

	public function call_end() {
		$this->_call['cache_hit'] = $this->cache_hit();
		$this->_call['end'] = microtime(true);

		return $this->_call;
	}

	public function call_info() {
		return $this->_call;
	}

	public function code($code, $protocol = NULL) {
		if ($protocol === NULL)
			$protocol = $_SERVER['SERVER_PROTOCOL'];

		$this->_info['code'] = intval($code);

		// header($protocol . ' ' . $code . ' ' . $this->_codes[$code]);
		header($protocol . ' ' . $code);
	}

	public function error($message) {
		$this->_info['errors'] = true;
		$this->_errors['message'] = $message;
	}

	public function header($key = NULL, $value = NULL, $replace = true) {
		/* Always convert key to lowercase */
		$key = strtolower($key);

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
		/* Check if there's a cached version of the input (meaning input() was already called before) */
		if ($this->_input !== NULL)
			return $this->_input;

		/* Fetch raw data */
		$raw_data = file_get_contents('php://input');

		/* Check if there's any input */
		if (!$raw_data) {
			$this->_input = NULL;
			return NULL;
		}

		/* If the content type isn't set as application/json, we'll not accept this request */
		if (strstr($this->header('content-type'), 'application/json') === false) {
			/* Content type is not acceptable here */
			$this->error('Only application/json is acceptable as the content-type.');

			/* Not acceptable */
			$this->output('406');
		}

		/* Check if content is compressed and if we should deal with it */
		if ($this->header('content-encoding') && current_config()['restful']['request']['encoding']['process']) {
			switch ($this->header('content-encoding')) {
				case 'gzip': {
					/* Uncompress the entity */
					$raw_data_uncompressed = gzuncompress($raw_data);

					/* Check if it was successful */
					if ($raw_data_uncompressed === false) {
						$this->error('The content encoding was set to \'gzip\' but the data uncompress process failed.');
						$this->output('400');
					}

					/* Re-assign the inflated data */
					$raw_data = $raw_data_uncompressed;
				}; break;

				case 'deflate': {
					/* Uncompress the entity */
					$raw_data_inflated = gzinflate($raw_data);
					
					/* Check if it was successful */
					if ($raw_data_inflated === false) {
						$this->error('The content encoding was set to \'deflate\' but the data inflate process failed.');
						$this->output('400');
					}

					/* Re-assign the inflated data */
					$raw_data = $raw_data_inflated;
				}; break;

				default: {
					$this->error('Unsupported content enconding: ' . $this->header('content-encoding'));
					$this->output('400');
				}
			}
		}

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

		/* Cache input */
		$this->_input = $json_data;

		/* Return the decoded data */
		return $json_data;
	}

	public function output($code, $data = NULL, $force_close = false) {
		/* If we are in the middle of a multiple entry request, store the response until all the requests are processed */
		if ($this->_multi_enabled === true) {
			if ($force_close === true) {
				error_log(__FILE__ . ': ' . __FUNCTION__ . ': ' .
					'A multi entry request was performed under a method that sets \'force_close\' to \'true\'. ' .
					'Unlike a single entry request, this may cause unexpected behaviour since the connection won\'t be closed until all the entries are processed. ' .
					'Call trace: ' . json_encode($this->_call)
				);
			}

			/* Initialize entry response */
			$response = array();

			/* Set response status code */
			$response['code'] = intval($code);

			/* Check if the request was successful and contains data */
			if ($data !== NULL && !$this->_info['errors']) {
				/* Set response data */
				$response['data'] = $data;
			} else if ($this->_info['errors']) {
				/* Mark this multi entry request as containing errors */
				$this->_multi_errors = true;

				/* Set response errors */
				$response['errors'] = $this->_errors;

				/* Reset errors, so the next request starts clean */
				$this->_info['errors'] = false;
				$this->_errors = array('message' => NULL);
			}

			/* Store the request response */
			array_push($this->_multi_responses, $response);

			/* Handle next request, if any */
			return true;
		}

		/* Check if there's a method set */
		if ($this->_info['method'] === NULL)
			$this->method(); /* Initialize method */

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
			$this->header('content-type', 'application/json');

			/* Add the data section */
			if (is_array($data)) {
				$body['data'] = $data;
			} else {
				/* Try to decode JSON data */
				$json_data = json_decode($data, true);

				if ($json_data !== NULL) {
					$body['data'] = $json_data; /* JSON data */
					unset($data);
					unset($json_data);
				} else {
					$body['data'] = $data; /* Raw data */
					unset($data);
				}
			}
		}

		/* Inform that this request call is about to end */
		$this->call_end();

		/* Set call information */
		$body['info']['call'] = $this->call_info();

		/* Encode response to JSON */
		if (($output = json_encode($body)) === false) {
			$this->error('Unable to encode content.');
			$this->output('500'); /* Recursive */
		}

		/* Set Content-Length to avoid chunked transfer encodings */
		$this->header('content-length', strlen($output));

		/* Check if debug is enabled */
		if ($this->_debug) {
			/* If so, dump output contents to error log */
			error_log($output);
		}

		/* Check if we should inform the client to close the connection */
		if ($force_close === true) {
			/* Close user connection */
			$this->header('connection', 'close');
		}

		/* Send the response */
		echo($output);

		/* Flush output buffer */
		ob_end_flush();
		ob_flush();
		flush();

		/* Finish request for FastCGI */
		fastcgi_finish_request();

		/* Handle events */
		if ($this->_event) {
			/* Initialize event object */
			$event = array();

			/* Check triggers and add data according to the each trigger setting */

			if ($this->_event_triggers['request']['info'])
				$event['request']['info'] = $this->_request_info();

			if ($this->_event_triggers['request']['data'] && $this->input())
				$event['request']['data'] = $this->input();
			
			if ($this->_event_triggers['response']['info'] && isset($body['info']))
				$event['response']['info'] = $body['info'];
			
			if ($this->_event_triggers['response']['data'] && isset($body['data']))
				$event['response']['data'] = $body['data'];

			if ($this->_event_triggers['response']['errors'] && isset($body['errors']))
				$event['response']['errors'] = $body['errors'];

			/* Push event to the event queue */
			if ($event)
				$this->event->push($event, $this->_event_triggers['context'] ? $this->_event_triggers['context'] : current_config()['restful']['event']['context']);
		}

		/* Check if logging is enabled, and if so, log the request */
		if ($this->_logging)
			$this->_log_request($body);

		/* If force_close is not set to true, do not allow further processing */
		if ($force_close !== true)
			exit();

		/* From this point on, we know that force_close was set to true and we will allow further processing.
		 * This will allow functions to finish time consuming tasks that will render no output to the user, creating a background processing task.
		 */
	}

	public function init($ctrl, $obj_function, $argv = NULL) {
		/* Initialize request id */
		$this->id();

		/* Set object name and called function */
		$this->call_start(strtolower(get_class($ctrl)), $obj_function, $argv);
	}

	public function validate($method = NULL) {
		/* If the client does not accept application/json content, we'll not accept this request */
		if (strstr($this->header('accept'), 'application/json') === false) {
			/* Content type is not acceptable here */
			$this->error('The accept header must contain the application/json content type.');

			/* Not acceptable */
			$this->output('406');
		}

 		/* Check if this is an allowed method */
		if (!in_array($this->method(), $this->_methods) || ($method !== NULL && ($this->method() != $method))) {
			/* Method is not present in the allowed methods array */
			$this->error('Method ' . $this->method() . ' is not allowed.');

			/* Method not allowed */
			$this->output('405');
		}
	}

	public function process(&$ctrl, $argv = NULL) {
		/* Process method */
		switch ($this->method()) {
			case 'GET': {
				if (($argv == NULL) || (count($argv) > 1)) {
					/* Initialize environment */
					$this->init($ctrl, 'listing', $argv);

					/* Validate RESTful request */
					$this->validate();

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
					/* Initialize environment */
					$this->init($ctrl, 'view', $argv);

					/* Validate RESTful request */
					$this->validate();

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
				/* Initialize environment */
				$this->init($ctrl, 'insert', $argv);

				/* Validate RESTful request */
				$this->validate();

				if (method_exists($ctrl, 'insert')) {
					/* Check if this is a request with a single entity entry... */
					if (count(array_filter(array_keys($this->input()), 'is_string')) > 0) {
						/* Single entry */
						$ctrl->insert($argv);
					} else if (gettype($this->input()) != 'string') {
						/* Multiple entries */
						$this->_multi_init();

						/* Iterate over each entry */
						for ($i = 0; $i < count($this->input()); $i ++) {
							$ctrl->insert($argv);
						}

						/* Commit responses */
						$this->_multi_commit();
					} else {
						$this->error('Invalid data type detected in entity body.');
						$this->output('400');
					}
				} else {
					/* Object method is not implemented (no handler declared) */
					$this->error('No handler declared for POST (insert).');

					/* Not found */
					$this->output('404');
				}
			} break;

			case 'PATCH': {
				/* Initialize environment */
				$this->init($ctrl, 'modify', $argv);

				/* Validate RESTful request */
				$this->validate();

				if (method_exists($ctrl, 'modify')) {
					/* Check if this is a request with a single entity entry... */
					if (count(array_filter(array_keys($this->input()), 'is_string')) > 0) {
						/* Single entry */
						$ctrl->modify($argv);
					} else if (gettype($this->input()) != 'string') {
						/* Multiple entries */
						$this->_multi_init();

						/* Iterate over each entry */
						for ($i = 0; $i < count($this->input()); $i ++) {
							$ctrl->modify($argv);
						}

						/* Commit responses */
						$this->_multi_commit();
					} else {
						$this->error('Invalid data type detected in entity body.');
						$this->output('400');
					}
				} else {
					/* Object method is not implemented (no handler declared) */
					$this->error('No handler declared for PATCH (modify).');

					/* Not found */
					$this->output('404');
				}
			} break;

			case 'PUT': {
				/* Initialize environment */
				$this->init($ctrl, 'update', $argv);

				/* Validate RESTful request */
				$this->validate();

				if (method_exists($ctrl, 'update')) {
					/* Check if this is a request with a single entity entry... */
					if (count(array_filter(array_keys($this->input()), 'is_string')) > 0) {
						/* Single entry */
						$ctrl->update($argv);
					} else if (gettype($this->input()) != 'string') {
						/* Multiple entries */
						$this->_multi_init();

						/* Iterate over each entry */
						for ($i = 0; $i < count($this->input()); $i ++) {
							$ctrl->update($argv);
						}

						/* Commit responses */
						$this->_multi_commit();
					} else {
						$this->error('Invalid data type detected in entity body.');
						$this->output('400');
					}
				} else {
					/* Object method is not implemented (no handler declared) */
					$this->error('No handler declared for PUT (update).');

					/* Not found */
					$this->output('404');
				}
			} break;

			case 'DELETE': {
				/* Initialize environment */
				$this->init($ctrl, 'delete', $argv);

				/* Validate RESTful request */
				$this->validate();

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
				/* Initialize environment */
				$this->init($ctrl, 'options', $argv);

				/* Validate RESTful request */
				$this->validate();

				/* Initialize allow array (used to set Allow header) */
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
				$this->header('allow', implode(', ', $allow));

				/* If there is a options() method defined on this controller, call it */
				if (method_exists($ctrl, 'options')) {
					$ctrl->options();
				} else {
					/* Otherwise just return 200 OK */
					$this->output('200');
				}
			} break;

			default: {
				/* Initialize environment */
				$this->init($ctrl, NULL, NULL);

				/* Validate RESTful request */
				$this->validate(); /* This is expected to fail with: 405 Method Not Allowed */
			}
		}
	}

	public function request($method, $url, $data = NULL, $headers = NULL, &$status_code = false, &$raw_output = false, $timeout_conn = NULL /* in ms */, $timeout_exec = NULL /* in ms */, $basic_auth = NULL) {
            /* Set required request headers */
			if ($headers === NULL) {
				$req_headers = array(
					'accept: application/json',
					'content-type: application/json'
				);
			} else {
				$req_headers = $headers;
			}

            /* Forward request to the underlying layer (notify) */
            $ch = curl_init();

            /* Set the request URL */
            curl_setopt($ch, CURLOPT_URL, $url);

			/* Check if basic authentication is defined */
			if ($basic_auth !== NULL)
				curl_setopt($ch, CURLOPT_USERPWD, $basic_auth);

            /* Set cURL request headers */
            curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);

			/* Set connection timeout value, if set (from milliseconds) */
			if ($timeout_conn !== NULL)
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, intval($timeout_conn / 1000));

			/* Set max lifetime for the connection, if set (from milliseconds) */
			if ($timeout_exec !== NULL)
				curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout_exec);

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

			/* Set HTTP/2 protocol, if available */
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);

            /* Grant that cURL will return the response output */
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            /* Execute the request */
            $output = curl_exec($ch);

			/* Check for cURL errors */
			if (curl_errno($ch)) {
				error_log(__FILE__ . ': ' . __FUNCTION__ . ': cURL error: ' . curl_error($ch));
				curl_close($ch);

				$status_code = NULL;
				$raw_output = NULL;

				return NULL;
			}

			/* Set status code, if requested */
			if ($status_code !== false)
				$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			/* Set raw output, if requested */
			if ($raw_output !== false)
				$raw_output = $output;

            /* Close the cURL handler */
            curl_close($ch);

            /* All good */
            return json_decode($output, true);
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
			if (method_exists($this->_doc_object, 'insert') && $accepted_collection && $uri_collection !== false)
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
		if ($response_types !== false)
			$this->_doc['method'][$method]['response']['types'][$func] = $response_types;

		/* Body */
		if ($response_body_args !== false) {
			foreach ($response_body_args as $k => $v) {
				$this->_doc['method'][$method]['response']['body'][$func][$k] = $v;
			}
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

