<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Author:   Pedro A. Hortas
 * Email:    pah@ucodev.org
 * Modified: 18/11/2018
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

class UW_ND extends UW_Module {
	private $_session = NULL;


	/** Construct **/

	public function __construct() {
		parent::__construct();

		/* Check if RESTful interface is enabled */
		if (!current_config()['nd']['enabled']) {
			$this->restful->error('uWeb ND interface is disabled');
			$this->restful->output('403');
		}
	}


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
			'accept: application/json'
		);

		/* Add the current request id to the headers, so the remote service can keep track of the origin request */
		array_push($req_headers, current_config()['nd']['header']['request_id'] . ': ' . $this->restful->id());

		/* If there's body data to be set, set the content-type header */
		if (is_array($data))
			array_push($req_headers, 'content-type: application/json');

		/* Check if we can trust the requester headers */
		if (in_array($_SERVER['REMOTE_ADDR'], current_config()['nd']['trusted_sources'])) {
			/* Get x-forwarded-for value, if any */
			$xfrd = $this->restful->header('x-forwarded-for');

			if ($xfrd !== NULL) {
				/* If x-forwarded-for is already set, append the remote ip address to it... */
				array_push($req_headers, 'x-forwarded-for: ' . $xfrd . ', ' . $_SERVER['REMOTE_ADDR']);
				array_push($req_headers, 'x-real-ip: ' . trim(explode(',', $xfrd)[0]));
			} else {
				/* Get X-Real-IP value, if any */
				$xrip = $this->restful->header('x-real-ip');

				if ($xrip !== NULL) {
					/* If x-real-ip is set, use its value to set the new x-forwarded-for and x-real-ip headers values... */
					array_push($req_headers, 'x-forwarded-for: ' . $xrip);
					array_push($req_headers, 'x-real-ip: ' . $xrip);
				}
			}
		} else {	
			/* Otherwise, set a brand new x-forwarded-for header */
			array_push($req_headers, 'x-forwarded-for: ' . $_SERVER['REMOTE_ADDR']);
			array_push($req_headers, 'x-real-ip: ' . $_SERVER['REMOTE_ADDR']);
		}

		/* Forward request to the underlying engine (nd-php) */
		$req = array();

		/* Check if we should accept certain encodings */
		if (current_config()['nd']['encoding']['accept'] !== NULL) {
			/* Grant that we support the selected encoding */
			foreach (current_config()['nd']['encoding']['accept'] as $accept_encoding) {
				switch ($accept_encoding) {
					case 'deflate':
					case 'gzip': break;
					default: {
						$this->restful->error('Unsupported accept-encoding set: ' . current_config()['nd']['encoding']['accept']);
						$this->restful->output('500');
					}
				}
			}

			/* Set accept-encoding header */
			$req['accept_encoding'] = implode(', ', current_config()['nd']['encoding']['accept']);
		}

		/* Set the request URL */
		$req['url'] = current_config()['nd']['backend']['base_url'] . $uri;

		/* Set request method to POST */
		$req['method'] = 'POST';

		/* Assign request data */
		$req['data'] = $data;

		/* Set request body data, if any */
		$req['content_encoding'] = current_config()['nd']['encoding']['content'];

		/* Set cURL request headers */
		$req['headers'] = $req_headers;

		/* Set session cookie data, if any */
		$req['cookie'] = $session['cookie'];

		/* Execute the request */
		$http_status_code = NULL;
		$http_raw_output = NULL;

		$nd_output = $this->restful->request(
			$req['method'],
			$req['url'],
			$req['data'],
			$req['headers'],
			$http_status_code,
			$http_raw_output,
            current_config()['nd']['timeout']['connect'],
            current_config()['nd']['timeout']['execute'],
			NULL,
			true,
			$req['accept_encoding'],
			$req['content_encoding'],
			$req['cookie']
		);

		/* Reassign output (data is already JSON decoded) */
		$nd_data = $nd_output;

		/* Check if JSON data was successfully decoded */
		if ($nd_data === NULL) {
			/* Cannot decode JSON data */

			/* Map relevant underlying layer errors */
			if (in_array($http_status_code, array(401, 403))) {
				/* Always explicitly inform about unauthorized or forbidden status codes */
				$this->log($http_status_code, __FILE__, __LINE__, __FUNCTION__, 'An authorization or access request was denied while processing the request at the underlying layer: ' . $http_raw_output, $session);
				$this->restful->error('An authorization or access request was denied while processing the request at the underlying layer.');
				$this->restful->output($http_status_code); /* [401] Unauthorized / [403] Forbidden */
			} else if ($http_status_code == 409) {
				/* Conflicting data should be forwarded from the underlying layer to the client */
				$this->log($http_status_code, __FILE__, __LINE__, __FUNCTION__, 'A conflict occured while processing the request at the underlying layer: ' . $http_raw_output, $session);
				$this->restful->error('A conflict occured while processing the request at the underlying layer.');
				$this->restful->output($http_status_code); /* [409] Conflict */
			} else if (!$http_raw_output) {
				/* Check if the response contains data */
				$this->log('502', __FILE__, __LINE__, __FUNCTION__, 'Empty response from the underlying layer.', $session);
				$this->restful->error('An error ocurred while retrieving data from the underlying layer. No data received. Please contact support.');
				$this->restful->output('502'); /* Bad Gateway */
			} else {
				/* Mask any other status as Bad Gateway */
				$this->log('502', __FILE__, __LINE__, __FUNCTION__, 'Unable to decode JSON data from the underlying layer response. Output: ' . $http_raw_output, $session);
				$this->restful->error('An error ocurred while decoding data from the underlying layer. Please contact support.');
				$this->restful->output('502'); /* Bad Gateway */
			}
		} else if ($nd_data['status'] !== true) {
			/* The request was understood, but the underlying layer is refusing to fulfill it */
			$this->log(isset($nd_data['code']) ? $nd_data['code'] : '502', __FILE__, __LINE__, __FUNCTION__, 'Request was not successful: ' . $nd_data['content'] . '.', $session);
			$this->restful->error($nd_data['content']);
			$this->restful->output(isset($nd_data['code']) ? $nd_data['code'] : '502');
		} else if (!isset($nd_data['data'])) {
			/* The request was understood, but the underlying layer is refusing to fulfill it */
			$this->log('502', __FILE__, __LINE__, __FUNCTION__, 'Response contains no data field set.', $session);
			$this->restful->error('Failed to retrieve the requested data.');
			$this->restful->output('502'); /* Bad Gateway */
		}

		/* All good */
		return $nd_data['data'];
	}

	public function session_exists() {
		/* NOTE: Even if this routine returns true, it does not always grant that a session was initialized.
		 *       It should only be used to evaluate if session data was sent along with the request.
		 */

		/* Evaluate if session was already initiated / retrieved. If so, deliver the stored data */
		if ($this->_session !== NULL)
			return true;

		/* Get user id and authentication token from request headers */
		$user_id    = $this->restful->header(current_config()['nd']['header']['user_id']);
		$auth_token = $this->restful->header(current_config()['nd']['header']['auth_token']);

		/* Grant that user id header is set */
		if (!$user_id || !is_numeric($user_id))
			return false;

		/* Grant that authentication token header is set */
		if (!$auth_token || strlen($auth_token) != 40 || hex2bin($auth_token) === false)
			return false;

		/* All good */
		return true;
	}

	public function session_init($always_return = false, $after_precheck_return = false) {
		/* Evaluate if session was already initiated / retrieved. If so, deliver the stored data */
		if ($this->_session !== NULL)
			return $this->_session;

		/* Get user id and authentication token from request headers */
		$user_id    = $this->restful->header(current_config()['nd']['header']['user_id']);
		$auth_token = $this->restful->header(current_config()['nd']['header']['auth_token']);

		/* Check if user id header is set */
		if ($user_id === NULL) {
			if ($always_return === true) {
				return array(
					'status' => '401'
				);
			} else {
				$this->log('401', __FILE__, __LINE__, __FUNCTION__, current_config()['nd']['header']['user_id'] . ' header is not set.');
				$this->restful->error(current_config()['nd']['header']['user_id'] . ' header is not set.');
				$this->restful->output('401'); /* Bad Request */
			}			
		}

		/* Grant that user id header is valid */
		if (!is_numeric($user_id)) {
			if ($always_return === true) {
				return array(
					'status' => '400'
				);
			} else {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, current_config()['nd']['header']['user_id'] . ' header is invalid, or contains no data.');
				$this->restful->error(current_config()['nd']['header']['user_id'] . ' header is invalid, or contains no data.');
				$this->restful->output('400'); /* Bad Request */
			}
		}

		/* Check if authentication token header is set */
		if ($auth_token === NULL) {
			if ($always_return === true) {
				return array(
					'status' => '401'
				);
			} else {
				$this->log('401', __FILE__, __LINE__, __FUNCTION__, current_config()['nd']['header']['auth_token'] . ' header is not set.', array('user_id' => $user_id, 'token' => NULL));
				$this->restful->error(current_config()['nd']['header']['auth_token'] . ' header is not set.');
				$this->restful->output('401'); /* Bad Request */
			}
		}

		/* Grant that authentication token header is set */
		if (strlen($auth_token) != 40 || hex2bin($auth_token) === false) {
			if ($always_return === true) {
				return array(
					'status' => '400'
				);
			} else {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, current_config()['nd']['header']['auth_token'] . ' header is invalid, or contains no data.', array('user_id' => $user_id, 'token' => NULL));
				$this->restful->error(current_config()['nd']['header']['auth_token'] . ' header is invalid, or contains no data.');
				$this->restful->output('400'); /* Bad Request */
			}
		}

		/* Load auth cache context */
		$cache_context_orig = $this->cache->context();
		$this->cache->load(current_config()['nd']['cache']['context']['auth']);

		/* Get session cookie */
		$enc_session_cookie = $this->cache->get('nd_user_session_' . sha1($user_id . $auth_token));

		/* Reload original cache context */
		$this->cache->load($cache_context_orig);

		/* If we're unable to fetch the session cookie, the user needs to re-authenticate */
		if (!$enc_session_cookie) {
			if (($always_return === true) || ($after_precheck_return === true)) {
				return array(
					'status' => '401'
				);
			} else {
				$this->log('401', __FILE__, __LINE__, __FUNCTION__, 'Cannot retrieve session data. Authentication required.', array('user_id' => $user_id, 'token' => NULL));
				$this->restful->error('Cannot retrieve session data. Authentication required.');
				$this->restful->output('401'); /* Unauthorized */
			}
		}

		/* Decrypt session cookie (We need do rtrim any paddings left from decryption) */
		$session_cookie = rtrim($this->encrypt->decrypt($enc_session_cookie, hex2bin($auth_token), false));

		/* If we're unable to decrypt the session cookie, an invalid authentication token was used */
		if (!$session_cookie || strstr($session_cookie, 'HttpOnly') === false) { /* NOTE: We're searching for a plain HttpOnly in order to detect (earlier) that the data was decrypted */
			if (($always_return === true) || ($after_precheck_return === true)) {
				return array(
					'status' => '401'
				);
			} else {
				$this->log('401', __FILE__, __LINE__, __FUNCTION__, 'Cannot decrypt session data.', array('user_id' => $user_id, 'token' => NULL));
				$this->restful->error('Invalid authentication token.');
				$this->restful->output('401'); /* Unauthorized */
			}
		}

		/* Store session data */
		$this->_session = array(
			'user_id' => $user_id,
			'token'   => $auth_token,
			'cookie'  => $session_cookie,
			'status'  => '201'
		);

		/* Return session data */
		return $this->_session;
	}

	public function session_destroy($session) {
		/* Load auth cache context */
		$cache_context_orig = $this->cache->context();
		$this->cache->load(current_config()['nd']['cache']['context']['auth']);

		/* Delete cached session data */
		$this->cache->delete('nd_user_session_' . sha1($session['user_id'] . $session['token']));

		/* Reload original cache context */
		$this->cache->load($cache_context_orig);

		/* Destroy stored session data */
		$this->_session = NULL;
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
		foreach (array('username', 'password', 'password_check', 'email', 'terms') as $field) {
			if (!isset($register[$field])) {
				$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Registration failed: Missing required field: ' . $field . '. (Requested by username ' . $register['username'] . ')');
				$this->restful->error('Missing required field: ' . $field);
				$this->restful->output('403'); /* Forbidden */
			}
		}

		/* Forward registration request to the underlying layer (nd-php) */
		$nd_data = $this->request('/register/newuser', $register);

		/* Check if the required data is present */
		if (!isset($nd_data['user_id']) || !isset($nd_data['registered']) || $nd_data['registered'] !== true) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Registration failed for user \'' . $register['username'] . '\': Required data from the underlying layer is missing.');
			$this->restful->error('An error occurred. Please contact support.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Set the response data */
		$data['userid'] = intval($nd_data['user_id']);
		$data['registered'] = true;

		/* All good */
		return $data;
	}

	public function user_authenticate($auth) {
		/* Forward authentication request to the underlying layer (nd-php)
		 * NOTE: This is a special case where we need to also process the response headers, so we won't use $this->request() here.
		 */

		/* Set required headers */
		$req_headers = array(
			'accept: application/json',
			'content-type: application/json'			
		);

		/* Get x-forwarded-for value, if any */
		$xfrd = $this->restful->header('x-forwarded-for');

		if ($xfrd !== NULL) {
			/* If x-forwarded-for is already set, append the remote ip address to it... */
			array_push($req_headers, 'x-forwarded-for: ' . $xfrd . ', ' . $_SERVER['REMOTE_ADDR']);
			array_push($req_headers, 'x-real-ip: ' . trim(explode(',', $xfrd)[0]));
		} else {
			/* Otherwise, set a brand new x-forwarded-for header */
			array_push($req_headers, 'x-forwarded-for: ' . $_SERVER['REMOTE_ADDR']);
			array_push($req_headers, 'x-real-ip: ' . $_SERVER['REMOTE_ADDR']);
		}

		/* Add the current request id to the headers, so the remote service can keep track of the origin request */
		array_push($req_headers, current_config()['nd']['header']['request_id'] . ': ' . $this->restful->id());

		/* Initialize cURL */
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, current_config()['nd']['backend']['base_url'] . '/login/authenticate');	
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($auth));
		curl_setopt($ch, CURLOPT_HEADER, true);

		/* Check if we should replace user agent */
		if (current_config()['nd']['user_agent']['replace'] === true) {
			curl_setopt($ch, CURLOPT_USERAGENT, current_config()['nd']['user_agent']['name'] . ' ' . current_config()['nd']['user_agent']['version']);
		} else if (isset($_SERVER['HTTP_USER_AGENT'])) {
			/* Otherwise, if User-Agent header is set, use it */
			curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
		}

		$output = curl_exec($ch);
		curl_close($ch);

		/* If the response is empty, we cannot proceed */
		if (!$output) {
			$this->log('502', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Empty response from the underlying layer.');
			$this->restful->error('An error ocurred while retrieving data from the underlying layer. No data received. Please contact support.');
			$this->restful->output('502'); /* Bad Gateway */
		}

		/* Fetch cookie from headers */
		$headers = array_slice(explode("\r\n", $output), 0, -1);
		$session_cookie = NULL;

		foreach ($headers as $header) {
			if (strtolower(substr($header, 0, 12)) == 'set-cookie: ')
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
			$this->log('502', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Unable to decode JSON data from the underlying response body.');
			$this->restful->error('An error ocurred while decoding data from the underlying layer. Please contact support.');
			$this->restful->output('502'); /* Bad Gateway */
		}

		/* Check if request was successful */
		if ($data_raw['status'] !== true) {
			$this->log(isset($data_raw['code']) ? $data_raw['code'] : '502', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Request was not successful: ' . $data_raw['content'] . '.');
			$this->restful->error($data_raw['content']);
			$this->restful->output(isset($data_raw['code']) ? $data_raw['code'] : '502');
		}

		/* Check if the required data is present */
		if (!isset($data_raw['data']['user_id']) || !isset($data_raw['data']['apikey'])) {
			$this->log('502', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Required data from the underlying layer is missing.');
			$this->restful->error('An error ocurred while retrieving data from the underlying layer. Data is incomplete. Please contact support.');
			$this->restful->output('502'); /* Bad Gateway */
		}

		/* Check if the required data is valid */
		if (!is_numeric($data_raw['data']['user_id']) || strlen($data_raw['data']['apikey']) != 40 || hex2bin($data_raw['data']['apikey']) === false) {
			$this->log('502', __FILE__, __LINE__, __FUNCTION__, 'Authentication failed for user \'' . $auth['username'] . '\': Received data from the underlying layer is invalid.');
			$this->restful->error('An error ocurred while retrieving data from the underlying layer. Data is invalid. Please contact support.');
			$this->restful->output('502'); /* Bad Gateway */
		}

		/* Set the response data */
		$data['userid'] = intval($data_raw['data']['user_id']);
		$data['token']  = openssl_digest(openssl_random_pseudo_bytes(256), 'sha1');
		$data['roles'] = $data_raw['data']['roles'];

		/* Extract session lifetime from Max-Age */
		if (preg_match('/Max-Age=(\d+);/i', $session_cookie, $matches) !== 1) {
			$this->log('N/A', __FILE__, __LINE__, __FUNCTION__, 'Unable to retrieve session lifetime for user \'' . $auth['username'] . '\'. Using default (' . current_config()['nd']['backend']['session_lifetime'] . ').');

			/* Since we couldn't extract the session lifetime value from the cookie, we'll use the default */
			$session_lifetime = current_config()['nd']['backend']['session_lifetime'];
		} else {
			/* Set the session lifetime value from what was extracted from the cookie */
			$session_lifetime = $matches[1];
		}
		
		/* Encrypt session cookie with user authentication token. FIXME: Limited to  */
		$enc_session_cookie = $this->encrypt->encrypt($session_cookie, hex2bin($data['token']), false);

		/* Set user properties */
		$user_data = array();
		$user_data['userid'] = $data['userid'];
		$user_data['roles'] = $data['roles'];
		$user_data['username'] = $data_raw['data']['username'];
		$user_data['photo'] = $data_raw['data']['photo'];
		$user_data['is_admin'] = $data_raw['data']['is_admin'];
		$user_data['is_superuser'] = $data_raw['data']['is_superuser'];
		$user_data['timezone'] = $data_raw['data']['timezone'];
		$user_data['sessions_id'] = $data_raw['data']['session_id'];

		/* Load auth cache context */
		$cache_context_orig = $this->cache->context();
		$this->cache->load(current_config()['nd']['cache']['context']['auth']);

		/* Cache session information */
		$this->cache->set('nd_user_session_' . sha1($data['userid'] . $data['token']), $enc_session_cookie, $session_lifetime);

		/* Cache user data */		
		$this->cache->set('nd_user_data_' . sha1($data['userid'] . $data['token']), $user_data, $session_lifetime);

		/* Reload generic cache context */
		$this->cache->load($cache_context_orig);

		/* All good */
		return $data;
	}

	public function user_data($user_id = NULL, $auth_token = NULL) {
		/* If no user ID was set, determine it from the headers */
		if ($user_id === NULL)
			$user_id = $this->restful->header(current_config()['nd']['header']['user_id']);

		/* If no authentication token was set, determine it from the headers */
		if ($auth_token === NULL)
			$auth_token = $this->restful->header(current_config()['nd']['header']['auth_token']);

		/* Weak validation for user ID and authentication token */
		if (!$user_id || !$auth_token) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unable to retrieve session data for the provided credentials.');
			$this->restful->error('Unable to retrieve session data for the provided credentials.');
			$this->restful->output('400'); /* Bad Request */
		}

		/* Load auth cache context */
		$cache_context_orig = $this->cache->context();
		$this->cache->load(current_config()['nd']['cache']['context']['auth']);

		/* Get user data from auth cache */
		$user_data = $this->cache->get('nd_user_data_' . sha1($user_id . $auth_token));

		/* Reload original cache context */
		$this->cache->load($cache_context_orig);

		/* All good */
		return $user_data;
	}

	public function user_logout() {
		/* Retrieve authentication and session data from headers */
		$session = $this->session_init();

		/* Forward request to the underlying layer (nd-php) */
		$nd_data = $this->request('/login/logout', NULL, $session);

		/* Check if the logout was successful */
		if ($nd_data['logout'] !== true) {
			/* Not found */
			$this->log('502', __FILE__, __LINE__, __FUNCTION__, 'Logout failed.', $session);
			$this->restful->error('Logout failed.');
			$this->restful->output('502'); /* Bad Gateway */
		}

		/* Destroy user session */
		$this->session_destroy($session);
	}

	public function search_ndsl($input, $session) {
		/** Sanitize input **/

		/* Check if all properties are acceptable */
		foreach ($input as $k => $v) {
			if (!in_array($k, array('distinct', 'limit', 'offset', 'totals', 'orderby', 'ordering', 'show', 'query'))) {
				$this->log('406', __FILE__, __LINE__, __FUNCTION__, 'Unacceptable property found: ' . $k, $session);
				$this->restful->error('Unacceptable property found: ' . $k);
				$this->restful->output('406'); /* Not Acceptable */
			}
		}

		/* Check distinct property */
		if (isset($input['distinct'])) {
			if (gettype($input['distinct']) != 'boolean') {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid type detected for property \'distinct\': Expecting boolean type.', $session);
				$this->restful->error('Invalid type detected for property \'distinct\': Expecting boolean type.');
				$this->restful->output('400'); /* Bad Request */				
			}

			/* Set property to request body */
			$data['_distinct'] = $input['distinct'];
		}

		/* Check totals property */
		if (isset($input['totals'])) {
			if (gettype($input['totals']) != 'boolean') {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid type detected for property \'totals\': Expecting boolean type.', $session);
				$this->restful->error('Invalid type detected for property \'totals\': Expecting boolean type.');
				$this->restful->output('400'); /* Bad Request */				
			}

			/* Set property to request body */
			$data['_totals'] = $input['totals'];
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

			/* Validate query criteria keywords. Also validate obvious types */
			foreach ($input['query'] as $k => $v) {
				/* $v must be an associative array, containing a criteria keyword and value */
				if ((gettype($v) != 'array') || !count(array_filter(array_keys($v), 'is_string'))) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid criteria type detected for field \'' . $k . '\': ' . $v, $session);
					$this->restful->error('Invalid criteria type detected for field \'' . $k . '\': ' . $v);
					$this->restful->output('400'); /* Bad Request */
				}

				foreach ($v as $ck => $cv) {
					switch ($ck) {
						case 'eq':
						case 'ne':
						case 'lt':
						case 'gt':
						case 'from':
						case 'to': {
							switch (gettype($cv)) {
								case 'string':
								case 'double':
								case 'integer': break;

								default: {
									$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only string, datetime, date, time, float, double or integer types are accepted.', $session);
									$this->restful->error('Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only string, datetime, date, time, float, double or integer types are accepted.');
									$this->restful->output('400'); /* Bad Request */									
								}
							}
						} break;

						case 'contains': {
							switch (gettype($cv)) {
								case 'string': break;
								case 'array': {
									/* Do not accept empty arrays */
									if (!count($cv)) {
										$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Empty arrays are not accepted.', $session);
										$this->restful->error('Invalid value for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Empty arrays are not accepted.');
										$this->restful->output('400'); /* Bad Request */
									}
								} break;

								default: {
									$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only string or array types are accepted.', $session);
									$this->restful->error('Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only string or array types are accepted.');
									$this->restful->output('400'); /* Bad Request */
								}
							}
						} break;

						case 'in':
						case 'not_in': {
							switch (gettype($cv)) {
								case 'array': {
									/* Check if there are non-unique values on the array for non "in" ordered criteria, and strip them out of the query */
									if (isset($input['ordering']) && ($input['ordering'] != 'in')) {
										$cv_set = array();
			
										foreach ($cv as $cve) {
											if (!in_array($cve, $cv_set))
												array_push($cv_set, $cve);
										}
			
										$cv = $cv_set;

										$input['query'][$k][$ck] = $cv;
									}

									break;
								}

								default: {
									$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only array type is accepted.', $session);
									$this->restful->error('Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only array type is accepted.');
									$this->restful->output('400'); /* Bad Request */									
								}
							}
						} break;

						case 'or':
						case 'diff':
						case 'exact': {
							switch (gettype($cv)) {
								case true:
								case false: break;

								default: {
									$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only boolean type is accepted.', $session);
									$this->restful->error('Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only boolean type is accepted.');
									$this->restful->output('400'); /* Bad Request */									
								}
							}
						} break;

						case 'is':
						case 'is_not': {
							switch (gettype($cv)) {
								case true:
								case false:
								case NULL: break;

								default: {
									$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only boolean type or null values are accepted.', $session);
									$this->restful->error('Invalid value type for criteria \'' . $ck . '\' under field \'' . $k . '\': ' . $cv . '. Only boolean type or null values are accepted.');
									$this->restful->output('400'); /* Bad Request */									
								}
							}
						} break;

						default: {
							$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid criteria keyword detected for field \'' . $k . '\': ' . $ck, $session);
							$this->restful->error('Invalid criteria keyword detected for field \'' . $k . '\': ' . $ck);
							$this->restful->output('400'); /* Bad Request */
						}
					}
				}
			}

			/* Set  Query */
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

	public function view($ctrl, $argv = NULL, $fields = array(), $public = false) {
		$fields_mapped  = isset($fields['mapped']) ? $fields['mapped'] : array();
		$fields_visible = isset($fields['visible']) ? $fields['visible'] : array();

		/* Check if this is a public request or if an authenticated session is required */
		if ($public === false) {
			/* Retrieve authentication and session data from headers */
			$session = $this->session_init();
			$reqbody = NULL;
		} else {
			/* No authenticated session will be used. Public user creditials will be used for this request. */
			$session = NULL;
			$reqbody['_userid'] = current_config()['nd']['auth']['public']['user_id'];
			$reqbody['_apikey'] = current_config()['nd']['auth']['public']['api_key'];
		}

		/* Grant that entry ID is set */
		if ($argv === NULL || (count($argv) != 1) || !is_numeric($argv[0])) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Missing or invalid entry ID.', $session);
			$this->restful->error('Missing or invalid entry ID.');
			$this->restful->output('400'); /* Bad request */
		}

		/* Forward request to the underlying layer (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/view/' . $argv[0], $reqbody, $session);

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
				/* Initialize field for multiple relationship array */
				$entry[$k] = array();

				/* Extract only the keys from the rel array */
				foreach ($v as $vk => $vv) {
						array_push($entry[$k], $vk);
				}
			}
		}

		/* Aggregate mixed fields */
		if (isset($nd_data['mixed'])) {
			foreach ($nd_data['mixed'] as $k => $v) {
				/* Initialize array, if required... */
				if (!isset($entry[$k]))
					$entry[$k] = array();

				array_push($entry[$k], $v);
			}
		}

		/** Mangle results according to $fields_mapped and $fields_visible **/

		/* Set a temporary row to be safely iterated */
		$row = $entry;

		/* Iterate the row and make any required changes in the $fields array */
		foreach ($row as $k => $v) {
			/* If the field is mapped, rename it */
			if (isset($fields_mapped[$k])) {
				/* Unset old key */
				unset($entry[$k]);
				/* Rename to new key, based on map */
				$k = $fields_mapped[$k];
				/* Assign value to the new key */
				$entry[$k] = $v;
			}

			/* If $fields_visible is set, filter out fields not present in the array */
			if (count($fields_visible) && !in_array($k, $fields_visible)) {
				unset($entry[$k]);
				continue;
			}
		}

		/* All good */
		return $entry;
	}


	public function list_default($ctrl, $argv = NULL, $fields = array(), $public = false) {
		$fields_mapped  = isset($fields['mapped']) ? $fields['mapped'] : array();
		$fields_visible = isset($fields['visible']) ? $fields['visible'] : array();

		/* Check if this is a public request or if an authenticated session is required */
		if ($public === false) {
			/* Retrieve authentication and session data from headers */
			$session = $this->session_init();
		} else {
			/* No authenticated session will be used. Public user creditials will be used for this request. */
			$session = NULL;
			$reqbody['_userid'] = current_config()['nd']['auth']['public']['user_id'];
			$reqbody['_apikey'] = current_config()['nd']['auth']['public']['api_key'];
		}

		/* Initialize data */
		$reqbody['data'] = NULL;

		/* Check if there are conditions to be set */
		if ($argv !== NULL) {
			if (isset($argv[0]))
				$reqbody['data']['_limit'] = abs(intval($argv[0]));

			if (isset($argv[1]))
				$reqbody['data']['_offset'] = abs(intval($argv[1]));

			if (isset($argv[2])) {
				$orderby_field = preg_match('/^[a-zA-Z0-9_]+$/', $argv[2]) ? $argv[2] : 'id';

				/* Check if orderby field is part of visible fields */
				if (!in_array($orderby_field, $fields['visible'])) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid or non-existent orderby field: ' . $orderby_field, $session);
					$this->restful->error('Invalid orderby field: ' . $orderby_field);
					$this->restful->output('400'); /* Bad Request */
				}

				/* Translate orderby field, if mapped */
				foreach ($fields['mapped'] as $k => $v) {
					if ($v == $orderby_field) {
						$orderby_field = $k;
						break;
					}
				}

				$reqbody['data']['_orderby'] = $orderby_field;
			}

			if (isset($argv[3]))
				$reqbody['data']['_ordering'] = (strtolower($argv[3]) == 'desc') ? 'desc' : 'asc';

			if (isset($argv[4])) {
				/* Check if 'totals' is either 0 or 1 */
				if (($argv[4] !== '0') && ($argv[4] !== '1')) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid value for \'totals\' argument: ' . $argv[4] . '. It must be set to 0 or 1.', $session);
					$this->restful->error('Invalid value for \'totals\' argument: ' . $argv[4] . '. It must be set to 0 or 1.');
					$this->restful->output('400'); /* Bad Request */
				}

				$reqbody['data']['_totals'] = intval($argv[4]) ? true : false;
			}
		}

		/* Forward request to the underlying layer (nd-php) */
		if ($reqbody['data'] !== NULL || (isset($reqbody['_userid']) && isset($reqbody['_apikey']))) {
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
				/* Rename fields that are set in $fields_mapped */
				if (isset($fields_mapped[$k])) {
					/* Unset the value */
					unset($nd_data['result'][$i][$k]);
					/* Rename to new key, based on map */
					$k = $fields_mapped[$k];
					/* Set the new mapped key with the actual value */
					$nd_data['result'][$i][$k] = $v;
				}

				/* Filter fields that are not present in $fields_visible */
				if (count($fields_visible) && !in_array($k, $fields_visible)) {
					unset($nd_data['result'][$i][$k]);
					continue;
				}

				/* Multiple relationship values are delivered as a comma separated list (string type).
				 * This requires proper conversion to an integer array.
				 */
				if (substr($k, 0, 4) == 'rel_') {
					$nd_data['result'][$i][$k] = $v
						? /* if $v contains data, map it to an integer array */
						array_map(
							function($x) { return intval($x); },
							explode(',', $v)
						)
						: /* otherwise, set the value as an empty array */
						array();
				}
			}
		}

		/* All good */
		return $nd_data;
	}


	public function insert($ctrl, $argv = NULL, $input = array(), $fields = array(), $public = false) {
		$fields_accepted = isset($fields['accepted']) ? $fields['accepted'] : array();
		$fields_mapped   = isset($fields['mapped']) ? $fields['mapped'] : array();
		$fields_required = isset($fields['required']) ? $fields['required'] : array();

		/* Check if this is a public request or if an authenticated session is required */
		if ($public === false) {
			/* Retrieve authentication and session data from headers */
			$session = $this->session_init();
			$reqbody = NULL;
		} else {
			/* No authenticated session will be used. Public user creditials will be used for this request. */
			$session = NULL;
			$reqbody['_userid'] = current_config()['nd']['auth']['public']['user_id'];
			$reqbody['_apikey'] = current_config()['nd']['auth']['public']['api_key'];
		}

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

		/* Set request data for the underlying layer (nd-php) */
		$reqbody['data'] = $entry;

		/* Forward the insert request to the underlying layer (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/insert', $reqbody, $session);

		/* Check if the entry was successfully updated */
		if (!isset($nd_data['inserted']) || ($nd_data['inserted'] !== true)) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Update failed: Unable to insert entry.', $session);
			$this->restful->error('Unable to insert entry.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Set inserted id */
		$data['id'] = intval($nd_data['insert_id']);

		/* All good */
		return $data;
	}


	public function update($ctrl, $argv = NULL, $input = array(), $fields = array(), $public = false) {
		$fields_accepted = isset($fields['accepted']) ? $fields['accepted'] : array();
		$fields_mapped   = isset($fields['mapped']) ? $fields['mapped'] : array();

		/* Check if this is a public request or if an authenticated session is required */
		if ($public === false) {
			/* Retrieve authentication and session data from headers */
			$session = $this->session_init();
			$reqbody = NULL;
		} else {
			/* No authenticated session will be used. Public user creditials will be used for this request. */
			$session = NULL;
			$reqbody['_userid'] = current_config()['nd']['auth']['public']['user_id'];
			$reqbody['_apikey'] = current_config()['nd']['auth']['public']['api_key'];
		}

		/* Validate arguments, if any */
		if ($argv === NULL || (count($argv) != 1) || !is_numeric($argv[0])) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Cannot modify the entire collection.', $session);
			$this->restful->error('Cannot modify the entire collection.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Check if there's any input */
		if (!count($input)) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'No input data fields could be found.', $session);
			$this->restful->error('No input data fields could be found.');
			$this->restful->output('400'); /* Bad Request */
		}

		/* Sanitize input */
		$entry = array();

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

		/* Set request data for the underlying layer (nd-php) */
		$reqbody['data'] = $entry;

		/* Forward the update request to the underlying layer (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/update/' . $argv[0], $reqbody, $session);

		/* Check if the entry was successfully updated */
		if (!isset($nd_data['updated']) || ($nd_data['updated'] !== true)) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Update failed: Unable to update entry.', $session);
			$this->restful->error('Unable to update entry.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Initialize response data */
		$data = array();

		/* Process changed fields */
		$data['changed'] = array();
		$data['count'] = 0;

		/* Reverse mapped fields to process the unmapped fields received from the underlying layer */
		$fields_mapped_rev = array_flip($fields_mapped);

		foreach ($nd_data['changed'] as $row) {
			/* Reverse map the updated field names */
			if (isset($fields_mapped_rev[$row['field']]))
				$row['field'] = $fields_mapped_rev[$row['field']];

			/* Hide changes on fields that are not part of the accepted field list */
			if (!in_array($row['field'], $fields_accepted))
				continue;

			/** Include changed field values (old and new) **/

			/* Multiple relationships need to be converted from comma separated string values into integer array value */
			if (substr($row['field'], 0, 4) == 'rel_') {
				$data['changed'][$row['field']]['old'] = array_map(
					function($x) { return intval($x); },
					explode(',', $row['value_old'])
				);
				$data['changed'][$row['field']]['new'] = array_map(
					function($x) { return intval($x); },
					explode(',', $row['value_new'])
				);
			} else {
				/* No special processing for other types of fields */
				$data['changed'][$row['field']]['old'] = $row['value_old'];
				$data['changed'][$row['field']]['new'] = $row['value_new'];
			}

			/* Update counter (number of updated fields) */
			$data['count'] += 1;
		}

		/* All good */
		return $data;
	}


	public function delete($ctrl, $argv = NULL, $public = false) {
		/* Check if this is a public request or if an authenticated session is required */
		if ($public === false) {
			/* Retrieve authentication and session data from headers */
			$session = $this->session_init();
			$reqbody = NULL;
		} else {
			/* No authenticated session will be used. Public user creditials will be used for this request. */
			$session = NULL;
			$reqbody['_userid'] = current_config()['nd']['auth']['public']['user_id'];
			$reqbody['_apikey'] = current_config()['nd']['auth']['public']['api_key'];
		}

		/* Validate arguments, if any */
		if ($argv === NULL || (count($argv) != 1) || !is_numeric($argv[0])) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Cannot delete the entire collection.', $session);
			$this->restful->error('Cannot delete the entire collection.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* Forward request to the underlying layer (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/delete/' . $argv[0], $reqbody, $session);

		/* Check if the entry was successfully deleted */
		if (!isset($nd_data['deleted']) || ($nd_data['deleted'] !== true)) {
			$this->log('403', __FILE__, __LINE__, __FUNCTION__, 'Delete failed: Unable to delete entry.', $session);
			$this->restful->error('Unable to delete entry.');
			$this->restful->output('403'); /* Forbidden */
		}

		/* All good */
		return ;
	}


	public function search($ctrl, $input = array(), $fields = array(), $validate_method = true, $public = false) {
		$fields_accepted    = isset($fields['accepted']) ? $fields['accepted'] : array();
		$fields_mapped_pre  = isset($fields['mapped_pre']) ? $fields['mapped_pre'] : array();
		$fields_mapped_post = isset($fields['mapped_post']) ? $fields['mapped_post'] : array();
		$fields_visible     = isset($fields['visible']) ? $fields['visible'] : array();

		/* Check if this is a public request or if an authenticated session is required */
		if ($public === false) {
			/* Retrieve authentication and session data from headers */
			$session = $this->session_init();
			$reqbody = NULL;
		} else {
			/* No authenticated session will be used. Public user creditials will be used for this request. */
			$session = NULL;
			$reqbody['_userid'] = current_config()['nd']['auth']['public']['user_id'];
			$reqbody['_apikey'] = current_config()['nd']['auth']['public']['api_key'];
		}

		/* Only POST method is accepted for this call */
		if ($validate_method === true && $this->restful->method() != 'POST') {
			$this->log('405', __FILE__, __LINE__, __FUNCTION__, 'Only POST method is allowed to be used for searches.', $session);
			$this->restful->error('Only POST method is allowed to be used for searches.');
			$this->restful->output('405'); /* Method Not Allowed */
		}

		/* Check if there's any input */
		if (!count($input) || !isset($input['query'])) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'No input data fields could be found or search query is missing.', $session);
			$this->restful->error('No input data fields could be found or search query is missing.');
			$this->restful->output('400'); /* Bad Request */
		}

		/* Validate and rename orderby field, if set */
		if (isset($input['orderby'])) {
			$orderby_field = $input['orderby'];

			/* Check if orderby field is part of visible or accepted fields */
			if (!in_array($orderby_field, $fields['visible']) && !in_array($orderby_field, $fields['accepted'])) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid or non-existent orderby field: ' . $orderby_field, $session);
				$this->restful->error('Invalid orderby field: ' . $orderby_field);
				$this->restful->output('400'); /* Bad Request */
			}

			/* Translate orderby field, if mapped */
			if (isset($fields['mapped_pre'][$orderby_field]))
				$input['orderby'] = $fields['mapped_pre'][$orderby_field];
		}

		/* Validate ordering field and check for special case 'in' (inorder) */
		if (isset($input['ordering']) && (strtolower($input['ordering']) == 'in')) {
                /* Ordering by 'in' requires 'orderby' parameter to be explicitly set */
                if (!isset($input['orderby'])) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Parameter \'ordering\' is set as \'in\', but no \'orderby\' parameter was found.', $session);
                    $this->restful->error('Parameter \'ordering\' is set as \'in\', but no \'orderby\' parameter was found.');
                    $this->restful->output('400'); /* Bad Request */
                }

                /* Ordering by 'in' requires 'query' parameter to contain a 'in' criteria for the 'orderby' field. */
                if (!isset($input['query'][$input['orderby']]['in'])) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Parameter \'ordering\' is set as \'in\', but \'query\' does not contain a \'in\' criteria for the field set for \'orderby\' parameter.', $session);
                    $this->restful->error('Parameter \'ordering\' is set as \'in\', but \'query\' does not contain a \'in\' criteria for the field set for \'orderby\' parameter.');
                    $this->restful->output('400'); /* Bad Request */
                }

				/* Store original limit and set the query limit to the amount of elements present in the "in" criteria for the "orderby" field */
				$inorder_limit = $input['limit'];
				$input['limit'] = count($input['query'][$input['orderby']]['in']);

				/* Validate requested limit for the inorder query */
				if ($inorder_limit > $input['limit']) {
					$this->restful->error('The requested \'limit\' value is greater than the amount of elements present in the \'in\' criteria.');
					$this->restful->output('400'); /* Bad Request */
				}

				/* Store original offset value and reset query offset */
				$inorder_offset = $input['offset'];
				$input['offset'] = 0;

				/* Validate requested offset for the inorder query */
				if ($inorder_offset >= $input['limit']) {
					$this->restful->error('The requested \'offset\' value cannot be greater than or equal to the amount of elements present in the \'in\' criteria.');
					$this->restful->output('400'); /* Bad Request */
				}

                /* Mark this search for reordering based on 'in' criteria */
                $inorder = true;

                /* Set order to ascending (could also be descending, as the result will be reordered) */
                $input['ordering'] = 'asc';
		} else {
			/* If no ordering was set, inorder is always false */
			$inorder = false;
		}

		/* Sanitize input and rename any mapped fields from query */
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

		/* Look for aggregation settings */
		if (isset($input['aggregations'])) {
			$aggregations = $input['aggregations'];
			unset($input['aggregations']);
		}

		/* Look for 404 settings */
		if (isset($input['404'])) {
			$set_404 = $input['404'];
			unset($input['404']);
		}

		/* Set request data */
		$reqbody['data'] = $this->search_ndsl($input, $session);

		/* Forward the update request to the underlying layer (nd-php) */
		$nd_data = $this->request('/' . $ctrl . '/result/basic', $reqbody, $session);

		/* Check if the received type is what we're expecting */
		if (gettype($nd_data['result']) != 'array') {
			$this->log('502', __FILE__, __LINE__, __FUNCTION__, 'Invalid data received from the underlying layer: Unexpected type (Expecting array).', $session);
			$this->restful->error('An error ocurred while retrieving data from the underlying layer. Data type is invalid. Please contact support.');
			$this->restful->output('502'); /* Bad Gateway */
		}

		/* If we've received an empty array, the search succeded, but no results were found... */
		if (!$nd_data['count']) {
			/* Check if 404 was requested when no results are present */
			if (isset($set_404) && ($set_404 === true)) {
				$this->restful->output('404'); /* Not found */
			} else {
				$this->restful->output('201'); /* Search was peformed, but no content was delivered */
			}
		}

		/* inorder results require a pre-existing array, filled with empty (false) values */
		if ($inorder === true) {
            /* Get 'in' criteria values */
            $in_values = $input['query'][$input['orderby']]['in'];

            /* Initialize array */
            $data_inorder = array_fill(0, count($in_values), false);
		}

		/* Iterate over the result array, converting any types required and mapped fields */
		for ($i = 0; $i < $nd_data['count']; $i ++) {
			/* Set a temporary row to be safely iterated */
			$row = $nd_data['result'][$i];

			/* Mangle that, if required... */
			foreach ($row as $k => $v) {
				/* Multiple relationship values are delivered as a comma separated list (string type).
				 * This requires proper conversion to an integer array.
				 */
				if (substr($k, 0, 4) == 'rel_') {
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
					/* Reload the value, as it might have changed since the start of this iteration */
					$v = $nd_data['result'][$i][$k];
					/* Unset the value */
					unset($nd_data['result'][$i][$k]);
					/* Set the new mapped key with the actual value */
					$nd_data['result'][$i][$fields_mapped_post[$k]] = $v;
				}
			}

			/* Populate inorder data array (reordered result) */
			if ($inorder === true)
				$data_inorder[intval(array_search($row[$input['orderby']], $in_values))] = $nd_data['result'][$i];
		}

		/* If inorder reordering was requested, post-process the result... */
		if ($inorder === true) {
			/* Filter out empty results (from non-existing entries that were included under the 'in' criteria) */
			$nd_data['result'] = array_values(array_filter($data_inorder));

			/* Update totals, if required */
			if (isset($input['total']) && ($input['total'] === true))
				$nd_data['total'] = count($nd_data['result']);

			/* Also apply the original requested limit and offset to the result */
			$nd_data['result'] = array_slice($nd_data['result'], $inorder_offset, $inorder_limit);

			/* Update count */
			$nd_data['count'] = count($nd_data['result']);
		}

		/* Check for aggregation requests */
		if (isset($aggregations) && is_array($aggregations) && (count($aggregations) > 0)) {
			/*
			 * Full syntax:
			 *
			 *   "aggregations": {
			 *       "xpto_id": {
			 *           "field": "id",
			 *           "object": "xpto",
			 *           "show": [ "id", "field1", "field2" ]
			 *       }
			 *   }
			 *
			 * Short syntax:
			 *
			 *   "aggregations": {
			 *       "xpto_id": [ "id", "field1", "field2" ]
			 *   }
			 *
			 */
			foreach ($aggregations as $k => $v) {
				/* Check if $v is array and contains data */
				if (!is_array($v) || !count($v)) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Aggregation field \'' . $k . '\' is not of array type, or it is an empty array.');
					$this->restful->error('Aggregation field \'' . $k . '\' is not of array type, or it is an empty array.');
					$this->restful->output('400'); /* Bad request */					
				}

				/* Check if this is a short syntax aggregation */
				if (array_keys($v) === range(0, count($v) - 1)) {
					/* Check if we can extrapolate the full syntax from the aggregation field */
					if (substr($k, -3) !== '_id') {
						$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Aggregation field \'' . $k . '\' does not support short syntax.');
						$this->restful->error('Aggregation field \'' . $k . '\' does not support short syntax.');
						$this->restful->output('400'); /* Bad request */
					}

					/* Create full syntax from short syntax */
					$_v = array();
					$_v['field'] = 'id';
					$_v['object'] = substr($k, 0, -3);
					$_v['show'] = $v;
					$_v['recursive'] = false;

					/* Overwrite short syntax */
					$v = $_v;
				} else {
					/* Check if 'field' property is set for this aggregation */
					if (!isset($v['field'])) {
						$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Property \'field\' is missing for the following aggregation: ' . $k);
						$this->restful->error('Property \'field\' is missing for the following aggregation: ' . $k);
						$this->restful->output('400'); /* Bad request */
					}

					/* Check if 'object' property is set for this aggregation */
					if (!isset($v['object'])) {
						$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Property \'object\' is missing for the following aggregation: ' . $k);
						$this->restful->error('Property \'object\' is missing for the following aggregation: ' . $k);
						$this->restful->output('400'); /* Bad request */
					}

					/* Check if 'show' property is set for this aggregation */
					if (!isset($v['show'])) {
						$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Property \'show\' is missing for the following aggregation: ' . $k);
						$this->restful->error('Property \'show\' is missing for the following aggregation: ' . $k);
						$this->restful->output('400'); /* Bad request */
					}

					/* Check if 'recursive' property is set and is of type boolean. If not set, assume false by default */
					if (isset($v['recursive'])) {
						if (gettype($v['recursive']) != 'boolean') {
							$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Property \'recursive\' must be of boolean type.');
							$this->restful->error('Property \'recursive\' must be of boolean type.');
							$this->restful->output('400'); /* Bad request */
						}
					} else {
						$v['recursive'] = false;
					}
				}

				/* Fetch entry details (prepare for aggregation) */
				$ids = array();

				/* Check if the target field exists in the result (if we are here, at least one result was found) */
				if (!isset($nd_data['result'][0][$k])) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Missing aggregation field under search results: ' . $k, $session);
					$this->restful->error('Missing aggregation field under search results: ' . $k);
					$this->restful->output('400'); /* Bad request */
				}

				/* Gather the entry ID's for this aggregation */
				foreach ($nd_data['result'] as $row)
					array_push($ids, $row[$k]);

				/* If this is a recursive aggregation, we shall make a recur to the API to fetch the data instead of calling directly the ND underlying layer */
				if ($v['recursive'] === true) {
					/* Perform a NDSL query to the API (self) */
					$r_ndsl = $this->restful->request(
						'POST',
						base_url(true) . $v['object'],
						array(
							'limit' => count($ids),
							'offset' => 0,
							'show' => $v['show'],
							'query' => array(
								$v['field'] => array(
									'in' => $ids
								)
							)
						),
						array(
							current_config()['nd']['header']['user_id'] . ': ' . $this->restful->header(current_config()['nd']['header']['user_id']),
							current_config()['nd']['header']['auth_token'] . ': ' . $this->restful->header(current_config()['nd']['header']['auth_token']),
							'content-type: application/json',
							'accept: application/json'
						),
						$status_code,
						$raw_output,
						current_config()['nd']['timeout']['connect'],
						current_config()['nd']['timeout']['execute']
					);

					/* Check if the search was successful */
					if ($status_code != 201) {
						$this->log($status_code, __FILE__, __LINE__, __FUNCTION__, 'Unable to perform recursive NDSL search: ' . $r_ndsl['errors']['message']);
						$this->restful->error('Unable to perform recursive NDSL search: ' . $r_ndsl['errors']['message']);
						$this->restful->output($status_code);
					}

					/* Set the results */
					if (isset($r_ndsl['data'][array_pop(explode('/', $v['object']))]['result'])) {
						$ndsl_output['result'] = $r_ndsl['data'][array_pop(explode('/', $v['object']))]['result'];
					} else {
						$ndsl_output['result'] = $r_ndsl['data']['result'];
					}
				} else {
					/* Fetch method properties from aggregation object */
					$method_properties = $this->properties($v['object'], 'search');
					$fields = $method_properties['fields'];
					$route = $method_properties['route'];

					/* Grant that all fields in $v are present in acceptable fields for this aggregation */
					foreach ($v['show'] as $f) {
						if (!in_array($f, $fields['visible'])) {
							$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unacceptable output field \'' . $f . '\' under aggregation: ' . $k, $session);
							$this->restful->error('Unacceptable output field \'' . $f . '\' under aggregation: ' . $k);
							$this->restful->output('400'); /* Bad request */
						}
					}

					/* Retrieve entry details for all the entry ID's belonging to this aggregation */
					$ndsl_output = $this->search(
						/* Object */
						$route,
						/* NDSL query */
						array(
							'limit' => count($ids),
							'offset' => 0,
							'show' => $v['show'],
							'query' => array(
								$v['field'] => array(
									'in' => $ids
								)
							)
						),
						/* Mappings */
						array(
							'accepted' => array($v['field']),
							'visible' => $fields['visible'],
							'mapped_pre' => $fields['mapped_pre'],
							'mapped_post' => $fields['mapped_post']
						)
					);
				}

				/* Aggregate results */
				$nd_data['result'] = $this->aggregation->join(
					$nd_data['result'],
					$k,
					$ndsl_output['result'],
					$v['field'],
					$v['show'],
					NULL,
					$k
				);
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

	public function remap($src, $map) {
		for ($i = 0; $i < count($src); $i ++) {
			if (isset($map[$src[$i]]))
				$src[$i] = $map[$src[$i]];
		}

		return $src;
	}

	public function properties($object, $method = NULL, $property = NULL) {
		/* Craft object properties file path */
		$obj_file = SYSTEM_BASE_DIR . current_config()['nd']['models']['base_path'] . '/' . $object . '.json';

		/* TODO: Before requesting from cache service, add a local cache object on this class to store/load object properties
		 * that were already requested during this request.
		 */

		/* Check if there's a cached version for this object */
		$obj_properties = $this->cache->get('nd_properties_' . $object);

		if (!$obj_properties) {
			/* Check if properties file exists */
			if (!file_exists($obj_file)) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'File \'' . $obj_file . '\' does not exist.');
				$this->restful->error('Unable to find properties for object: ' . $object);
				$this->restful->output('400'); /* Bad request */
			}

			/* Load properties file contents */
			$obj_content = file_get_contents($obj_file);

			if ($obj_content === false) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unable to load file contents: ' . $obj_file);
				$this->restful->error('Unable to load properties for object: ' . $object);
				$this->restful->output('500'); /* Internal Server Error */
			}

			/* Decode properties contents */
			$obj_properties = json_decode($obj_content, true);

			if ($obj_properties === false) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unable to decode file contents: ' . $obj_file);
				$this->restful->error('Unable to decode properties for object: ' . $object);
				$this->restful->output('500'); /* Internal Server Error */
			}

			/* Cache object properties */
			$this->cache->set('nd_properties_' . $object, $obj_properties);
		}

		/* Check if driver is set */
		if (!isset($obj_properties['driver'])) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Driver not found in: ' . $obj_file);
			$this->restful->error('Driver not found in object: ' . $object);
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* Check if driver matches */
		if ($obj_properties['driver'] != 'nd') {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Incompatible driver found in: ' . $obj_file);
			$this->restful->error('Incompatible driver found in object: ' . $object);
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* Check if object name exists */
		if (!isset($obj_properties[$object])) {
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unable to find object name: ' . $object);
			$this->restful->error('Unable to find object name: ' . $object);
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* Determine the granularity of the result */
		if ($method === NULL) {
			/* If no method name was set, return the full object properties */
			return $obj_properties[$object];
		} else if (!isset($obj_properties[$object][$method])) { /* Check if object method exists */
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unable to find object method (' . $method . ') for object: ' . $object);
			$this->restful->error('Unable to find object method (' . $method . '): ' . $object);
			$this->restful->output('400'); /* Bad request */
		}

		if ($property === NULL) {
			/* If no specific property was set, return all the method properties */
			return $obj_properties[$object][$method];
		} else if (!isset($obj_properties[$object][$method][$property])) { /* Check if method property exists */
			$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unable to find object method (' . $method . ') property (' . $property . ') for object: ' . $object);
			$this->restful->error('Unable to find object method (' . $method . ') property (' . $property . ') for object: ' . $object);
			$this->restful->output('400'); /* Bad Request */
		}

		/* Return the specific property */
		return $obj_properties[$object][$method][$property];
	}

	public function validate_value_type($field, $ftype, $value, $ioop) {
		if ($value === NULL)
			return true; /* NULL types always match any ftype */

		/* Get value type */
		$vtype = gettype($value);

		/* Match field type against value type */
		if ($ftype == 'integer') {
			return $vtype == 'integer';
		} else if (($ftype == 'float') || ($ftype == 'double')) {
			return ($vtype == 'double') || ($vtype == 'integer'); /* NOTE: integer is accepted if no decimal representation is present */
		} else if ($ftype == 'boolean') {
			return $vtype == 'boolean';
		} else if (($ftype == 'datetime') || ($ftype == 'time') || ($ftype == 'date')) {
			try {
				$dt = new DateTime($value);
			} catch (Exception $e) {
				return false;
			}
		} else if (substr($ftype, 0, 6) == 'string') {
			/* Check if value is of type string */
			if ($vtype != 'string')
				return false;

			/* Get max string length */
			$matches = array();
			if (!preg_match('/^string\((\d+)\)$/i', $ftype, $matches)) {
				$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
				$this->restful->error('Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
				$this->restful->output('500'); /* Internal Server Error */
			}

			/* Check if length of value doesn't exceed the max string length from field type */
			if (strlen($value) > $matches[1])
				return false;
		} else if (substr($ftype, 0, 5) == 'array') {
			/* Check if value is of type array */
			if ($vtype != 'array')
				return false;

			/* Get array elements type */
			$matches = array();

			if (!preg_match('/^array\(([a-z0-9\_\(\)]+)\)$/i', $ftype, $matches)) {
				$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
				$this->restful->error('Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
				$this->restful->output('500'); /* Internal Server Error */
			}

			/* Validate type for each element of the array */
			foreach ($value as $av) {
				if (!$this->validate_value_type($field, $matches[1], $av, $ioop))
					return false;
			}
		} else if (substr($ftype, 0, 6) == 'object') {
			/* Check if value is of type array */
			if (($vtype != 'array') && ($vtype != 'string'))
				return false;

			/* Get type of the object */
			$matches = array();

			if (!preg_match('/^object\(([a-z0-9\_\(\)]+)\)$/i', $ftype, $matches)) {
				$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
				$this->restful->error('Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
				$this->restful->output('500'); /* Internal Server Error */
			}

			/* Validate object type */
			switch ($matches[1]) {
				case 'file': {
					if ($ioop == 'input') {
						/* Check mandatory file attributes */
						if (!isset($value['name']))
							return false;
						if (!isset($value['contents']))
							return false;
						if (!isset($value['encoding']))
							return false;
					} else if ($ioop == 'output') {
						if (!isset($value['name']))
							return false;
						if (!isset($value['url']))
							return false;
					}
				} break;

				case 'custom': break;

				default: {
					$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
					$this->restful->error('Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
					$this->restful->output('500'); /* Internal Server Error */
				}
			}
		} else {
			/* The provided field type wasn't recognized */
			$this->log('500', __FILE__, __LINE__, __FUNCTION__, 'Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
			$this->restful->error('Unrecognized internal format for type \'' . $ftype . '\' on field: ' . $field);
			$this->restful->output('500'); /* Internal Server Error */
		}

		/* All good */
		return true;
	}

	public function validate_data_types($object, $method, $data, $ftypes = NULL) {
		/* Check if data type validation is disabled */
		if (current_config()['nd']['models']['validate']['types'] === false)
			return;

		/* Get object field types */
		if ($ftypes === NULL)
			$ftypes = $this->properties($object, 'options', 'types');

		/* Validate data types according to selected method */
		if (in_array($method, array('insert', 'modify'))) { /* Input */
			/* Check if input type validation is disabled */
			if (current_config()['nd']['models']['validate']['input_types'] === false)
				return;

			/* If method is modify, check if the entry id has a valid format */
			if ($method == 'modify') {
				if (!isset($data['id']) || !$data['id'] || (intval($data['id']) <= 0)) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid entry value set on URI.');
					$this->restful->error('Invalid entry value set on URI.');
					$this->restful->output('400'); /* Bad Request */
				}
			}

			/* Check if data input is set */
			if (!isset($data['input']))
				return;

			/* Check if all fields are defined and have the correct type */
			foreach ($data['input'] as $k => $v) {
				/* Check if field is defined */
				if (!isset($ftypes[$k])) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Undefined field detected: ' . $k);
					$this->restful->error('Undefined field detected: ' . $k);
					$this->restful->output('400'); /* Bad Request */
				}

				/* Check value type */
				if (!$this->validate_value_type($k, $ftypes[$k], $v, 'input')) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on field: ' . $k);
					$this->restful->error('Invalid data type detected on field: ' . $k);
					$this->restful->output('400'); /* Bad Request */
				}
			}
		} else if ($method == 'search') { /* Input / Output */
			/* Check if data input is set */
			if (isset($data['input'])) {
				/* Check if input type validation is disabled */
				if (current_config()['nd']['models']['validate']['input_types'] === false)
					return;

				/* Validate fields under 'show' array, if present */
				if (isset($data['input']['show'])) {
					/* Grant that 'show' is of type array */
					if (!is_array($data['input']['show'])) {
						$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type for property \'show\': Expecting array of strings.');
						$this->restful->error('Invalid data type for property \'show\': Expecting array of strings.');
						$this->restful->output('400'); /* Bad Request */
					}

					/* Grant that all fields set under 'show' are defined */
					foreach ($data['input']['show'] as $v) {
						if (!isset($ftypes[$v])) {
							$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Undefined field detected under \'show\' property: ' . $v);
							$this->restful->error('Undefined field detected under \'show\' property: ' . $v);
							$this->restful->output('400'); /* Bad Request */
						}
					}
				}

				/* Check if a 'query' was set */
				if (!isset($data['input']['query'])) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'No \'query\' was found.');
					$this->restful->error('No \'query\' was found.');
					$this->restful->output('400'); /* Bad Request */
				}

				/* Validate input types */
				foreach ($data['input']['query'] as $f => $c) {
					/* $f -> field, $c -> criteria */

					/* Check if field is defined */
					if (!isset($ftypes[$f])) {
						$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Undefined field detected: ' . $f);
						$this->restful->error('Undefined field detected: ' . $f);
						$this->restful->output('400'); /* Bad Request */
					}

					/* Validate data types for each criteria value, also validating if criteria name exists */
					foreach ($c as $k => $v) {
						if (in_array($k, array('ne', 'eq', 'lt', 'lte', 'gt', 'gte', 'is', 'is_not', 'from', 'to'))) {
							/* Check value type */
							if (!$this->validate_value_type($f, $ftypes[$f], $v, 'input')) {
								$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on criteria \'' . $k . '\' for field: ' . $f);
								$this->restful->error('Invalid data type detected on criteria \'' . $k . '\' for field: ' . $f);
								$this->restful->output('400'); /* Bad Request */
							}
						} else if ($k == 'contains') {
							/* The 'contains' criteria can receive a single value or multiple values. We first check if it is of array type, and if so,
							 * we need to check each individual value inside the array
							 */
							if (is_array($v)) {
								/* Validate each search value type against the most basic type of the field */
								foreach ($v as $av) {
									/* Check value type */
									if (!$this->validate_value_type($f, $ftypes[$f], $av, 'input')) {
										$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on criteria \'' . $k . '\' for field: ' . $f);
										$this->restful->error('Invalid data type detected on criteria \'' . $k . '\' for field: ' . $f);
										$this->restful->output('400'); /* Bad Request */
									}
								}
							} else {
								/* If 'contains' it is not of array type, check the type of the single value */
								if (!$this->validate_value_type($f, $ftypes[$f], $v, 'input')) {
									$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on criteria \'' . $k . '\' for field: ' . $f);
									$this->restful->error('Invalid data type detected on criteria \'' . $k . '\' for field: ' . $f);
									$this->restful->output('400'); /* Bad Request */
								}	
							}
						} else if (($k == 'in') || ($k == 'not_in')) {
							/* This criteria requires the search value to be array (set of values) */
							if (!is_array($v)) {
								$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on criteria \'' . $k . '\' for field \'' . $f . '\': Expecting array.');
								$this->restful->error('Invalid data type detected on criteria \'' . $k . '\' for field \'' . $f . '\': Expecting array.');
								$this->restful->output('400'); /* Bad Request */
							}

							/* If field is of type array, extract the type of elements (basic type) */
							$matches = array();
							$sftype = NULL; /* Field basic type */

							if (preg_match('/^array\(([a-z0-9\_\(\)]+)\)$/i', $ftypes[$f], $matches)) {
								$sftype = $matches[1];
							} else {
								/* Otherwise, use the type of the field */
								$sftype = $ftypes[$f];
							}

							/* Validate each search value type against the most basic type of the field */
							foreach ($v as $av) {
								/* Check value type */
								if (!$this->validate_value_type($f, $sftype, $av, 'input')) {
									$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on criteria \'' . $k . '\' for field: ' . $f);
									$this->restful->error('Invalid data type detected on criteria \'' . $k . '\' for field: ' . $f);
									$this->restful->output('400'); /* Bad Request */
								}
							}
						} else if (($k == 'diff') || ($k == 'exact') || ($k == 'or')) {
							if (gettype($v) != 'boolean') {
								$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on criteria \'' . $k . '\' for field \'' . $f . '\': Expecting boolean for: ' . $k);
								$this->restful->error('Invalid data type detected on criteria \'' . $k . '\' for field \'' . $f . '\': Expecting boolean for: ' . $k);
								$this->restful->output('400'); /* Bad Request */
							}
						} else {
							$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unrecognized criteria: ' . $k);
							$this->restful->error('Unrecognized criteria: ' . $k);
							$this->restful->output('400'); /* Bad Request */
						}
					}
				}
			}

			/* Check if data output is set */
			if (isset($data['output'])) {
				/* Check if output type validation is disabled */
				if (current_config()['nd']['models']['validate']['output_types'] === false)
					return;

				/* Validate output types */
				foreach ($data['output']['result'] as $row) {
					foreach ($row as $k => $v) {
						/* Check for reserved / exceptional keys and ignore them... */
						if ($k == '_aggregations')
							continue;

						/* Check if field is defined */
						if (!isset($ftypes[$k])) {
							$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Undefined field detected: ' . $k);
							$this->restful->error('Undefined field detected: ' . $k);
							$this->restful->output('400'); /* Bad Request */
						}

						/* Check value type */
						if (!$this->validate_value_type($k, $ftypes[$k], $v, 'output')) {
							$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on field: ' . $k);
							$this->restful->error('Invalid data type detected on field: ' . $k);
							$this->restful->output('400'); /* Bad Request */
						}
					}
				}
			}
		} else if ($method == 'view') { /* Output */
			/* Check if output type validation is disabled */
			if (current_config()['nd']['models']['validate']['output_types'] === false)
				return;

			/* Check if the entry id has a valid format */
			if (!isset($data['id']) || !$data['id'] || (intval($data['id']) <= 0)) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid entry value set on URI.');
				$this->restful->error('Invalid entry value set on URI.');
				$this->restful->output('400'); /* Bad Request */
			}

			/* Check if data output is set */
			if (!isset($data['output']))
				return;

			/* Validate output types */
			foreach ($data['output'] as $k => $v) {
				/* Check if field is defined */
				if (!isset($ftypes[$k])) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Undefined field detected: ' . $k);
					$this->restful->error('Undefined field detected: ' . $k);
					$this->restful->output('400'); /* Bad Request */
				}

				/* Check value type */
				if (!$this->validate_value_type($k, $ftypes[$k], $v, 'output')) {
					$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on field: ' . $k);
					$this->restful->error('Invalid data type detected on field: ' . $k);
					$this->restful->output('400'); /* Bad Request */
				}
			}
		} else if ($method == 'listing') { /* Output */
			/* Check if output type validation is disabled */
			if (current_config()['nd']['models']['validate']['output_types'] === false)
				return;

			/* Check if data output is set */
			if (!isset($data['output']))
				return;

			/* Validate output types */
			foreach ($data['output']['result'] as $row) {
				foreach ($row as $k => $v) {
					/* Check if field is defined */
					if (!isset($ftypes[$k])) {
						$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Undefined field detected: ' . $k);
						$this->restful->error('Undefined field detected: ' . $k);
						$this->restful->output('400'); /* Bad Request */
					}

					/* Check value type */
					if (!$this->validate_value_type($k, $ftypes[$k], $v, 'output')) {
						$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid data type detected on field: ' . $k);
						$this->restful->error('Invalid data type detected on field: ' . $k);
						$this->restful->output('400'); /* Bad Request */
					}
				}
			}
		} else if ($method == 'delete') { /* N/A */
			/* Check if the entry id has a valid format */
			if (!isset($data['id']) || !$data['id'] || (intval($data['id']) <= 0)) {
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Invalid entry value set on URI.');
				$this->restful->error('Invalid entry value set on URI.');
				$this->restful->output('400'); /* Bad Request */
			}
		}
	}

	public function cache_data_get($object, $method, $args) {
		/* NOTE: Cache may be a security risk since it doesn't account for the current session privileges. Take care when enabling it. */

		/* Check if cache control demands no caching */
		if (($hdr_cache_control = $this->restful->header('cache-control'))) {
			$ctrls = explode(',', $hdr_cache_control);

			/* Search for no-cache control */
			foreach ($ctrls as $ctrl) {
				if (trim(strtolower($ctrl)) == 'no-cache')
					return NULL; /* If no-cache is set, do not read cached data */
			}
		}

		/* Craft key suffix */
		$ksuffix = $object . '_' . $method . '_' . md5(json_encode($args));

		/* Get current timestamp */
		$ctime = time();

		/* Load the context where object data resides */
		$cache_context_orig = $this->cache->context();
		$this->cache->load(current_config()['nd']['cache']['context']['generic']);

		/* Get object creation and invalidation time */
		$tcreated = $this->cache->get('nd_created_' . $ksuffix);
		$tinvalid = $this->cache->get('nd_invalidated_' . $object);

		/* Check if cache is still valid */
		if  ($tcreated &&
			($tcreated > $tinvalid) &&
			($data = $this->cache->get('nd_data_' . $object . '_' . $method . '_' . md5(json_encode($args)))))
		{
			/* Get cache lifetime */
			$tlifetime = $this->cache->get('nd_lifetime_' . $ksuffix);

			/* Set expiration time header if lifetime is available */
			if ($tlifetime)
				$this->restful->header('expires', date('l, d-M-Y H:i:s T', $tcreated + $tlifetime));
			
			/* Get document ETag */
			$etag = $this->cache->get('nd_etag_' . $ksuffix);

			/* Set ETag header if a document hash is available */
			if ($etag)
				$this->restful->header('etag', 'W/"' . $etag . '"'); /* NOTE: Currently, we assume a weak ETag validation */
		} else {
			/* If the cache was created before it was invalidated, do not return any cached data */
			$data = NULL;
		}

		/* Reload the original cache context */
		$this->cache->load($cache_context_orig);

		/* All good */
		return $data;
	}

	public function cache_data_set($object, $method, $args, $data, $lifetime = NULL) {
		/* NOTE: Cache may be a security risk since it doesn't account for the current session privileges. Take care when enabling it. */

		/* Craft key suffix */
		$ksuffix = $object . '_' . $method . '_' . md5(json_encode($args));

		/* If no lifetime is set, use the default value */
		if ($lifetime === NULL)
			$lifetime = current_config()['nd']['cache']['lifetime']['generic'];

		/* Get current timestamp */
		$ctime = time();

		/* Load the context where object data resides */
		$cache_context_orig = $this->cache->context();
		$this->cache->load(current_config()['nd']['cache']['context']['generic']);

		/* Set cache data, creation and life time */
		$this->cache->set('nd_data_' . $ksuffix, $data, $lifetime);
		$this->cache->set('nd_created_' . $ksuffix, $ctime, $lifetime);
		$this->cache->set('nd_lifetime_' . $ksuffix, $lifetime, $lifetime);
		$this->cache->set('nd_invalidated_' . $object, 0, $lifetime);

		/* Set ETag */
		if (($json_data = json_encode($data)))
			$this->cache->set('nd_etag_' . $ksuffix, sha1($json_data), $lifetime);

		/* Reload the original cache context */
		$this->cache->load($cache_context_orig);
	}

	public function cache_data_invalidate($object, $lifetime = NULL) {
		/* If no lifetime is set, use the default value */
		if ($lifetime === NULL)
			$lifetime = current_config()['nd']['cache']['lifetime']['generic'];
		
		$this->cache->set('nd_invalidated_' . $object, time(), $lifetime);
	}

	public function event_triggers($triggers = NULL) {
		/* Validate type of the event triggers */
		if (!is_array($triggers)) {
			$this->restful->error('Invalid type for trigger configuration: Expecting array.');
			$this->restful->output('500');
		}

		/* Mark this request to generate an event */
		$this->restful->event_triggers(
			isset($triggers['request']['info']) ? $triggers['request']['info'] : false,
			isset($triggers['request']['data']) ? $triggers['request']['data'] : false,
			isset($triggers['response']['info']) ? $triggers['response']['info'] : false,
			isset($triggers['response']['data']) ? $triggers['response']['data'] : false,
			isset($triggers['response']['errors']) ? $triggers['response']['errors'] : false,
			isset($triggers['context']) ? $triggers['context'] : NULL
		);
	}

	public function forward($object, $method, $argv, $input = NULL) {
		/* Forward request */
		switch ($method) {
			case 'view': {
				/* Initialize data */
				$data = NULL;

				/* Fetch object properties */
				$properties = $this->properties($object, $method);

				/* Check if an event must be generated */
				if (isset($properties['event']))
					$this->event_triggers($properties['event']);

				/* Check if caching is enabled */
				if (isset($properties['cache']) && ($properties['cache'] === true)) {
					/* Before retrieving cached data, if this object requires authentication, first evaluate if there's a valid session */
					if (isset($properties['auth']) && $properties['auth'] === true)
						$this->session_init(); /* This will prevent public requests from acessing data that requires authenticated users */

					/* If so, try to retrieve data from the cached entry */
					$data = $this->cache_data_get($object, $method, $argv);
				}

				/* Fetch data from the underlying layer only if we didn't have it already (from cache) */
				if (!$data) {
					/* Fetch entry data, if exists */
					$data = $this->view($properties['route'], $argv, $properties['fields'], isset($properties['public']) ? $properties['public'] : false);

					/* Check if caching is enabled */
					if (isset($properties['cache']) && ($properties['cache'] === true)) {
						/* If so, cache the retrieved data */
						$this->cache_data_set($object, $method, $argv, $data, isset($properties['cache_lifetime']) ? $properties['cache_lifetime'] : NULL);
					}
				} else {
					$this->restful->cache_hit(true);
				}

				/* Validate data types */
				$this->validate_data_types($object, $method, array('id' => $argv[0], 'output' => $data));

				/* All good */
				return array(
					'code' => $properties['status']['success'][0], /* OK */
					'data' => $data
				);
			} break;

			case 'listing': {
				/* Initialize data */
				$data = NULL;

				/* Fetch object properties */
				$properties = $this->properties($object, $method);

				/* Check if an event must be generated */
				if (isset($properties['event']))
					$this->event_triggers($properties['event']);

				/* Check if caching is enabled */
				if (isset($properties['cache']) && ($properties['cache'] === true)) {
					/* Before retrieving cached data, if this object requires authentication, first evaluate if there's a valid session */
					if (isset($properties['auth']) && $properties['auth'] === true)
						$this->session_init(); /* This will prevent public requests from acessing data that requires authenticated users */

					/* If so, try to retrieve data from the cached entry */
					$data = $this->cache_data_get($object, $method, $argv);
				}

				/* Fetch data from the underlying layer only if we didn't have it already (from cache) */
				if (!$data) {
					/* Fetch collection */
					$data = $this->list_default($properties['route'], $argv, $properties['fields'], isset($properties['public']) ? $properties['public'] : false);

					/* Check if caching is enabled */
					if (isset($properties['cache']) && ($properties['cache'] === true)) {
						/* If so, cache the retrieved data */
						$this->cache_data_set($object, $method, $argv, $data, isset($properties['cache_lifetime']) ? $properties['cache_lifetime'] : NULL);
					}
				} else {
					$this->restful->cache_hit(true);
				}

				/* Validate data types */
				$this->validate_data_types($object, $method, array('output' => $data));

				/* All good */
				return array(
					'code' => $properties['status']['success'][0], /* OK */
					'data' => $data
				);
			} break;

			case 'insert': {
				/* Fetch object properties */
				$properties = $this->properties($object, $method);

				/* Check if an event must be generated */
				if (isset($properties['event']))
					$this->event_triggers($properties['event']);

				/* Get request input, if none was set */
				if ($input === NULL)
					$input = $this->restful->input();

				/* Validate data types */
				$this->validate_data_types($object, $method, array('input' => $input));

				/* Insert the entry */
				$data = $this->insert($properties['route'], $argv, $input, $properties['fields'], isset($properties['public']) ? $properties['public'] : false);

				/* Perform cache invalidation for referenced objects */
				if (isset($properties['invalidate']) && is_array($properties['invalidate'])) {
					foreach ($properties['invalidate'] as $invalid_obj) {
						$this->cache_data_invalidate($invalid_obj);
					}
				}

				/* All good */
				return array(
					'code' => $properties['status']['success'][0], /* Created */
					'data' => $data
				);
			} break;

			case 'modify': {
				/* Fetch object properties */
				$properties = $this->properties($object, $method);

				/* Check if an event must be generated */
				if (isset($properties['event']))
					$this->event_triggers($properties['event']);

				/* Get request input, if none was set */
				if ($input === NULL)
					$input = $this->restful->input();

				/* Validate data types */
				$this->validate_data_types($object, $method, array('id' => $argv[0], 'input' => $input));

				/* Update the entry */
				$data = $this->update($properties['route'], $argv, $input, $properties['fields'], isset($properties['public']) ? $properties['public'] : false);

				/* Perform cache invalidation for referenced objects */
				if (isset($properties['invalidate']) && is_array($properties['invalidate'])) {
					foreach ($properties['invalidate'] as $invalid_obj) {
						$this->cache_data_invalidate($invalid_obj);
					}
				}

				/* All good */
				return array(
					'code' => $properties['status']['success'][0], /* OK */
					'data' => $data
				);
			} break;

			case 'delete': {
				/* Fetch object properties */
				$properties = $this->properties($object, $method);

				/* Check if an event must be generated */
				if (isset($properties['event']))
					$this->event_triggers($properties['event']);

				/* Validate data types */
				$this->validate_data_types($object, $method, array('id' => $argv[0]));

				/* Delete the entry */
				$this->delete($properties['route'], $argv, isset($properties['public']) ? $properties['public'] : false);

				/* Perform cache invalidation for referenced objects */
				if (isset($properties['invalidate']) && is_array($properties['invalidate'])) {
					foreach ($properties['invalidate'] as $invalid_obj) {
						$this->cache_data_invalidate($invalid_obj);
					}
				}

				/* All good */
				return array(
					'code' => $properties['status']['success'][0], /* OK */
					'data' => NULL
				);
			} break;

			case 'search': {
				/* Initialize data */
				$data = NULL;

				/* Fetch object properties */
				$properties = $this->properties($object, $method);

				/* Check if an event must be generated */
				if (isset($properties['event']))
					$this->event_triggers($properties['event']);

				/* Get request input, if none was set */
				if ($input === NULL)
					$input = $this->restful->input();

				/* Validate input data types */
				$this->validate_data_types($object, $method, array('input' => $input));

				/* Check if caching is enabled */
				if (isset($properties['cache']) && ($properties['cache'] === true)) {
					/* Before retrieving cached data, if this object requires authentication, first evaluate if there's a valid session */
					if (isset($properties['auth']) && $properties['auth'] === true)
						$this->session_init(); /* This will prevent public requests from acessing data that requires authenticated users */

					/* If so, try to retrieve data from the cached entry */
					$data = $this->cache_data_get($object, $method, $input);
				}

				/* Fetch data from the underlying layer only if we didn't have it already (from cache) */
				if (!$data) {
					/* Search the collection */
					$data = $this->search($properties['route'], $input, $properties['fields'], true, isset($properties['public']) ? $properties['public'] : false);

					/* Check if caching is enabled */
					if (isset($properties['cache']) && ($properties['cache'] === true)) {
						/* If so, cache the retrieved data */
						$this->cache_data_set($object, $method, $input, $data, isset($properties['cache_lifetime']) ? $properties['cache_lifetime'] : NULL);
					}
				} else {
					$this->restful->cache_hit(true);
				}

				/* Validate output data types */
				$this->validate_data_types($object, $method, array('output' => $data));

				/* All good */
				return array(
					'code' => $properties['status']['success'][0], /* Created */
					'data' => $data
				);
			} break;

			case 'options': {
				/* Fetch object properties */
				$properties = $this->properties($object);

				/* Check if an event must be generated */
				if (isset($properties['event']))
					$this->event_triggers($properties['event']);

				/* Document available fields */
				$this->restful->doc_fields(
					/* Types */
					$properties['options']['types'],
					/* Defaults */
					$properties['options']['defaults'],
					/* Options */
					$properties['options']['options'],
					/* Descriptions */
					$properties['options']['descriptions']
				);

				/* Document GET method, for single and/or collection requests */
				if (isset($properties['view']) || isset($properties['listing'])) {
					/* GET */
					$this->restful->doc_method_get_request(
						/* Headers - Single */
						isset($properties['view'])
							? array_merge(
								array('accept: application/json'),
								($properties['view']['auth'] === true)
									? array(
										current_config()['nd']['header']['user_id'] . ': <userid>',
										current_config()['nd']['header']['auth_token'] . ': <token>'
									  )
									: array()
							  )
							: false,
						/* Headers - Collection */
						isset($properties['listing'])
							? array_merge(
								array('accept: application/json'),
								($properties['listing']['auth'] === true)
									? array(
										current_config()['nd']['header']['user_id'] . ': <userid>',
										current_config()['nd']['header']['auth_token'] . ': <token>'
									  )
									: array()
							  )
							: false,
						/* URI - Single */
						isset($properties['view']) ? NULL : false,
						/* URI - Collection */
						isset($properties['listing']) ? NULL : false,
						/* Additional Notes - Single */
						false,
						/* Additional Notes - Collection */
						false
					);

					$this->restful->doc_method_get_response(
						/* Headers - Single */
						isset($properties['view'])
							? array('content-type: application/json')
							: false,
						/* Headers - Collection */
						isset($properties['listing'])
							? array('content-type: application/json')
							: false,
						/* Codes Success - Single */
						isset($properties['view'])
							? $properties['view']['status']['success']
							: false,
						/* Codes Failure - Single */
						isset($properties['view'])
							? $properties['view']['status']['failure']
							: false,
						/* Codes Success - Collection */
						isset($properties['listing'])
							? $properties['listing']['status']['success']
							: false,
						/* Codes Failure - Collection */
						isset($properties['listing'])
							? $properties['listing']['status']['failure']
							: false,
						/* Body Visible - Single */
						isset($properties['view'])
							? $this->remap($properties['view']['fields']['visible'], $properties['view']['fields']['mapped'])
							: false,
						/* Body Visible - Collection */
						isset($properties['listing'])
							? $this->remap($properties['listing']['fields']['visible'], $properties['listing']['fields']['mapped'])
							: false,
						/* Types - Single */
						NULL,
						/* Types - Collection */
						NULL,
						/* Additional Notes - Single */
						array(
							'NDFS Reference: https://github.com/ucodev/uweb/blob/master/documentation/ndfs.txt',
						),
						/* Additional Notes - Collection */
						isset($properties['view'])
							? array('Maximum number of entries per result: ' . $properties['listing']['limit'])
							: false
					);
				}

				/* Document POST */
				if (isset($properties['insert'])) {
					/* POST */
					$this->restful->doc_method_post_request(
						/* Headers - Single */
						false,
						/* Headers - Collection */
						array_merge(
							array(
							'accept: application/json',
							'content-type: application/json'
							),
							($properties['insert']['auth'] === true)
								? array(
									current_config()['nd']['header']['user_id'] . ': <userid>',
									current_config()['nd']['header']['auth_token'] . ': <token>'
								  )
								: array()
						),
						/* URI Single */
						false,
						/* URI Collection */
						NULL,
						/* Body Accepted - Single */
						false,
						/* Body Accepted - Collection */
						$properties['insert']['fields']['accepted'],
						/* Body Required - Single */
						false,
						/* Body Required - Collection */
						$properties['insert']['fields']['required'],
						/* Additional Notes - Single */
						array(
							'NDFS Reference: https://github.com/ucodev/uweb/blob/master/documentation/ndfs.txt'
						),
						/* Additional Notes - Collection */
						false
					);

					$this->restful->doc_method_post_response(
						/* Headers - Single */
						false,
						/* Headers - Collection */
						array(
							'content-type: application/json'
						),
						/* Codes Success - Single */
						false,
						/* Codes Failure - Single */
						false,
						/* Codes Success - Collection */
						$properties['insert']['status']['success'],
						/* Codes Failure - Collection */
						$properties['insert']['status']['failure'],
						/* Types - Single */
						false,
						/* Types - Collection */
						NULL,
						/* Additional Notes - Single */
						false,
						/* Additional Notes - Collection */
						false
					);
				}

				/* Document PATCH */
				if (isset($properties['modify'])) {
					/* PATCH */
					$this->restful->doc_method_patch_request(
						/* Headers - Single */
						array_merge(
							array(
							'accept: application/json',
							'content-type: application/json'
							),
							($properties['modify']['auth'] === true)
								? array(
									current_config()['nd']['header']['user_id'] . ': <userid>',
									current_config()['nd']['header']['auth_token'] . ': <token>'
								  )
								: array()
						),
						/* Headers - Collection */
						false,
						/* URI - Single */
						NULL,
						/* URI - Collection */
						false,
						/* Body Accepted - Single */
						$properties['modify']['fields']['accepted'],
						/* Body Accepted - Collection */
						false,
						/* Additional Notes - Single */
						array(
							'NDFS Reference: https://github.com/ucodev/uweb/blob/master/documentation/ndfs.txt'
						),
						/* Additional Notes - Collection */
						false
					);

					$this->restful->doc_method_patch_response(
						/* Headers - Single */
						array(
							'content-type: application/json'
						),
						/* Headers - Collection */
						false,
						/* Codes Success - Single */
						$properties['modify']['status']['success'],
						/* Codes Failure - Single */
						$properties['modify']['status']['failure'],
						/* Codes Success - Collection */
						false,
						/* Codes Failure - Collection */
						false,
						/* Types - Single */
						false,
						/* Types - Collection */
						false,
						/* Additional Notes - Single */
						false,
						/* Additional Notes - Collection */
						false
					);
				}

				/* Document DELETE */
				if (isset($properties['delete'])) {
					/* DELETE */
					$this->restful->doc_method_delete_request(
						/* Headers - Single */
						array_merge(
							array(
							'accept: application/json'
							),
							($properties['delete']['auth'] === true)
								? array(
									current_config()['nd']['header']['user_id'] . ': <userid>',
									current_config()['nd']['header']['auth_token'] . ': <token>'
								  )
								: array()
						),
						/* Headers - Collection */
						false,
						/* URI - Single */
						NULL,
						/* URI - Collection */
						false,
						/* Additional Notes - Single */
						false,
						/* Additional Notes - Collection */
						false
					);

					$this->restful->doc_method_delete_response(
						/* Headers - Single */
						array(
							'content-type: application/json'
						),
						/* Headers - Collection */
						false,
						/* Codes Success - Single */
						$properties['delete']['status']['success'],
						/* Codes Failure - Single */
						$properties['delete']['status']['failure'],
						/* Codes Success - Collection */
						false,
						/* Codes Failure - Collection */
						false,
						/* Types - Single */
						false,
						/* Types - Collection */
						false,
						/* Additional Notes - Single */
						false,
						/* Additional Notes - Collection */
						false
					);
				}

				/* Document POST (search) */
				if (isset($properties['search'])) {
					/* POST (search) */
					$this->restful->doc_method_custom(
						/* Method */
						'POST',
						/* Function */
						'search',
						/* URI Args */
						'',
						/* Request Body Args */
						array(
							'accepted' => $properties['search']['fields']['accepted']
						),
						/* Response Body Args */
						array(
							'visible' => $properties['search']['fields']['visible']
						),
						/* Response Types */
						'"data": { "count": <integer>, "total": <integer>, "result": [ { "<key>": <value>, ... }, ... ] }',
						/* Codes Success */
						$properties['search']['status']['success'],
						/* Codes Failure */
						$properties['search']['status']['failure'],
						/* Request Headers */
						array_merge(
							array(
							'accept: application/json',
							'content-type: application/json'
							),
							($properties['search']['auth'] === true)
								? array(
									current_config()['nd']['header']['user_id'] . ': <userid>',
									current_config()['nd']['header']['auth_token'] . ': <token>'
								  )
								: array()
						),
						/* Response Headers */
						array(
							'content-type: application/json'
						),
						/* Additional Notes - Request */
						array(
							'NDSL Reference: https://github.com/ucodev/uweb/blob/master/documentation/ndsl.txt'
						),
						/* Additional Notes - Response */
						array(
							'Maximum number of entries per result: ' . $properties['search']['limit'],
							'NDFS Reference: https://github.com/ucodev/uweb/blob/master/documentation/ndfs.txt',
						)
					);
				}

				/* All good */
				return array(
					'code' => 200, /* OK */
					'data' => $this->restful->doc_generate()
				);
			} break;

			default: {
				/* Unrecognized function */
				$this->log('400', __FILE__, __LINE__, __FUNCTION__, 'Unrecognized function: ' . $method);
				$this->restful->error('Unrecognized function: ' . $method);
				$this->restful->output('400'); /* Bad Request */
			}
		}
	}
}
