<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 11/06/2017
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

/** THIS FILE IS LOADED FROM system/index.php **/

/* uWeb Base Class */
class UW_Base {
	public function __construct() {
		return;
	}
}

/* Include all system core modules */
foreach (glob("system/core/*.php") as $sys_core) {
	if (substr($sys_core, -9) == 'index.php')
		continue;

    include($sys_core);
}

/* Include all system models */
foreach (glob("system/models/*.php") as $sys_model) {
    include($sys_model);

	/* Instantiate the object if present in autoload */
	if (in_array($sys_model, $config['autoload']['models']))
		eval('$__objects[\'autoload\'][\'' . $sys_model . '\'] = new UW_' . ucfirst($sys_model) . '();');
}

/* Include all system modules */
foreach (glob("system/modules/*.php") as $sys_module) {
    include($sys_module);

	/* Instantiate the object if present in autoload */
	if (in_array($sys_module, $config['autoload']['modules']))
		eval('$__objects[\'autoload\'][\'' . $sys_module. '\'] = new UW_' . ucfirst($sys_module) . '();');
}

/* Include all system extensions */
foreach (glob("system/extensions/*.php") as $sys_ext)  {
    include($sys_ext);

	/* Instantiate the object if present in autoload */
	if (in_array($sys_ext, $config['autoload']['extensions']))
		eval('$__objects[\'autoload\'][\'' . $sys_ext . '\'] = new UW_' . ucfirst($sys_ext) . '();');
}

/* Include all application models */
foreach (glob("application/models/*.php") as $app_model) {
    include($app_model);

	/* Instantiate the object if present in autoload */
	if (in_array($app_model, $config['autoload']['models']))
		eval('$__objects[\'autoload\'][\'' . $app_model . '\'] = new UW_' . ucfirst($app_model) . '();');
}

/* Include all application modules */
foreach (glob("application/modules/*.php") as $app_module) {
    include($app_module);

	/* Instantiate the object if present in autoload */
	if (in_array($app_module, $config['autoload']['modules']))
		eval('$__objects[\'autoload\'][\'' . $app_module . '\'] = new UW_' . ucfirst($app_module) . '();');
}
