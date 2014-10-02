<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 24/09/2014
 */

class UW_Hello extends UW_Controller {
	public function index() {
		$this->model->load('dummy');
		$this->dummy->test();

		$test = $this->session->get('test');
		echo($test);
		$this->session->set('test', 'Custom Session Data');

		$data['value'] = 'Index page';

		$this->view->load('hello', $data);
	}

	public function world($value = '') {
		$this->db->trans_begin();

		$this->db->query('SELECT id AS value FROM dummy WHERE id >= ?', array($value));

		if (!$this->db->trans_commit()) {
			header('HTTP/1.1 503 Service Unavailable');
			die('Failed.');
		}

		$data['value'] = '';

		if ($this->db->num_rows()) {
			foreach ($this->db->fetchall() as $row)
				$data['value'] .= $row['value'] . ', ';
		} else {
			$data['value'] = 'No results.';
		}

		$this->view->load('hello', $data);
	}
}

