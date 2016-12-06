<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 06/12/2016
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

class UW_ND extends UW_Module {
	public function log($code, $file, $line, $function, $message, $session = NULL) {
		error_log(
			'[' . $code . ']: ' .
			$file . ':' .
			$line . ': ' .
			$function . '(): ' .
			$message . '.' .
			($session !== NULL ? ' [UserID: ' . $session['user_id'] . ']' : '')
		);
	}

	public function request($uri, $data = NULL, $session = NULL) {
		/* Set required request headers */
		$req_headers = array(
			'Accept: application/json'
		);

		/* If there's body data to be set, set the Content-Type header */
		if (is_array($data))
			array_push($req_headers, 'Content-Type: application/json');

		/* Get X-Forwarded-For value, if any */
		$xfrd = $this->restful->header('X-Forwarded-For');

		if ($xfrd !== NULL) {
			/* If X-Forwarded-For is already set, append the remote ip address to it... */
			array_push($req_headers, 'X-Forwarded-For: ' . $xfrd . ', ' . $_SERVER['REMOTE_ADDR']);
			array_push($req_headers, 'X-Real-IP: ' . trim(explode(',', $xfrd)[0]));
		} else {
			/* Otherwise, set a brand new X-Forwarded-For header */
			array_push($req_headers, 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR']);
			array_push($req_headers, 'X-Real-IP: ' . $_SERVER['REMOTE_ADDR']);
		}

		/* Forward request to the backend engine (nd-php) */
		$ch = curl_init();

		/* Set the request URL */
		curl_setopt($ch, CURLOPT_URL, ND_REQ_BACKEND_BASE_URL . $uri);

		/* Set cURL request headers */
		curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);

		/* Set request body data, if any */
		if ($data !== NULL) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
		}

		/* Set session cookie data, if any */
		if ($session !== NULL) {
			curl_setopt($ch, CURLOPT_COOKIESESSION, false);
			curl_setopt($ch, CURLOPT_COOKIE, $session['cookie']);
		}

		/* Replace User-Agent, if required */
		if (ND_REQ_USER_AGENT_REPLACE === true)
			curl_setopt($ch, CURLOPT_USERAGENT, ND_REQ_USER_AGENT_NAME . ' ' . ND_REQ_USER_AGENT_VER);

		/* Grant that cURL will return the response output */
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		/* Execute the request */
		$output = curl_exec($ch);

		/* Close the cURL handler */
		curl_close($ch);

