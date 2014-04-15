<?php
ini_set('display_errors', 1);
error_reporting(E_ALL | E_NOTICE | E_STRICT);
define ('UTF_TICK_OK', '✔');
define ('UTF_TICK_NO', 'x');

if (isset($_SERVER['APP_DIR']))
    define ('APP_DIR', $_SERVER['APP_DIR']);
else
    define ('APP_DIR', __DIR__);

$strings = array(

	'ext' => array(
		'PDO' => 'PDO',
		'pdo_mysql', 'pdy_mysql',
		'dom' => 'dom',
	),

);

$tests = array(
	array(
		'trigger' => 'db.php',
		'reqs' => array('db.conf.php'),
		'ext' => array('PDO', 'pdo_mysql'),
		'method' => 'test_db',
	),
	array(
		'trigger' => 'db.orm.php',
		'reqs' => array('db.php', 'qry5.php'),
		'method' => 'test_orm',
    ),
	array(
		'trigger' => 'libtempl.php',
		'ext' => array('dom'),
    ),
);

function _debug_log($str) {

}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    do_POST();
    $own_url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: $own_url");
    exit;
}

if (isset($_GET['page'])) html_page($_GET['page']); else

html_page('tests');

function do_POST() {
    switch ($_POST['action']) {
        case 'sync':
            include_once 'db.orm.php';
            ORM::loadModels('models');
            ORM::Sync($_POST['class']);
        break;
        case 'wipe':
            include_once 'db.orm.php';
            ORM::loadModels('models');
            ORM::FixClear($_POST['class']);
        break;
        case 'drop':
            include_once 'db.orm.php';
            ORM::loadModels('models');
            ORM::Destroy($_POST['class']);
        break;
        case 'dbutfize':
			global $db_conf;
			require_once('db.php');
			echo "<pre>";
			    //try {
				$db = new db_PDO($db_conf);
        			$sql = array(
        				"ALTER DATABASE ".$db_conf['base']." DEFAULT charset = utf8;" => array(),
        				"ALTER DATABASE ".$db_conf['base']." charset = utf8;" => array(),
        				"ALTER DATABASE ".$db_conf['base']." DEFAULT collate = utf8_general_ci;" => array(),
        				"ALTER DATABASE ".$db_conf['base']." collate = utf8_general_ci;" => array(),
        			);
        			$db->run($sql);
					/*$db->set("ALTER DATABASE ".$db_conf['base']." DEFAULT charset = utf8;");
					$db->set("ALTER DATABASE ".$db_conf['base']." charset = utf8;");
					$db->set("ALTER DATABASE ".$db_conf['base']." DEFAULT collate = utf8;");
					$db->set("ALTER DATABASE ".$db_conf['base']." collate = utf8;");*/
				//} catch (Exception $e) {
				//}
        break;
        case 'writedbconf':
            unset($_POST['action']);
            if (isset($_POST['utf']) && $_POST['utf'] == 'on') $_POST['utf'] = true;
            else $_POST['utf'] = false;
            if (isset($_POST['persist']) && $_POST['persist'] == 'on') $_POST['presist'] = true;
            else $_POST['persist'] = false;
            file_put_contents('db.conf.php',
                    '<?php'.PHP_EOL.
                    '$db_conf = ' . var_export($_POST,true) . ';'.PHP_EOL.
                    '?>'.PHP_EOL
                );
           global $db_conf;
           $db_conf = $_POST;
        break;
    }
}

class HTable {

    private $r;

    public function __construct() {
        $this->r = ''; 
        $this->r .= '<table>';
    }

    public function close() {
        $this->r .= '</table>';
    }
    public function add_header($name) {
        $this->r .= '<tr>';
            $this->r .= '<th colspan=4>';
            $this->r .= $name;
            $this->r .= '</th>';
        $this->r .= '</tr>';
    }
    public function add_row($type, $name, $check, $fix='') {
        $this->r .= '<tr class="'.($check ? 'pass' : 'fail').'">';
            $this->r .= '<td>';
            $this->r .= $type;
            $this->r .= '</td>';
            $this->r .= '<td>';
            $this->r .= $name;
            $this->r .= '</td>';
            $this->r .= '<td>';
            $this->r .= ($check ? UTF_TICK_OK : UTF_TICK_NO);
            $this->r .= '</td>';
            $this->r .= '<td>';
            $this->r .= $fix;
            $this->r .= '</td>';
        $this->r .= '</tr>';
    }
    public function render() {
        return $this->r;
    }
}

