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

class UW_View extends UW_Base {
	public function load($file, $data = NULL, $export_content = false, $enforce = true) {
		/* If enforce is set, grant that no potential harmful tags are exported to the view */
		if ($enforce && $data) {
			foreach ($data as $k => $v) {
				/* NOTE: This is only effective for string type values. Any other object won't be checked */
				if (gettype($v) == "string" && strpos(str_replace(' ', '', strtolower($v)), '<script') !== false) {
					header($_SERVER['SERVER_PROTOCOL'] . ' 500');
					die('load(): Unable to load views with <script> tags on their $data strings when $enforce is set to true (default).');
				}
			}
		}

		/* Check if there's anything to extract */
		if ($data !== NULL)
			extract($data, EXTR_PREFIX_SAME, "uw_");

		/* Unset $data variable as it's no longer required */
		unset($data);

		/* Validate filename */
		if ($enforce) {
			if (strpos($file, '../') !== false) {
				header($_SERVER['SERVER_PROTOCOL'] . ' 500');
				die('load(): Unable to load view files with \'../\' string on their names.');
			}
		}

		/* Load view from file */
		if ($export_content) {
			ob_start();
			include('application/views/' . $file . '.php');
			$content = ob_get_contents();
			ob_end_clean();
			return $content;
		} else {
			include('application/views/' . $file . '.php');
			return true;
		}
	}
}

class UW_Model {
	public $cache = NULL;
	public $db = NULL;
	public $session = NULL;
	public $encrypt = NULL;

	public function __construct() {
		/* Initialize system cache controller */
		$this->cache = new UW_Cache;

		/* Initialize system database controller */
		$this->db = new UW_Database;
		
		/* Initialize system session controller */
		$this->session = new UW_Session($this->db, $this->cache);

		/* Initialize system encryption controller */
		$this->encrypt = new UW_Encrypt;
	}
	
	public function load($model, $is_library = false, $tolower = false, $class_path = NULL) {
		global $__objects;

		if (!preg_match('/^[a-zA-Z0-9_]+$/', $model))
			return false;

		if ($is_library === true) {
			/* We're loading a library */
			eval('$this->' . ($tolower ? strtolower($model) : $model) . ' = new ' . ($class_path !== NULL ? $class_path : $model) . '();');
		} else {
			/* Be default, model objects are instantiated only once and, on subsequent calls, a reference to the existing
			 * (instantiated) object is passed.
			 */
			if ($__objects['enabled'] === true) {
				if (isset($__objects['autoload'][$model])) {
					eval('$this->' . $model . ' = &$__objects[\'autoload\'][\'' . $model . '\'];');
				} else if (isset($__objects['adhoc'][$model])) {
					eval('$this->' . $model . ' = &$__objects[\'adhoc\'][\'' . $model . '\'];');
				} else {
					eval('$__objects[\'adhoc\'][\'' . $model . '\'] = new UW_' . ucfirst($model) . '();');
					eval('$this->' . $model . ' = &$__objects[\'adhoc\'][\'' . $model . '\'];');
				}
			} else {
				eval('$this->' . $model . ' = new UW_' . ucfirst($model) . '();');
			}
		}

		return true;
	}
}

/* Alias class for loading methods (Old API compatibility) */
class UW_Load extends UW_Model {
	private $_database = NULL;
	private $_view = NULL;
	private $_model = NULL;
	private $_extension = NULL;
	private $_library = NULL;

	public function __construct($database, $model, $view, $extension, $library) {
		/* Initialize system database controller */
		$this->_database = $database;
		
		/* Initialize model class */
		$this->_model = $model;

		/* Initialize system view controller */
		$this->_view = $view;

		/* Initialize system extensions */
		$this->_extension = $extension;

		/* Initialize libraries */
		$this->_library = $library;
	}

	public function view($file, $data = NULL, $export_content = false) {
		return $this->_view->load($file, $data, $export_content);
	}

	public function model($model) {
		return $this->_model->load($model, false, false);
	}

	public function module($module) {
		return $this->_model->load($module, false, false);
	}

	public function database($database, $return_self = false) {
		return $this->_database->load($database, $return_self);
	}

	public function extension($extension) {
		/* Extensions loading are treated as models, just a different name and a different directory */
		return $this->_model->load($extension);
	}

	public function library($library, $tolower = true, $class_path = NULL) {
		/* Libraries loading are treated as models, with some minor changes (no UW_ prefix required on library main class and optional $tolower parameter) */
		return $this->_model->load($library, true, $tolower, $class_path);
	}
}

class UW_Module extends UW_Model {
	public $view = NULL;
	public $model = NULL;
	public $module = NULL;
	public $extension = NULL;
	public $library = NULL;
	public $load = NULL;

	public function __construct() {
		global $config;

		parent::__construct();
		
		/* Initialize model class */
		$this->model = $this;

		/* Initialize module class */
		$this->module = $this;

		/* Initialize system view controller */
		$this->view = new UW_View;

		/* Initialize system extension class */
		$this->extension = $this; /* Extensions loading are treated as models, just a different name and a different directory */

		/* Initialize library class */
		$this->library = $this; /* Libraries loading are treated as models, just a different name and a different directory */

		/* Initialize load class */
		$this->load = new UW_Load($this->db, $this->model, $this->view, $this->extension, $this->library);

		/* Autoload configured libraries */
		foreach ($config['autoload']['libraries'] as $_lib)
			$this->load->library($_lib);

		/* Autoload configured extensions */
		foreach ($config['autoload']['extensions'] as $_ext)
			$this->load->extension($_ext);

		/* Autoload configured models */
		foreach ($config['autoload']['models'] as $_model)
			$this->load->model($_model);
	}
}

class UW_Controller extends UW_Module {
	public function __construct() {
		global $config;

		parent::__construct();

		/* Autoload configured interfaces */
		foreach ($config['autoload']['modules'] as $_module)
			$this->load->module($_module);
	}
}
