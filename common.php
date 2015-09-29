<?php

function load_config($item = null, $name = 'config.php') {
	static $config = null;
	if ($config == null) {
		include $name;
		$config = get_defined_vars();
		unset($config['config']);
	}
	if ($item != null) {
		if (isset($config[$item])) return $config[$item];
		return false;
	}
	return $config;
}

class WAuth {
	private static $me = null;
	private static $class_name = null;
	private static $enabled = false;

	static function enable($class_name) {
		self::$class_name = $class_name;
		self::$enabled = true;
		$class_name::session_start();
	}
	static function disable() {
		$class_name = self::$class_name; 
		self::$enabled = false;
		$class_name::session_stop();
	}
	static function login($username, $password) {
		$class = self::$class_name;
		return $class::login($username, $password);
	}
	static function logout() {
		$class = self::class_name;
		return $class::logout();
	}
	static function loggedIN() {
		$class = self::$class_name;
		return $class::loggedIN();
	}
	static function hasPower($power) {
		$class = self::$class_name;
		return $class::hasPower($power);
	}
	static function forcelogin() {
		$class = self::$class_name;
		return $class::forcelogin();
	}
	static function forcepower($power) {
		$class = self::$class_name;
		return $class::forcepower($power);
	}
}

class Language {
	static $languages = array();
	static $default = null;
	static public function Add($name, $code, $suffix=null) {
		if ($suffix === null) $suffix = $code;
		self::$languages[] = array(
			'name' => $name,
			'code' => $code,
			'suffix' => $suffix,
		);
	}
	static public function loadFromConfig() {
		$languages = load_config('languages');
		$default = load_config('default_language');
		if (!$languages) throw new Exception('$languages not defined in config.');
		if (!$default) throw new Exception('$default_language not defined in config.');
		foreach ($languages as $name => $suffix) {
			$code = $suffix;
			if (!$code) $code = $default;
			//if ($suffix) $suffix = '_'.$suffix;

			Language::Add($name, $code, $suffix);
		}
		Language::SetDefault($default);

	}
	static public function Supported() {
		return self::$languages;
	}
	static public function SetDefault($code) {
		$codes = self::Grab('code', null);
		if (!in_array($code, $codes)) throw new Exception("Language with code '$code' is not defined.");
		self::$default = $code;
	}
	static public function GetDefault() {
		if (self::$default === null) throw new Exception("Default language not set.");
		return self::$default;
	}
	static private function Grab($what, $by = null, $sep = '') {
		if (!in_array($by, array(null, 'code', 'name', 'suffix')))
			throw new Exception("Argument must be one of NULL|'code'|'name'|'suffix'");
		$ret = array();
		foreach (self::$languages as $l) {
			$val = $l[$what];
			if ($val) $val = $sep . $val;
			if ($by)
				$ret[$l[$by]] = $val;
			else
				$ret[] = $val;
		}
		return $ret;
	}
	static public function Names($by='code') {
		return self::Grab('name', $by);
	}
	static public function Codes($by='name') {
		return self::Grab('code', $by);
	}
	static public function Suffixes($by='code', $sep='_') {
		return self::Grab('suffix', $by);
	}
}

function _L() { return call_user_func_array('L', func_get_args()); }
function L($name, $lang = null) {
	if (!defined('LANGUAGE_DIR')) {
		define('LANGUAGE_DIR', constant('APP_DIR') . '/language');
		_debug_log("LANGUAGE_DIR constant not defined, using `".constant("LANGUAGE_DIR")."`");
	}
	static $use = null;
	static $loaded = 0;
	global $_L;
	if (!$use) {
		if (defined('LANG'))
			$use = $lang = constant('LANG');
		if ($lang === null) {
			$langs = _LANGUAGE();
			$use = $langs[0];
		}
	}
	if (!$loaded) {
		include constant('LANGUAGE_DIR') . '/lang.'.$use.'.php';
		$loaded = 1;
	}
	if (!isset($_L[$name])) {
		_debug_log("String `$name` not defined in lang `$use`.");
	}
	if (func_num_args() > 2) {
		$append = func_get_args();
		array_shift($append);
		array_shift($append);
		if (isset($_L[$name])) return vsprintf($_L[$name], $append);
	}
	if (isset($_L[$name])) return $_L[$name];
	return $name;
}


if (!function_exists('_debug_log')) {
$_debug_logs = array();
function _debug_log($str) {
	global $_debug_logs;
	$_debug_logs[] = $str;
	if (defined('HEAVY_DEBUG')) er($str);
} }

function er() {
	if (!defined('DEBUG')) return '';
	$args = func_get_args();
	$ret = '<pre>';
	foreach ($args as $arg) $ret .= print_r($arg, 1)."\n";
	$ret .= '</pre>';
	if ($args[sizeof($args)-1] === 1) return $ret;
	echo $ret;
}

