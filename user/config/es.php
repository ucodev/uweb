<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

$es['enabled'] = false;
$es['base_url'] = 'http://localhost:9200';
$es['timeout']['connect'] = 10000;  /* in ms */
$es['timeout']['execute'] = 30000;  /* in ms */
