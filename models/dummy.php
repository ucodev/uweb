<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Author: Pedro A. Hortas
 * Email: pah@ucodev.org
 * Date: 24/09/2014
 */

class UW_Dummy extends UW_Model {
	public function test() {
		echo('Testing Dummy model.<br />');
	}
}
