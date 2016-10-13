<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 14/10/2016
 */

class Dummy_rest extends UW_Controller {
	/** Entry Point **/

	public function index($argv = NULL) {
		/* Process RESTful calls for this controller */
		$this->restful->process($this, $argv);
	}


	/** Begin of default RESTful handlers **/

	public function view($argv) {
		/* GET */
		$this->restful->output('200', 'Viewing item ' . $argv[0]);
	}

	public function listing() {
		/* GET */
		$this->restful->output('200', 'Listing collection');
	}

	public function insert($argv = NULL) {
		/* POST */

		if ($argv !== NULL) {
			$this->restful->error('Cannot insert with the specified ID.');
			$this->restful->output('403');
		}

		$this->restful->output('201', 'Inserted a new item');
	}

	public function modify($argv = NULL) {
		/* PATCH */

		if ($argv === NULL) {
			$this->restful->error('Cannot modify the entire collection.');
			$this->restful->output('403');
		}

		$this->restful->output('200', 'Modifying item ' . $argv[0]);
	}

	public function update($argv = NULL) {
		/* PUT */

		if ($argv === NULL) {
			$this->restful->error('Cannot update the entire collection.');
			$this->restful->output('403');
		}

		$this->restful->output('200', 'Updating item ' . $argv[0]);
	}

	public function delete($argv = NULL) {
		/* DELETE */

		if ($argv === NULL) {
			$this->restful->error('Cannot delete the entire collection.');
			$this->restful->output('403');
		}

		$this->restful->output('200', 'Deleting item ' . $argv[0]);
	}


	/** Begin of custom RESTful handlers **/

	public function search($argv = NULL) {
		/* Pre-validation of the RESTful request */
		$this->restful->validate();

		if ($this->restful->method() != 'POST') {
			$this->restful->error('Only POST method in allowed to be used for searches.');
			$this->restful->output('405');
		}

		$this->restful->output('200', 'Searching... ');
	}
}
