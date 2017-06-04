<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Cache settings */
$cache['default']['driver'] = 'memcached';
$cache['default']['host'] = '127.0.0.1';
$cache['default']['port'] = '11211';
$cache['default']['key_prefix'] = 'uweb_';
$cache['default']['active'] = false;