		/* Check if the response contains data */
		if (!$output) {
			$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Empty response from the underlying layer.', $session);
			$this->restful->error('An error ocurred while retrieving data from the backend. No data received. Please contact support.');
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* Decode JSON data */
		$nd_data = json_decode($output, true);

		/* Check if JSON data was successfully decoded */
		if ($nd_data === NULL) {
			/* Cannot decode JSON data */
			$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Unable to decode JSON data from the underlying response body.', $session);
			$this->restful->error('An error ocurred while decoding data from the backend. Please contact support.');
			$this->restful->output('500'); /* Internal Server Error */
		} else if ($nd_data['status'] !== true) {
			/* The request was understood, but the backend engine is refusing to fulfill it */
			$this->log(isset($nd_data['code']) ? $nd_data['code'] : '500', __FILE__, __LINE__, __FUNCTION__, 'Request was not successful: ' . $nd_data['content'] . '.', $session);
			$this->restful->error($nd_data['content']);
			$this->restful->output(isset($nd_data['code']) ? $nd_data['code'] : '500');
		} else if (!isset($nd_data['data'])) {
			/* The request was understood, but the backend engine is refusing to fulfill it */
			$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Response contains no data field set.', $session);
			$this->restful->error('Failed to retrieve the requested data.');
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* All good */
		return $nd_data['data'];
	}

	public function session_init() {
		/* Get user id and authentication token from request headers */
		$user_id    = $this->restful->header(ND_REQ_HEADER_USER_ID);
		$auth_token = $this->restful->header(ND_REQ_HEADER_AUTH_TOKEN);

		/* Grant that user id header is set */
		if (!$user_id || !is_numeric($user_id)) {
			$this->log('401', __FILE__, __LINE__, __FUNCTION__, ND_REQ_HEADER_USER_ID . ' header is not set, is invalid, or contains no data.');
			$this->restful->error(ND_REQ_HEADER_USER_ID . ' header is not set, is invalid, or contains no data.');
			$this->restful->output('401'); /* Unauthorized */
		}

		/* Grant that authentication token header is set */
		if (!$auth_token || strlen($auth_token) != 40 || hex2bin($auth_token) === false) {
			$this->log('401', __FILE__, __LINE__, __FUNCTION__, ND_REQ_HEADER_AUTH_TOKEN . ' header is not set, is invalid, or contains no data.', array('user_id' => $user_id, 'token' => NULL));
			$this->restful->error(ND_REQ_HEADER_AUTH_TOKEN . ' header is not set, is invalid, or contains no data.');
			$this->restful->output('401'); /* Unauthorized */
		}

		/* Get session cookie */
		$enc_session_cookie = $this->cache->get('nd_user_session_' . $user_id);

		/* If we're unable to fetch the session cookie, the user needs to re-authenticate */
		if (!$enc_session_cookie) {
			$this->log('401', __FILE__, __LINE__, __FUNCTION__, 'Cannot retrieve session data. Authentication required.', array('user_id' => $user_id, 'token' => NULL));
			$this->restful->error('Cannot retrieve session data. Authentication required.');
			$this->restful->output('401'); /* Unauthorized */
		}

		/* Decrypt session cookie (We need do rtrim any paddings left from decryption) */
		$session_cookie = rtrim($this->encrypt->decrypt($enc_session_cookie, hex2bin($auth_token), false));

		/* If we're unable to decrypt the session cookie, an invalid authentication token was used */
		if (!$session_cookie || strstr($session_cookie, 'HttpOnly') === false) { /* NOTE: We're searching for a plain HttpOnly in order to detect (earlier) that the data was decrypted */
			$this->log('401', __FILE__, __LINE__, __FUNCTION__, 'Cannot decrypt session data.', array('user_id' => $user_id, 'token' => NULL));
			$this->restful->error('Invalid authentication token.');
			$this->restful->output('401'); /* Unauthorized */
		}

		/* Return session data */
		return array(
			'user_id' => $user_id,
			'token'   => $auth_token,
			'cookie'  => $session_cookie
		);
	}

	public function session_destroy($session) {
		/* Delete cached session data */
		$this->cache->delete('nd_user_session_' . $session['user_id']);
	}

	public function user_register($register) {
		/* Validate username length */
		if (strlen($register['username']) < 5) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Registration failed: Username ' . $register['username'] . ' must be greater than 5 characters.');
			$this->restful->error('Username length must be equal or greater than 5 characters.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Validate password length */
		if (strlen($register['password']) < 8) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Registration failed: Password for user ' . $register['username'] . ' must be equal or greater than 8 characters.');
			$this->restful->error('Password length must be equal or greater than 8 characters.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Grant that we've all the required fields */
		foreach (array('first_name', 'last_name', 'username', 'password', 'password_check', 'email', 'countries_id', 'terms') as $field) {
			if (!isset($register[$field])) {
				$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Registration failed: Missing required field: ' . $field . '. (Requested by username ' . $register['username'] . ')');
				$this->restful->error('Missing required field: ' . $field);
				$this->restful->output('403'); /* Forbidden */
			}
		}

		/* Forward registration request to the backend engine (nd-php) */
		$nd_data = $this->request('/register/newuser' . $argv[0], $register);

		/* Check if the required data is present */
		if (!isset($nd_data['user_id']) || !isset($nd_data['registered']) || $nd_data['registered'] !== true) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Registration failed for user \'' . $register['username'] . '\': Required data from the backend is missing.');
			$this->restful->error('An error occurred. Please contact support.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Set the response data */
		$data['userid'] = $nd_data['user_id'];
		$data['registered']  = true;

		/* All good */
		return $data;
	}

	public function user_authenticate($auth) {
		/* Forward authentication request to the backend engine (nd-php)
		 * NOTE: This is a special case where we need to also process the response headers, so we won't use $this->request() here.
		 */
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, ND_REQ_BACKEND_BASE_URL . '/login/authenticate');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Accept: application/json',
			'Content-Type: application/json'
		));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($auth));
		curl_setopt($ch, CURLOPT_HEADER, true);