function page_info() {

	echo phpinfo();

}

function page_tests() {
    global $tests;

    $table = new HTable();

	$passed = array();

	foreach ($tests as $test) {
		if (file_exists($test['trigger'])) {

			$table->add_header($test['trigger']);

            if (isset($test['reqs']))
                $need_files = sizeof($test['reqs']);
            else
                $need_files = 0;
            if ($need_files) 
            foreach ($test['reqs'] as $req) {

                $ok = file_exists($req);
                $comment = '';

                if (isset($passed[$req]) && $passed[$req] === false) {
                	$ok = false;
$comment = 'failed own tests';
                }

			if ($req == 'db.conf.php') { //uberhack
			$comment = fix_dbconf();
			$comment .=	fix_utf();
		}


                $table->add_row('req', $req, $ok, $comment);

                if ($ok) $need_files--;

            }
            if (isset($test['ext']))
                $oks = test_extensions($test['ext']);
            else $oks = array();
            $need_ext = sizeof($oks);
            
            if ($need_ext)
            foreach ($oks as $name=>$ok) {

                $table->add_row('ext', $name, $ok);

                if ($ok) $need_ext--;

            }
            $master = false;
            if (!$need_ext && !$need_files) {
                if (isset($test['method'])) {
                    $master = call_user_func($test['method'], $table);
                } else {
                    $master = true;
                }
            } else if (isset($test['method'])) {
		$table->add_row('---', '('.$test['method'].')', 0, 'skipping');
            }
            $passed[$test['trigger']] = $master;
            //if (!$master) break;

        } else {
          /*  echo getcwd();
            echo "No such file", $test['trigger'];
            echo "<BR>";*/
        }

    }

    $table->close();

    echo $table->render();
}

function test_db($table = null) {
    include_once 'db.php';
    $ok = false;
    $fail = '';
    global $db_conf;
    try {
        $db = new db_PDO($db_conf);
        $db->get('SHOW TABLES');
        $ok = true;
    } catch(Exception $e) {
        $fail = $e->getMessage();
    }
    if ($table) {
        $table->add_row('db', 'Connection to the database.', $ok, $fail);

	if ($db_conf['utf']) {
		$var1 = $db->get('SHOW VARIABLES LIKE "character_set_database";');
		$var2 = $db->get('SHOW VARIABLES LIKE "collation_database";');
		$charset  = $var1[0]['Value'];
		$collation  = $var2[0]['Value'];
			if (substr($charset, 0, 3) != 'utf' || substr($collation, 0, 3) != 'utf') {
				$ok = false;
				$fail = "charset is ".$charset . ", collation is ". $collation;
			}
			$table->add_row('utf', 'Database charset and collation are utf-8.', $ok, $fail);
	}
    }
    return $ok;
}

