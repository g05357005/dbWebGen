<?
	//------------------------------------------------------------------------------------------
	function render_form() {
	//------------------------------------------------------------------------------------------
		global $TABLES;

		$table_name = $_GET['table'];
		if(!isset($TABLES[$table_name]))
			return proc_error('Invalid table name or table not configured.');
		$table = $TABLES[$table_name];

		if(!is_allowed($table, $_GET['mode']) && !is_own_user_record(true))
			return proc_error('You are not allowed to perform this action.');

		// get the unique form id (either from POST, or generate)
		$form_id = isset($_POST['__form_id__']) ? $_POST['__form_id__'] : ($_POST['__form_id__'] = uniqid('__form_id__', true));
		#debug_log("$form_id = ", $_SESSION[$form_id]);

		if($_GET['mode'] == MODE_NEW) {
			echo "<h1>New {$table['item_name']}</h1>\n";

			// fake pre filled fields
			// all URL parameters that are prepended with PREFILL_PREFIX will get pre-filled
			foreach($_GET as $p => $v) {
				if(substr($p, 0, strlen(PREFILL_PREFIX)) == PREFILL_PREFIX && strlen($p) > strlen(PREFILL_PREFIX))
					$_POST[substr($p, strlen(PREFILL_PREFIX))] = $v;
			}

			// set default field values
			foreach($table['fields'] as $field_name => $field_info) {
				if(isset($field_info['default']) && !isset($_POST[$field_name]))
					$_POST[$field_name] = get_default($field_info['default']);
			}
		}
		else { // MODE_EDIT
			// in inline mode it can happen that the fk_self does not exist yet
			$edit_id = get_primary_key_values_from_url($table);
			if($edit_id === false)
				return false;

			$parent_form = null;
			if(is_inline()) {
				$page_title = "Edit {$table['item_name']} Details";
				if(!isset($_GET['parent_form']))
					return proc_error('Parent form id not provided');

				$parent_form = $_GET['parent_form'];
			}
			else {
				$page_title = "Edit {$table['item_name']}";
				if(count($edit_id) == 1)
					$page_title .= ' <small>'. html($table['fields'][first(array_keys($edit_id))]['label']) . '<span class="smsp">=</span>' . html(first(array_values($edit_id))) .'</small>';
			}

			echo "<h1>$page_title</h1>\n";

			#debug_log("POST = ", $_POST);
			if(!isset($_POST['__item_id__'])) { // if not already posted (with errors obviously), then get from DB
				if(!build_post_array_for_edit_mode($table_name, $table, $edit_id, $parent_form))
					return false;
			}
		}

		echo "<p>{$table['description']}</p>\n";

		echo "<p>Fill the form fields and then press 'Save'. Fields indicated with <span class='required-indicator'>&#9733;</span> are required.</p>\n";

		if(is_inline())
			echo "<p>Note that your edits will only be stored in the database if the original form is also submitted</p>\n";

		echo "<form class='form-horizontal bg-gray' role='form' method='post' enctype='multipart/form-data'><fieldset>\n";

		$form_tabs = new FormTabs($table);
		echo $form_tabs->begin();

		echo "<input type='hidden' id='__form_id__' name='__form_id__' value='$form_id' />";
		echo '<input type="hidden" id="__table_name__" name="__table_name__" value="'. unquote($_GET['table']) .'"/>';
		echo '<input type="hidden" id="__item_id__" name="__item_id__" value="'. ($_GET['mode'] == MODE_EDIT? unquote(first(array_values($edit_id))) : '') .'" />'; // do not remove this, it is referenced in other places!!!

		$submit_button = "<div class='form-group'>\n".
			"<div class='col-sm-offset-3 col-sm-9'>\n".
			"<input type='submit' class='btn btn-primary' value='Save' />\n";

		if($_GET['mode'] == MODE_EDIT && !is_inline())
			echo $submit_button. '</div></div>';

		$i = 0;
		$conditional_label_scripts = array();

		foreach($table['fields'] as $field_name => $field) {
			if(!is_field_editable($field))
				continue;

			echo $form_tabs->new_tab_if_needed($field_name);

			if(is_inline() && in_array($field_name, $table['primary_key']['columns'])) {
				// do not show key fields in inline mode.
				echo "<input type='hidden' id='{$field_name}' name='{$field_name}' value='". unquote($_GET[$field_name]) ."' />";
				continue;
			}

			$prefilled = isset($_GET[PREFILL_PREFIX . $field_name]);// ? 'prefilled' : '';

			$required_indicator = (is_field_required($field) ? '<span class="required-indicator">&#9733;</span>' : '');
			echo "<div class='form-group'>\n";
			echo "<label title='This field is ". (is_field_required($field) ? 'required' : 'optional') ."' class='control-label col-sm-3' for='{$field_name}'>";
			render_help($field);
			echo "<span data-field='$field_name'>{$field['label']}</span>{$required_indicator}</label>\n";

			render_control($form_id, $field_name, $field, $i++ == 0, isset($_GET[PREFILL_PREFIX . $field_name]));

			echo "</div>\n";

			if(isset($field['conditional_form_label']))
				$conditional_label_scripts[] = get_conditional_label_script($table, $field_name, $field['conditional_form_label']);
		}

		echo $form_tabs->close();

		echo $submit_button;
		if($_GET['mode'] == MODE_NEW)
			"<input type='reset' class='btn btn-default' value='Clear Form' />\n";
        echo "</div>\n</div>\n</fieldset></form>\n";
		echo "<div style='padding-bottom:4em'>&nbsp;</div>";

		echo implode($conditional_label_scripts);
	}

	//------------------------------------------------------------------------------------------
	function get_conditional_label_script(/*const*/ &$table, $field_name, /*const*/ &$field_cond) {
	//------------------------------------------------------------------------------------------
		$mapping_json = json_encode($field_cond['mapping']);
		$default_val = str_replace('"', '\\"', $table['fields'][$field_name]['label']);

		$js = <<<EOT
			<script>
				$('#{$field_cond['controlled_by']}').on('change', function() {
					var val = $(this).val();
					var mapping = $mapping_json;
					$('label > span[data-field="{$field_name}"]').html(val in mapping ? mapping[val] : "$default_val");
				});
			</script>
EOT;

		return $js;
	}

	//------------------------------------------------------------------------------------------
	function build_post_array_for_edit_mode($table_name, $table, $edit_id, $parent_form) {
	//------------------------------------------------------------------------------------------
		global $TABLES;

		if(is_inline()) {
			#debug_log("TRACE: Coming from {$TABLES[$_GET['inline']]['display_name']}, editing field {$TABLES[$_GET['inline']]['fields'][$_GET['lookup_field']]['label']}, association with {$TABLES[$_GET['inline']]['fields'][$_GET['lookup_field']]['lookup']['table']} #{$edit_id[$TABLES[$_GET['inline']]['fields'][$_GET['lookup_field']]['linkage']['fk_other']]}");
			// here we only want to query if both foreign keys are present and the user has
			// not yet edited the details of the linkage (which would be reflected in the session
			// variable)
			//
			// formulated differently: we can skip querying if any foreign
			// key is missing or if there is already detail info in the session var for this association

			$arr_details = get_inline_linkage_details(
				$parent_form,
				$_GET['lookup_field'],
				$edit_id[get_inline_fieldname_fk_other()]);

			if($arr_details !== false) {
				#debug_log('Retrieving from session var = ', $arr_details);
				// user has already edited these details. copy from session var
				foreach($arr_details['details'] as $col => $val) {
					$_POST[$col] = $val;

					if(!is_field_required($table['fields'][$col]))
						$_POST["{$col}__null__"] = ($val === null ? 'true' : 'false');
				}

				return true;
			}

			if(in_array('', array_values($edit_id))) { // missing foreign key
				// here the user has created a new (parent) record and then edited one of the linkage fields ofd this unsaved record
				#debug_log('Details edit of a new unsaved record');

				// we don't have to do anything actually, all fields will be put to default value.
				return true;
			}
		}

		$query = build_query($table_name, $table, $edit_id, MODE_EDIT, NULL, $params);

		$db = db_connect();
		if($db === false)
			return proc_error('Cannot connect to DB.');

		$res = $db->prepare($query);

		if($res === FALSE)
			return proc_error('Query preparation went wrong.', $db);

		if($res->execute($params) === FALSE)
			return proc_error('Query execution went wrong.', $db);

		if($res->rowCount() != 1) {
			if(is_inline() && $res->rowCount() == 0) {
				// here we have both foreign keys set, but the association was added in the form and not saved yet.
				#debug_log('Editing new unsaved association for existing record');

				// we don't have to do anything actually, all fields will be put to default value.
				return true;
			}

			return proc_error("Requested object not found.");
		}

		foreach($res->fetch(PDO::FETCH_ASSOC) as $col => $val) {
			$reset_field = is_field_reset($table['fields'][$col]);

			if($reset_field)
				$_POST[$col] = is_field_required($table['fields'][$col])? '' : NULL;
			else
				$_POST[$col] = $val;

			if(!is_field_required($table['fields'][$col]))
				$_POST["{$col}__null__"] = ($reset_field || $val === null ? 'true' : 'false');
		}

		return true;
	}

	//------------------------------------------------------------------------------------------
	function render_help($field) {
	//------------------------------------------------------------------------------------------
		if(!isset($field['help']) || $field['help'] == '')
			return;

		echo get_help_popup('Help', $field['help']);
	}

	//------------------------------------------------------------------------------------------
	function render_control($form_id, $field_name, $field, $focus, $prefilled) {
	//------------------------------------------------------------------------------------------
		global $TABLES;
		global $LOGIN;
		$table_name = $_GET['table'];
		$table = $TABLES[$table_name];

		if(!is_allowed($table, $_GET['mode']) && is_own_user_record(true))
			$disabled = ($field_name != $LOGIN['password_field']);
		else
			$disabled = $prefilled;

		$field_obj = FieldFactory::create($table_name, $field_name, $field);
		$render_settings = array(
			'disabled' => $disabled,
			'focus' => $focus
		);

		switch($field_obj->get_type()) {
			case T_LOOKUP:
				$render_settings['form_id'] = $form_id;
				$html = sprintf(
					"<div class='%s col-sm-%s'>%s</div>\n",
					$field_obj->is_dropdown_hidden() ? 'invisible' : '',
					$field_obj->get_width(),
					$field_obj->render_control($render_settings)
				);
				$field_obj->render_create_new_button_html($html);
				if($field_obj->get_cardinality() == CARDINALITY_MULTIPLE)
					$field_obj->render_linked_items($html);
				echo $html;
				break;

			default:
				echo sprintf(
					"<div class='col-sm-%s'>%s</div>\n",
					$field_obj->get_width(), $field_obj->render_control($render_settings)
				);
				break;
		}

		echo $field_obj->render_setnull_box();
	}

	//------------------------------------------------------------------------------------------
	function get_linked_items($field_name) {
	//------------------------------------------------------------------------------------------
		if(!isset($_POST[$field_name]))
			return array();

		$v = trim($_POST[$field_name]);
		if($v == '')
			return array();

		return json_decode($v);
	}

	//------------------------------------------------------------------------------------------
	function handle_uploaded_file($table, $field_name, $field, &$file) {
	//------------------------------------------------------------------------------------------
		/*
			$file = [
				'name' => 'UNICODE.txt',
				'type' => 'text/plain',
				'tmp_name' => 'C:\xampp\tmp\php2F38.tmp',
				'error' => 0,
				'size' => 529
			]
		*/

		if($file['error'] != UPLOAD_ERR_OK)
			return proc_error(get_file_upload_error_msg($file['error']));

		if(isset($field['max_size']) && $file['size'] > $field['max_size'])
			return proc_error("Uploaded file exceeds size limitation of {$file['size']} bytes.");

		if(isset($field['allowed_ext']) && is_array($field['allowed_ext'])) {
			$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
			if(!in_array($ext, $field['allowed_ext']))
				return proc_error("File extension '$ext' is not allowed. The following are allowed: " . implode(', ', $field['allowed_ext']));
		}

		if(!isset($field['location']))
			return proc_error("Target location for uploaded files not set. Contact your admin.");

		if($field['store'] & STORE_FOLDER) {
			// make sure storage location ends with a slash /
			$store_folder = $field['location'];
			if(substr($field['location'], -1) != '/')
				$store_folder .= '/';
			$store_folder = str_replace("\\", '/', $store_folder);

			if(!is_dir($store_folder) && !mkdir($store_folder, 0777, true))
				return proc_error('Could not create target directory.');

			$target_filename = $store_folder . $file['name'];

			if($_GET['mode'] == MODE_NEW && file_exists($target_filename))
				return proc_error('Cannot upload file, because a file with the same name already exists at the storage location.');

			// when editing, first we need to remove existing file if name is different
			if($_GET['mode'] == MODE_EDIT) {
				$where = array();
				$params = array();
				foreach($table['primary_key']['columns'] as $pk_col) {
					$where[] = db_esc($pk_col) . ' = ?';
					$params[] = $_GET[$pk_col];
				}

				$sql = sprintf('SELECT %s FROM %s WHERE %s',
					db_esc($field_name), db_esc($_GET['table']), implode(' AND ', $where));

				$succ = db_get_single_val($sql, $params, $prev_filename);
				if($succ && $prev_filename != $file['name'] && file_exists($store_folder . $prev_filename))
					unlink($store_folder . $prev_filename);
			}

			$moved = move_uploaded_file($file['tmp_name'], $target_filename);
			if(!$moved)
				return proc_error('Could not store uploaded file.');

			$file['path'] = $target_filename;
		}

		if($field['store'] & STORE_DB)
			return proc_error('Storing files in the DB is not supported yet.');

		return true;
	}

	//------------------------------------------------------------------------------------------
	function get_form_id() {
	//------------------------------------------------------------------------------------------
		return $_POST['__form_id__'];
	}

	//------------------------------------------------------------------------------------------
	function get_sql_update($table_name, /*const*/ &$table, /*const*/ &$columns,
		/*in,out*/ &$values, /*in*/ $pk_values) {
	//------------------------------------------------------------------------------------------
		$sql = 'UPDATE ' . db_esc($table_name) . ' SET ';

		for($i=0; $i<count($columns); $i++) {
			if($i > 0) $sql .= ', ';

			if($table['fields'][$columns[$i]]['type'] == T_POSTGIS_GEOM)
				$sql .= db_esc($columns[$i]) . " = ST_GeomFromText(?,{$table['fields'][$columns[$i]]['SRID']})";
			else
				$sql .= db_esc($columns[$i]) . ' = ?';
		}

		$sql .= ' WHERE ';

		$pk = 0;
		foreach($pk_values as $pk_col => $pk_val) {
			$sql .= ($pk++ == 0 ? ' ' : ' AND ') . db_esc($pk_col) . ' = ?';
			$values[] = $pk_val;
		}

		return $sql;
	}

	//------------------------------------------------------------------------------------------
	function get_sql_insert($table_name, /*const*/ &$table, /*const*/ &$columns) {
	//------------------------------------------------------------------------------------------
		$sql = 'INSERT INTO '. db_esc($table_name) . ' (';

		for($i=0; $i<count($columns); $i++) {
			if($i > 0) $sql .= ', ';
			$sql .= db_esc($columns[$i]);
		}

		$sql .= ') VALUES (';

		for($i=0; $i<count($columns); $i++) {
			if($i > 0) $sql .= ', ';

			if($table['fields'][$columns[$i]]['type'] == T_POSTGIS_GEOM)
				$sql .= "ST_GeomFromText(?,{$table['fields'][$columns[$i]]['SRID']})";
			else
				$sql .= '?';
		}
		$sql .= ')';

		return $sql;
	}

	//------------------------------------------------------------------------------------------
	function process_form() {
	//------------------------------------------------------------------------------------------
		global $TABLES;
		global $LOGIN;

		if(count($_POST) == 0)
			return false;

		if($_GET['mode'] != MODE_NEW && $_GET['mode'] != MODE_EDIT)
			return proc_error('Unknown mode.');

		$table_name = $_GET['table'];
		if(!isset($TABLES[$table_name]))
			return proc_error('Table name is missing or invalid.');

		$table = $TABLES[$table_name];

		if(!is_allowed($table, $_GET['mode']) && !is_own_user_record(true))
			return proc_error('You are not allowed to perform this action.');

		$columns = array();
		$many2many_field_assocs = array();
		$values = array();
		$arr_inline_details = array();

		// store the name of the password field in the users table if an underpriviledged user is editing their own user record
		$password_only_field = ($_GET['mode'] == MODE_EDIT
			&& !is_allowed($table, $_GET['mode'])
			&& is_own_user_record(true)
			? $LOGIN['password_field'] : null);

		foreach($table['fields'] as $field_name => $field_info) {
			// we process non-editable fields in any case
			if(!is_field_editable($field_info)) {
				if(isset($field_info['default'])) {
					$columns[] = $field_name;
					$values[] = get_default($field_info['default']);
				}

				// no further processing
				continue;
			}

			// we ignore all fields except the password field, if an underpriviledged user is changing their own user record
			if($password_only_field !== null && $password_only_field !== $field_name)
				continue;

			// we wanna store the raw stuff for the form
			if(is_inline() && isset($_POST[$field_name]))
				$arr_inline_details[$field_name] = $_POST[$field_name];

			if($field_info['type'] == T_UPLOAD) {
				if(!isset($_FILES[$field_name]))
					return proc_error("Please browse for an upload for <b>{$field_info['label']}</b>.");

				if($_FILES[$field_name]['size'] == 0)
					return proc_error("No file to upload selected for field <b>{$field_info['label']}</b>.");

				if($_FILES[$field_name]['error'] !== UPLOAD_ERR_OK)
					return proc_error(get_file_upload_error_msg($_FILES[$field_name]['error']));

				$succ = handle_uploaded_file($table, $field_name, $field_info, $_FILES[$field_name]);
				if($succ === false)
					return false;

				if($field_info['store'] & STORE_FOLDER) {
					$columns[] = $field_name;
					$values[] = $_FILES[$field_name]['name'];
				}

				continue;
			}

			if(!isset($_POST[$field_name]))
				return proc_error("Field {$field_info['label']} is missing.");

			if(is_field_required($field_info) && $_POST[$field_name] === '' || $_POST[$field_name] === null) {
				// only fk_self can be missing in mode inline
				if(is_inline() && in_array($field_name, array_keys(get_primary_key_values_from_url($table)))) {
					$columns[] = $field_name;
					$values[] = $_POST[$field_name];
					continue;
				}

				return proc_error("Please fill in required field <b>{$field_info['label']}</b>");
			}

			if(!is_field_required($field_info) && is_field_setnull($field_name, $field_info)) {
				$columns[] = $field_name;
				$values[] = NULL;
			}
			else if($field_info['type'] == T_LOOKUP && $field_info['lookup']['cardinality'] == CARDINALITY_MULTIPLE) {
				$many2many_field_assocs[$field_name] = get_linked_items($field_name);
				if(is_field_required($field_info) && count($many2many_field_assocs[$field_name]) == 0)
					return proc_error("Please provide at least one value for required field <b>{$field_info['label']}</b>");
			}
			else if($field_info['type'] == T_PASSWORD) {
				if(isset($field_info['min_len']) && mb_strlen($_POST[$field_name]) < $field_info['min_len'])
					return proc_error("Password is too short. Minimum length is {$field_info['min_len']}.");

				if(isset($LOGIN['password_hash_func']) && !function_exists($LOGIN['password_hash_func']))
					return proc_error("Password hash function {$LOGIN['password_hash_func']} does not exist. Inform your admin.");

				$columns[] = $field_name;
				$values[] = isset($LOGIN['password_hash_func']) ? $LOGIN['password_hash_func']($_POST[$field_name]) : $_POST[$field_name];
			}
			else {
				$columns[] = $field_name;
				$values[] = is_field_trim($field_info) ? trim($_POST[$field_name]) : $_POST[$field_name];
			}
		}

		if(count($columns) == 0 && count($many2many_field_assocs) == 0)
			return proc_error('No values to store in database.');

		if($_GET['mode'] == MODE_NEW) {
			// call 'before_insert' hook functions
			if(isset($table['hooks']) && isset($table['hooks']['before_insert']))
				$table['hooks']['before_insert']($table_name, $table, $columns, $values);

			$sql = get_sql_insert($table_name, $table, $columns);
		}
		else { // MODE_EDIT
			// call 'before_update' hook functions
			if(isset($table['hooks']) && isset($table['hooks']['before_update']))
				$table['hooks']['before_update']($table_name, $table, $columns, $values);

			$edit_ids = get_primary_key_values_from_url($table);

			if(is_inline()) {
				// copy the values to session
				set_inline_linkage_details($_GET['parent_form'], $_GET['lookup_field'],
					$edit_ids[get_inline_fieldname_fk_other()], $arr_inline_details, $columns, $values);

				// here we just close the window.
				echo "<script>window.close();</script>";
				return true;
			}

			$sql = get_sql_update($table_name, $table, $columns, $values, $edit_ids);
		}

		// FIRST INSERT OR UPDATE THE RECORD
		$db = db_connect();
		if($db === false)
			return proc_error('Cannot connect to DB.');

		$stmt = $db->prepare($sql);
		if($stmt === FALSE)
			return proc_error('SQL statement preparation failed.', $db);

		#debug_log($sql, "\nvalues := ", $values);

		if(FALSE === $stmt->execute($values))
			return proc_error('SQL statement execution failed.', $db);

		$form_id = get_form_id();

		// THEN HANDLE THE MANY-TO-MANY-ASSOCIATIONS (only if not in m:n table, since composite FKs do not work yet)
		$primary_keys = array();
		if($_GET['mode'] == MODE_NEW) {
			// Obtain primary key assoc in $primary_keys
			if($table['primary_key']['auto']) { // CURRENTLY WORKS ONLY WITH ONE PRIMARY KEY COLUMN (NO COMPOSITE KEYS!)
				$new_id = $db->lastInsertId($table['primary_key']['sequence_name']);
				if($new_id === null || $new_id == 0 || $new_id == '')
					return proc_error('Setting id_sequence_name appears invalid');

				$primary_keys[$table['primary_key']['columns'][0]] = $new_id;
			}
			else {
				foreach($table['primary_key']['columns'] as $pk)
					$primary_keys[$pk] = $_POST[$pk];
			}
		}
		else {
			foreach($table['primary_key']['columns'] as $pk)
				$primary_keys[$pk] = $_GET[$pk];

			// REMOVE ALL N:N ASSIGNMENTS THAT ARE NOT NEEDED ANY MORE
			foreach($many2many_field_assocs as $field_name => $values) {
				// delete all associations that are not in $values. if $values is empty, delete all associations
				$question_marks = array();
				foreach($values as $value)
					$question_marks[] = '?';
				$question_marks = implode(', ', $question_marks);

				// the "select" and "delete" parts of the SQL statement will be prepended later
				$from_where = 'FROM ' . db_esc($table['fields'][$field_name]['linkage']['table']) .
					' WHERE ' . db_esc($table['fields'][$field_name]['linkage']['fk_self']) . ' = ?';

				if($question_marks != '') {
					$from_where .= sprintf(' AND %s NOT IN (%s)',
						db_esc($table['fields'][$field_name]['linkage']['fk_other']),
						$question_marks);
				}

				// BEFORE_DELETE hooks >>
				// TODO: this can lead to problems in world with heavy concurrent use.
				// NOTE: if this block is changed, there will be effects on the after_delete hooks block
				$linkage_table_name = $table['fields'][$field_name]['linkage']['table'];

				$linkage_table = null;
				if(isset($TABLES[$linkage_table_name]))
					$linkage_table = $TABLES[$linkage_table_name];

				$before_delete_hook = null;
				if($linkage_table !== null
					&& isset($linkage_table['hooks'])
					&& isset($linkage_table['hooks']['before_delete'])
					&& trim($linkage_table['hooks']['before_delete']) != '')
				{
					$before_delete_hook = $linkage_table['hooks']['before_delete'];
				}

				$after_delete_hook = null;
				if($linkage_table !== null
					&& isset($linkage_table['hooks'])
					&& isset($linkage_table['hooks']['after_delete'])
					&& trim($linkage_table['hooks']['after_delete']) != '')
				{
					$after_delete_hook = $linkage_table['hooks']['after_delete'];
				}

				$has_delete_hooks = ($before_delete_hook !== null || $after_delete_hook !== null);
				$to_be_deleted = array();

				if($has_delete_hooks) {
					// first check which ones will be deleted
					$select_stmt = $db->prepare('SELECT * ' . $from_where);
					if($select_stmt === false)
						return proc_error("Preparing of updating of relationships failed for field {$field_name} (step 0).", $db);
					if($select_stmt->execute(array_merge(array_values($primary_keys), $values)) === false)
						return proc_error("Executing the updating of relationships failed for field {$field_name} (step 0).", $db);

					while($record = $select_stmt->fetch(PDO::FETCH_ASSOC)) {
						$pk_hash = array(
							$table['fields'][$field_name]['linkage']['fk_self'] => $record[$table['fields'][$field_name]['linkage']['fk_self']],
							$table['fields'][$field_name]['linkage']['fk_other'] => $record[$table['fields'][$field_name]['linkage']['fk_other']]
						);
						$to_be_deleted[] = $pk_hash;
						// call before_delete hook, if any. be careful with this, because actual delete might fail

						if($before_delete_hook !== null)
							$before_delete_hook ($table['fields'][$field_name]['linkage']['table'], $linkage_table, $pk_hash);
					}
				} // << BEFORE_DELETE hooks

				// ACTUAL DELETION >>
				$delete_stmt = $db->prepare('DELETE ' . $from_where);
				if($delete_stmt === false)
					return proc_error("Preparing of updating of relationships failed for field {$field_name} (step 1).", $db);
				if($delete_stmt->execute(array_merge(array_values($primary_keys), $values)) === false)
					return proc_error("Executing the updating of relationships failed for field {$field_name} (step 1).", $db);
				// << ACTUAL DELETION

				// AFTER_DELETE hook >>
				if($after_delete_hook !== null) {
					foreach($to_be_deleted as $pk_hash) {
						$after_delete_hook ($table['fields'][$field_name]['linkage']['table'], $linkage_table, $pk_hash);
					}
				}
				// << AFTER_DELETE hook

				// determine which assoications in $values already exist, and remove them form $values
				$linkage_info = $table['fields'][$field_name]['linkage'];
				$the_table = db_esc($linkage_info['table']);
				$the_fk_self = db_esc($linkage_info['fk_self']);
				$the_fk_other = db_esc($linkage_info['fk_other']);

				for($i=count($values)-1; $i>=0; $i--) {
					$sql = sprintf('SELECT COUNT(1) FROM %s WHERE %s = ? AND %s = ?',
						$the_table, $the_fk_self, $the_fk_other);

					$stmt = $db->prepare($sql);
					if($stmt === false)
						return proc_error("Preparing of updating of relationships failed for field {$field_name} (step 2).", $db);

					if($stmt->execute(array_merge(array_values($primary_keys), array($values[$i]))) === false)
						return proc_error("Executing the updating of relationships failed for field {$field_name} (step 2).", $db);

					$cunt = $stmt->fetchColumn();
					if($cunt > 0) {
						#debug_log("$form_id, $field_name, {$values[$i]}");
						// need to check if we need to update inline edits to linked records
						$inline_details = get_inline_linkage_details($form_id, $field_name, $values[$i], $primary_keys);

						if($inline_details !== false) {
							#debug_log("Inline details found for $field_name / {$values[$i]}", $inline_details);
							$inline_params = array_merge($inline_details['params'], array());

							$pk_values = array(
								$linkage_info['fk_self'] => first(array_values($primary_keys)), // only one
								$linkage_info['fk_other'] => $values[$i]
							);

							$sql_update = get_sql_update($linkage_info['table'], $TABLES[$linkage_info['table']],
								$inline_details['columns'], $inline_params, $pk_values);

							#debug_log($sql_update, $inline_params);
							// prep & exec
							$details_upd = $db->prepare($sql_update);
							if($details_upd === false)
								proc_info("Failed to prepare update of details of relation to record {$values[$i]} in field {$table['fields'][$field_name]['label']}", $db);
							else if($details_upd->execute($inline_params) === false)
								proc_info("Failed to execute update of details of relation to record {$values[$i]} in field {$table['fields'][$field_name]['label']}", $db);
						}

						// already exists, needs to be removed from values
						unset($many2many_field_assocs[$field_name][$i]);
					}
				}
			}
		}

		// THEN LINK THE NEW VALUES FOR THE n:n TABLES
		foreach($many2many_field_assocs as $field => $values) {
			if(count($values) == 0)
				continue;

			$field_info = $table['fields'][$field];

			$ins_fields = array();
			$ins_values = array();

			if(!defined('POS_FK_OTHER'))
				define('POS_FK_OTHER', 0); // convention: fk_other must be at the beginning POSITION = 0, so for each SQL execution later the foreign key value can be set at array index 0
			if(!defined('POS_FK_SELF'))
				define('POS_FK_SELF', 1);

			$ins_fields[POS_FK_OTHER] = db_esc($field_info['linkage']['fk_other']);
			$ins_values[POS_FK_OTHER] = ''; // will be replaced during execution

			// TODO UPDATE FOR COMPOSITE FK_SELF & FK_OTHER -- NOTE POSITION MATTTERS: fk_self MUST BE AT POSITION = 1
			$ins_fields[POS_FK_SELF] = db_esc($field_info['linkage']['fk_self']);
			$ins_values[POS_FK_SELF] = first(array_values($primary_keys));

			// defaults of n:m linkage tables
			$default_fields = array();
			$default_values = array();
			if(isset($field_info['linkage']['defaults']) && is_array($field_info['linkage']['defaults'])) {
				foreach($field_info['linkage']['defaults'] as $def_field => $def_value) {
					$default_fields[] = db_esc($def_field);
					$default_values[] = get_default($def_value);
				}
			}

			$default_fields = array_merge($ins_fields, $default_fields);
			$default_params = array_merge($ins_values, $default_values);
			$default_placeholders = array_fill(0, count($default_params), '?');

			$default_sql = sprintf('INSERT INTO %s (%s) VALUES (%s)',
				db_esc($field_info['linkage']['table']),
				implode(', ', $default_fields),
				implode(', ', $default_placeholders));

			$default_stmt = $db->prepare($default_sql);
			if($default_stmt === false)
				return proc_error('SQL linkage statement with default values preparation failed.', $db);

			foreach($values as $value) { //TODO UPDATE FOR COMPOSITE FK_SELF & FK_OTHER
				// see whether inline linkage details are available
				$inline_details = get_inline_linkage_details($form_id, $field, $value,
					array($field_info['linkage']['fk_self'] => $ins_values[POS_FK_SELF]));

				if($inline_details === false) {
					// no details available, continue with default values
					$default_params[POS_FK_OTHER] = $value;

					#debug_log("Default linkage: $default_sql", $default_params);
					if($default_stmt->execute($default_params) === false)
						proc_info("Record was stored, but could not set related record $value for '$field_name'", $db);
				}
				else {
					// we do have additional info, so we need to prepare and exec a different SQL statement
					$ins_values[POS_FK_OTHER] = $value;

					$sql_insert = get_sql_insert($field_info['linkage']['table'], $TABLES[$field_info['linkage']['table']],
						$inline_details['columns']);

					#debug_log("Inline details for $field, linked item $value := ", $inline_details, "\n   SQL: $sql_insert");

					// execute the SQL
					$details_stmt = $db->prepare($sql_insert);
					if($details_stmt === false)
						proc_info("Preparation of updating of association details in field {$table['fields'][$field]['label']} with record #{$value} failed", $db);
					else if($details_stmt->execute($inline_details['params']) === false)
						proc_info("Updating of association details in field {$table['fields'][$field]['label']} with record #{$value} failed", $db);
				}
			}
		}

		// call 'after_insert/update' hook functions
		if($_GET['mode'] == MODE_NEW && isset($table['hooks']) && isset($table['hooks']['after_insert']))
			$table['hooks']['after_insert']($table_name, $table, $primary_keys);
		else if($_GET['mode'] == MODE_EDIT && isset($table['hooks']) && isset($table['hooks']['after_update']))
			$table['hooks']['after_update']($table_name, $table, $primary_keys);

		// here we are done and can clear the form from the session --> TEST
		// unset($_SESSION[$form_id]);

		if(is_popup()) {
			$key = $table['primary_key']['columns'][0];

			$_SESSION['redirect'] = sprintf('?mode=%s&table=%s&lookup_table=%s&lookup_field=%s&pk_value=%s',
				MODE_CREATE_DONE, $table_name, $_GET['popup'], $_GET['lookup_field'], isset($_POST[$key]) ? $_POST[$key] : $primary_keys[$key]);

			return true;
		}

		if(isset($_GET['special']) && $_GET['special'] == SPECIAL_EDIT_LINKED_RECORD) {
			$pk_val = first(array_values($primary_keys)); // FIXME: works only with single primary keys.
			$source_table = $_GET['source_table'];
			$source_field = $_GET['source_field'];
			$lookup_settings = &$TABLES[$source_table]['fields'][$source_field]['lookup'];
			$raw_label_sql = sprintf('select %s from %s t where %s = ?',
				resolve_display_expression($lookup_settings['display'], 't'),
				db_esc($table_name),
				db_esc(first(array_keys($primary_keys)))
			);
			if(!db_get_single_val($raw_label_sql, array($pk_val), $raw_label))
				return proc_error('Something went wrong when retrieving the updated record. It may have been deleted in the meantime.');
			$label_html = "'" . str_replace("'", "\\'", format_lookup_item_label($raw_label, $lookup_settings, $pk_val, 'html', true)) . "'";
			echo <<<JS
			<script>
				do {
					if(!window.opener)
						break;
					var edited_item_div = $(window.opener.document).find('.multiple-select-item[data-field="{$source_field}"][data-id-other="{$pk_val}"]');
					if(edited_item_div.length == 0)
						break;
					// set display label of linked item
					edited_item_div.find('.multiple-select-text').html($label_html);
					window.close();
				} while(false);
			</script>
JS;
			return true;
		}

		proc_success(sprintf('Record %s in the database.',
			$_GET['mode'] == MODE_NEW ? 'stored' : 'updated')); // success

		if(!isset($_SESSION['redirect'])) {
			$new_keys = array();
			foreach($table['primary_key']['columns'] as $pk)
				$new_keys[$pk] = isset($_POST[$pk]) ? $_POST[$pk] : $primary_keys[$pk];

			$_SESSION['redirect'] = "?table={$table_name}&mode=".MODE_VIEW. '&' . http_build_query($new_keys);
		}

		return true;
	}
?>