		/* Check if we should replace user agent */
		if (ND_REQ_USER_AGENT_REPLACE === true)
			curl_setopt($ch, CURLOPT_USERAGENT, ND_REQ_USER_AGENT_NAME . ' ' . ND_REQ_USER_AGENT_VER);

		$output = curl_exec($ch);
		curl_close($ch);

		/* If the response is empty, we cannot proceed */
		if (!$output) {
			$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Empty response from the underlying layer.');
			$this->restful->error('An error ocurred while retrieving data from the backend. No data received. Please contact support.');
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* Fetch cookie from headers */
		$headers = array_slice(explode("\r\n", $output), 0, -1);
		$cookie_header = NULL;

		foreach ($headers as $header) {
			if (substr($header, 0, 12) == 'Set-Cookie: ')
				$session_cookie = substr($header, 12);
		}

		/* If no Set-Cookie was found, we shall not proceed. */
		if ($session_cookie === NULL) {
			$this->log('401', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': No Set-Cookie was found.');
			$this->restful->error('Authentication failed. Invalid username or password.');
			$this->restful->output('401'); /* Unauthorized */
		}

		/* Fetch response body */
		$body = array_slice(explode("\r\n", $output), -1)[0];

		/* Decode JSON data */
		$data_raw = json_decode($body, true);

		/* If we're unable to decode the JSON data present in the body, we cannot proceed */
		if ($data_raw === NULL) {
			$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Unable to decode JSON data from the underlying response body.');
			$this->restful->error('An error ocurred while decoding data from the backend. Please contact support.');
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* Check if request was successful */
		if ($data_raw['status'] !== true) {
			$this->log(isset($data_raw['code']) ? $data_raw['code'] : '500', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Request was not successful: ' . $data_raw['content'] . '.');
			$this->restful->error($data_raw['content']);
			$this->restful->output(isset($data_raw['code']) ? $data_raw['code'] : '500');
		}

		/* Check if the required data is present */
		if (!isset($data_raw['data']['user_id']) || !isset($data_raw['data']['apikey'])) {
			$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Required data from the underlying layer is missing.');
			$this->restful->error('An error ocurred while retrieving data from the backend. Data is incomplete. Please contact support.');
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* Check if the required data is valid */
		if (!is_numeric($data_raw['data']['user_id']) || strlen($data_raw['data']['apikey']) != 40 || hex2bin($data_raw['data']['apikey']) === false) {
			$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Received data from the underlying layer is invalid.');
			$this->restful->error('An error ocurred while retrieving data from the backend. Data is invalid. Please contact support.');
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* Set the response data */
		$data['userid'] = intval($data_raw['data']['user_id']);
		$data['token']  = $data_raw['data']['apikey'];
		$data['roles'] = $data_raw['data']['roles'];

		/* Extract session lifetime from Max-Age */
		if (preg_match('/Max-Age=(\d+);/i', $session_cookie, $matches) !== 1) {
			$this->log('N/A', __FILE__, __LINE__, __FUNCTION__, 'Unable to retrieve session lifetime for user \'' . $auth['username'] . '\'. Using default (' . ND_REQ_SESSION_LIFETIME . ').');

			/* Since we couldn't extract the session lifetime value from the cookie, we'll use the default */
			$session_lifetime = ND_REQ_SESSION_LIFETIME;
		} else {
			/* Set the session lifetime value from what was extracted from the cookie */
			$session_lifetime = $matches[1];
		}
		
		/* Encrypt session cookie with user authentication token. FIXME: Limited to  */
		$enc_session_cookie = $this->encrypt->encrypt($session_cookie, hex2bin($data['token']), false);

		/* Set user properties */
		$user_data = array();
		$user_data['timezone'] = $data_raw['data']['timezone'];

		/* Cache session information */
		$this->cache->set('nd_user_session_' . $data['userid'], $enc_session_cookie, $session_lifetime);

		/* Cache user data */		
		$this->cache->set('nd_user_data_' . $data['userid'], $user_data, $session_lifetime);

		/* All good */
		return $data;
	}

	public function user_data($user_id = NULL) {
		if ($user_id === NULL)
			$user_id = $this->restful->header(ND_REQ_HEADER_USER_ID);

		return $this->cache->get('nd_user_data_' . $user_id);
	}

	public function user_logout() {
		/* Retrieve authentication and session data from headers */
		$session = $this->session_init();

		/* Forward request to backend engine (nd-php) */
		$nd_data = $this->request('/login/logout' . $argv[0], NULL, $session);

		/* Check if the logout was successful */
		if ($nd_data['logout'] !== true) {
			/* Not found */
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Logout failed.', $session);
			$this->restful->error('Logout failed.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* TODO: FIXME: Call /login/logout on ndphp layer */

		/* Destroy user session */
		$this->session_destroy($session);
	}

	public function search_ndsl($input, $session) {
		/** Sanitize input **/

		/* Check if all properties are acceptable */
		foreach ($input as $k => $v) {
			if (!in_array($k, array('limit', 'offset', 'orderby', 'ordering', 'show', 'query'))) {
				$this->log('406', __FILE__, __LINE__, __FUNCTION__, 'Unacceptable property found: ' . $k, $session);
				$this->restful->error('Unacceptable property found: ' . $k);
				$this->restful->output('406'); /* Not Acceptable */
			}
		}

		/* Check limit property */
		if (isset($input['limit'])) {
			/* Validate type */
			if (gettype($input['limit']) != 'integer') {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid type detected for property \'limit\': Expecting integer type.', $session);
				$this->restful->error('Invalid type detected for property \'limit\': Expecting integer type.');
				$this->restful->output('400'); /* Bad Request */
			}

			/* Validate value */
			if ($input['limit'] <= 0) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value detected for property \'limit\': Must be greater than zero.', $session);
				$this->restful->error('Invalid value detected for property \'limit\': Must be greater than 0.');
				$this->restful->output('400'); /* Bad Request */
			}

			/* Set property to request body */
			$data['_limit'] = $input['limit'];
		}

		/* Check offset property */
		if (isset($input['offset'])) {
			/* Validate type */
			if (gettype($input['offset']) != 'integer') {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid type detected for property \'offset\': Expecting integer type.', $session);
				$this->restful->error('Invalid type detected for property \'offset\': Expecting integer type.');
				$this->restful->output('400'); /* Bad Request */
			}

			/* Validate value */
			if ($input['offset'] < 0) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value detected for property \'offset\': Must be equal or greater than zero.', $session);
				$this->restful->error('Invalid value detected for property \'offset\': Must be equal or greater than 0.');
				$this->restful->output('400'); /* Bad Request */
			}

			/* Set property to request body */
			$data['_offset'] = $input['offset'];
		}

		/* Check orderby property */
		if (isset($input['orderby'])) {
			/* Validate type */
			if (gettype($input['orderby']) != 'string') {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid type detected for property \'orderby\': Expecting string type.', $session);
				$this->restful->error('Invalid type detected for property \'orderby\': Expecting string type.');
				$this->restful->output('400'); /* Bad Request */
			}

			/* Set property to request body */
			$data['_orderby'] = $input['orderby'];
		}

