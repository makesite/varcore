<?php
error_reporting(E_ALL);
/*
 * As soon as you include this file, your (super)global namespace gets polluted in
 * the following way:
 *  $_SERVER['REQUEST_METHOD'] will contain over-rides from $_POST["_method"] and
 *    $_SERVER["HTTP_X_HTTP_METHOD_OVERRIDE"].
 *  $_POST['_method'] will be unset() if present!
 *  $_SERVER['NODE_URI'] will contain approximate resource identifier.
 *  $_SERVER['SITE_PATH'] will contain approximate controller path.
 *  $_SERVER['SITE_URL'] will contain approximate BASE_URL.
 *  $_POST[] values will be urldecoded if $_SERVER['CONTENT_TYPE'] is
 *    'application/x-www-form-urlencoded'
 */
$__tmp_url = ($_SERVER['REQUEST_URI'] == $_SERVER['SCRIPT_NAME'] ?
	substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/') + 1)
	: $_SERVER['REQUEST_URI']);
$__tmp_url = urldecode($__tmp_url);
$_SERVER['SITE_URL'] =
	(isset($_SERVER['https']) || $_SERVER['SERVER_PORT'] == 443 ? "https://" : "http://") .
	($_SERVER['HTTP_HOST'] ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']).
	($_SERVER['SITE_PATH'] = substr($__tmp_url, 0, strlen($__tmp_url)
		- strlen(urldecode($_SERVER['QUERY_STRING']))
		- (substr($__tmp_url, -1) == '?' ? 1 : 0)
		));
$__tmp_url = substr($_SERVER['REQUEST_URI'], strlen($_SERVER['SITE_PATH']));
$__tmp_url = urldecode($__tmp_url);
$__tmp_off = strrpos($__tmp_url, '?'); if ($__tmp_off === false) $__tmp_off = strpos($__tmp_url, '&');
unset($_GET[(
	$_SERVER['NODE_URI'] = ($__tmp_off === false ? $__tmp_url : substr($__tmp_url, 0, $__tmp_off))
	)], $__tmp_off, $__tmp_url);
if (!isset($argc) || $argc < 2) { $argc = count(($argv = preg_split("#/#", $_SERVER['NODE_URI'], -1, PREG_SPLIT_NO_EMPTY))); $_SERVER['argc'] = 0; }
if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) $_SERVER['REQUEST_METHOD'] = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
if (isset($_POST['_method']) && (in_array(strtoupper($_POST['_method']),array('PUT','DELETE','POST','GET','HEAD','OPTIONS')))) {
	$_SERVER['REQUEST_METHOD'] = strtoupper($_POST['_method']);	unset($_POST['_method']);	}
if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/x-www-form-urlencoded') {
	foreach ($_POST as $key => $val) {
		if (is_string($val))
			$_POST[$key] = urldecode($val);
	}
}

/* Aditionally, _GET() and _POST() functions provide convinient shortcuts for
 * very popular REQUEST use-cases: */
function _GET() { global $argv; return $argv + $_GET; }
function _POST() { return _FORMED() ? $_POST + $_GET : $_POST + array('raw'=>_RAW_POST()); }
function _PAYLOAD() { return (($_SERVER['REQUEST_METHOD'] == 'GET') ? $_GET : _POST()); }

function _REST() { return preg_split('#/#',$_SERVER['NODE_URI']) + _PAYLOAD(); }

function _IP() { /* Get request IP-address */
	return isset($_SERVER['HTTP_X_FORWARDED_FOR']) ?
		$_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
}

