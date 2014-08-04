<?php

function formalize($object, $action, $method, $errors, $extra_html = array()) {

	if ($action === null) $action = 'edit'.strtolower(get_class($object));

	$fields = formObject($object, $errors);

	return formHtml($fields, $action, $method, $extra_html);

}

function fillObject($object, $fill, &$errors = NULL) {

	if ($errors == null) $errors = array();

	$fields = formObject($object);
//er("Fields are",$fields);
	$class = get_class($object);

	foreach ($fields as $field) {

		$property = $field['name'];
		$input = $field['type'];
		
		$value = @$fill[$property];

		$filter = $property.'_filter';
		$test = $property.'_test';

		if (strpos($input, 'password') !== FALSE && !$value) {
			_debug_log(" Ignoring empty field - $property");
			continue;
		}
//er("Doing field", $field);
		if (isset($field['filter'])) {
			$func = $field['filter'];
			//er("Running filter ", $func, "on value", $value);
			if (is_callable($func)) {
				$value = $func($value, $property, $object);
			} else if (is_callable($class, $func)) {
				$value = $object->$func($value, $property);
			} else {
				$value = preg_replace($field['filter'], $value);
			}
		}

		if (isset($field['test'])) {
			$func = $field['test'];
			if (is_callable($func)) {
				$err = $func($value, $property, $errors);
			} else if (is_callable($class, $func)) {
				$err = $object->$func($value, $property, $errors);
			} else {
				$err = preg_match($field['test'], $value);
			}
			if ($err !== true) {
				$errors[$property] = ($err === false ? "Test failed" : $err);
				//continue;
			}
		}

		if ($field['required'] && !$value) {
				$errors[$property] = "Required field";
					//continue;
		}

		_debug_log($class.' -> '.$property.' = '.str_replace(array("\r","\n"),'\n',substr(print_r($value,1), 0, 100)));
		$object->$property = $value;
	}

	return sizeof($errors);
}

class WForm {

	public $action = null;
	public $method = 'POST';
	public $fieldsets = array();

	public $fields = array();

	public $form_class = '';
	public $form_id = '';
	public $input_class = '';
	public $label_class = '';
	public $hint_class = '';
	public $row_error_class = '';
	public $label_error_class = '';
	public $input_error_class = '';

	public $input_header = '';
	public $input_footer = '';

	public $textarea_hack = 'data-drop-handler="embed_document"';

	public $row_header = '<li>';
	public $row_footer = '</li>';

	public $hint_header = '<span class="hint">';
	public $hint_footer = '</span>';

	public $alert_header = '<span class="problem">';
	public $alert_footer = '</span>';

	public function __construct($fields, $action, $method = 'POST', $extra_html = array()) {

		$this->action = $action;
		$this->method = $method;
		
		$this->fields = $fields;

		/* Each fieldset has some extra html to it */
		foreach ($fields as $field) {
			$this->fieldsets[$field['group']] = array(
				'html-preIn'=>'',
				'html-preOut'=>'',
				'html-postIn'=>'',
				'html-postOut'=>'',
			);
		}

		/* By applying a '<' or '>' symbol to end or beginning
		 * of a form, one can direct where user-passed html goes.
		 *
		 * >main -- before, outside
		 * <main -- before, inside
		 * main< -- after, inside
		 * main> -- after, outside
		 */
		foreach ($extra_html as $group_name => $group_html) {
			$mod = '-postOut';
			if (substr($group_name, 0, 1) == '>') {
				$mod = '-postOut';
				$group_name = substr($group_name, 1);
			}
			else if (substr($group_name, 0, 1) == '<') {
				$mod = '-preIn';
				$group_name = substr($group_name, 1);
			}
			if (substr($group_name, -1) == '<') {
				$mod = '-preOut';
				$group_name = substr($group_name,0, strlen($group_name)-1);
			}
			if (substr($group_name, -1) == '>') {
				$mod = '-postIn';
				$group_name = substr($group_name,0, strlen($group_name)-1);
			}
			$this->fieldsets[$group_name]['html'.$mod] = $group_html;
		}

	}