		/* Check ordering property */
		if (isset($input['ordering'])) {
			/* Validate type */
			if (gettype($input['ordering']) != 'string') {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid type detected for property \'ordering\': Expecting string type.', $session);
				$this->restful->error('Invalid type detected for property \'ordering\': Expecting string type.');
				$this->restful->output('400'); /* Bad Request */
			}

			/* Validate value */
			if (!in_array($input['ordering'], array("asc", "desc"))) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value detected for property \'ordering\': Expecting "asc" or "desc".', $session);
				$this->restful->error('Invalid value detected for property \'ordering\': Expecting "asc" or "desc".');
				$this->restful->output('400'); /* Bad Request */
			}

			/* Set property to request body */
			$data['_ordering'] = $input['ordering'];
		}

		/* Initialize NDSL Query */
		$ndslq = array();

		/* Check query property */
		if (isset($input['query'])) {
			/* Validate type */
			if (gettype($input['query']) != 'array') {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid type detected for property \'query\': Expecting array type.', $session);
				$this->restful->error('Invalid type detected for property \'query\': Expecting array type.');
				$this->restful->output('400'); /* Bad Request */
			}

			/* TODO: Pre-check/validate NDSL Query value */

			/* Set NDSL Query */
			$ndslq = $input['query'];

			/* Check show property */
			if (isset($input['show'])) {
				/* Validate type */
				if (gettype($input['show']) != 'array') {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid type detected for property \'show\': Expecting array type.', $session);
					$this->restful->error('Invalid type detected for property \'show\': Expecting array type.');
					$this->restful->output('400'); /* Bad Request */
				}

				/* Set property to NDSL Query */
				$ndslq['_show'] = $input['show'];
			}
		}

		/* Encode NDSL query into search_value property */
		$data['search_value'] = json_encode($ndslq);

		/* All good */
		return $data;
	}


	/** ND WebAPI interfaces **/

	public function view($ctrl, $argv = NULL, $fields = array()) {
		$fields_mapped  = isset($fields['mapped']) ? $fields['mapped'] : array();
		$fields_visible = isset($fields['visible']) ? $fields['visible'] : array();

		/* Retrieve authentication and session data from headers */
		$session = $this->session_init();

		/* Grant that entry ID is set */
		if ($argv === NULL || (count($argv) != 1) || !is_numeric($argv[0])) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Missing or invalid entry ID.', $session);
			$this->restful->error('Missing or invalid entry ID.');
			$this->restful->output('400'); /* Bad request */
		}

		/* Forward request to backend engine (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/view/' . $argv[0], NULL, $session);

		/* Check if the entry was found */
		if (!isset($nd_data['fields']) || !count($nd_data['fields'])) {
			/* Not found */
			$this->log('204', __FILE__, __LINE__, __FUNCTION__, 'Entry exists, but no usable data was found.', $session);
			$this->restful->error('Entry exists, but no usable data was found.');
			$this->restful->output('204'); /* No Content */
		}

		/** Aggregate fields **/

		/* Set basic fields */
		$entry = $nd_data['fields'][0];

		/* Aggregate multiple relationship fields */
		if (isset($nd_data['rel'])) {
			foreach ($nd_data['rel'] as $k => $v) {
				$entry[$k] = $v;
			}
		}

		/* Aggregate mixed fields */
		if (isset($nd_data['mixed'])) {
			foreach ($nd_data['mixed'] as $k => $v) {
				$entry[$k] = $v;
			}
		}

		/** Mangle results according to $fields_mapped and $fields_visible **/

		/* Set a temporary row to be safely iterated */
		$row = $entry;

		/* Iterate the row and make any required changes in the $fields array */
		foreach ($row as $k => $v) {
			/* If $fields_visible is set, filter out fields not present in the array */
			if (count($fields_visible) && !in_array($k, $fields_visible)) {
				unset($entry[$k]);
				continue;
			}

			/* Multiple relationship values are delivered as a comma separated list (string type).
			 * This requires proper conversion to an integer array.
			 */
			if (substr($k, 4) == 'rel_') {
				$entry[$k] = $v
					? /* if $v contains data, map it to an integer array */
					array_map(
						function($x) { return intval($x); },
						explode(',', $v)
					)
					: /* otherwise, set the value as an empty array */
					array();
			}

			/* If there the field is mapped, renamed it */
			if (isset($fields_mapped[$k])) {
				unset($entry[$k]);
				$entry[$fields_mapped[$k]] = $v;
			}
		}

		/* All good */
		return $entry;
	}


	public function list_default($ctrl, $argv = NULL, $fields = array()) {
		$fields_mapped  = isset($fields['mapped']) ? $fields['mapped'] : array();
		$fields_visible = isset($fields['visible']) ? $fields['visible'] : array();

		/* Retrieve authentication and session data from headers */
		$session = $this->session_init();

		/* Initialize data */
		$reqbody['_userid'] = $session['user_id'];
		$reqbody['_apikey'] = $session['token'];
		$reqbody['data'] = NULL;

		/* Check if there are conditions to be set */
		if ($argv !== NULL) {
			if (isset($argv[0]))
				$reqbody['data']['_limit'] = abs(intval($argv[0]));

			if (isset($argv[1]))
				$reqbody['data']['_offset'] = abs(intval($argv[1]));

			if (isset($argv[2]))
				$reqbody['data']['_orderby'] = preg_match('/^[a-zA-Z0-9_]+$/', $argv[2]) ? $argv[2] : 'id';

			if (isset($argv[3]))
				$reqbody['data']['_ordering'] = (strtolower($argv[3]) == 'desc') ? 'desc' : 'asc';
		}

		/* Forward request to backend engine (nd-php) */
		if ($reqbody['data'] !== NULL) {
			$nd_data = $this->request('/' . $ctrl . '/list_default', $reqbody, $session);
		} else {
			$nd_data = $this->request('/' . $ctrl . '/list_default', NULL, $session);
		}

		/* Iterate the result array, mangling required types to match proper JSON responses */
		for ($i = 0; $i < $nd_data['count']; $i ++) {
			/* Set a temporary row to be safely iterated */
			$row = $nd_data['result'][$i];

			/* Mangle data, if required */
			foreach ($row as $k => $v) {
				/* Filter fields that are not present in $fields_visible */
				if (count($fields_visible) && !in_array($k, $fields_visible)) {
					unset($nd_data['result'][$i][$k]);
					continue;
				}

				/* Multiple relationship values are delivered as a comma separated list (string type).
				 * This requires proper conversion to an integer array.
				 */
				if (substr($k, 4) == 'rel_') {
					$nd_data['result'][$i][$k] = $v
						? /* if $v contains data, map it to an integer array */
						array_map(
							function($x) { return intval($x); },
							explode(',', $v)
						)
						: /* otherwise, set the value as an empty array */
						array();
				}

				/* Rename fields that are set in $fields_mapped */
				if (isset($fields_mapped[$k])) {
					unset($nd_data['result'][$i][$k]);
					$nd_data['result'][$i][$fields_mapped[$k]] = $v;
				}
			}
		}

		/* All good */
		return $nd_data;
	}


	public function insert($ctrl, $argv = NULL, $input = array(), $fields = array()) {
		$fields_accepted = isset($fields['accepted']) ? $fields['accepted'] : array();
		$fields_mapped   = isset($fields['mapped']) ? $fields['mapped'] : array();
		$fields_required = isset($fields['required']) ? $fields['required'] : array();

		/* Retrieve authentication and session data from headers */
		$session = $this->session_init();

		/* Validate arguments, if any */
		if ($argv !== NULL) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Cannot insert with the specified ID.', $session);
			$this->restful->error('Cannot insert with the specified ID.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Check if there's any input */
		if (!count($input)) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'No input data fields could found.', $session);
			$this->restful->error('No input data fields could be found.');
			$this->restful->output('400'); /* Bad Request */
		}

		/* Check if the required fields are present */
		foreach ($fields_required as $rfield) {
			if (!isset($input[$rfield])) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Missing required field: ' . $rfield, $session);
				$this->restful->error('Missing required field: ' . $rfield);
				$this->restful->output('400'); /* Bad request */
			}
		}

		/* Sanitize input */
		$entry = array();
		$reqbody = array();

		foreach ($input as $k => $v) {
			/* Any field not present in $fields_accepted will cause a bad request */
			if (!in_array($k, $fields_accepted)) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unacceptable field: ' . $k, $session);
				$this->restful->error('Unacceptable field: ' . $k);
				$this->restful->output('400'); /* Bad request */
			}

			/* Check if key is mapped to something else... */
			if (isset($fields_mapped[$k])) {
				$entry[$fields_mapped[$k]] = $v;
			} else {
				$entry[$k] = $v;
			}
		}

		/* Set request data for the backend engine (nd-php) */
		$reqbody['_userid'] = $session['user_id'];
		$reqbody['_apikey'] = $session['token'];
		$reqbody['data'] = $entry;

		/* Forward the insert request to the backend engine (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/insert', $reqbody, $session);

		/* Check if the entry was successfully updated */
		if (!isset($nd_data['inserted']) || ($nd_data['inserted'] !== true)) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Update failed: Unable to insert entry.', $session);
			$this->restful->error('Unable to insert entry.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Set inserted id */
		$data['id'] = $nd_data['insert_id'];

		/* All good */
		return $data;
	}


	public function update($ctrl, $argv = NULL, $input = array(), $fields = array()) {
		$fields_accepted = isset($fields['accepted']) ? $fields['accepted'] : array();
		$fields_mapped   = isset($fields['mapped']) ? $fields['mapped'] : array();

		/* Retrieve authentication and session data from headers */
		$session = $this->session_init();

		/* Validate arguments, if any */
		if ($argv === NULL || (count($argv) != 1) || !is_numeric($argv[0])) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Cannot modify the entire collection.', $session);
			$this->restful->error('Cannot modify the entire collection.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Check if there's any input */
		if (!count($input)) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'No input data fields could found.', $session);
			$this->restful->error('No input data fields could be found.');
			$this->restful->output('400'); /* Bad Request */
		}

		/* Sanitize input */
		$entry = array();
		$reqbody = array();

		foreach ($input as $k => $v) {
			if (!in_array($k, $fields_accepted)) {
				/* Any field not present in $fields_accepted will cause a bad request */
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unacceptable field: ' . $k, $session);
				$this->restful->error('Unacceptable field: ' . $k);
				$this->restful->output('400'); /* Bad request */
			}

			/* Check if key is mapped to something else... */
			if (isset($fields_mapped[$k])) {
				$entry[$fields_mapped[$k]] = $v;
			} else {
				$entry[$k] = $v;
			}
		}

		/* Set request data for the backend engine (nd-php) */
		$reqbody['_userid'] = $session['user_id'];
		$reqbody['_apikey'] = $session['token'];
		$reqbody['data'] = $entry;

		/* Forward the update request to the backend engine (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/update/' . $argv[0], $reqbody, $session);

		/* Check if the entry was successfully updated */
		if (!isset($nd_data['updated']) || ($nd_data['updated'] !== true)) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Update failed: Unable to update entry.', $session);
			$this->restful->error('Unable to update entry.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* All good */
		return;
	}


	public function delete($ctrl, $argv = NULL) {
		/* Retrieve authentication and session data from headers */
		$session = $this->session_init();

		/* Validate arguments, if any */
		if ($argv === NULL || (count($argv) != 1) || !is_numeric($argv[0])) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Cannot delete the entire collection.', $session);
			$this->restful->error('Cannot delete the entire collection.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Forward request to backend engine (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/delete/' . $argv[0], NULL, $session);

		/* Check if the entry was successfully deleted */
		if (!isset($nd_data['deleted']) || ($nd_data['deleted'] !== true)) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Delete failed: Unable to delete entry.', $session);
			$this->restful->error('Unable to delete entry.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* All good */
		return ;
	}


	public function search($ctrl, $input = array(), $fields = array()) {
		$fields_accepted    = isset($fields['accepted']) ? $fields['accepted'] : array();
		$fields_mapped_pre  = isset($fields['mapped_pre']) ? $fields['mapped_pre'] : array();
		$fields_mapped_post = isset($fields['mapped_post']) ? $fields['mapped_post'] : array();
		$fields_visible     = isset($fields['visible']) ? $fields['visible'] : array();

		/* Retrieve authentication and session data from headers */
		$session = $this->session_init();

		/* Only POST method is accepted for this call */
		if ($this->restful->method() != 'POST') {
			$this->log('405', __FILE__, __LINE__, __FUNCTION__, 'Only POST method is allowed to be used for searches.', $session);
			$this->restful->error('Only POST method is allowed to be used for searches.');
			$this->restful->output('405'); /* Method Not Allowed */
		}

		/* Check if there's any input */
		if (!count($input) || !isset($input['query'])) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'No input data fields could found or search query is missing.', $session);
			$this->restful->error('No input data fields could be found or search query is missing.');
			$this->restful->output('400'); /* Bad Request */
		}

		/* Sanitize input and rename any mapped fields */
		$query = array();

		foreach ($input['query'] as $k => $v) {
			/* If $fields_accepted is set, grant that the search query only targets the accepted fields  */
			if (count($fields_accepted) && !in_array($k, $fields_accepted)) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unacceptable field: ' . $k, $session);
				$this->restful->error('Unacceptable field: ' . $k);
				$this->restful->output('400'); /* Bad Request */
			}

			/* Check if key is mapped to something else... */
			if (isset($fields_mapped_pre[$k])) {
				$query[$fields_mapped_pre[$k]] = $v;
			} else {
				$query[$k] = $v;
			}
		}

		/* Set the new query array */
		$input['query'] = $query;

		/* If both $fields_visible and 'show' array are set:
		 *  - Unset any fields from 'show' array that are not set in $fields_visible.
		 *
		 * Also rename any fields that are mapped by $fields_mapped_pre array.
		 *
		 */
		if (isset($input['show'])) {
			/* Initialize a new show array */
			$show = array();

			/* Iterate show array, filtering out any fields not present in $fields_visible (is set) */
			foreach ($input['show'] as $v) {
				if (count($fields_visible) && !in_array($v, $fields_visible))
					continue;

				/* Rename the field, if it's mapped... */
				if (isset($fields_mapped_pre[$v])) {
					array_push($show, $fields_mapped_pre[$v]);
				} else {
					/* Otherwise, just set the original name */
					array_push($show, $v);
				}
			}

			/* Set the new show array */
			$input['show'] = $show;
		}

		/* Set request data */
		$reqbody['_userid'] = $session['user_id'];
		$reqbody['_apikey'] = $session['token'];
		$reqbody['data']    = $this->search_ndsl($input, $session);

		/* Forward the update request to the backend engine (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/result/basic', $reqbody, $session);

		/* Check if the received type is what we're expecting */
		if (gettype($nd_data['result']) != 'array') {
			$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Invalid data received from the underlying layer: Unexpected type (Expecting array).', $session);
			$this->restful->error('An error ocurred while retrieving data from the backend. Data type is invalid. Please contact support.');
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* If we've received an empty array, the search succeded, but no results were found... */
		if (!$nd_data['count'])
			$this->restful->output('201'); /* Search was peformed, but no content was delivered */


		/* Iterate over the result array, converting any types required and mapped fields */
		for ($i = 0; $i < $nd_data['count']; $i ++) {
			/* Set a temporary row to be safely iterated */
			$row = $nd_data['result'][$i];

			/* Mangle that, if required... */
			foreach ($row as $k => $v) {
				/* Multiple relationship values are delivered as a comma separated list (string type).
				 * This requires proper conversion to an integer array.
				 */
				if (substr($k, 4) == 'rel_') {
					$nd_data['result'][$i][$k] = $v
						? /* if $v contains data, map it to an integer array */
						array_map(
							function($x) { return intval($x); },
							explode(',', $v)
						)
						: /* otherwise, set the value as an empty array */
						array();
				}

				if (isset($fields_mapped_post[$k])) {
					unset($nd_data['result'][$i][$k]);
					$nd_data['result'][$i][$fields_mapped_post[$k]] = $v;
				}
			}
		}

		/* Deliver results */
		return $nd_data;
	}


	public function register($argv = NULL, $input = array(), $fields = array()) {
		$fields_accepted = isset($fields['accepted']) ? $fields['accepted'] : array();
		$fields_mapped   = isset($fields['mapped']) ? $fields['mapped'] : array();
		$fields_required = isset($fields['required']) ? $fields['required'] : array();

		/* Validate argument vector */
		if ($argv !== NULL) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Cannot register with the specified ID.');
			$this->restful->error('Cannot register with the specified ID.');
			$this->restful->output('403'); /* Forbidden */
		}

		/** Sanitize input. TODO: Although the underlying layer already sanitize the fields, we shall perform some pre-checks here. **/

		/* Check required fields */
		foreach ($fields_required as $rfield) {
			if (!isset($input[$rfield])) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Missing required field: ' . $rfield);
				$this->restful->error('Missing required field: ' . $rfield);
				$this->restful->output('400'); /* Bad Request */
			}
		}

		/* Check accepted fields and process field mappings */
		foreach ($input as $k => $v) {
			if (!in_array($k, $fields_accepted)) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unacceptable field: ' . $k);
				$this->restful->error('Unacceptable field: ' . $k);
				$this->restful->output('400'); /* Bad Request */
			}

			/* Check if key is mapped to something else... */
			if (isset($fields_mapped[$k])) {
				$register[$fields_mapped[$k]] = $v;
			} else {
				$register[$k] = $v;
			}
		}
		
		/* Replicate the password value to password check value (field required by nd-php) */
		$register['password_check'] = $register['password'];

		/* Register user */
		$data = $this->user_register($register);

		/* All good */
		return $data;
	}
}
