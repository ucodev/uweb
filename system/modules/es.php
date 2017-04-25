<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 05/03/2017
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

class UW_ES extends UW_Module {
    private function _fulltext_boosted_score($config, $index, $input, $max_records = 500, $type = NULL) {
        /** Validate Input */
        if ($input === NULL) {
            $this->restful->error('Unable to decode JSON data.');
            $this->restful->output('400');
        }

        /* Validation - Check mandatory fields */
        if (!isset($input['type'])) {
            $this->restful->error('Missing required parameter: \'type\'.');
            $this->restful->output('400');
        }

        if ($input['type'] != 'fulltext') {
            $this->restful->error('Parameter \'type\' must have the value \'fulltext\'.');
            $this->restful->output('400');
        }

        if (!isset($input['query'])) {
            $this->restful->error('Missing required parameter: \'query\'.');
            $this->restful->output('400');
        }

        if (!isset($input['query']['text'])) {
            $this->restful->error('Missing required parameter \'text\' on \'query\'.');
            $this->restful->output('400');
        }

        $search['type'] = 'fulltext';
        $search['text'] = $input['query']['text'];

        /* Validation - Check optional fields */

        /* Show */
        if (isset($input['show'])) {
            if (gettype($input['show']) != 'array') {
                $this->restful->error('Paramter \'show\' is set, but it\'s not of array type.');
                $this->restful->output('400');
            }

            $search['show'] = array();

            foreach ($input['show'] as $field) {
                if (gettype($field) != 'string') {
                    $this->restful->error('Not all elements inside parameter \'show\' are of type string.');
                    $this->restful->output('400');
                }

                /* Ignore any fields not present in the configuration */
                if (!in_array($field, $config['fields']['visible']))
                    continue;
                
                array_push($search['show'], $field);
            }

            /* Grant that there's at least one field to be shown */
            if (!count($search['show'])) {
                $this->restful->error('No valid fields were set to be visible inside parameter \'show\'.');
                $this->restful->output('400');
            }
        } else {
            $search['show'] = $config['fields']['visible'];
        }

        /* Limit */
        if (isset($input['limit'])) {
            /* Check if limit value is an integer */
            if (gettype($input['limit']) != 'integer') {
                $this->restful->error('Paramter \'limit\' is set, but it\'s not of integer type.');
                $this->restful->output('400');
            }

            /* Set the result limit */
            $search['limit'] = $input['limit'];

            /* Check if the limit is not greater than 500 */
            if ($search['limit'] > 500) {
                $this->restful->error('Parameter \'limit\' must be lesser or equal to 500.');
                $this->restful->output('400');
            }
        } else {
            $search['limit'] = 10;
        }

        /* Offset */
        if (isset($input['offset'])) {
            /* Check if offset value is an integer */
            if (gettype($input['offset']) != 'integer') {
                $this->restful->error('Paramter \'offset\' is set, but it\'s not of integer type.');
                $this->restful->output('400');
            }

            /* Set the offset of the result */
            $search['offset'] = $input['offset'];
        } else {
            $search['offset'] = 0;
        }


        /** ES Query **/

        /* ES Query - Initialize boosted query */
        $es_input['query']['function_score']['query']['bool']['should'] = array();

        /* ES Query - Set boosted fields and respective boosting factor */
        foreach ($config['fields']['match']['boost'] as $k => $v) {
            array_push($es_input['query']['function_score']['query']['bool']['should'],
                array(
                    'match' => array(
                        $k => array(
                            'query' => $search['text'],
                            'fuzziness' => $config['fuzziness'],
                            'boost' => $v
                        )
                    )
                )
            );
        }

        /* ES Query - Set normal fields as multi match */
        array_push($es_input['query']['function_score']['query']['bool']['should'],
            array(
                'multi_match' => array(
                    'fields' => $config['fields']['match']['normal'],
                    'query' => $search['text'],
                    'fuzziness' => $config['fuzziness']
                )
            )
        );

        /* ES Query - Set function score parameters */
        $es_input['query']['function_score']['field_value_factor'] = array(
            'field' => $config['score']['field'],
            'modifier' => $config['score']['modifier'],
            'factor' => $config['score']['factor']
        );

        /* ES Query - Set additional boost parameters for function score */
        $es_input['query']['function_score']['boost_mode'] = $config['score']['boost_mode'];
        $es_input['query']['function_score']['max_boost'] = $config['score']['max_boost'];

		/* Forward request to the search engine (ES) */
		$ch = curl_init();

		/* Set the request URL */
        curl_setopt($ch, CURLOPT_URL, rtrim($config['query']['base_url'], '/') . '/' . $index . ($type !== NULL ? ('/' . $type) : '') .'/_search?size=' . $search['limit'] . '&from=' . $search['offset']);

		/* Set request body data, if any */
		if ($es_input !== NULL) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($es_input) ? json_encode($es_input) : $es_input);
		}

		/* Grant that cURL will return the response output */
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		/* Execute the request */
		$es_output = curl_exec($ch);

		/* Close the cURL handler */
		curl_close($ch);


        /** Output **/
        $output = json_decode($es_output, true);

        if ($output === NULL || !isset($output['hits'])) {
            $this->restful->error('Unable to decode JSON data retrieved from search engine.');
            $this->restful->code('500');
        }

        $data[$index]['total'] = $output['hits']['total'];
        $data[$index]['count'] = count($output['hits']['hits']);
        $data[$index]['result'] = array();

        foreach ($output['hits']['hits'] as $hit) {
            $filtered_hit = array();

            foreach ($hit['_source'] as $k => $v) {
                if (!in_array($k, $search['show']))
                    continue;
                
                $filtered_hit[$k] = $v;
            }

            array_push($data[$index]['result'], $filtered_hit);
        }

        /* All good */
        return $data;
    }

    public function query($config, $index, $input, $max_records = 500, $type = NULL) {
        /** Validate Input */
        if ($input === NULL) {
            $this->restful->error('Unable to decode JSON data.');
            $this->restful->output('400');
        }

        /* Validation - Check mandatory fields */
        if (!isset($input['type'])) {
            $this->restful->error('Missing required parameter: \'type\'.');
            $this->restful->output('400');
        }

        /* Perform the request based on the input type */
        if ($input['type'] == 'fulltext') {
            if ($config['query']['type'] == 'boosted' && $config['query']['function'] == 'score') {
                return $this->_fulltext_boosted_score($config, $index, $input, $max_records, $type);
            } else {
                $this->restful->error('Unrecognized or unsupported \'query\' configuration parameters.');
                $this->restful->output('400');
            }
        }

        /* Unrecognized input type */
        $this->restful->error('Parameter \'type\' from input must match a valid type.');
        $this->restful->output('400');
    }

    public function get($config, $index, $type, $id) {
		/* Forward request to the search engine (ES) */
		$ch = curl_init();

		/* Set the request URL */
        curl_setopt($ch, CURLOPT_URL, rtrim($config['query']['base_url'], '/') . '/' . $index . '/' . $type .'/' . $id);

		/* Grant that cURL will return the response output */
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		/* Execute the request */
		$es_output = curl_exec($ch);

		/* Close the cURL handler */
		curl_close($ch);

        /* Initialize data */
        $data = NULL;

        /* Check if there's any output */
        if ($es_output)
            $data = json_decode($es_output, true);

        /* Check if there's valid JSON data */
        if ($data === NULL)
            return NULL;

        /* Check if document was found */
        if ($data['found'] === false)
            return false;

        /* All good */
        return $data['_source'];
    }
}