	public function render($needed_fieldsets = null) {

		if (!$needed_fieldsets) $needed_fieldsets = array_keys($this->fieldsets);

		$action = $this->action;
		$method = $this->method;
		$fields = $this->fields;

		$output = '';
		$_method = '';
		$enc = '';

		if (substr($method, 0,1) == '@') {
			$method = substr($method, 1);
			$enc = ' enctype="multipart/form-data"';
		}
		if (substr($method, 0,1) == '_') {
			$_method = '<input type="hidden" name="_method" value="'.substr($method, 1).'" />'.PHP_EOL;
			$method = 'POST';
		}
		if (substr($method, 0,2) == 'X_') {
			$_method = '<input type="hidden" name="X_METHOD_OVERRIDE" value="'.substr($method, 2).'" />'.PHP_EOL;
			$method = 'POST';
		}
		/* Note: <form ...> is prepended at the end */

		foreach ($needed_fieldsets as $fieldset) {

			if (!isset($this->fieldsets[$fieldset])) {
				_debug_log("Ignoring unknown fieldset `".$fieldset."`");
				continue;
			}

			if ($this->fieldsets[$fieldset]['html-preOut'])
				$output .= $this->fieldsets[$fieldset]['html-preOut'] . PHP_EOL;

			$output .= '<fieldset name="'.$fieldset.'">'.PHP_EOL;

			if ($this->fieldsets[$fieldset]['html-preIn'])
				$output .= $this->fieldsets[$fieldset]['html-preIn'] . PHP_EOL;

			foreach ($fields as $field) {

				if ($field['group'] != $fieldset) continue;

				$property = $field['name'];
				$input = $field['type'];
				$title = $field['title'];

				/* HACK!!! DO NOT USE HTML5 required field */
				if (defined('DEBUG')) $field['required'] = '';
		
				$value = $field['value'];

				$row_wrap = 0;


				$label_class = $this->label_class;
				if ($field['error']) $label_class .= ' '.$this->label_error_class;
				if ($field['hint']) $label_class .= ' '.$this->hint_class;
				$label_class = trim($label_class);

				$label_class_exp = ($label_class ? ' class="'.$label_class.'"' : '');

				$input_class = $field['class'] . ' ' . ($field['error'] ? $this->input_error_class : $this->input_class);
				$input_class = trim($input_class);
				$input_class_exp = ($input_class ? ' class="'.$input_class.'" ' : '');
				//$hint_class = ($field['hint'] ? ' class="hint" ' : '');

				/* Hack -- autoadjust enctype */
				if ($input == 'file') $enc = ' enctype="multipart/form-data"';

				if ($input != 'hidden') {
					$output .= sprintf($this->row_header, ($field['error'] ? $this->row_error_class : '') );
					$output .= '<label for="'.$property.'-field"'.$label_class_exp.'>'.$title.':</label>';
					$output .= $this->input_header;
					$row_wrap = 1;
				}
				switch ($input) {
					case 'hidden':
						$output .= "<input type='hidden' name='".$property."' value='".$value."'/>".PHP_EOL;
					break;
					case 'checklist':
						$output .= '<div class="checklist">';
						$marked = array();
						foreach ($value as $val) {
							$selected = ($value ? ' checked' : '');
							$output .= $val->name;
							$output .= '<input type="checkbox" '.$selected.$input_class_exp.' name="'.$property.'['.$val->name.']">'.PHP_EOL;
							$marked[] = $val->name;
						}
						foreach ($field['options'] as $option) {
							if (in_array($option['name'], $marked)) continue;
							$selected = ($option['selected'] && !$value) ? ' checked' : '';
							$output .= $option['name'];
							$output .= '<input type="checkbox" '.$selected.$input_class_exp.' name="'.$property.'['.$option['name'].']">'.PHP_EOL;
						}
						$output .= '</div>';
						$row_wrap = 1;
					break;
					case 'select':
						$output .= '<select id="'.$property.'-field"'.$input_class_exp.' name="'.$property.'">'.PHP_EOL;
						if ($field['options']) foreach ($field['options'] as $option) {
							$output .= '<option value="'.$option['value'].'"'.
								($option['selected'] ? ' selected' : '').
								'>'.$option['name'].'</option>';
						}
						$output .= '</select>'.PHP_EOL;
						$row_wrap = 1;
					break;
					case 'radio':
						if ($field['options']) {
							#$output .= '<select id="'.$property.'-field"'.$prob_class.' name="'.$property.'">'.PHP_EOL;
							foreach ($field['options'] as $i=>$option) {
								$output .= '<input type="radio" value="'.$option['value'].'" name="'.$property.'"'.
									'id="'.$property.'-field'.$i.'"'.
									($option['selected'] ? ' checked' : '').
									'>'.
									'<label for="'.$property.'-field'.$i.'">'.
									$option['name'].
									'</label>';
							}
						}
						#$output .= '</select>'.PHP_EOL;
						$row_wrap = 1;
					break;
					case 'checkbox':
						$selected = ($value ? ' checked' : '');
						$output .= '<input id="'.$property.'-field"'.$input_class_exp.' type="'.$input.'" value="1" name="'.$property.'"'.$selected.' />'.PHP_EOL;
						$row_wrap = 1;
					break;
					case 'textarea':
						$output .= '<textarea cols="80" rows="24" placeholder="'.$title.'" id="'.$property.'-field"'.$input_class_exp.' name="'.$property.'"'.$this->textarea_hack.'>'.$value.'</textarea>'.PHP_EOL;
						$row_wrap = 1;
					break;
					default:	/* text, */
						if (!$input) $input = 'text';
						if (is_object($value)) throw new Exception("Field <b>".$property."</b> has value (".get_class($value).") that can't be converted to string");
						$output .= '<input size="80" placeholder="'.$title.'" id="'.$property.'-field"'.$input_class_exp.' type="'.$input.'" name="'.$property.'" value="'.$value.'" '.$field['required'].' />'.PHP_EOL;
					break;
				}

				if ($field['error']) {
					$output .= $this->alert_header.$field['error'].$this->alert_footer.PHP_EOL;
				}
				if ($field['hint']) {
					$output .= $this->hint_header.$field['hint'].$this->hint_footer.PHP_EOL;
				}
				if ($row_wrap) {
					$output .= $this->input_footer.PHP_EOL;
					$output .= $this->row_footer.PHP_EOL;
				}
			}

			if ($this->fieldsets[$fieldset]['html-postIn'])
				$output .= $this->fieldsets[$fieldset]['html-postIn'] . PHP_EOL;

			$output .= '</fieldset>'.PHP_EOL;

			if ($this->fieldsets[$fieldset]['html-postOut'])
				$output .= $this->fieldsets[$fieldset]['html-postOut'] . PHP_EOL;

		}

		$output .= '</form>';

		$form_class = ($this->form_class ? ' class="'.$this->form_class.'"' : '');
		$form_id = ($this->form_id ? ' id="'.$this->form_id.'"' : '');

		$output_begin = '<form action="'.$action.'" method="'.$method.'"'.$enc.$form_id.$form_class.'>'.PHP_EOL;
		$output_begin .= $_method;

		return $output_begin . $output;
	}
}

