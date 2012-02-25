<?php
/*
 * LSS history:
 * version 0 - mangband.org
 * version 1 - mindloop.net
 * version 2 - whirl sess.php (not a pretty sight)
 * version 3 - this one
 */

/* This exact code block is fairly popular and might collide */
if (!class_exists('SINGLETON')) {
class SINGLETON {
	private static $me;
	private function __construct() { }
	public static function getInstance() {
		if (!isset(self::$me)) {
      		$c = __CLASS__;
      		self::$me = new $c;
    	}
    return self::$me;
	}
} }

/* SessionHandler prototype. */
abstract class SessionHandler {
	protected $sid = '';
	protected $name = '';
	protected $locks = array();

	public function __destruct() { $this->flush(); }

	protected function random_id() { return base64_encode(
		pack('N6', mt_rand(), mt_rand(), mt_rand(),
          mt_rand(), mt_rand(), mt_rand()));
	}

	public function id() {		return $this->sid;	}

	abstract public function clean();
	abstract public function regenerate_id();
	abstract public function start();
	abstract public function lock($key);
	abstract public function unlock($key);
	abstract public function get($key, $lock=TRUE);
	abstract public function set($key, $val);

	public function save($sid) {
		$this->sid = $sid;		
		//header();
		setcookie($this->name, $this->sid);
	}
	public function destroy() {
		if (!$this->sid) return;
_debug_log('Destroying session '.$this->sid);
		$this->stop();
		$this->save('');		
	}
	public function stop() {
		$this->flush();
		$this->sid = '';
	}
	public function flush() {
		foreach ($this->locks as $key => $need) {
			if ($need > 0) $this->unlock($key);
		}
	}
}

class SessionPDOMYSQL extends SessionHandler {
	const SESSION_MARK = 'LAST_UPDATE';
	private $db = NULL;
	public function __construct($db_conf, $name='PHPSESSID') {
		$this->db = new SessionDBS($db_conf);
		$this->name = $name;
		//$this->db->sync('vals');
	} 
	public function clean() {
		$vals = $this->db->SessionValueTable;
		$query = array( 
		'DELETE FROM '.$vals->table_name.' WHERE NOW()-access > ? AND sid NOT IN ('.
		'SELECT sid FROM ('.
		'SELECT sid FROM '.$vals->table_name.' WHERE NOW()-access <= ? and name = ?'.
		') as TemporaryDeletionTable );' => array(60, 60, self::SESSION_MARK));
		$this->db->run($query);
	}

	public function regenerate_id() {
		$sid = $this->random_id();
		_debug_log('Regenerating Session ID');
		$vals = $this->db->SessionValueTable;
		/* Get all fields */
		$qry = array(
		'SELECT name FROM ' . $vals->table_name .
		' WHERE sid = ?' => array($this->sid));
		$res = $this->db->fetch($qry);
		/* Lock all fields */
		foreach ($res as $arr) { foreach ($arr as $tmp=>$nam) {
				if (!$this->lock($nam)) trigger_error('Unable to lock var '. $nam . ' while regenerating id', E_USER_WARNING);
		}	}
		/* Change their SID ! */
		$qry = array('UPDATE ' . $vals->table_name . ' SET sid=? WHERE sid = ?' => array($sid, $this->sid));
		$this->db->run($qry);
		/* Unlock all fields */
		$this->flush();
		/* Cookie */
		$this->save($sid);
	}
	public function start($name='PHPSESSID') {
		$this->name = $name;	
		$found = 0;
		if (isset($_COOKIE[$name])) {
			$vals = $this->db->SessionValueTable;
			$sid = $_COOKIE[$name];
			$query = array(
			'UPDATE ' . $vals->table_name . 
			' SET value=value+1 '.
			'WHERE sid = ? AND name = ?' => array($sid, self::SESSION_MARK));
			#In DreamCode:
			if ($this->db->run($query)->rowCount() == 1) $found = 1;
			if ($found) _debug_log('Continuing session '.$sid);
		}
		if (!$found) {
			$sid = $this->random_id();
			_debug_log('Starting session '.$sid);
			$vals->add_one($sid, self::SESSION_MARK, 0);
		}
		$this->save($sid);		
	}

