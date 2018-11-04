<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Author:   Pedro A. Hortas
 * Email:    pah@ucodev.org
 * Modified: 28/10/2018
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

/* Define subcodes */
define('UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_PARTIAL', 1101); /* Set when at least one request from the composite returns a >= 400 code */
define('UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_NO_DATA', 1102); /* Set when at least one request from the composite requests a combination of data from an index that contains no results */
define('UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_NO_FIELD', 1103); /* Set when at least one request from the composite requests a combination of data from an index that doesn't contain the request field */
define('UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_PRECHECK_NO_PATH', 1104); /* Set when a base path pre-check fails. */
define('UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_TYPE_MISMATCH', 1105); /* Set when a type mismatch is present between the referenced variable and the existing property. */


class UW_Composite extends UW_Module {
    /* Indicates if variables were found inside explicit unsafe requests. This variable MUST be reset (set to false) at each child request iteration. */
    private $_unsafe_variables_found = false;
    /* Indicates the current content index being processed. This variable MUST be updated at each child request iteration. */
    private $_current_index = 0;


    private function _combine_headers($composite_headers = array(), $composite_name = 'none') {
        /* Retrieve parent request headers */
        $headers = $this->restful->header();

        /* Normalize case for header names and replace existing ones from parent request */
        foreach ($composite_headers as $k => $v) {
            $headers[strtolower($k)] = (string) $v;
        }

        /* Initialize headers list */
        $headers_list = array();

        /* Filter and combine headers */
        foreach ($headers as $k => $v) {
            /* Filter headers that should not be forwarded from the parent request */
            if (in_array($k, current_config()['composite']['filtered_parent_headers']))
                continue;

            /* Aggregate the header into the headers list */
            array_push($headers_list, $k . ': ' . $v);
        }

        /* Include the composite forward header with composite name set */
        array_push($headers_list, current_config()['composite']['forward_header'] . ': ' . $composite_name);

        /* Include configured child headers */
        foreach (current_config()['composite']['include_child_headers'] as $h) {
            array_push($headers_list, $h);
        }

        /* All good */
        return $headers_list;
    }

    private function _resolve_path_component($path_entry) {
        if (preg_match('/^[a-zA-Z0-9\_]+$/', $path_entry)) {
            /* Regular path entry */
            return array(
                'name' => $path_entry,
                'iterable' => false,
                'index' => NULL
            );
        } else if (preg_match('/^([a-zA-Z0-9\_]+)\[(\d*)\]$/', $path_entry, $matches)) {
            if ($matches[2] !== "") {
                /* Path entry specifies an array with an index defined */
                return array(
                    'name' => $matches[1],
                    'iterable' => false,
                    'index' => intval($matches[2])
                );
            } else {
                /* Path entry specifies an iterable object */
                return array(
                    'name' => $matches[1],
                    'iterable' => true,
                    'index' => NULL
                );
            }
        }

        /* Something is wrong with the path variable */
        $this->restful->error('Variable path component "' . $path_entry . '" from component index ' . $this->_current_index . ' cannot be resolved.');
        $this->restful->output('422');
    }