function formHtml($fields, $action, $method, $extra_html = array(), $opts = array() ) {

	$form = new WForm($fields, $action, $method, $extra_html);
	foreach ($opts as $key=>$val) $form->$key = $val;
	return $form->render();

}

function formField($property, $input, $value='', $error=FALSE) {
		/* Format field */
		$large_fields = array('body', 'text', 'description', 'content');
		$default = ($property == 'id' ? 'hidden' : (in_array($property, $large_fields) ? 'textarea' : 'text'));

		$title = $property;
		$tooltip = "";
		$class = "";

		$required = "";
		$group = "main";

		$attr = '';

		if ($input) {
			if (preg_match_all('#([a-z_\-]+)=([^a-zA-Z0-9])(.+?)(\2)#', $input, $mcs, PREG_SET_ORDER)) {
				$attr .= $mc[0];
				$input = str_replace($mc[0], "", $input);
			}
			if (preg_match("#`(.+)`#", $input, $mc)) {
				$title = $mc[1];
				$input = str_replace($mc[0], "", $input);
			}		
			if (preg_match('#"(.+)"#', $input, $mc)) {
				$tooltip = $mc[1];
				$input = str_replace($mc[0], "", $input);
			}
			$hints = preg_split('# #', $input);
			$input = '';
			foreach($hints as $hint) {
				if ($hint == 'required')
					$required = $hint;
				else
				if ($hint == 'multiple')
					$attr .= ' multiple ';
				else
				if (strpos($hint, '=') !== FALSE)
					$attr .= ' ' . $hint . ' ';
				else
				if (substr($hint, 0, 1) == '@')
					$group = substr($hint, 1);
				else
				if (substr($hint, 0, 1) == '.')
					$class = substr($hint, 1);
				else
					$input .= $hint;
			}
			$input = trim($input);
		} else {
			$input = $default;
		}

		return array(
			'name' => $property,
			'type' => $input,
			'attr' => trim($attr),
			'required' => $required,
			'group' => $group,

			'title'=>$title,
			'hint' =>$tooltip,
			'class'=>$class,

			'error'=>$error,
			'value'=>$value,

			'options'=>FALSE,
		);
}