	public function lock($key) {
		_debug_log('Acquiring exclusive lock  '.$key);
		$qry = array('SELECT GET_LOCK(?, ?)' => array($this->sid.'_'.$key, 10));
		$ret = $this->db->fetch($qry);
		$vals = array_values( $ret[0] );
		$val = current( $vals );
		if (!isset($this->locks[$key])) $this->locks[$key] = 0;
		if ($val == '1') $this->locks[$key]++; 
		return ($val == '1' ? TRUE : FALSE);
	}
	public function unlock($key) {
		_debug_log('Releasing exclusive lock  '.$key);
		$qry = array('SELECT RELEASE_LOCK(?)' => array($this->sid.'_'.$key));
		$ret = $this->db->fetch($qry);
		$vals = array_values( $ret[0] );
		$val = current( $vals );
		if ($val == '1') $this->locks[$key]--;
		return ($val == '1' ? TRUE : FALSE);
	}

	public function get($key, $lock = TRUE) {
		if (!$this->sid) $this->start();
		if ($lock && !$this->lock($key)) throw new Exception('Cant lock variable - timeout or error'); 

		# In Dream Code:
		# $val = current ( $sdb->vals(array('name'=>$key,'sid'=> $this->sid)) );
		$vals = $this->db->SessionValueTable;
		$qry = array(
		'SELECT value FROM ' .	$vals->table_name .
		' WHERE name = ? AND sid = ?' => array($key, $this->sid));
		$arr = $this->db->fetchObject($qry, 'SessionValue');
		$val = ( empty($arr) ? NULL : $arr[0] );
		/* New variable */
		if (!$val) $val = $vals->add_one($this->sid, $key, NULL);
		return $val->value;	
	}
	public function set($key, $val) {
		if (!isset($this->locks[$key]) || !$this->locks[$key]) throw new Exception('Cant SET variable without prior GET='.print_r($this->locks,1));
		_debug_log('Setting '.$key.' = '.$val);		
		$vals = $this->db->SessionValueTable;
		# In Dream Code:
		# $val = current ( $sdb->vals(array('name'=>$key,'sid'=> $sid)) );
		# $val->value = $val; $val->update(); 
		$qry = array(
		'UPDATE ' . $vals->table_name . 
		' SET value=? WHERE name = ? AND sid = ?' => array($val, $key, $this->sid));
		$this->db->run($qry);
		$this->unlock($key);
	}
}

