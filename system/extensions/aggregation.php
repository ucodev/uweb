<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 30/01/2017
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

class UW_Aggregation {
    public function join($blob, $key_blob, $extra, $key_extra, $aggr_keys, $nomatch_value = NULL, $aggr_name = 'default', $aggr_group = '_aggregations') {
        /* Iterate blob and look for key matches from $extra */
        for ($i_blob = 0; $i_blob < count($blob); $i_blob ++) {
            /* Reset match indicator */
            $key_match = false;

            /* Check if the current blob entry contains the specified blob key */
            if (!isset($blob[$i_blob][$key_blob]))
                continue;
            
            /* Search for a matching key/value on the extra data */
            for ($i_extra = 0; $i_extra < count($extra); $i_extra ++) {
                /* Check if the current extra entry contains the specified extra key */
                if (!isset($extra[$i_extra][$key_extra]))
                    continue;
                
                /* Check if this both values from keys match */
                if ($extra[$i_extra][$key_extra] === $blob[$i_blob][$key_blob]) {
                    /* Aggregate k/v pairs to blob */
                    foreach ($aggr_keys as $aggr_key)
                        $blob[$i_blob][$aggr_group][$aggr_name][$aggr_key] = $extra[$i_extra][$aggr_key];

                    /* Update match indicator */
                    $key_match = true;

                    /* No need to continue processing $extra */
                    break;
                }
            }

            /* If no match was found, populate the blob keys with $nomatch_value */
            if (!$key_match) {
                foreach ($aggr_keys as $aggr_key)
                    $blob[$i_blob][$aggr_group][$aggr_name][$aggr_key] = $nomatch_value;
            }
        }

        /* All good */
        return $blob;
    }

    public function add($blob, $extra) {
        /* Iterate over blob */
        for ($i = 0; $i < count($blob); $i ++) {
            /* Add extra K/V pairs to each blob row */
            foreach ($extra as $k => $v)
                $blob[$i][$k] = $v;
        }

        /* All good */
        return $blob;
    }
}