function formObject($object, $errors = NULL) {

		if (!is_object($object)) throw new Exception("Argument 1 not an Object");

		$fields = array();

		$class = get_class($object);

		$desc = array();
		if (isset($object->FIELDS)) $desc = $object->FIELDS;
		else if (isset($class::$_form)) $desc = $class::$_form;
		else if (is_callable(array($object, 'asFieldset'))) $desc = $object->asFieldset();

		foreach ($object as $property=>$value) {

			$input = null;

			if (strtoupper($property) == $property) continue;// Ignore upper-case properties
			if (preg_match("#_peers$#", $property)) continue;

			if (isset($desc[$property])) {
				$input = $desc[$property];
			} else { /* try the _field property */
				$staticprop = $property.'_field';
				if (isset($object->$staticprop)) $input = $object->$staticprop;
				else { /* try the comment reflection */

				}
			}

/*
			if (is_callable(array($object, $property.'_widget'))) {
				$caller = $property."_widget";
				$input = $object->$caller();
				echo "<pre>GOT OURSELVES a...";	print_r($input);	echo "</pre>";
			}
*/
			if ($input === '' || $input === false) continue; /* Ignore explicitly empty values */

			if (is_array($input))	/* the field is already formated */ 
				$field = $input;
			else
				$field = formField($property, $input);

			$peersprop = $property.'_peers';
			if (isset($object->$peersprop)) {
				//$input = 'select';
				$field['options'] = $object->$peersprop;
			}

			$testprop = $property.'_test';
			if (isset($object->$testprop)) $field['test'] = $object->$testprop;
			if (is_callable(array($object, $testprop))) $field['test'] = $testprop;

			$filterprop = $property.'_filter';
			if (isset($object->$filterprop)) $field['filter'] = $object->$filterprop;
			if (is_callable(array($object, $filterprop))) $field['filter'] = $filterprop;

			$field['value'] = $value;
			$field['error'] = isset($errors[$property]) ? $errors[$property] : FALSE;

			$fields[$property] = $field;
		}

		if (isset($object->FIELDS)) $object->FIELDS = $fields;
		return $fields;
}

function md_FormFill(&$fields, $values) {
	$hash = array();
	foreach ($fields as $i=>$field) {
		$hash[$field['name']] = $i;
	}

	foreach ($values as $name=>$value) {
		if (!isset($hash[$name])) continue;
		$i = $hash[$name];
		$field = $fields[$i];

		$field['value'] = $value;
		if (!is_array($value) && $field['options']) {
			foreach ($field['options'] as $j=>$opt) {
				if ($opt['value'] == $value) {
					$opt['selected'] = 'selected';
				} else {
					$opt['selected'] = '';
				}
				$field['options'][$j] = $opt;
			}
		}
		if (is_array($value) && $field['options']) {
			foreach ($field['options'] as $j=>$opt) {
				if (isset($value[$opt['name']])) {
					$opt['selected'] = 'selected';
				} else {
					$opt['selected'] = '';
				}
				$field['options'][$j] = $opt;
			}
		}
		$fields[$i] = $field;
	}
	return $fields;
}

