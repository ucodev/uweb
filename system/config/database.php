<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* MySQL */
$database['uweb']['driver']   = 'mysql';
$database['uweb']['host']     = '127.0.0.1';
$database['uweb']['port']     = '3306';
$database['uweb']['username'] = 'uweb_username';
$database['uweb']['password'] = 'uweb_password';

/* PgSQL
$database['another_database']['driver']   = 'pgsql';
$database['another_database']['host']     = '127.0.0.1';
$database['another_database']['port']     = '3306';
$database['another_database']['username'] = 'another_username';
$database['another_database']['password'] = 'another_password';
*/