    private function _lookup_field_value($entry, $path = array(), $count = 0) {
        /* Sanity checks */
        if ($count >= count($path)) {
            /* NOTE: If we ever reach this point, some state handling is missing in this procedure... */
            $this->restful->error('Composite procedure to lookup field values has entered into an invalid state under composite index ' . $this->_current_index . '.');
            $this->restful->output('400');
        }

        /* Check recursivity depth */
        if ($count > current_config()['composite']['max_recursive_var_path']) {
            $this->restful->error('Reached maximum allowed composite field lookup depth under composite index ' . $this->_current_index . '.');
            $this->restful->output('403');
        }

        /* Check if there are multiple paths for the current path position... */
        if (gettype($path[$count]) == 'array') {
            /* ... and check if the amount of options doesn't exceed the configuration limit ... */
            if (count($path[$count]) > current_config()['composite']['max_options_var_path']) {
                $this->restful->error('Number of allowed variable options exceeded for composite index ' . $this->_current_index . '.');
                $this->restful->output('403');
            }

            /* ... and iterate over them until a value is found. First ocurrence always takes precedence. */
            foreach ($path[$count] as $p) {
                /* Craft the current path based on the selected path entry */
                $current_path = $path;
                $current_path[$count] = $p;

                /* Reprocess the current entry with the crafted current path, at the same level count */
                $fvres = $this->_lookup_field_value($entry, $current_path, $count);

                /* First match wins */
                if ($fvres['found'] == true)
                    return $fvres;
            }
        }

        /* If we get here without a string type, the value couldn't be found in previous iterations of arrays */
        if (gettype($path[$count]) != 'string') {
            return array(
                'found' => false,
                'value' => NULL
            );
        }

        /* Resolve path component for any array/index pair that may have been specified */
        $rpath = $this->_resolve_path_component($path[$count]);

        /* Do not allow iterables at this stage */
        if ($rpath['iterable'] === true) {
            $this->restful->error('Iterable variable path component "' . $path[$count] . '", from composite index ' . $this->_current_index . ', cannot be iterated at this level. An index must be specified.');
            $this->restful->output('422');
        }

        /* Check if the resolved path is refering to a specific entry index */
        if ($rpath['index'] !== NULL) {
            /* If so, grant that entry is an array */
            if (!is_linear_array($entry[$rpath['name']])) {
                $this->restful->error('Component index ' . $this->_current_index . ' specifies an index under variable path component "' . $path[$count] . '", but the object it refers to is not a linear array.');
                $this->restful->output('422');
            }

            /* Grant that the the path exists for further processing... */
            if (!isset($entry[$rpath['name']]) || !isset($entry[$rpath['name']][$rpath['index']])) {
                /* Field not found */
                return array(
                    'found' => false,
                    'value' => NULL
                );                
            }

            /* Normalize entry processing */
            $current_entry = $entry[$rpath['name']][$rpath['index']];
        } else {
            /* Grant that the the path exists for further processing... */
            if (!isset($entry[$rpath['name']])) {
                /* Field not found */
                return array(
                    'found' => false,
                    'value' => NULL
                );                
            }

            /* Normalize entry processing */
            $current_entry = $entry[$rpath['name']];
        }

        /* If we are here, this is a straight (stringified) path position and it exists, so keep the regular recursive approach */
        if ((count($path) - 1) == $count) {
            /* Field found */
            return array(
                'found' => true,
                'value' => $current_entry
            );
        }

        /* Keep going... */
        return $this->_lookup_field_value($current_entry, $path, $count + 1);
    }

    private function _resolve_field_paths($field = array()) {
        /* Initialize path */
        $path = array();

        /* Iterate over field paths */
        foreach ($field as $f) {
            /* Check if field contains options */
            if (!strchr($f, '|')) {
                /* If not, add the regular field to the path */
                array_push($path, $f);
            } else {
                /* Check if variable options are encapsulated with () */
                if (($f[0] != '(') || (substr($f, -1) != ')')) {
                    $this->restful->error('Composite index ' . $this->_current_index . ' contains a variable with options, but it is not encapsulated with ().');
                    $this->restful->output('422');
                }

                /* Otherwise convert it into a field array (multiple choices inside path) */
                $fa = explode('|', trim($f, '()'));

                /* Check for empty entries */
                foreach ($fa as $entry) {
                    if (!$entry) {
                        $this->restful->error('Composite index ' . $this->_current_index . ' contains a variable with empty options.');
                        $this->restful->output('422');
                    }
                }

                /* Push the field array into path */
                array_push($path, explode('|', trim($f, '()')));
            }
        }

        /* All good */
        return $path;
    }