function md_FormRender($fields) {
	$out = '';
	foreach ($fields as $field) {
		$out .= $field['title'];
		$out .= ($field['required'] ? '*' : '');
		$out .= ': ';
		//echo "//".$field['type']."//";
		switch ($field['type']) {
			case 'text':
				$out .= ($field['value'] ? $field['value'] : '______');
			break;
			case 'checkbox':
				$out .= '[' . ($field['value'] ? 'x' : ' ') . ']';
			break;
			case 'radio':
				foreach ($field['options'] as $opt) {
					$out .= '(' . ($opt['selected'] ? 'x' : ' ') . ')';
					$out .= ' ' . $opt['name'];
					$out .= ' ';
				}
			break;
			case 'checklist':
				foreach ($field['options'] as $opt) {
					$out .= '[' . ($opt['selected'] ? 'x' : ' ') . ']';
					$out .= ' ' . $opt['name'];
					$out .= ' ';
				}
			break;
			case 'textarea':
				$out .= ($field['value'] ? "\n" . $field['value'] : '....');
			break;
			case 'file':
				$out .= '@'.$field['value']['name'];
			break;
			default:
				$out .= $field['value'];
			break;
		}
		$out .= PHP_EOL;
	}
	return $out;
}
function md_FormField($str) {

		if (!trim($str)) return NULL;

		$parts = preg_split("#:#", $str);

		if (sizeof($parts) < 2) return NULL;

		$left = trim($parts[0]);
		$right = trim($parts[1]);

		$input = 'text';
		$property = 'name';
		$attr = '';
		$group = 'main';

		$title = '';
		$tooltip = '';
		$error = '';
		$value = '';

		$opts = FALSE;

		$required = "";
		if (strpos($left, '*') !== FALSE) {
			$left = str_replace('*', '', $left);
			$required = "required";
		}
		$title = $left;

		if (preg_match("#@#", $right)) {
			$input = 'file';
		}

		if (preg_match("#^\.\.\.+#", $right)) {
			$input = 'textarea';
		}

		if (preg_match("#\(#", $right)) {
			$input = 'radio';
			$lines = preg_split("#\(#", $right);
			$opts = array();
			$i = 0;
			foreach ($lines as $line) {
				$mrk = preg_split("#\)#", $line);
				if (sizeof($mrk) < 2) continue;
				$name = trim($mrk[1]);
				$checked = trim($mrk[0]) ? "checked" : "";
				$opts[] = array(
					'name' => $name,
					'selected' => $checked,
					'value' => $i++,
				);
			}
		}

		if (preg_match("#\[#", $right)) {
			$input = 'checklist';
			$lines = preg_split("#\[#", $right);
			$opts = array();
			$i = 0;
			foreach ($lines as $line) {
				$mrk = preg_split("#\]#", $line);
				if (sizeof($mrk) < 2) continue;
				$name = trim($mrk[1]);
				$checked = trim($mrk[0]) ? "checked" : "";
				$opts[] = array(
					'name' => $name,
					'selected' => $checked,
					'value' => $i++,
				);
			}
			if (sizeof($opts) == 1) {
				$input = 'checkbox';
			} else {
				$value = array();
			}
		}


		return array(
			'name' => $property,
			'type' => $input,
			'attr' => trim($attr),
			'required' => $required,
			'group' => $group,

			'title'=>$title,
			'hint' =>$tooltip,

			'error'=>$error,
			'value'=>$value,

			'options'=>$opts,
		);
}
function md_Form($text) {

	$lines = preg_split("#\n#", $text);
	$fields = array();
	$i = 0;
	foreach ($lines as $line) {
		$field = md_FormField($line);
		if ($field) {
			$field['name'] = 'field'.$i;
			$fields[] = $field;
			$i++;
		}
	}
	return $fields;
}

function md_formalize($txt, $values = null, $action = false, $method = 'POST', $extra_html = array()) {
	$fields = md_Form($txt);

	$toHtml = $action ? true : false;

	if ($values)
		md_FormFill($fields, $values);

	if ($toHtml) {
		$form = new WForm($fields, $action, $method, $extra_html);
		return $form->render();
	}

	return md_FormRender($fields);
}

?>