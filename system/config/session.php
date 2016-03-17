<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Session settings */
$session['enable'] = true;
$session['name'] = 'uweb';
$session['encrypt'] = true;
$session['cookie_lifetime'] = 7200;
$session['cookie_path'] = '/uweb/';
$session['cookie_domain'] = 'localhost';
$session['cookie_secure'] = false;
$session['cookie_httponly'] = true;