    private function _find_base_iterable($entry, $field = array(), $level = 0) {
        /* Check if we reached the end of field path */
        if ($level >= (count($field) - 1)) {
            return array(
                'iterable' => false,
                'level' => NULL,
                'entry' => NULL,
                'path_exists' => true
            );
        }

        /* Resolve path component to determine if the current level represents an iterable */
        $rpath = $this->_resolve_path_component($field[$level]);

        /* Analyze the current field path level */
        if ($rpath['iterable'] === true) {
            /* Grant that field path exists in the entry */
            if (!isset($entry[$rpath['name']]) || !is_linear_array($entry[$rpath['name']])) {
                $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_NO_FIELD);
                $this->restful->warning('Composite index ' . $this->_current_index . ' contains an iterable variable with a non-existing or non-iterable property in its path.');
                return array(
                    'iterable' => false,
                    'level' => NULL,
                    'entry' => NULL,
                    'path_exists' => false
                );
            }

            /* Iterable exists. All good */
            return array(
                'iterable' => true,
                'level' => $level + 1,
                'entry' => $entry[$rpath['name']],
                'path_exists' => true
            );
        } else if ($rpath['index'] !== NULL) {
            /* Grant that field path exists in the entry */
            if (!is_linear_array($entry[$rpath['name']]) || (count($entry[$rpath['name']]) <= $rpath['index'])) {
                $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_NO_FIELD);
                $this->restful->warning('Composite index ' . $this->_current_index . ' contains a variable that represents a selected array index in its path, but it doesn\'t exist or the target object property isn\'t an array.');
                return array(
                    'iterable' => false,
                    'level' => NULL,
                    'entry' => NULL,
                    'path_exists' => false
                );
            }