/* Filesystem Session Handler. Mimics DB class above. */
class SessionFS extends SessionHandler {
	private $session_dir;
	private $cache_dir;
	private function keyfile($key) { return $this->session_dir . '/' . $key;	}
	private function clean_dir($dir) {
		if (is_dir($dir)) {
			$files = scandir($dir);
			foreach ($files as $file) {
				if (!is_dir($dir.'/'.$file))
					@unlink($dir.'/'.$file);
			}
			return TRUE;
		}
		return FALSE;
	}
	protected function random_id() { return md5( parent::random_id() ); }
	public function __construct($dir='', $name='PHPSESSID') {
		if (!is_string($dir)) throw new Exception('Argument 1 must be a path string.'); 
		if (!is_string($name)) throw new Exception('Argument 2 must be a session name string.');
		$dir = rtrim($dir, '/'); $made = FALSE;
		if (!$dir || realpath($dir) == realpath(dirname(__FILE__)) || (!file_exists($dir) && !($made=@mkdir($dir))) )
		throw new Exception('Can\'t use directory "'.print_r($dir,1).'" for cache. Make sure it\'s not top-level, exists and is writable to.');//''
		if ($made) chmod($dir, 0777);
		$this->cache_dir = $dir;
		$this->name = $name;		
	}
	public function clean() {
		$ctime = time();
		$dirs = scandir($this->cache_dir);
		foreach ($dirs as $short) {
			$dir = $this->cache_dir.'/'.$short;
			if ($short != '.' && $short != '..' && is_dir($dir)) {
				if ($ctime - filemtime($dir) > 60) {
					$this->clean_dir($dir);
					rmdir($dir);
				}
			}
		}
	}
	public function regenerate_id() {
		$sid = $this->random_id();
		_debug_log('Regenerating Session ID');
		$dir = $this->cache_dir.'/'.$sid;
		/* Move files */
		$files = @scandir($this->session_dir);
		if ($files)
		{  
			/* Unlock all fields */
			$this->flush();
			/* Move */
			rename($this->session_dir, $dir);
		}
		$this->session_dir = $dir;
		/* New cookie */
		$this->save($sid);
	}
	public function start() {
		$found = 0;
		if (isset($_COOKIE[$this->name])) {
			$sid = basename($_COOKIE[$this->name]);
			$dir = $this->cache_dir . '/' . $sid;
			if (file_exists($dir) && is_dir($dir)) {
				_debug_log('Continuing session '.$sid);
				$found = 1;
				touch($dir, time());			
			}
		}
		if (!$found) {
			$sid = $this->random_id();
			_debug_log('Starting session '.$sid);
			$dir = $this->cache_dir . '/' . $sid;
			mkdir($dir);
		}
		$this->session_dir = $dir;
		$this->save($sid);
	}
	public function lock($key) {
		_debug_log('Acquiring exclusive lock "'.$key.'"');
		$file = $this->keyfile($key);
		$mod = file_exists($file) ? 'r+' : 'w+';
		$fh = @fopen($file, $mod);
		if ($fh === FALSE) return FALSE;
		flock($fh, LOCK_EX);
		$this->locks[$key] =& $fh;
		return TRUE;
	}
	public function unlock($key) {
		_debug_log('Releasing exclusive lock "'.$key.'"');
		if (isset($this->locks[$key])) {
			$fh = $this->locks[$key];
			unset($this->locks[$key]);
			fclose($fh);
			return TRUE;
		}
		return FALSE;		
	}
	public function get($key, $lock = TRUE) {
		if (!$this->sid) $this->start();
		if ($lock && !$this->lock($key)) throw new Exception('Cant lock variable - timeout or error'); 
		$file = $this->keyfile($key);
		if (isset($this->locks[$key])) {
			$fh = $this->locks[$key];
		} else {
			$fh = @fopen($file, 'r+');
		}
		if ($fh === FALSE) return FALSE;
		$size = @filesize($file);
		if ($size) {
			$value = fread($fh, $size);
			fseek($fh, 0, SEEK_SET);
			return $value;
		}
		return FALSE;	
	}
	public function set($key, $val) {
		if (!isset($this->locks[$key]) || !$this->locks[$key]) throw new Exception('Cant SET variable without prior GET='.print_r($this->locks,1));
		$file = $this->keyfile($key);
		$fh = $this->locks[$key];
		ftruncate($fh, 0);
		fwrite($fh, $val);		
		if (!$this->unlock($key)) fclose($fh);
	}	
}
/* ############################################## */


/* (Local Session Singleton) */
class LSS extends SINGLETON {
	private static $sdb = NULL;

	private static $roles = NULL;
	private static $rev_roles = NULL;

	private static $heap = array();
	private static $copy = array();
	private static $keep = array(
		'db'=>NULL,		// Default DBO
		'sdb'=>NULL,	// Session db 
		'adb'=>NULL,	// Auth db (LDAP?)
		'site'=>NULL,	// Output doc
		'cache'=>NULL,	// Cacher
	);