function mini_form($action, $submit_label, $hiddens = array(), $classes = array()) {
	$ret = '';
	$ret .= '<form action="'.$action.'" method="POST"'.(isset($classes['form']) ? ' class="'.$classes['form'].'"' : '').'>'.PHP_EOL;
	foreach ($hiddens as $name=>$value)
	$ret .= "\t".'<input type="hidden" name="'.$name.'" value="'.$value.'" />'.PHP_EOL;
	$ret .= "\t".'<button type="submit"'.(isset($classes['button']) ? ' class="'.$classes['button'].'"' : '').'>'.$submit_label.'</button>'.PHP_EOL;
	$ret .= '</form>';
	return $ret;
}

function go_to($url) {
	if (defined('DEBUG')) {
		global $_debug_logs;
		_debug_log("DB:". print_r(ORM::getDB()->report(), 1));
		$to = $url;
		if (!$to) $to = 'index';
		echo "<h1>Redirecting</h1>";
		echo "goto <a href='".$_SERVER['SITE_URL'].$url."'>".$to."</a>";
		echo "<hr>";
		echo "<pre>";
		print_r($_debug_logs);
		echo "</pre>";
		echo "<hr>";
		echo "goto <a href='".$_SERVER['SITE_URL'].$url."'>".$to."</a>";
		exit;
	}
	header("Location: ".$_SERVER['SITE_URL'].$url);
	exit;
}

function draw_pages($url, $page, $quantity, $max, $style = 0) {
	$styles = array( 
		array('first', 'prev', 'next', 'last'),
		array('&laquo;', '&lt;', '&gt;', '&raquo;'),
	);
	$quantity = min($max, $quantity);
	if ($quantity == 0) return array();
	$pages = ceil($max / $quantity);
	if ($page < 1 || $page > $pages) return array();
	$ret = array();
	if ($page > 1)
	$ret[] = array(
		'href' => $url.'1',
		'text' => $styles[$style][0],//'first',
		'class' => (1 == $page ? 'active' : ''), 
	);
	if ($page > 1)
	$ret[] = array(
		'href' => $url.($page-1),
		'text' => $styles[$style][1],//'prev',
		'class' => '', 
	);
	for ($i = 1; $i < $pages + 1; $i++) {
		$ret[] = array(
			'href' => $url.$i,
			'text' => $i,
			'class' => ($i == $page ? 'active num' : 'num').
				($i == 1 ? ' first':($i == $pages ? ' last' : '')),
		);
	}
	if ($page < $pages)
	$ret[] = array(
		'href' => $url.($page+1),
		'text' => $styles[$style][2],//'next',,
		'class' => '', 
	);
	if ($page < $pages)
	$ret[] = array(
		'href' => $url.($pages),
		'text' => $styles[$style][3],//'last',
		'class' => ($pages == $page  ? 'active' : ''), 
	);
	return $ret;
}

function draw_exception($ex, $full = true) {

	if (is_string($ex)) return $ex;

	$html = '';
	
	$html .= '<em>'. $ex->getMessage() . '</em>';
	if (!$full) return $html;
	$html .= '<p>## '. $ex->getFile() . '('.$ex->getLine() .')</p>';
	$html .= '<pre>'. $ex->getTraceAsString() . '</pre>';

	return $html;

}

function _bytes($val) {
    $val = trim($val);
    switch(strtolower($val[strlen($val)-1])) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}
function max_file_size() {
    $a = _bytes(ini_get('upload_max_filesize'));
    $b = _bytes(ini_get('post_max_size'));
    $c = _bytes(ini_get('memory_limit'));
    $x = $a;
    $x = min($x, $b);
    $x = min($x, $c);
    return $x;
}

function http_digest_parse($txt) {
    $needed_parts = array('nonce'=>1, 'nc'=>1, 'cnonce'=>1, 'qop'=>1, 'username'=>1, 'uri'=>1, 'response'=>1);
    $data = array();
    $keys = implode('|', array_keys($needed_parts));
    preg_match_all('@(' . $keys . ')=(?:([\'"])([^\2]+?)\2|([^\s,]+))@', $txt, $matches, PREG_SET_ORDER); // " 
    foreach ($matches as $m) {
        $data[$m[1]] = $m[3] ? $m[3] : $m[4];
        unset($needed_parts[$m[1]]);
    }
    return $needed_parts ? false : $data;
}

if (!function_exists('http_response_code')) {
function http_response_code($code) {
	$http_codes = array(
		100 => 'Continue',
		101 => 'Switching Protocols',
		200 => 'OK',
		201 => 'Created',
		202 => 'Accepted',
		203 => 'Non-Authoritative Information',
		204 => 'No Content',
		205 => 'Reset Content',
		206 => 'Partial Content',
		300 => 'Multiple Choices',
		301 => 'Moved Permanently',
		302 => 'Moved Temporarily',
		303 => 'See Other',
		304 => 'Not Modified',
		305 => 'Use Proxy',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Time-out',
		409 => 'Conflict',
		410 => 'Gone',
		411 => 'Length Required',
		412 => 'Precondition Failed',
		413 => 'Request Entity Too Large',
		414 => 'Request-URI Too Large',
		415 => 'Unsupported Media Type',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Time-out',
		505 => 'HTTP Version not supported',
	);
	if (!isset($http_codes[$code])) return false;
	$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
	header($protocol . ' ' . $http_codes[$code] . ' ' . $text);
	return $code;
}
}

?>