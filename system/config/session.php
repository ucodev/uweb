<?php if (!defined('FROM_BASE')) { header('HTTP/1.1 403 Forbidden'); die('Invalid requested path.'); }

/* Session settings */
$session['enable'] = TRUE;
$session['name'] = 'uweb';
$session['encrypt'] = TRUE;
$session['cookie_lifetime'] = 7200;
$session['cookie_path'] = '/uweb/';
$session['cookie_domain'] = 'localhost';
$session['cookie_secure'] = FALSE;
$session['cookie_httponly'] = FALSE;