	public static function DB()     	{	return self::$keep['db']; }
	public static function HEAP()   	{	return self::$heap; }
	public static function PAGE()   	{	return self::$keep['site']; }
	public static function AUTH()   	{	return self::$keep['adb']; }
	public static function CACHE()   	{	return self::$keep['cache']; }
	public static function ROLES()  	{	return self::$roles; }
	public static function SESSION()	{	return self::$keep['sdb']; }

	public static function cacheWith($cacher) { self::$keep['cache'] = $cacher; }
	public static function authWith($adb) {	self::$keep['adb'] = $adb; }

	public static function sessionDB($sdb, $name='PHPSESSID') {
		if (is_array($sdb)) {
			$sdb = new SessionDB($sdb, $name);
		}
		if (is_string($sdb)) {
			if ($sdb == '') $sdb = ini_get('session.save_path') . '/session';
			$sdb = new SessionFS($sdb, $name);
		}
		if (is_object($sdb)) {
			if (rand(0, 100) <= 1)	$sdb->clean();
			self::$sdb = $sdb;
		}
		self::$keep['sdb'] = $sdb;	
	}

	public static function init($dbo, $site=NULL, $roles=array('guest','user')) {
		if (self::$keep['db'] == NULL) {
		  	if (!$dbo) throw new Exception('Argument 1 to LSS::init() must not evaluate to FALSE.');
		  	if (!is_array($roles)) throw new Exception('Argument 3 must be an array of roles or left out.');		  	
			self::$keep['db'] = $dbo;
			self::$keep['site'] = $site;
			self::$roles = $roles;
			self::$rev_roles = array_flip($roles);
		} else throw new Exception('Calling LSS::init() more then once is not allowed.'); 
	}
	
	public static function peek($key)  { 
		if (!isset(self::$copy[$key])) self::$copy[$key] = self::$sdb->get($key, FALSE);
		return self::$copy[$key];
	}
	public static function get($key) { return (self::$copy[$key] = self::$sdb->get($key, TRUE)); }
	public static function set($key,$val) {
		self::$copy[$key] = $val; 
		return self::$sdb->set($key,$val); 
	}

	public static function session_start() { return self::$sdb->start(); }
	public static function session_clean() { return self::$sdb->clean(); }
	public static function session_regen() { return self::$sdb->regenerate_id(); }

	public static function tset($key, $val)	{ self::$heap[$key] = $val; }
	public static function tget($key)   	{ return self::$heap[$key]; }

	public static function droles($id=FALSE,$add=NULL) {
		$level = (is_numeric($id) ? (int)$id : self::level($id));
		$tmp = array_reverse(array_slice(self::$roles, 0, $level+1));
		if ($add !== NULL) $tmp[] = $add;
		return $tmp;
	}

	/* Return YOUR or ROLE's level */
	public static function level($role=FALSE) {
		if ($role !== FALSE) {
			if (!isset(self::$rev_roles[ $role ]))
				throw new Exception('Undefined role "'. $role.'"');
			return self::$rev_roles[ $role ];
		}
		return self::peek('lss.level'); 
	}
	/* Return YOUR or LEVEL's role */
	public static function role($level=FALSE) {
		if ($level === FALSE) $level = self::peek('lss.level');
		else if (!isset(self::$roles[ $level ]))
			throw new Exception('Level '. $level . ' is out of range.');
		return self::$roles[ $level ]; 
	}
	public static function level_up($level) {
		if ($level > count(self::$roles)) $level = count(self::$roles);
		if ($level <= self::peek('lss.level')) {
			//Breakin attempt?
		} else {
			self::$sdb->regenerate_id();
			self::get('lss.level');
			self::set('lss.level', $level);		
		}
	}
	public static function level_down($level) {
		if ($level < 0) $level = 0;
		if ($level >= self::get('lss.level')) {
			//Breakin attempt?
			self::$sdb->unlock('lss.level');
		} else {
			self::set('lss.level', $level);
			self::$sdb->regenerate_id();
		}
	}
}


?>