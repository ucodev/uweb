<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author:   Pedro A. Hortas
 * Email:    pah@ucodev.org
 * Modified: 01/10/2017
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

class UW_ES extends UW_Module {
    private function _usl_to_es($usl_query) {
        /* TODO: Add support for nested USL criteria. This will allow USL 'query' to be of type 'array' */

        /* Auxiliar clauses */
        $ranges = array();

        /* Initialize ES query */        
        $es_query = array();

        /* Iterate over fields */
        foreach ($usl_query as $field => $criteria) {
            $context = NULL; /* Reset context */

            /* Get filter type. TODO: FIXME: Boolean OR isn't fully implemented. The current behaviour causes all OR's to fall inside the
             * same 'should', disregarding any precedences.
             */
            if (isset($usl_query[$field]['or'])) {
                $filter_type = 'should';
            } else {
                $filter_type = 'must';
            }

            /* Check if there are any criteria set */
            if (!$criteria) {
                $this->restful->error('No criteria set.');
                $this->restful->output('400');
            }

            /* $criteria must be an associative array, containing a criteria keyword and value */
            if ((gettype($criteria) != 'array') || !count(array_filter(array_keys($criteria), 'is_string'))) {
                $this->restful->error('Invalid criteria type detected for field \'' . $field . '\': ' . $criteria);
                $this->restful->output('400'); /* Bad Request */
            }

            /* Iterate over field criteria */
            foreach ($criteria as $cond => $value) {
                switch ($cond) {
                    case 'or': continue; /* Skip OR's. If it's present in this criteria, it was already processed for $filter_type */
                    case 'exact': continue;
                    case 'diff': continue;

                    case 'contains': {
                        /* Validate if $value is string. TODO: FIXME: 'contains' shall also accept array of strings */
                        if (gettype($value) != 'string') {
                            $this->restful->error('Invalid type found in condition \'' . $cond . '\' on field \'' . $field . '\': Expecting string.');
                            $this->restful->output('400');
                        }

                        /* Check if this is a negative string search */
                        if (isset($usl_query[$field]['diff']) && $usl_query[$field]['diff']) {
                            /* Initialize filter type, if required */
                            if (!isset($es_query['filter']['bool'][$filter_type]))
                                $es_query['filter']['bool'][$filter_type] = array();

                            /* For 'diff', we should use a nested boolean query with 'must_not' inside the $filter_type */
                            array_push($es_query['filter']['bool'][$filter_type], array(
                                'bool' => array(
                                    'must_not' => array(
                                        ((isset($usl_query[$field]['exact']) && $usl_query[$field]['exact']) ? 'term' : 'match') => array(
                                            $field => $value
                                        )
                                    )
                                )
                            ));
                        } else {
                            /* Initialize filter type, if required */
                            if (!isset($es_query['filter']['bool'][$filter_type]))
                                $es_query['filter']['bool'][$filter_type] = array();

                            array_push($es_query['filter']['bool'][$filter_type], array(
                                ((isset($usl_query[$field]['exact']) && $usl_query[$field]['exact']) ? 'term' : 'match') => array(
                                    $field => $value
                                )
                            ));
                        }
                    } break;

                    case 'not_in': {
                        /* Validate if $value is array */
                        if (gettype($value) != 'array') {
                            $this->restful->error('Invalid type found in condition \'' . $cond . '\' on field \'' . $field . '\': Expecting array.');
                            $this->restful->output('400');
                        }

                        /* Initialize filter type, if required */
                        if (!isset($es_query['filter']['bool'][$filter_type]))
                            $es_query['filter']['bool'][$filter_type] = array();

                        /* Craft query terms */
                        $terms = array();

                        foreach ($value as $v) {
                            array_push($terms, array(
                                'term' => array(
                                    $field => $v
                                )
                            ));
                        }

                        /* Initialize filter type, if required */
                        if (!isset($es_query['filter']['bool'][$filter_type]))
                            $es_query['filter']['bool'][$filter_type] = array();

                        array_push($es_query['filter']['bool'][$filter_type], array(
                            'bool' => array(
                                'must_not' => $terms
                            )
                        ));
                    } break;

                    case 'in': {
                        /* Validate if $value is array */
                        if (gettype($value) != 'array') {
                            $this->restful->error('Invalid type found in condition \'' . $cond . '\' on field \'' . $field . '\': Expecting array.');
                            $this->restful->output('400');
                        }

                        /* Initialize filter type, if required */
                        if (!isset($es_query['filter']['bool'][$filter_type]))
                            $es_query['filter']['bool'][$filter_type] = array();

                        /* Craft query terms */
                        $terms = array();

                        foreach ($value as $v) {
                            array_push($terms, array(
                                'term' => array(
                                    $field => $v
                                )
                            ));
                        }

                        /* Initialize filter type, if required */
                        if (!isset($es_query['filter']['bool'][$filter_type]))
                            $es_query['filter']['bool'][$filter_type] = array();

                        array_push($es_query['filter']['bool'][$filter_type], array(
                            'bool' => array(
                                'should' => $terms
                            )
                        ));
                    } break;

                    case 'is': {
                        /* Validate if the type is NULL */
                        if ($value === NULL) {
                            /* TODO: FIXME: Elasticsearch (<= 5.x) does not allow null searches. https://www.elastic.co/guide/en/elasticsearch/reference/5.4/null-value.html */
                            $this->restful->error('NULL searches are not supported.');
                            $this->restful->output('400');
                        }

                        /* Validate if the type is boolean */
                        if (gettype($value) != 'boolean') {
                            $this->restful->error('Invalid type found in condition \'' . $cond . '\' on field \'' . $field . '\': Expecting boolean.');
                            $this->restful->output('400');
                        }
                    }
                    case 'eq': {
                        /* Validate if $value is integer or float */
                        if ($cond != 'is' && gettype($value) != 'integer' && gettype($value) != 'double') {
                            $this->restful->error('Invalid type found in condition \'' . $cond . '\' on field \'' . $field . '\': Expecting integer or float.');
                            $this->restful->output('400');
                        }

                        /* Initialize filter type, if required */
                        if (!isset($es_query['filter']['bool'][$filter_type]))
                            $es_query['filter']['bool'][$filter_type] = array();

                        array_push($es_query['filter']['bool'][$filter_type], array(
                            'term' => array(
                                $field => $value
                            )
                        ));
                    } break;

                    case 'is_not': {
                        /* Validate if the type is NULL */
                        if ($value === NULL) {
                            /* TODO: FIXME: Elasticsearch (<= 5.x) does not allow null searches. https://www.elastic.co/guide/en/elasticsearch/reference/5.4/null-value.html */
                            $this->restful->error('NULL searches are not supported.');
                            $this->restful->output('400');
                        }

                        /* Validate if the type is boolean */
                        if (gettype($value) != 'boolean') {
                            $this->restful->error('Invalid type found in condition \'' . $cond . '\' on field \'' . $field . '\': Expecting boolean.');
                            $this->restful->output('400');
                        }

                        /* Avoid double negation */
                        if ($value === false) {
                            $this->restful->error('Avoid double negation. Use \'{ "' . $field . '": { "is": true } }\'.');
                            $this->restful->output('400');
                        }
                    }
                    case 'ne': {
                        /* Validate if $value is integer or float */
                        if ($cond != 'is_not' && gettype($value) != 'integer' && gettype($value) != 'double') {
                            $this->restful->error('Invalid type found in condition \'' . $cond . '\' on field \'' . $field . '\': Expecting integer or float.');
                            $this->restful->output('400');
                        }

                        /* Initialize must_not, if required */
                        if (!isset($es_query['filter']['bool'][$filter_type]))
                            $es_query['filter']['bool'][$filter_type] = array();

                        /* For 'ne', we should use a nested boolean query with 'must_not' inside the $filter_type */
                        array_push($es_query['filter']['bool'][$filter_type], array(
                            'bool' => array(
                                'must_not' => array(
                                    'term' => array(
                                        $field => $value
                                    )
                                )
                            )
                        ));
                    } break;

                    case 'from': /* $cond may have been changed */ if ($cond == 'from') $cond = 'gte';
                    case 'to':   /* $cond may have been changed */ if ($cond == 'to') $cond = 'lte';
                    case 'gt':
                    case 'gte':
                    case 'lt':
                    case 'lte': {
                        /* Validate if $value is integer, float/double or string*/
                        if (gettype($value) != 'integer' && gettype($value) != 'double' && gettype($value) != 'string') {
                            $this->restful->error('Invalid type found in condition \'' . $cond . '\' on field \'' . $field . '\': Expecting integer, float, time, date or datetime.');
                            $this->restful->output('400');
                        }

                        /* TODO: Grant that 'string' type content matches time, date or datetime formats */

                        /* Initialize filter type for $ranges, if required */
                        if (!isset($ranges[$filter_type][$field]))
                            $ranges[$filter_type][$field] = array();

                        /* Push range condition */
                        $ranges[$filter_type][$field] = array_merge(
                            $ranges[$filter_type][$field], array($cond => $value)
                        );
                    } break;

                    default: {
                        $this->restful->error('Invalid criteria found in field \'' . $field . '\': ' . $cond . '.');
                        $this->restful->output('400');
                    }
                }
            }
        }

        /* Merge auxiliar clauses */
        foreach ($ranges as $filter_type => $kv) {
            foreach ($kv as $k => $v) {
                if (!isset($es_query['filter']['bool'][$filter_type]))
                    $es_query['filter']['bool'][$filter_type] = array();

                array_push($es_query['filter']['bool'][$filter_type], array(
                    'range' => array(
                        $k => $v
                    )
                ));
            }
        }

        /* All good */
        return $es_query;
    }

