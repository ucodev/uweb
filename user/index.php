<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 18/03/2016
 * License: GPLv3
 */

/*
 * This file is part of uweb.
 *
 * uweb is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * uweb is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with uweb.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/** THIS FILE IS LOADED FROM system/index.php **/

/* Include user utilities */
include('user/utils/index.php');

/* Include user libraries */
include('user/libraries/index.php');

/* Include user core controllers */
include('user/core/index.php');

/* Include all user models */
foreach (glob("system/models/*.php") as $user_model)
    include($user_model);

/* Include user extensions */
foreach (glob("user/extensions/*.php") as $user_ext)
    include($user_ext);