/* If you want some quick and dirty CLI functionality, call this function: */
function _CLI() { global $argc, $argv; if (!empty($_SERVER['argc'])) {
	/* First argument must be a HTTP REQUEST METHOD */
	$_SERVER['REQUEST_METHOD'] = strtoupper(array_shift($argv)); $argc--;
	/* Last argument might be a json object */
	if ($argc && substr($argv[$argc-1], -1) == '}') {
		/* Which then overwrites $_POST: */
		unset($_POST); $_POST = json_decode(array_pop($argv), TRUE);
		$argc--;
	}
	$_SERVER['NODE_URI'] = join('/', $argv);
} }
/* If you want quick and dirty HTTP-ACCEPT functionality, call this function: */
function _FORMAT($preferred=array('html'), $formats = array(
	'text/plain' => 'text',
	'text/html' => 'html',
	'text/xml' => 'xml',
	'application/xhtml+xml' => 'xhtml',
	'application/xml' => 'xml',
	'application/json' => 'json',
	'*/*' => 'html'
)) {
	if (!isset($_SERVER['REQUEST_FORMAT'])) {
		$_SERVER['REQUEST_FORMAT'] = array();
		$accept_types = preg_split('/,\s*/', $_SERVER['HTTP_ACCEPT']);
		if (isset($_SERVER['CONTENT_TYPE'])) $accept_types[] = $_SERVER['CONTENT_TYPE'];
		foreach ($accept_types as $type) {
			list($type, $q) = (strpos($type, 'q=') ? 
				preg_split('/;\s*q=/', $type) : array($type, 1));
			if (isset($formats[$type])) $_SERVER['REQUEST_FORMAT'][$formats[$type]] = $q;
		}
	}
	if (is_string($preferred)) {
		return isset($_SERVER['REQUEST_FORMAT'][$preferred]);
	}
	$sorted_types = array();
	foreach ($_SERVER['REQUEST_FORMAT'] as $type => $q)
		if (in_array($type, $preferred))
			$sorted_types[array_search($type, $preferred)] = $type;
	asort($sorted_types);
	return $sorted_types;
}
/* If you want quick and dirty LANGUAGE-ACCEPT, call this function: */
function _LANGUAGE($preferred=array('en')) {
	if (!isset($_SERVER['REQUEST_LANGUAGE'])) {
		$_SERVER['REQUEST_LANGUAGE'] = array();
		$languages = preg_split('/,\s*/', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
		//if (isset($_SERVER['CONTENT_TYPE'])) $accept_types[] = $_SERVER['CONTENT_TYPE'];
		foreach ($languages as $type) {
			list($type, $q) = (strpos($type, 'q=') ?
				preg_split('/;\s*q=/', $type) : array($type, 1));
			//if (isset($known[$type]))
			$_SERVER['REQUEST_LANGUAGE'][$type] = $q;
		}
	}
	$sorted = array();
	foreach ($_SERVER['REQUEST_LANGUAGE'] as $type=>$q)
		if (in_array($type, $preferred))
			$sorted[array_search($type, $preferred)] = $type;
	asort($sorted);
	return $sorted;
}
/* If you want quick and dirty XML-RPC functionality, call this function: */
function _XMLRPC($data=null) {
	if ($_SERVER['REQUEST_METHOD'] != 'POST'
	|| !isset($_SERVER['CONTENT_TYPE'])
	|| !isset($_SERVER['CONTENT_LENGTH'])
	|| ($_SERVER['CONTENT_TYPE'] != 'text/xml'
	&&	$_SERVER['CONTENT_TYPE'] != 'application/xml')
	) return FALSE;
	$method = '';
	return array(
	'server'=>$_SERVER['NODE_URI'],
	'method'=>&$method,
	'params'=>xmlrpc_decode_request((!$data ? _RAW_POST() : $data), $method),
	);
}
if (!function_exists('xmlrpc_decode_request')) {
function xmlrpc_decode_request($input, &$method, $encoding=NULL) {
	$xml = @simplexml_load_string($input);
	if (!$xml) return FALSE;
	$params = array();
	foreach ($xml->params->param as $p)
		$params[ (string)$p->name ] = current(unserialize_sxml($p->value));
	$method = (string) $xml->methodName;
	return $params;
}	}
function unserialize_sxml($data) {
	if ($data instanceof SimpleXMLElement) $data = (array) $data;
	if (is_array($data)) foreach ($data as &$item) $item = unserialize_sxml($item);
	return $data;
}

/* Determine if php's $_POST contains any payload */
function _FORMED() {
	if (!isset($_SERVER['CONTENT_TYPE']) 
	|| $_SERVER['CONTENT_TYPE'] == 'application/x-www-form-urlencoded'
	|| strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data')) {
		return TRUE;
	}
	return FALSE;
}
/* Return raw payload */
function _RAW_POST() {
	global $HTTP_RAW_POST_DATA;
	if (!isset($HTTP_RAW_POST_DATA))
		$HTTP_RAW_POST_DATA = file_get_contents('php://input');
	return $HTTP_RAW_POST_DATA;
}

/* Collect some information about the request and return it */
function _REQUEST() { $fmt = _FORMAT(); return array(
	'scheme'=>(isset($_SERVER['https']) ? 'https' : 'http'),
	'method'=>$_SERVER['REQUEST_METHOD'],
	'format'=> array_pop($fmt),
	'node'=>$_SERVER['NODE_URI'],
	'payload'=>_PAYLOAD(),
); }

/* Collect some information about uploaded files */
function _FILES() {
	$files = array();
	foreach ($_FILES as $one => $arr) {
		$n = count($arr['error']);
		$mult = array();
		for ($i = 0; $i < $n; $i++) {
			$FILE = array(
				'name' => $arr['name'][$i],
				'type' => $arr['type'][$i],
				'tmp_name' => $arr['tmp_name'][$i],
				'error' => $arr['error'][$i],
				'size' => $arr['size'][$i],
			);
			$mult[] = $FILE;
		}
		$files[$one] = $mult;
	}
	return $files;
}

/* Return reversed dispatch */
function _AGAIN($data2=null) {
	$data1 = preg_split('#/#', get_return_to());
	if ($data2) $data1 += $data2; 
	return $data1;
}

function get_return_to($default = null) {
	if (!$default && isset($_SERVER['HTTP_REFERER'])) $default = $_SERVER['HTTP_REFERER'];
	$site_url = $_SERVER['SITE_URL'];
	$r = (isset($_REQUEST['return_to']) ? $_REQUEST['return_to'] : $default);
	if (substr($r, 0, strlen($site_url)) == $site_url) {
		$r = substr($r, strlen($site_url));
	}
	return $r;
}
function safe_name($name) {
	$name = preg_replace('/[^a-zA-Z0-9]/', '', $name);
	if (!$name) $name = "index";
	return $name;
}

/* CDISPATCH-2 */
function safe_name2(&$args) {
	$next = array_shift($args);
	if (preg_match('/^[0-9]/', $next)) {
		array_unshift($args, $next);
		return 'index';
	}
	return safe_name($next);
}
function cdispatch($args, $fn='', $fna=null, $cycle=array(FALSE), $dir='', $fnx='_', $pl = false) {
	if (!is_array($args)) $args = preg_split('#/#',$args);
	if ($fna === null) $fna = $fn . '_';
	if (!is_array($cycle)) $cycle = array(FALSE);
	$next = safe_name(array_shift($args));
	if ($dir) {
		$filename = rtrim($dir,'/') . '/'. $next . ".php";
		_debug_log('Searching for file "'.$filename.'"');
		if (file_exists($filename)) {
			if ($fnx !== FALSE) {
				$fn = $next.$fnx.$fn; $fna = $next.$fnx.$fna;
				$next = safe_name(array_shift($args));
			}
			_debug_log('Including file "'.$filename. '"');
			include_once($filename);
		}
	}
	$class = ucfirst($next) . 'Controller';
	_debug_log('Checking for class "'.$class.'"');
	if (!class_exists($class)) {
		return false;
	}
	$obj = new $class;
	$next = safe_name2($args);
	foreach ($cycle as $fnc) {
		if ($fnc !== FALSE) $fnc = $fnc.'_';
		$fn_name = $fn.$fnc.$next; $fna_name = $fna.$fnc.$next;
		$fn_call = array($obj, $fn_name);
		$fna_call = array($obj, $fna_name);
		_debug_log('Looking for methods '.$fn_name. '(), '.$fna_name.'():');
		if ($fn && is_callable($fn_call))
		{
			_debug_log(' Calling method "'.$fn_name. '($,$,$)"');
			if ($pl) $args = array($args, $pl);
			return call_user_func_array($fn_call, $args);
		}
		else if ($fna !== false && is_callable($fna_call))
		{
			_debug_log(' Calling method "'.$fna_name. '(@)"');
			if ($pl) return call_user_func_array($fna_call, array($args, $pl));
			return call_user_func($fna_call, $args);
		}
		_debug_log(' Not found.');
	}
	return false;// $args;
}

/* DISPATCH */
function dispatch($args, $fn='', $fna=null, $cycle=array(FALSE), $dir='', $fnx='_', $pl = false) {
	if (!is_array($args)) $args = preg_split('#/#',$args);
	if ($fna === null) $fna = $fn . '_';
	if (!is_array($cycle)) $cycle = array(FALSE);
	$next = safe_name(array_shift($args));
	if ($dir) {
		$filename = rtrim($dir,'/') . '/'. $next . ".php";
		_debug_log('Searching for file "'.$filename.'"');
		if (file_exists($filename)) {
			if ($fnx !== FALSE) {
				$fn = $next.$fnx.$fn; $fna = $next.$fnx.$fna; 
				$next = safe_name(array_shift($args));
			}
			_debug_log('Including file "'.$filename. '"');
			include_once($filename);
		}
	}
	foreach ($cycle as $fnc) {
		if ($fnc !== FALSE) $fnc = $fnc.'_';
		$fn_name = $fn.$fnc.$next; $fna_name = $fna.$fnc.$next;
		_debug_log('Looking for functions '.$fn_name. '(), '.$fna_name.'():');
		if ($fn && function_exists($fn_name))
		{
			_debug_log(' Calling function "'.$fn_name. '($,$,$)"');
			if ($pl) $args = array($args, $pl);
			return call_user_func_array($fn_name, $args);
		}
		else if ($fna && function_exists($fna_name))
		{
			_debug_log(' Calling function "'.$fna_name. '(@)"');
			if ($pl) return call_user_func_array($fna_name, array($args, $pl));
			return call_user_func($fna_name, $args);
		}
		_debug_log(' Not found.');
	}
	return false;// $args;
}

?>