    private function _filter_constant_score($config, $index, $input, $max_records = 500, $type = NULL) {
        /** Validate Input */
        if ($input === NULL) {
            $this->restful->error('Unable to decode JSON data.');
            $this->restful->output('400');
        }

        /* Validation - Check mandatory fields */
        if (isset($input['type']) && $input['type'] != 'filter') {
            $this->restful->error('Parameter \'type\' should either be omitted or must have the value \'filter\'.');
            $this->restful->output('400');
        }

        if (!isset($input['query'])) {
            $this->restful->error('Missing required parameter: \'query\'.');
            $this->restful->output('400');
        }

        if (gettype($input['query']) != 'array') {
            $this->restful->error('Parameter \'query\' must be of type array.');
            $this->restful->output('400');
        }

        $search['type'] = 'filter';
        $search['query']['constant_score']['filter'] = $this->_usl_to_es($input['query'])['filter'];

        /* Validation - Check optional fields */

        /* Show */
        if (isset($input['show'])) {
            if (gettype($input['show']) != 'array') {
                $this->restful->error('Parameter \'show\' is set, but it\'s not of array type.');
                $this->restful->output('400');
            }

            /* Grant that there's at least one field to be shown */
            if (!count($input['show'])) {
                $this->restful->error('No valid fields were set to be visible inside parameter \'show\'.');
                $this->restful->output('400');
            }

            $search['show'] = $input['show'];
        }

        /* Limit */
        if (isset($input['limit'])) {
            /* Check if limit value is an integer */
            if (gettype($input['limit']) != 'integer') {
                $this->restful->error('Parameter \'limit\' is set, but it\'s not of integer type.');
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
                $this->restful->error('Parameter \'offset\' is set, but it\'s not of integer type.');
                $this->restful->output('400');
            }

            /* Set the offset of the result */
            $search['offset'] = $input['offset'];
        } else {
            $search['offset'] = 0;
        }

        /* Order by */
        if (isset($input['orderby'])) {
            /* Check if orderby value is a string */
            if (gettype($input['orderby']) != 'string') {
                $this->restful->error('Parameter \'orderby\' is set, but it\'s not of string type.');
                $this->restful->output('400');
            }

            /* Set the orderby of the result */
            $search['orderby'] = $input['orderby'];
        }

        /* Ordering */
        if (isset($input['ordering'])) {
            /* Check if ordering value is a string */
            if ((strtolower($input['ordering']) != 'asc') && (strtolower($input['ordering']) != 'desc') && (strtolower($input['ordering'] != 'in'))) {
                $this->restful->error('Parameter \'ordering\' is set, but it\'s not one of \'asc\', \'desc\' nor \'in\'.');
                $this->restful->output('400');
            }

            /* Check if this is an in-order ordering */
            if (strtolower($input['ordering']) == 'in') {
                /* Ordering by 'in' requires 'orderby' parameter to be explicitly set */
                if (!isset($input['orderby'])) {
                    $this->restful->error('Parameter \'ordering\' is set as \'in\', but no \'orderby\' parameter was found.');
                    $this->restful->output('400');
                }

                /* Ordering by 'in' requires 'query' parameter to contain a 'in' criteria for the 'orderby' field. */
                if (!isset($input['query'][$input['orderby']]['in'])) {
                    $this->restful->error('Parameter \'ordering\' is set as \'in\', but \'query\' does not contain a \'in\' criteria for the field set for \'orderby\' parameter.');
                    $this->restful->output('400');
                }

                /* Mark this search for reordering based on 'in' criteria */
                $search['inorder'] = true;

                /* Set order to ascending (could also be descending, as the result will be reordered) */
                $search['ordering'] = 'asc';
            } else {
                /* Set the ordering of the result */
                $search['ordering'] = strtolower($input['ordering']);
            }
        } else if (isset($input['orderby'])) {
            /* If orderby was set, use a default 'asc' ordering */
            $search['ordering'] = 'asc';
        }


        /** ES Query **/

        /* ES Query - Initialize query */
        $es_input['query'] = $search['query'];
        $es_input['size'] = $search['limit'];
        $es_input['from'] = $search['offset'];

        if (isset($search['show']))
            $es_input['_source']['includes'] = $search['show'];

        if (isset($search['orderby']))
            $es_input['sort'][$search['orderby']]['order'] = $search['ordering'];

		/* Forward request to the search engine (ES) */
		$ch = curl_init();

		/* Set the request URL */
        curl_setopt($ch, CURLOPT_URL, rtrim($config['query']['base_url'], '/') . '/' . $index . ($type !== NULL ? ('/' . $type) : '') .'/_search?pretty');

		/* Set request body data, if any */
		if ($es_input !== NULL) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($es_input) ? json_encode($es_input) : $es_input);
		}

		/* Grant that cURL will return the response output */
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		/* Execute the request */
		$es_output = curl_exec($ch);

		/* Get HTTP Status Code */
		$http_status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		/* Close the cURL handler */
		curl_close($ch);


        /** Status **/
        if (!in_array($http_status_code, array(200, 201))) {
            $this->restful->error('Unable to retrieve data from the search engine.');
            $this->restful->output('502'); /* Bad Gateway */
        }


        /** Output **/
        $output = json_decode($es_output, true);

        if ($output === NULL || !isset($output['hits'])) {
            $this->restful->error('Unable to decode JSON data retrieved from search engine.');
            $this->restful->output('502'); /* Bad Gateway */
        }

        /* Initialize data */
        $data = array();
        $data[$index]['total'] = $output['hits']['total'];
        $data[$index]['count'] = count($output['hits']['hits']);

        /* inorder results require a pre-existing array, filled with empty (false) values */
        if (isset($search['inorder']) && ($search['inorder'] === true)) {
            /* Get 'in' criteria values */
            $in_values = $input['query'][$input['orderby']]['in'];

            /* Initialize array */
            $data[$index]['result'] = array_fill(0, count($in_values), false);

            /* Remap and reorder the result */
            foreach ($output['hits']['hits'] as $hit)
                $data[$index]['result'][intval(array_search($hit['_source'][$search['orderby']], $in_values))] = $hit['_source'];

            /* Filter empty values (values that are set to false, which were not filled by the above iteration) */
            $data[$index]['result'] = array_values(array_filter($data[$index]['result']));
        } else {
            $data[$index]['result'] = array();

            /* Remap result */
            foreach ($output['hits']['hits'] as $hit)
                array_push($data[$index]['result'], $hit['_source']);
        }


        /* All good */
        return $data;
    }

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

        if ($input['type'] != 'fulltext' && $input['type'] != 'fulltext-filter') {
            $this->restful->error('Parameter \'type\' must have the value \'fulltext\' or \'fulltext-filter\'.');
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

        $search['type'] = $input['type'];
        $search['text'] = $input['query']['text'];

        /* Check if there's a filter set */
        if (isset($input['query']['filter'])) {
            /* If the search query contains a filter, force the fulltext-filter type */
            $search['type'] = 'fulltext-filter';
            $search['filter'] = $input['query']['filter'];
        } else {
            /* If the search query does not contains a filter, force the fulltext type */
            $search['type'] = 'fulltext';
        }

        /* Validation - Check optional fields */

        /* Show */
        if (isset($input['show'])) {
            if (gettype($input['show']) != 'array') {
                $this->restful->error('Parameter \'show\' is set, but it\'s not of array type.');
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
                $this->restful->error('Parameter \'offset\' is set, but it\'s not of integer type.');
                $this->restful->output('400');
            }

            /* Set the offset of the result */
            $search['offset'] = $input['offset'];
        } else {
            $search['offset'] = 0;
        }


        /** ES Query **/

        /* ES Query - Initialize boosted query */
        $es_input['query']['function_score']['query']['bool']['must'] = array();
        $es_input['query']['function_score']['query']['bool']['must']['bool']['should'] = array();

        /* ES Query - If this is a fulltext-filter type, check and translate filter properties */
        if ($search['type'] == 'fulltext-filter') {
            if (!isset($search['filter'])) {
                /* We should never get here, as the fulltext and fulltext-filter should have been automatically determiend on pre-checks */
                $this->restful->error('Searches of type \'fulltext-filter\' must have a \'filter\' property.');
                $this->restful->output('400');
            }

            /* Translate USL filter to ES filter */
            $es_input['query']['function_score']['query']['bool']['filter'] = $this->_usl_to_es($search['filter'])['filter'];
        }
        

        /* ES Query - Set boosted fields and respective boosting factor */
        foreach ($config['fields']['match']['boost'] as $k => $v) {
            array_push($es_input['query']['function_score']['query']['bool']['must']['bool']['should'],
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
        array_push($es_input['query']['function_score']['query']['bool']['must']['bool']['should'],
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
            $this->restful->output('502'); /* Bad Gateway */
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

    private function _raw($config, $index, $input, $max_records = 500, $type = NULL) {
        /** Validate Input */
        if ($input === NULL) {
            $this->restful->error('Unable to decode JSON data.');
            $this->restful->output('400');
        }

        /* Validation - Check mandatory fields */
        if (isset($input['type']) && $input['type'] != 'raw') {
            $this->restful->error('Parameter \'type\' should either be omitted or must have the value \'filter\'.');
            $this->restful->output('400');
        }

        if (!isset($input['query'])) {
            $this->restful->error('Missing required parameter: \'query\'.');
            $this->restful->output('400');
        }

        if (gettype($input['query']) != 'array') {
            $this->restful->error('Parameter \'query\' must be of type array.');
            $this->restful->output('400');
        }

        $search['type'] = 'raw';
        $search['query'] = $input['query'];


        /** ES Query **/

        /* ES Query - Initialize query */
        $es_input = $search['query'];

		/* Forward request to the search engine (ES) */
		$ch = curl_init();

		/* Set the request URL */
        curl_setopt($ch, CURLOPT_URL, rtrim($config['query']['base_url'], '/') . '/' . $index . ($type !== NULL ? ('/' . $type) : '') .'/_search');

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

        if ($output === NULL) {
            $this->restful->error('Unable to decode JSON data retrieved from search engine.');
            $this->restful->output('502'); /* Bad Gateway */
        }

        /* Set $data */
        $data = $output;

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
        if ($input['type'] == 'fulltext' || $input['type'] == 'fulltext-filter') {
            if ($config['query']['type'] == 'boosted' && $config['query']['function'] == 'score') {
                return $this->_fulltext_boosted_score($config, $index, $input, $max_records, $type);
            } else {
                $this->restful->error('Unrecognized or unsupported \'query\' configuration parameters.');
                $this->restful->output('400');
            }
        } else if ($input['type'] == 'filter') {
            if ($config['query']['type'] == 'constant' && $config['query']['function'] == 'score') {
                return $this->_filter_constant_score($config, $index, $input, $max_records, $type);
            } else {
                $this->restful->error('Unrecognized or unsupported \'query\' configuration parameters.');
                $this->restful->output('400');
            }
        } else if ($input['type'] == 'raw') {
            return $this->_raw($config, $index, $input, $max_records, $type);
        }

        /* Unrecognized input type */
        $this->restful->error('Parameter \'type\' from input must match a valid type.');
        $this->restful->output('400');
    }

    public function delete($config, $index, $type, $id) {
		/* Prepare and forward request to the search engine (ES) */
		$ch = curl_init();

		/* Set the request URL */
        curl_setopt($ch, CURLOPT_URL, rtrim($config['query']['base_url'], '/') . '/' . $index . '/' . $type . '/' . $id);

        /* Set DELETE method */
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

		/* Execute the request */
		curl_exec($ch);

        /* Get status code */
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        /* Return true if entry was deleted, otherwise return false */
        return ($status_code == 200) ? true : false;
    }

    public function get($config, $index, $type, $id) {
		/* Forward request to the search engine (ES) */
		$ch = curl_init();

		/* Set the request URL */
        curl_setopt($ch, CURLOPT_URL, rtrim($config['query']['base_url'], '/') . '/' . $index . '/' . $type . '/' . $id);

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

    public function post($config, $index, $type, $data, $id = NULL) {
        /* If $id wasn't set, try to retrieve it from $data object */
        if ($id === NULL) {
            if (isset($data['id']))
                $id = $data['id'];
        }

        /* Encode data to JSON */
        if (!($data_json = json_encode($data))) {
            $this->restful->error('Cannot decode JSON data.');
            $tihs->restful->output('400');
        }

		/* Prepare and forward request to the search engine (ES) */
		$ch = curl_init();

		/* Set the request URL */
        curl_setopt($ch, CURLOPT_URL, rtrim($config['query']['base_url'], '/') . '/' . $index . '/' . $type . (($id !== NULL) ? ('/' . $id) : ''));

		/* Grant that cURL will return the response output */
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        /* Set POST method and contents */
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);

		/* Execute the request */
		$es_output = curl_exec($ch);

        /* Get status code */
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		/* Close the cURL handler */
		curl_close($ch);

        /* Check if code is 200 or 201 */
        if (($status_code != 200) && ($status_code != 201))
            return false;

        /* Initialize data */
        $data = NULL;

        /* Check if there's any output */
        if ($es_output)
            $data = json_decode($es_output, true);

        /* Check if there's valid JSON data */
        if ($data === NULL)
            return NULL;

        /* Check if document was found */
        if (($data['result'] != 'created') && ($data['result'] != 'updated'))
            return false;

        /* All good */
        return array(
            'id' => $data['_id'],
            'result' => $data['result']
        );
    }

    public function put($config, $index, $type, $data, $id = NULL) {
        /* If $id wasn't set, try to retrieve it from $data object */
        if ($id === NULL) {
            if (isset($data['id']))
                $id = $data['id'];
        }

        /* Encode data into JSON */
        if (!($data_json = json_encode($data))) {
            $this->restful->error('Cannot decode JSON data.');
            $tihs->restful->output('400');
        }

        /* Create a temporary resource */
        if (!($data_fp = fopen('php://temp/maxmemory:1048576', 'w'))) {
            $this->restful->error('Unable to create temporary resource.');
            $this->restful->output('500');
        }

        /* Dump JSON data into temporary file */
        fwrite($data_fp, json_encode($data));
        fseek($data_fp, 0); /* Reset file pointer */

		/* Prepare and forward request to the search engine (ES) */
		$ch = curl_init();

		/* Set the request URL */
        curl_setopt($ch, CURLOPT_URL, rtrim($config['query']['base_url'], '/') . '/' . $index . '/' . $type . (($id !== NULL) ? ('/' . $id) : ''));

		/* Grant that cURL will return the response output */
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        /* Set PUT method and respective contents */
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_INFILE, $data_fp);
        curl_setopt($ch, CURLOPT_INFILEZIE, strlen($data_json));

		/* Execute the request */
		$es_output = curl_exec($ch);

        /* Get status code */
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		/* Close the cURL handler */
		curl_close($ch);

        /* Check if code is 200 or 201 */
        if (($status_code != 200) && ($status_code != 201))
            return false;

        /* Initialize data */
        $data = NULL;

        /* Check if there's any output */
        if ($es_output)
            $data = json_decode($es_output, true);

        /* Check if there's valid JSON data */
        if ($data === NULL)
            return NULL;

        /* Check if document was found */
        if (($data['result'] != 'created') && ($data['result'] != 'updated'))
            return false;

        /* All good */
        return array(
            'id' => $data['_id'],
            'result' => $data['result']
        );
    }
}