            /* Keep going ... */
            return $this->_find_base_iterable($entry[$rpath['name']][$rpath['index']], $field, $level + 1);
        } else {
            if (!isset($entry[$rpath['name']])) {
                $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_NO_FIELD);
                $this->restful->warning('Composite index ' . $this->_current_index . ' contains a variable in its path that targets a non-existing object property.');
                return array(
                    'iterable' => false,
                    'level' => NULL,
                    'entry' => NULL,
                    'path_exists' => false
                );
            }

            if (is_linear_array($entry[$rpath['name']])) {
                $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_TYPE_MISMATCH);
                $this->restful->warning('Composite index ' . $this->_current_index . ' contains a variable that targets a linear array property, but no iterable or index is referenced.');
                return array(
                    'iterable' => false,
                    'level' => NULL,
                    'entry' => NULL,
                    'path_exists' => false
                );
            }

            return $this->_find_base_iterable($entry[$rpath['name']], $field, $level + 1);
        }
    }

    private function _combine_results($output, $index, $field = array()) {
        /* Check if index is valid */
        if ((count($output) - 1) < $index) {
            $this->restful->error('Composite index ' . $this->_current_index . ' contains a request to combine results over a non-existing, or not yet filled index: ' . $index);
            $this->restful->output('422');
        }

        /* Initialize result */
        $result = NULL;

        /* Find base iterable, if any */
        $base = $this->_find_base_iterable($output[$index], $field);

        if (!$base['path_exists']) {
            $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_NO_FIELD);
            $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_PRECHECK_NO_PATH);
            $this->restful->warning('Composite index ' . $this->_current_index . ' failed a base field path pre-check. Please validate the variable path.');
            return NULL;
        }

        if ($base['iterable']) {
            /* Initialize result list */
            $result_list = array();

            /* Check if there are elements to iterate */
            if (!count($base['entry'])) {
                $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_NO_DATA);
                $this->restful->warning('Composite index ' . $this->_current_index . ' contains an iterable variable that targets an empty iterable property.');
                return NULL;
            }

            /* Iterate over results */
            foreach ($base['entry'] as $e) {
                /* Lookup for field */
                $fvres = $this->_lookup_field_value($e, $this->_resolve_field_paths(array_slice($field, $base['level'])));

                /* Check if requested field is present */
                if (!$fvres['found']) {
                    $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_NO_FIELD);
                    $this->restful->warning('Composite index ' . $this->_current_index . ' contains an iterable variable that references an invalid field path.');
                    return NULL;
                }

                /* Store result */
                array_push($result_list, $fvres['value']);
            }

            /* Set output result */
            $result = $result_list;
        } else {
            /* Lookup for field */
            $fvres = $this->_lookup_field_value($output[$index], $this->_resolve_field_paths($field));

            /* Check if requested field is present */
            if (!$fvres['found']) {
                $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_COMBINE_NO_FIELD);
                $this->restful->warning('Composite index ' . $this->_current_index . ' contains a variable that references an invalid field path.');
                return NULL;
            }

            /* Set output result */
            $result = $fvres['value'];
        }

        /* All good */
        return $result;
    }

    private function _lookup_index_from_name($name) {
        $input = $this->restful->input();

        for ($i = 0; $i < count($input['content']); $i ++) {
            $c = $input['content'][$i];

            if (isset($c['name']) && ($c['name'] == $name))
                return $i; /* Index found */
        }

        /* Not found */
        return false;
    }

    private function _replace_variables(&$v, $k, $output) {
        if (is_linear_array($v)) {
            /* Check if $v length isn't absurdely high */
            if (count($v) > current_config()['composite']['max_var_array_length']) {
                $this->restful->error('Composite index ' . $this->_current_index . ' contains an array with variable references that is too big.');
                $this->restful->output('422');
            }

            /* If value is a linear array, find/replace the variables inside each element of the array */
            for ($i = 0; $i < count($v); $i ++)
                $this->_replace_variables($v[$i], $k, $output);
        } else if (gettype($v) != 'string') {
            /* Skip any non-string type */
            return;
        }

        /* Check if strict variables are enabled - if strict variables are enabled, the string value that contains a variable will be limited to one variable and won't accept constants along variables */
        if (current_config()['composite']['enforce_strict_vars']) {
            $var_regex = '/^(\$\$[A-Za-z0-9\_]+\.[A-Za-z0-9\_\(\)\|\.\[\]]+\$\$)$/';
        } else {
            $var_regex = '/(\$\$[A-Za-z0-9\_]+\.[A-Za-z0-9\_\(\)\|\.\[\]]+\$\$)/';
        }

        /* Loop indefinetely, until no matches are found */
        do {
            /* (Re)validate $v type, as it may have been replaced. Must be string - if not, there are no more subsituitions to perform */
            if (gettype($v) != 'string')
                break;

            /* Check if there's a variable present */
            if (preg_match($var_regex, $v, $matches)) {
                /* Check variable length */
                if (strlen($matches[1]) > current_config()['composite']['max_var_length']) {
                    $this->restful->error('Variable length exceeded on unsafe composite index ' . $this->_current_index . '.');
                    $this->restful->output('422');
                }

                /* Extract compound */
                $compound = trim($matches[1], '$');

                /* Extract variables */
                $variables = explode('.', $compound);

                /* Process primary index */
                if (is_castable_integer($variables[0])) {
                    $index = intval($variables[0]);
                } else if ($variables[0] == 'last') {
                    $index = $this->_current_index - 1;
                } else {
                    /* Check for empty indexes - this should never occur */
                    if (!$variables[0]) {
                        $this->restful->error('Composite index ' . $this->_current_index . ', marked as unsafe, has no primary index reference.');
                        $this->restful->output('422');
                    }

                    /* Lookup primary index value from name */
                    $index = $this->_lookup_index_from_name($variables[0]);

                    /* Check if an index was found */
                    if ($index === false) {
                        $this->restful->error('Composite index ' . $this->_current_index . ', marked as unsafe, references a primary index name that was not found: ' . $variables[0]);
                        $this->restful->output('422');
                    }
                }

                /* Grant that index is valid and within the acceptable limits */
                if (($index === NULL) || ($index < 0) || ($index > (current_config()['composite']['limit'] - 1))) {
                    $this->restful->error('Composite index ' . $this->_current_index . ', marked as unsafe, contains a variable with an invalid primary index: ' . $index);
                    $this->restful->output('422');
                }

                /* Process field */
                $field = array_slice($variables, 1);

                /* Grant that field list is not empty */
                if (!$field || !count($field)) {
                    $this->restful->error('Composite index ' . $this->_current_index . ', marked as unsafe, contains an invalid field path (zero elements found).');
                    $this->restful->output('422');
                }

                /* Indicate that variables were found on this explicit unsafe request */
                $this->_unsafe_variables_found = true;

                /* Replace variable */
                if (current_config()['composite']['enforce_strict_vars']) {
                    $v = $this->_combine_results($output, $index, $field);
                } else {
                    $v_result = $this->_combine_results($output, $index, $field);

                    if ($v == $matches[1]) {
                        /* If $v contents matches exactly the variable name, make a direct replacement to keep the original types */
                        $v = $v_result;
                    } else if (gettype($v_result) == 'string') {
                        /* If $v_result is of type string and $v doesn't match exactly the variable name, perform a full replacement (multiple ocurrences) of the variable(s) */
                        $v = str_replace($matches[1], $v_result, $v);
                    } else if (in_array(gettype($v_result), array('integer', 'double', 'boolean', 'NULL'))) {
                        $v = str_replace($matches[1], (string) $v_result, $v);
                    } else {
                        $this->restful->error('Composite index ' . $this->_current_index . ' variable replacement is subject to a non-castable type under index: ' . $index);
                        $this->restful->output('422');
                    }
                }
            } else {
                /* If there are no matches, abort looping over the current value */
                break;
            }
        } while (true);
    }

    private function _lookup_variables(&$payload, $output) {
        array_walk_recursive($payload, array($this, '_replace_variables'), $output);
    }

    public function process() {
        /** Pre-checks **/

        /* Check if recursive composites are not allowed, and grant that the parent request is not a child composite */
        if (!current_config()['composite']['allow_recursive']) {
            /* If forward header is set in the parent request, this is a child composite of a parent composite */
            if ($this->restful->header(current_config()['composite']['forward_header'])) {
                $this->restful->error('Recursive composites are not allowed.');
                $this->restful->output('403');
            }
        }


		/** Initialize I/O **/

		$output = array();
		$input = $this->restful->input();


		/** Validate composite **/

        if (!isset($input['composite'])) {
            $this->restful->error('No composite name found. A composite must have a "composite" key with a meaninful name.');
            $this->restful->output('400');
        }

        if (gettype($input['composite']) != 'string') {
            $this->restful->error('Composite name contains an invalid type. Expecting string.');
            $this->restful->output('400');
        }

        if (isset($input['single']) && (gettype($input['single']) != 'boolean')) {
            $this->restful->error('Composite contains a property "single" with an invalid type. Expecting boolean.');
            $this->restful->output('400');
        }

        if (!isset($input['content'])) {
            $this->restful->error('No content found in composite data. A "content" key with a linear array as its type is required.');
            $this->restful->output('400');
        }

		/* Check if composite content has the right base type */
		if (!is_linear_array($input['content'])) {
			$this->restful->error('Composites require linear arrays as their base type.');
			$this->restful->output('400');
		}

		/* Check if composite doesn't exceed the maximum allowed numer of elements */
		if (count($input['content']) > current_config()['composite']['limit']) {
			$this->restful->error('Composites cannot have more than ' . current_config()['composite']['limit'] . ' elements.');
			$this->restful->output('400');
		}

		/* Grant that every entry from the composite and determine key validity */
		for ($i = 0; $i < count($input['content']); $i ++) {
			$e = $input['content'][$i];

            /* Check if unsafe is set and contains a valid type */
            if (isset($e['unsafe'])) {
                /* Validate type */
                if (gettype($e['unsafe']) != 'boolean') {
                    $this->restful->error('Composite element index ' . $i . ' contains a "unsafe" property with an invalid type. Expecting boolean.');
                    $this->restful->output('400');
                }

                /* Check if explicit unsafe is true and if it is allowed to exist */
                if ($e['unsafe'] && !current_config()['composite']['allow_explicit_unsafe']) {
                    $this->restful->error('Composite element index ' . $i . ' contains explicit unsafe requests.');
                    $this->restful->output('403');
                }
            }

			/* Check required keys */
			foreach (array('endpoint', 'method') as $k) {
				if (!isset($e[$k]) || !$e[$k]) {
					$this->restful->error('Composite element index ' . $i . ' doesn\'t contain the following required key: ' . $k);
					$this->restful->output('400');
				}

				/* Check key type (must be string) */
				if (gettype($e[$k]) != 'string') {
					$this->restful->error('Composite element index ' . $i . ' contains an invalid type for key "' . $k . '". Expecting string.');
					$this->restful->output('400');
				}

                /* Check if method is valid */
                if (($k == 'method') && !in_array($e[$k], current_config()['composite']['enabled_methods'])) {
                    $this->restful->error('The selected method for composite element index ' . $i . ' contains an invalid method: ' . $e[$k] . '. Expecting: GET, POST, PATCH, PUT or OPTIONS');
                    $this->restful->output('422');
                }

				/* Methods that require a payload must have a "payload" key present */
				if (($k == 'method') && !in_array($e[$k], current_config()['composite']['no_payload_methods'])) {
					if (!isset($e['payload'])) {
						$this->restful->error('The selected method for composite element index ' . $i . ' requires a "payload" key to be present.');
						$this->restful->output('400');
					} else {
						if (!is_array($e['payload'])) {
							$this->restful->error('The contents of the payload for composite element index ' . $i . ' contains an invalid type.');
							$this->restful->output('400');
						}
					}
				}
			}

			/* Check optional key types */
			if (isset($e['headers'])) {
				/* Check if headers contains a valid base type */
				if (!is_array($e['headers'])) {
					$this->restful->error('Composite element index ' . $i . ' contains a "headers" key with an invalid type. Expecting non-linear array (map).');
					$this->restful->output('400');
				}

                if (is_linear_array($e['headers'])) {
					$this->restful->error('Composite element index ' . $i . ' contains a "headers" key with an invalid type: linear array. Expecting non-linear array (map).');
					$this->restful->output('400');
                }

				foreach ($e['headers'] as $k => $v) {
					if (current_config()['composite']['strict_header_types'] === true) {
                        if (gettype($v) != 'string') {
						    $this->restful->error('Composite element index ' . $i . ' containing a "headers" key of the right type (non-linear array), but at least one entry value of this array is not of strict string type for the following key: ' . $k);
                            $this->restful->output('400');
                        }
					} else {
                        if (!in_array(gettype($v), array('string', 'integer', 'double', 'boolean', 'NULL'))) {
						    $this->restful->error('Composite element index ' . $i . ' containing a "headers" key of the right type (non-linear array), but at least one entry value of this array is not of an acceptable types for the following key: ' . $k);
                            $this->restful->output('400');
                        }
                    }
				}
            }

            /* Check if required is set and contains a valid type */
            if (isset($e['required']) && (gettype($e['required']) != 'boolean')) {
                $this->restful->error('Composite element index ' . $i . ' contains a "required" property with an invalid type. Expecting boolean.');
                $this->restful->output('400');
            }

            /* Check if an hidden property is set and contains a valid type */
            if (isset($e['hidden']) && (gettype($e['hidden']) != 'boolean')) {
                $this->restful->error('Composite element index ' . $i . ' contains a "hidden" property with an invalid type. Expecting boolean.');
                $this->restful->output('400');
            }

            /* Check if there's a named index property set and contains a valid type and doesn't use reserved values */
            if (isset($e['name'])) {
                /* Must be of string type */
                if (gettype($e['name']) != 'string') {
                    $this->restful->error('Composite element index ' . $i . ' contains a "name" property with an invalid type. Expecting string.');
                    $this->restful->output('400');
                }

                /* Must not include the reserved keyword "last" */
                if ($e['name'] == 'last') {
                    $this->restful->error('Composite element index ' . $i . ' contains a "name" property with a reserved keyword: last.');
                    $this->restful->output('400');
                }
            }
		}


		/** Execute composite **/

        $output_list = array_pad(array(), count($input['content']), NULL);
        $status_list = array_pad(array(), count($input['content']), NULL);

        for ($i = 0; $i < count($input['content']); $i ++) {
            /* Update current index */
            $this->_current_index = $i;

            /* Shorten variable name for the current content entry */
            $c = $input['content'][$i];

            /* If this entry is marked as unsafe, replace any variables found. */
            if (isset($c['unsafe']) && ($c['unsafe'] === true)) {
                /* Reset any previous indication of found variables from previous child requests */
                $this->_unsafe_variables_found = false;

                /* Security is now in the hands of the requester, as an unsafe call was forced.
                 * The requester must grant that a the content of the payload is always fully controlled programatically, and never filled or partially controlled by a third party.
                 */
                $this->_lookup_variables($c, $output_list);

                /* Check if explicit unsafe requests can appear without variables set, and if not, check if any variables were set */
                if (!current_config()['composite']['allow_unsafe_without_vars'] && !$this->_unsafe_variables_found) {
                    $this->restful->error('Composite element index ' . $i . ' contains an enabled unsafe property, but no variables were found in its content.');
                    $this->restful->output('403');
                }
            }

            /* Combine headers */
			$c_headers = $this->_combine_headers(isset($c['headers']) ? $c['headers'] : array(), $input['composite']);

            /* Perform request */
            $r = $this->restful->request(
                $c['method'],
                base_url(true) . ltrim($c['endpoint'], '/'),
                $c['payload'],
                $c_headers,
                $status_code,
                $raw_output,
                current_config()['composite']['max_child_connection_time'],
                current_config()['composite']['max_child_execution_time'],
                current_config()['composite']['child_basic_auth'],
                true, /* Always include related */
                current_config()['composite']['child_accept_encoding'],
                current_config()['composite']['child_content_encoding']
            );

            /* Update status list */
            array_push($status_list, $status_code);

            /* Update output list */
            $output_list[$i] = $r;

            /* Check if the returned status code falls under the lowest code to trigger an error */
            if ($status_code >= current_config()['composite']['lowest_error_code']) {
                /* Check if the request is marked as required, and if so, abort execution... */
                if (isset($c['required']) && ($c['required'] === true)) {
                    $this->restful->error('Composite element index ' . $i . ' is marked as required and failed to be executed. Returned status code: ' . $status_code);
                    $this->restful->output('502');
                } else {
                    /* Set result partial error subcode when a request fails and is not marked as required */
                    $this->restful->subcode(UWEB_SUBCODE_COMPOSITE_ERROR_RESULT_PARTIAL);
                }
            } else if (isset($input['single']) && ($input['single'] === true)) {
                /* If "single" property is enabled, the first successful request shall end the composite execution. */
                break;
            }
        }


        /** Post-process results **/

        /* Check for composite child requests which results should be removed from the effective response */
        for ($i = 0; $i < count($input['content']); $i ++) {
            if (($status_list[$i] !== NULL) && isset($input['content'][$i]['hidden']) && ($input['content'][$i]['hidden'] === true)) {
                /* Exclude result from output list */
                $output_list[$i] = array('hidden' => true, 'code' => $status_list[$i]);
            }
        }


        /* All good */
        return array(
            'code' => '201',
            'data' => $output_list
        );
    }
}
