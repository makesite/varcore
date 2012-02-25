<?php

function formalize($object, $action, $method, $errors, $extra_html = array()) {

	$fields = formObject($object, $errors);

	return formHtml($fields, $action, $method, $extra_html);

}

function fillObject($object, $fill, &$errors = NULL) {

	if ($errors == null) $errors = array();

	$fields = formObject($object);

	$class = get_class($object);

	foreach ($fields as $field) {

		$property = $field['name'];
		$input = $field['type'];
		
		$value = @$fill[$property];

		$filter = $property.'_filter';
		$test = $property.'_test';

		if (isset($field['filter'])) {
			$func = $field['filter'];
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
				$err = $func($value);
			} else {
				$err = preg_match($$field['test'], $value);
			}
			if ($err) {
				$errors[$property] = "Test failed";
				//continue;
			}
		}

		if ($field['required'] && !$value) {
				$errors[$property] = "Required field";
					//continue;
		}			

_debug_log($class.' -> '.$property.' = '.substr($value, 0, 100));
		$object->$property = $value;
	}

	return sizeof($errors);
}

function formHtml($fields, $action, $method, $extra_html = array() ) {

	$output = '';
	$_method = '';
	$enc = '';

	$fieldset = 'main';

	if (substr($method, 0,1) == '@') {
		$method = substr($method, 1);
		$enc = ' enctype="multipart/form-data"';
	}
	if (substr($method, 0,1) == '_') {
		$_method = '<input type="hidden" name="_method" value="'.substr($method, 1).'" />'.PHP_EOL;
		$method = 'POST';
	}

	$output .= '<form action="'.$action.'" method="'.$method.'"'.$enc.'>'.PHP_EOL;
	$output .= $_method;

	$output .= '<fieldset title="'.$fieldset.'">'.PHP_EOL;
	foreach ($fields as $field) {

		$property = $field['name'];
		$input = $field['type'];

		$nfs = $field['group'];
		$field['required'] = '';

		$value = $field['value'];
		
		$row_wrap = 0;

		if ($nfs && $nfs != $fieldset) {
			$output .= '</fieldset>'.PHP_EOL;
			if (isset($extra_html[$fieldset])) 
			$output .= $extra_html[$fieldset].PHP_EOL;
			$extra_html[$fieldset] = '';
			$output .= '<fieldset title="'.$nfs.'">';
			$fieldset = $nfs;
		}

		$prob_class = ($field['error'] ? ' class="err" ' : '');

		switch ($input) {

			case 'hidden':
				$output .= "<input type='hidden' name='".$property."' value='".$value."'/>\n";
			break;
			case 'select':
				$output .= '<li>';
				$output .= '<label for="'.$property.'-field"'.$prob_class.'>'.$property.':</label>';
				$output .= '<select id="'.$property.'-field"'.$prob_class.' name="'.$property.'">'.PHP_EOL;
				if ($field['options']) foreach ($field['options'] as $option) {
					$output .= '<option value="'.$option['value'].'"'.
						($option['selected'] ? ' selected' : '').
						'>'.$option['name'].'</option>';
				}
				$output .= '</select>'.PHP_EOL;
				$row_wrap = 1;
			break;
			case 'radio':
				$output .= '<li>';
				$output .= '<label for="'.$property.'-field"'.$prob_class.'>'.$property.':</label>';
				if ($field['options']) {
					#$output .= '<select id="'.$property.'-field"'.$prob_class.' name="'.$property.'">'.PHP_EOL;
				 	foreach ($field['options'] as $option) {
						$output .= '<input type="radio" value="'.$option['value'].'" name="'.$property.'"'.
							($option['selected'] ? ' selected' : '').
							'>'.$option['name'].'</option>';
					}
				}
				#$output .= '</select>'.PHP_EOL;
				$row_wrap = 1;
			break;
			case 'checkbox':
				$selected = ($value ? ' checked' : '');
				$output .= '<li>';
				$output .= '<label for="'.$property.'-field"'.$prob_class.'>'.$property.':</label>';
				$output .= '<input id="'.$property.'-field"'.$prob_class.' type="'.$input.'" value="1" name="'.$property.'"'.$selected.' />'.PHP_EOL;
				$row_wrap = 1;
			break;
			case 'textarea':
				$drop_handler = '';
				if ($property == 'body') {
					$drop_handler = 'data-drop-handler="embed_document"';
				}
				$output .= '<li>';
				$output .= '<label for="'.$property.'-field"'.$prob_class.'>'.$property.':</label>';
				$output .= '<textarea cols="80" rows="24" placeholder="'.$property.'" id="'.$property.'-field"'.$prob_class.' name="'.$property.'"'.$drop_handler.'>'.$value.'</textarea>'.PHP_EOL;
				$row_wrap = 1;
			break; 
			default:	/* text, */
				$output .= '<li>';
				$output .= '<label for="'.$property.'-field"'.$prob_class.'>'.$property.':</label>';
				$output .= '<input size="80" placeholder="'.$property.'" id="'.$property.'-field"'.$prob_class.' type="'.$input.'" name="'.$property.'" value="'.$value.'" '.$field['required'].' />'.PHP_EOL;
				$row_wrap = 1;
			break;
		}

		if ($prob_class) {
			$output .= '<span class="problem">'.$field['error'].'</span>'.PHP_EOL;
		}
		if ($row_wrap) {
			$output .= '</li>'.PHP_EOL;		
		}
	}
	$output .= '</fieldset>'.PHP_EOL;

	if (isset($extra_html[$fieldset])) 
	$output .= $extra_html[$fieldset].PHP_EOL;

	$output .= '</form>';

	return $output;
}

function formField($property, $input, $value='', $error=FALSE) {
		/* Format field */
		$default = ($property == 'id' ? 'hidden' : ($property == 'body' ? 'textarea' : 'text')); 

		$required = "";
		$group = "";
	
		$attr = '';

		if ($input) {
			$hints = preg_split('# #', $input);
			$input = '';
			foreach($hints as $hint) {
				if ($hint == 'required')
					$required = $hint;
				else
				if (strpos($hint, '=') !== FALSE)
					$attr .= $hint;
				else 
				if (substr($hint, 0, 1) == '@')
					$group = substr($hint, 1);
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
			'attr' => $attr,
			'required' => $required,
			'group' => $group,

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
			if ($input === '') continue; /* Ignore explicitly empty values */

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

			$filterprop = $property.'_filter';
			if (isset($object->$filterprop)) $field['filter'] = $object->$filterprop;

			$field['value'] = $value;
			$field['error'] = isset($errors[$property]) ? $errors[$property] : FALSE;

			$fields[$property] = $field;
		}

		if (isset($object->FIELDS)) $object->FIELDS = $fields;
		return $fields;
}

?>