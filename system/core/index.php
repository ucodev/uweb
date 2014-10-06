<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 24/09/2014
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

/* Load all system core modules */
foreach (glob("system/core/*.php") as $sys_core) {
	if (substr($sys_core, -9) == 'index.php')
		continue;

    include($sys_core);
}

/* Load all user models */
foreach (glob("application/models/*.php") as $user_model)
    include($user_model);
