<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 11/03/2016
 */

class Test extends UW_Controller {
	private function test_model() {
		$this->model->load('dummy');
		return $this->dummy->test();
	}
	
	private function test_database($value) {
		$this->db->trans_begin();

		$q = $this->db->select('id')->from('dummy')->where_in('id', array(1, 2, 3))->get();
		//$this->db->select('id');
		//$this->db->from('dummy');
		//$this->db->where('id >=', $value);
		//$this->db->where_in('id', array(1, 2, 3));
		//echo($this->db->get_compiled_select()[0]);
		//$q = $this->db->get();
		//echo ($q->row()->num_rows);
		//$this->db->query('SELECT `id` FROM `dummy` WHERE `id` >= ?', array($value));

		if (!$this->db->trans_commit()) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 503');
			die('Failed.');
		}

		$output = '';

		if ($q->num_rows()) {
			foreach ($q->fetchall() as $row)
				$output .= $row['id'] . ', ';
		} else {
			return 'No results.';
		}
		
		$this->db->close();

		return $output;
	}

	private function test_session() {
		$this->session->set('test', 'Custom Session Data');
		return $this->session->get('test');
	}

	public function index() {
		$data['model_output'] = $this->test_model();
		$data['database_output'] = $this->test_database(1);
		$data['session_output'] = $this->test_session();
		
		$this->view->load('test', $data);
	}
}