function fix_dbconf() {
    $conf = array();
    try {
        require_once('db.php');
        if (!isset($db_conf)) global $db_conf;
        $conf = $db_conf;
    } catch(Exception $e) {
    }
    if (!isset($conf['utf'])) $conf['utf'] = false;
    if (!isset($conf['type']) || !$conf['type']) $conf['type'] = 'mysql';
    if (!isset($conf['host']) || !$conf['host']) $conf['host'] = 'localhost';
    $utf_check = $conf["utf"]? 'checked':'';
    $can_write = is_writable('db.conf.php') ? "Write" : "File unwritable' disabled='disabled";
    foreach ( array('login','pass','base','prefix') as $one) 
    if (!isset($conf[$one])) $conf[$one]='';
return <<<HTML
    <form action='install.php' method='POST'>
    <input type='hidden' name='action' value='writedbconf'>
    <input type='text' name='file' value='db.conf.php' disabled='true' placeholder='file'>
    <input type='text' name='type' value='{$conf["type"]}' placeholder='type'>
    <input type='text' name='host' value='{$conf["host"]}' placeholder='host'>
    <input type='text' name='login' value='{$conf["login"]}' placeholder='user'>
    <input type='text' name='pass' value='{$conf["pass"]}' placeholder='pass'>
    <input type='text' name='base' value='{$conf["base"]}' placeholder='base'>
    <input type='text' name='prefix' value='{$conf["prefix"]}' placeholder='prefix'>
    <input type='checkbox' name='utf' {$utf_check} placeholder='prefix'>utf

    <input type='submit' value='{$can_write}'>
    </form>
HTML;
}
function fix_utf() {
    $conf = array();
    try {
        require_once('db.php');
        if (!isset($db_conf)) global $db_conf;
        $conf = $db_conf;
    } catch(Exception $e) {
    }
    if (!isset($conf['utf'])) $conf['utf'] = false;
	//if (!$conf['utf']) return;
return <<<HTML
    <form action='install.php' method='POST'>
    <input type='hidden' name='action' value='dbutfize'>
    <input type='submit' value='utf-8-ize'>
    </form>
HTML;
}
function fix_orm($class, $ok = false) {
$x = '';
if (!$ok)
$x .= <<<HTML
    <form action='install.php' method='POST'>
    <input type='hidden' name='action' value='sync'>
    <input type='hidden' name='class' value='$class'>
    <input type='submit' value='Sync'>
    </form>
HTML;
$x .= <<<HTML
    <form action='install.php' method='POST'>
    <input type='hidden' name='action' value='wipe'>
    <input type='hidden' name='class' value='$class'>
    <input type='submit' value='Wipe'>
    </form>
HTML;
$x .= <<<HTML
    <form action='install.php' method='POST'>
    <input type='hidden' name='action' value='drop'>
    <input type='hidden' name='class' value='$class'>
    <input type='submit' value='Drop'>
    </form>
HTML;
return $x;
}

function test_orm($table) {
    if (!test_db()) return false; 
    include_once 'db.orm.php';
    if (file_exists('models') && is_dir('models')) {
        $models = ORM::loadModels( getcwd().'/models', '.*', '*.php');
    }
	$all = get_declared_classes();
	foreach ($all as $pclass)
		if (is_subclass_of($pclass, 'ORM_Model'))
			$class_list[] = $pclass;

    foreach ($class_list as $class) {

        $ok = false;
        try {    
            $sql = ORM::Sync($class, true);
            $r = $sql[$class];
            $ok = !sizeof($r);
        }
        catch (Exception $e) {
            $ok = false;
        }
        $table->add_row('orm', $class, $ok, fix_orm($class, $ok) );

    }

    return true;
}

function page_basic() {
    global $strings;
}

function test_extensions($test) {
    $ext = get_loaded_extensions();
    $ret = array();
    foreach ($test as $one) {
        $ret[$one] = false;
        if (in_array($one, $ext)) {
            $ret[$one] = true;
        }
    }
    return $ret;
}

function html_css() {
return <<<CSS

body {
    font-family: Sans-Serif;
}

table {
    width: 100%;
    border-spacing: 0;
}

table td:nth-child(3) {
    font-size: x-large;
}

table th {
    background: #696;
    color: #fff;
    text-align: left;
    padding-left: 1em;
}
table tr:nth-child(2n) {
    background: #ccc;
}
table tr:nth-child(2n+1) {
    background: #ccc;
}
table td:last-child {
    width: 25%;
}
table form {
    display: inline;
}

table tr.pass {
    color: green;
}
table tr.fail {
    color: red;
}

CSS;
}


function html_head() {
echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
HTML;
echo "<style>".html_css()."</style>";
echo <<<HTML
</head>
<body>
HTML;
}

function html_tail() {
echo <<<HTML
</body>
</html>
HTML;
}

function html_page($page) {
html_head();
    switch ($page) {
        case 'info':  page_info(); break;
        case 'basic': page_basic(); break;
        case 'tests': page_tests(); break;
        default: break;
    }
html_tail();
}


?>