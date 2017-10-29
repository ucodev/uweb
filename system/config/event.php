<?php if (!defined('FROM_BASE')) { header($_SERVER['SERVER_PROTOCOL'] . ' 403'); die('Invalid requested path.'); }

/* Redis */
$event['default']['driver']   = 'redis';
$event['default']['host']     = '127.0.0.1';
$event['default']['port']     = 6379;
$event['default']['name']     = 'uweb';
$event['default']['password'] = 'password';
$event['default']['persistent'] = true;
