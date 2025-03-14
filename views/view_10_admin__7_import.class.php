<?php
class View_Admin__Import extends View
{
	private $_stage = 'begin';
	private $_sess = NULL;
	private $_dummy_family = NULL;
	private $_dummy_person = NULL;
	private $_captured_errors = Array();
	private $_error_index = 0;
	private $_groups = Array();

	static function getMenuPermissionLevel()
	{
		return PERM_SYSADMIN;
	}

	function getTitle()
	{
		return 'Import Persons';
	}

	function printView()
	{
		switch ($this->_stage) {
			case 'begin':
				$this->_printBeginView();
				break;

			case 'confirm':
				$this->_printConfirmView();
				break;

			case 'done':
				break;
		}
	}

	function processView()
	{
		$this->_sess =& $_SESSION['import'];

		$GLOBALS['system']->includeDBClass('family');
		$GLOBALS['system']->includeDBClass('person');
		$GLOBALS['system']->includeDBClass('person_group');
		$GLOBALS['system']->includeDBClass('congregation');
		$GLOBALS['system']->includeDBClass('person_note');

		if (!empty($_REQUEST['done'])) {
			$this->_stage = 'done';
		} else if (!empty($_POST['confirm_import'])) {
			$this->_processImport();
		} else if (!empty($_FILES['import'])) {
			$this->_preparePreview();
		}
	}


	private function _printBeginView()
	{
		$text = _('This page allows you to import persons, families, groups and notes from a spreadsheet containing one person per row.  You can upload a CSV file or paste tab-separated text.
			The data must be formatted like this sample file.  (Correct column headers are important, but column order is flexible). 
			Jethro treats successive rows as members of the same family, unless (a) the family name or family details are different or (b) there is a blank row in between.
			Along with the person and family details, a "note" column allows you to add a note to the person record, and "group" columns allow you to add the person to new or existing groups. 
			If you choose the "update" option below, Jethro will try to update existing persons/families rather than creating new ones.  If an existing person is matched by an import row, surrounding rows may be imported as new members of that family if applicable.');
		$s = _('sample file');
		$text = str_replace($s, '<a href="?call=sample_import">'.$s.'</a>', $text);
		$text = '<p class="text">'.str_replace("\n", '</p><p class="text">', $text);
		print_message($text, 'info', true);
		?>
		<form method="post" enctype="multipart/form-data">
		<table class="table">
			<tr>
				<th class="narrow">Import Data&nbsp</th>
				<td>
					<label>
						<input type="radio" name="data_source" value="upload" data-toggle="visible" data-target=".importsource" data-match-attr="data-source" 
							<?php if (array_get($_REQUEST, 'data_source', 'upload') == 'upload') echo 'checked="checked"'; ?> />
						Upload file&nbsp;
						<input class="importsource" data-source="upload" type="file" name="import"
							<?php if (array_get($_REQUEST, 'data_source', 'upload') != 'upload') echo 'style="display:none"'; ?> />
					</label>
					<label>
						<input type="radio" name="data_source" value="input" data-toggle="visible" data-target=".importsource" data-match-attr="data-source" 
							<?php if (array_get($_REQUEST, 'data_source', 'upload') == 'input') echo 'checked="checked"'; ?> />
						Paste text...<br />
						<textarea style="width: 50ex; height: 6ex;
										<?php if (array_get($_REQUEST, 'data_source', 'upload') != 'input') echo 'display:none'; ?>
							" name="importdata" class="importsource" data-source="input" placeholder="Paste Tab-separated text here"></textarea>
					</label>
				</td>
			</tr>
			<tr>
				<th>Group</th>
				<td>
					<label>
						<input type="radio" name="group_type" value="new" 
							<?php if (array_get($_REQUEST, 'group_type', 'new') == 'new') echo 'checked="checked"'; ?>
							data-toggle="visible" data-target=".grouptype" data-match-attr="data-group-source"  />
						Create a new group
						<span class="grouptype" data-group-source="new"
							<?php if (array_get($_REQUEST, 'group_type', 'new') != 'new') echo 'style="display:none"'; ?>	>
							called
							<input type="text" name="new_group_name" value="<?php echo ents(array_get($_REQUEST, 'new_group_name', '')); ?>"/>
							in category
							<?php
							Person_Group_Category::printChooser('new_group_categoryid', array_get($_REQUEST, 'new_group_categoryid'));
							?>
						</span>
					</label>
					<label>
						<input type="radio" name="group_type" value="existing"
						<?php if (array_get($_REQUEST, 'group_type', 'new') == 'existing') echo 'checked="checked"'; ?>
						data-toggle="visible" data-target=".grouptype" data-match-attr="data-group-source" />
						Add to an existing group
						<span class="grouptype" data-group-source="existing"
							<?php if (array_get($_REQUEST, 'group_type', 'new') != 'existing') echo 'style="display:none"'; ?>	>
							<?php Person_Group::printChooser('groupid', array_get($_REQUEST, 'groupid', 0)); ?>
						</span>
					</label>											
					<p class="smallprint">All created/updated persons will be added to this group as a record of this import</p>
				</td>
			</tr>
			<tr>
				<th>Options</th>
				<td>
					<label class="checkbox">
						<input type="checkbox" name="match_existing" value="1" <?php if (array_get($_REQUEST, 'match_existing', 1)) echo 'checked="checked"';?> data-toggle="enable" data-target="#match-options *"/>
						Update existing persons if their first and last name match
					</label>
					<div class="indent-left" id="match-options">
						<label class="checkbox">
							<input type="checkbox" name="match_email" value="1" <?php if (array_get($_REQUEST, 'match_email', 0)) echo 'checked="checked"';?>/>
							Don't match if email address is different
						</label>
						<label class="checkbox">
							<input type="checkbox" name="match_mobile_tel" value="1" <?php if (array_get($_REQUEST, 'match_mobile_tel', 0)) echo 'checked="checked"';?>/>
							Don't match if mobile number is different
						</label>
						If a field value differs,
						<label class="checkbox" checked="checked">
							<input type="radio" name="overwrite_existing" <?php if (array_get($_REQUEST, 'overwrite_existing', 1)) echo 'checked="checked"';?> value="1" />
							use the value from the import file
						</label>
						<label class="checkbox">
							<input type="radio" name="overwrite_existing" <?php if (!array_get($_REQUEST, 'overwrite_existing', 1)) echo 'checked="checked"';?> value="0" />
							preserve the existing value in Jethro
						</label>
					</div>
				</td>
			</tr>
			<tr>
				<td>&nbsp;</td>
				<td>
					<input type="submit" class="btn" value="Continue &raquo;" /> 
					<p class="smallprint">(<?php echo _('You will be asked to confirm at the next step'); ?>)</p>
				</td>
			</tr>
		</table>
		</form>
		<?php
	}


	private function _preparePreview()
	{
		switch ($_REQUEST['group_type']) {
			case 'existing':
				if (empty($_REQUEST['groupid'])) {
					add_message(_("You must choose a group first"), 'error');
					$this->_stage = 'begin';
					return;
				}
				break;
			case 'new':
				if (!strlen(array_get($_REQUEST, 'new_group_name'))) {
					add_message(_("You must enter a name for the new group"), 'error');
					$this->_stage = 'begin';
					return;
				}
				break;
		}
		
		switch ($_REQUEST['data_source']) {
			case 'upload':
				if (empty($_FILES['import']) || empty($_FILES['import']['tmp_name'])) {
					add_message(_("You must upload a file"), 'error');
					return;
				}
				$datafile = $_FILES['import']['tmp_name'];
				$separator = ",";
				break;
			case 'input':
				if (!strlen($_REQUEST['importdata'])) {
					add_message(_("You must enter some import data"), 'error');
					return;
				}
				$datafile = tempnam(sys_get_temp_dir(), 'jethroimport');
				$separator = "\t";
				file_put_contents($datafile, $_REQUEST['importdata']);
				break;
			default:
				trigger_error("Invalid data_source value");
		}
		
		$this->_dummy_family = new Family();
		$this->_dummy_person = new Person();
		$family_fields = Array('family_name', 'home_tel', 'address_street', 'address_suburb', 'address_state', 'address_postcode');
		$_SESSION['import'] = Array();

		$this->_sess['overwrite_existing'] = array_get($_REQUEST, 'overwrite_existing', 1);
		$this->_sess['groupid'] = (int)array_get($_POST, 'groupid'); // the group for every new/updated person to go in
		$this->_sess['new_group_name'] = array_get($_POST, 'new_group_name'); // the group for every new/updated person to go in
		$this->_sess['new_group_categoryid'] = (int)array_get($_POST, 'new_group_categoryid'); // the group for every new/updated person to go in

		// @var existing groups mentioned in a 'group' column (name => id)
		$this->_sess['matched_groups'] = Array();

		// @var names new groups to be created, mentioned in a 'group' column
		$this->_sess['new_groups'] = Array();

		// @var New families to be created, including an array of family members
		$this->_sess['new_families'] = Array();

		// @var Count of new persons
		$this->_sess['total_new_persons'] = 0;

		// @var New persons to be added to existing families, with ['familyid'] set.
		$this->_sess['new_persons'] = Array();

		// @var Existing persons to be updated, keyed by personid
		// Format ready for ->fromCSVRow plus _note and _groups.
		$this->_sess['person_updates'] = Array();

		// @var Existing families to be updated, keyed by familyid.
		// Format ready for ->fromCSVRow plus _note and _groups.
		$this->_sess['family_updates'] = Array();

		// @var Ids => names of the custom fields in play in this import (so we know what to display)
		$this->_sess['used_custom_fields'] = Array();

		$custom_fields = Array();
		foreach (Person::getCustomFields() as $id => $detail) {
			$custom_fields[self::_stringToKey($detail['name'])] = $id;
		}

		// read the csv and save to session
		$fp = fopen($datafile, 'r');
		if (!$fp) {
			add_message(_("There was a problem reading your CSV file.  Please try again."), 'error');
			$this->stage = 'begin';
			return;
		}
		$map = fgetcsv($fp, 0, $separator, '"');
		$sample_header = self::getSampleHeader();
		foreach ($map as $k => $v) {
			if ($v == '') continue;
			$v = self::_stringToKey($v);
			if (!in_array($v, $sample_header)) {
				add_message("Unrecognised column \"".$v.'" will be ignored', 'warning');
			}
			$map[$k] = $v;
			if ($cfid = array_get($custom_fields, $v)) {
				$this->_sess['used_custom_fields'][$cfid] = $v;
			}
		}
		$has_required_cols = TRUE;
		foreach (self::getSampleHeader(TRUE) as $hcol) {
			if (!in_array($hcol, $map)) {
				add_message("Your file must contain a $hcol column", 'error');
				$has_required_cols = FALSE;
			}
		}
		if (!$has_required_cols) {
			$this->_stage = 'begin';
			return;
		}

		$row_errors = Array();
		$current_new_family_data = Array();
		$current_existing_family_data = NULL;

		$i = 0;
		while ($rawrow = fgetcsv($fp, 0, $separator, '"')) {
			$i++;
			$row = Array();
			foreach ($map as $index => $fieldname) {
				if ($fieldname == 'group') {
					if (strlen($g = array_get($rawrow, $index, ''))) {
						$row['_groups'][] = $g;
						$gk = self::_stringToKey($g);
						if (!isset($this->_sess['matched_groups'][$gk])) {
							if ($gp = Person_Group::findByName($g)) {
								$this->_sess['matched_groups'][$gk] = $gp->id;
							} else {
								$this->_sess['new_groups'][$gk] = $g;
							}
						}
					}
				} else if ($fieldname == 'note') {
					if (strlen($n = array_get($rawrow, $index, ''))) $row['_note'] = $n;
				} else {
					$row[$fieldname] = trim(array_get($rawrow, $index, ''));
				}
			}

			if ($this->_isEmptyRow($row)
					|| (empty($current_existing_family_data) && $this->_isNewFamily($row, $current_new_family_data))
					|| (empty($current_new_family_data) && $this->_isNewFamily($row, $current_existing_family_data))
			) {
				// Start a new family for the next row
				if (!empty($current_new_family_data['members'])) {
					$this->_sess['new_families'][] = $current_new_family_data;
				}
				$current_new_family_data = Array();
				$current_existing_family_data = Array();
			}
			if ($this->_isEmptyRow($row)) {
				continue;
			}

			$ei = $i;
			$name = array_get($row, 'first_name', '').' '.array_get($row, 'last_name', '');
			if (strlen(trim($name))) $ei .= ' ('.$name.')';
			$this->_captureErrors($ei);

			if (!empty($row['congregation'])) {
				$row['congregationid'] = Congregation::findByName($row['congregation']);
			}

			$family_row = $this->_filterArrayKeys($row, $family_fields);
			$person_row = $this->_arrayDiffAssoc($row, $family_row);

			if ($existingPerson = $this->_findExistingPerson($row)) {
				// SCENARIO 1 - WE ARE UPDATING AN EXISTING PERSON (AND FAMILY)

				// Try updating the person fields
				$person_row['first_name'] = $existingPerson->getValue('first_name'); // avoid case munging
				$person_row['last_name'] = $existingPerson->getValue('last_name'); // avoid case munging
				$existingPerson->fromCsvRow($person_row, $this->_sess['overwrite_existing']);

				// Update the family fields (looking out for family import data already in play)
				if (!empty($current_new_family_data['members'])) {
					// a previous import row started creating a new family.
					// put the existing family members in the list of new members for this existing family
					foreach ($current_new_family_data['members'] as $member) {
						$member['familyid'] = $existingPerson->getValue('familyid');
						$this->_sess['new_persons'][] = $member;
					}
					unset($current_new_family_data['members']);

					// Grab any family data supplied in the previous rows
					$this->_pushIntoArray($current_new_family_data, $family_row);
					$current_new_family_data = Array();
				}

				// Try updating the family fields
				$family_row['status'] = 'current';
				$familyObj = $GLOBALS['system']->getDBObject('family', $existingPerson->getValue('familyid'));
				$family_row['family_name'] = $familyObj->getValue('family_name'); // avoid case munging
				$familyObj->fromCsvRow($family_row, $this->_sess['overwrite_existing']);
				if (empty($current_existing_family_data) || ($existingPerson->getValue('familyid') != $current_existing_family_data['id'])) {
					$current_existing_family_data = $family_row + Array('id' => $familyObj->id);
				} else {
					$this->_pushIntoArray($family_row, $current_existing_family_data);
				}

				if (!$this->_haveErrors($i)) {
					if (isset($this->_sess['person_updates'][$existingPerson->id])) {
						// a previous import row also matched this same person.
						// Later rows take precedence over earlier rows, but data should be combined where possible (eg groups).
						$already_groups = array_get($this->_sess['person_updates'][$existingPerson->id], '_groups', Array());
						$this->_pushIntoArray($this->_sess['person_updates'][$existingPerson->id], $person_row);
						$this->_sess['person_updates'][$existingPerson->id] = $person_row;
						if (!empty($person_row['_groups'])) {
							$this->_sess['person_updates'][$existingPerson->id]['_groups'] = array_unique(array_merge($already_groups, $person_row['_groups']));
						}

					} else {
						$this->_sess['person_updates'][$existingPerson->id] = $person_row;
					}
					$this->_sess['family_updates'][$existingPerson->getValue('familyid')] = $current_existing_family_data;
				}
			} else {

				// SCENARIO 2 - WE ARE CREATING A NEW PERSON,

				// Try pulling details into person object - will throw errors on bad data
				$this->_dummy_person->reset();
				$this->_dummy_person->setValue('familyid', '-1');
				$this->_dummy_person->fromCsvRow($person_row);

				$this->_dummy_family->reset();
				$this->_dummy_family->setValue('status', 'current');
				if (!empty($current_existing_family_data)) {
					// 2A) THE NEW PERSON IS TO BE ADDED TO AN EXISTING FAMILY

					// Add to the existing family data any extra details supplied in this row
					$this->_pushIntoArray($family_row, $current_existing_family_data);

					// Try setting values - will throw errors if bad data
					$this->_dummy_family->fromCsvRow($current_existing_family_data);

					if (!$this->_haveErrors($i)) {
						$person_row['familyid'] = $current_existing_family_data['id'];
						$this->_sess['new_persons'][] = $person_row;
						$this->_sess['family_updates'][$current_existing_family_data['id']] = $current_existing_family_data;
					}
				} else if (!empty($current_new_family_data)) {
					// 2B) THE NEW PERSON IS TO BE ADDED TO A NEW FAMILY ALREADY STARTED IN THIS IMPORT

					// Add to the new family data any extra details supplied in this row
					$this->_pushIntoArray($family_row, $current_new_family_data);

					// Try setting values - will throw errors if bad data
					$this->_dummy_family->fromCsvRow($current_new_family_data);

					if (!$this->_haveErrors($i)) {
						$current_new_family_data['members'][] = $person_row;
					}


				} else {
					// 2C) THE NEW PERSON IS TO BE ADDED TO A WHOLE NEW FAMILY NOT YET SEEN IN THIS IMPORT

					$current_new_family_data = $family_row;

					// Try setting values - will throw errors if bad data
					$this->_dummy_family->fromCsvRow($current_new_family_data);

					if (!$this->_haveErrors($i)) {
						$current_new_family_data = $family_row;
						$current_new_family_data['members'][] = $person_row;
					}
				}
			}
		}

		// Add any outstanding new family to the pile
		if (!empty($current_new_family_data['members'])) {
			$this->_sess['new_families'][] = $current_new_family_data;
		}

		foreach ($this->_sess['new_families'] as $f) {
			$this->_sess['total_new_persons'] += count($f['members']);
		}

		$row_errors = $this->_getErrors();
		if (!empty($row_errors)) {
			$msg = _('Your import file is not valid.  Please correct the following errors and try again:').'<ul>';
			foreach ($row_errors as $line => $errors) {
				$msg .= '<li>Row '.($line).': '.implode('; ', $errors).'</li>';
			}
			$msg .= '</ul>';
			add_message($msg, 'failure', true);
			$this->_stage = 'begin';
		} else {
			$this->_stage = 'confirm';
		}
		unlink($datafile);

	}


	private function _printConfirmView()
	{
		if (($this->_sess['groupid'])) {
			$groupname = $GLOBALS['system']->getDBObject('person_group', $_SESSION['import']['groupid'])->toString();
		} else {
			$groupname = $this->_sess['new_group_name'];
		}
		$GLOBALS['system']->includeDBClass('family');
		$this->_dummy_family = new Family();
		?>
		<p class="alert alert-info text">
			Your import is ready to run. Check the details below then click "Proceed" at the bottom.
		</p>
		<h2>Summary</h2>
		<table class="table table-bordered table-auto-width">
			<tr>
				<td>New families to be created</td>
				<td><?php echo count($this->_sess['new_families']); ?></td>
			</tr>
			<tr>
				<td>New persons to be created</td>
				<td>
					<?php
					$printed = FALSE;
					if ($this->_sess['total_new_persons']) {
						echo $this->_sess['total_new_persons'].' within new families';
						$printed = TRUE;
					}
					if ($np = count($this->_sess['new_persons'])) {
						if ($printed) echo '<br />';
						echo $np.' within existing families';
						$printed = TRUE;
					}
					if (!$printed) echo '0';
					?>
				</td>
			</tr>
			<tr>
				<td>Existing families to be updated</td>
				<td><?php echo count($this->_sess['family_updates']); ?></td>
			</tr>
			<tr>
				<td>Existing persons to be updated</td>
				<td><?php echo count($this->_sess['person_updates']); ?></td>
			</tr>
		<?php
		if (!empty($this->_sess['new_groups']) || !empty($this->_sess['matched_groups'])) {
			?>
			<tr>
				<td>New groups to be created</td>
				<td><?php echo count($this->_sess['new_groups']); ?></td>
			</tr>
			<tr>
				<td>Existing groups to have members added</td>
				<td><?php echo count($this->_sess['matched_groups']); ?></td>
			</tr>
			<?php
		}
		?>
		</table>
		<?php
		printf('<p>'._('All new and updated persons will be added to the %s group.').'</p>', '<i>'.ents($groupname).'</i>');

		if (!empty($this->_sess['new_groups'])) {
			?>
			<h3>New groups to be created</h3>
			<ul>
				<li>
					<?php echo implode('</li><li>', $this->_sess['new_groups']); ?>
				</li>
			</ul>
			<?php
		}

		if (!empty($this->_sess['new_families'])) {
			?>
			<h3>New families and persons to be created</h3>
			<table class="table table-bordered">
				<thead>
					<tr>
						<th>Family Name</th>
						<th>Home Tel</th>
						<th>Address</th>

						<?php
						$this->_printPersonHeaders();
						?>

					</tr>
				</thead>
				<tbody>
				<?php
				foreach ($this->_sess['new_families'] as $familydata) {
					$rowspan = 'rowspan="'.count($familydata['members']).'"';
					?>
					<tr>
						<td <?php echo $rowspan; ?> class="nowrap"><?php echo ents(array_get($familydata, 'family_name')); ?></td>
						<td <?php echo $rowspan; ?> class="nowrap"><?php echo ents(array_get($familydata, 'home_tel')); ?></td>
						<td <?php echo $rowspan; ?> class="nowrap">
							<?php
							if (!empty($familydata['address_street'])) {
								echo nl2br(ents($familydata['address_street']));
								echo '<br />'.$familydata['address_suburb'].' '.$familydata['address_state'].' '.$familydata['address_postcode'];
							}
							?>
						</td>
					<?php
					$this->_printPersonRows($familydata['members'], TRUE);
				}
				?>
				</tbody>
			</table>
			<?php
		}

		if (!empty($this->_sess['new_persons'])) {
			?>

			<h3>New persons to be added to existing families</h3>
			<table class="table table-bordered">
				<thead>
					<tr>
						<th>Family</th>
						<?php
						$this->_printPersonHeaders();
						?>
					</tr>
				</thead>
				<tbody>
				<?php
				$this->_printPersonRows($this->_sess['new_persons'], FALSE, TRUE)
				?>
				</tbody>
			</table>
			<?php
		}

		if (!empty($this->_sess['family_updates'])) {
			?>
			<h3>Existing families to be updated</h3>
			<table class="table table-bordered table-auto-width">
				<thead>
					<tr>
						<th>ID</th>
						<th>Family Name</th>
						<th>Home Tel</th>
						<th>Address</th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ($this->_sess['family_updates'] as $id => $familydata) {
					?>
					<tr>
						<td>
							<a href="?view=families&familyid=<?php echo (int)$id; ?>" class="med-popup">#<?php echo (int)$id; ?></a>
						</td>
						<td class="nowrap"><?php echo ents(array_get($familydata, 'family_name')); ?></td>
						<td class="nowrap"><?php echo ents(array_get($familydata, 'home_tel')); ?></td>
						<td class="nowrap">
							<?php
							if (!empty($familydata['address_street'])) {
								echo nl2br(ents($familydata['address_street']));
								echo '<br />'.$familydata['address_suburb'].' '.$familydata['address_state'].' '.$familydata['address_postcode'];
							}
							?>
						</td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>
			<?php
		}

		if (!empty($this->_sess['person_updates'])) {
			?>
			<h3>Existing persons to be updated</h3>
			<table class="table table-bordered table-auto-width">
				<thead>
					<tr>
						<th>ID</th>
						<?php $this->_printPersonHeaders(); ?>
					</tr>
				</thead>
				<tbody>
				<?php
				$this->_printPersonRows($this->_sess['person_updates'], FALSE, FALSE, TRUE);
				?>
				</tbody>
			</table>
			<?php
		}
		?>

		<form method="post"><input type="submit" name="confirm_import" value="Proceed with import" class="confirm-title btn btn-danger" title="Proceed with import" />
		<a href="<?php echo build_url(array()); ?>" class="btn">Cancel and start again</a>
		</form>
		<?php
	}


	private function _processImport()
	{
		ini_set('memory_limit', '256M');
		ini_set('max_execution_time', 60*10);
		ini_set('zlib.output_compression', 'Off');
		include_once 'templates/head.template.php';
		
		// read from session and create
		$GLOBALS['system']->doTransaction('BEGIN');
		$done = 0;
		$todo = $this->_sess['total_new_persons'] + count($this->_sess['new_persons']) + count($this->_sess['family_updates']) + count($this->_sess['person_updates'])
		?>
		
		<h1 style="position: absolute; text-align: center; top: 40%; color: #ccc; width: 100%">Importing...</h1>
		<div style="border: 1px solid; width: 50%; height: 30px; top: 50%; left: 25%; position: absolute"><div id="progress" style="background: blue; height: 30px; width: 2%; overflow: visible; line-height: 30px; text-align: center; color: white" /></div>
		<p style="text-align: center; color: #888">If this indicator stops making progress, your import may still be running in the background.<br />You should <a href="<?php echo build_url(Array('view' => 'persons__list_all')); ?>">check your system for the imported persons</a> before running the import again.</p>
		<?php

		// Create new groups and load up existing groups to be ready to receive persons.
		foreach ($this->_sess['new_groups'] as $key => $name) {
			$g = new Person_Group();
			$g->setValue('name', $name);
			$g->setValue('categoryid', NULL);
			if (!$g->create()) {
				trigger_error("Could not create group $name");
				exit;
			}
			$this->_groups[$key] = $g;
		}
		foreach ($this->_sess['matched_groups'] as $key => $groupid) {
			$this->_groups[$key] = new Person_Group($groupid);
		}

		$this->_captureErrors();

		foreach ($this->_sess['new_families'] as $familyrow) {
			$members = $familyrow['members'];
			unset($familyrow['members']);
			$family = new Family();
			$family->fromCSVRow($familyrow);
			if (!$family->create()) {
				trigger_error('Family: '.$familydata['family_name']." not created");
				continue;
			}
			foreach ($members as $member) {
				$member['familyid'] = $family->id;
				$person = new Person();
				$person->fromCSVRow($member);
				if (!$person->create()) {
					$results[] = "Person: ".$member['first_name'].' '.$member['last_name'].' not created';
					continue;
				}
				$this->_finalisePerson($person, $member); // adds note and group
				$this->_printProgress($done++, $todo);
				unset($person);
			}

		}
		foreach ($this->_sess['new_persons'] as $row) {
			if (!isset($row['gender'])) $row['gender'] = 'Unknown';
			$person = new Person();
			$person->fromCSVRow($row);
			if (!$person->create()) {
				trigger_error("Person: ".$person['first_name'].' '.$person['last_name'].' not created', E_USER_WARNING);
				continue;
			}
			$this->_finalisePerson($person, $row); // adds note and group
			$this->_printProgress($done++, $todo);
			unset($person);
		}
		foreach ($this->_sess['family_updates'] as $familyid => $familyrow) {
			$family = new Family($familyid);
			if (!$family->acquireLock()) {
				trigger_error("Could not update ".$familydata['family_name'].' because another user is editing it. Please try again later.', E_USER_WARNING);
				continue;
			}
			$family->fromCSVRow($familyrow, $this->_sess['overwrite_existing']);
			if (!$family->save()) {
				trigger_error('Family: '.$familydata['family_name']." not updated");
			}
			$family->releaseLock();
			$this->_printProgress($done++, $todo);
			unset($family);
		}
		foreach ($this->_sess['person_updates'] as $personid => $row) {
			$person = new Person($personid);
			if (!$person->acquireLock()) {
				trigger_error("Could not update ".$person->toString().' because another user is editing them. Please try again later.', E_USER_WARNING);
				continue;
			}
			$person->fromCSVRow($row, $this->_sess['overwrite_existing']);
			if (!$person->save()) {
				trigger_error("Person: ".$row['first_name'].' '.$row['last_name'].' not created', E_USER_WARNING);
				continue;
			}
			$this->_finalisePerson($person, $row); // adds note and group
			$this->_printProgress($done++, $todo);
		}

		$url = '?view='.$_REQUEST['view'];
		if ($errors = $this->_getErrors()) {
			$msg = _('Errors during import - import aborted').'. <ul><li>'.implode('</li></li>', $errors[0]).'</li></ul>';
			add_message($msg, 'failure', true);
			$GLOBALS['system']->doTransaction('ROLLBACK');
		} else {
			add_message(_('Import complete'), 'success',TRUE);
			$GLOBALS['system']->doTransaction('COMMIT');
			if ($total_group = $this->_getTotalGroup()) {
				$url = build_url(Array('view' => 'groups', 'groupid' => $total_group->id));
			}
		}

		?><script>document.location = '<?php echo $url; ?>';</script>
		<?php
		exit;
	}



	//////////////////// PRIVATE HELPERS //////////////////////////////

	private function _printPersonHeaders()
	{
		?>
				<th>First Name</th>
				<th>Last Name</th>
				<th>Age</th>
				<th>Gender</th>
				<th>Congregation</th>
				<th>Status</th>
				<th>Email</th>
				<th>Mobile Tel</th>
				<th>Work Tel</th>
				<?php
				foreach ($this->_sess['used_custom_fields'] as $fieldid => $label) {
					?>
					<th><?php echo ents($label); ?></th>
					<?php
				}
				?>
				<th>Note</th>
				<th>To be added to groups</th>
		<?php
	}

	private function _printPersonRows($persons, $omitFirstTR=FALSE, $includeFamilyLink=FALSE, $includePersonLink=FALSE)
	{
		$first = TRUE;
		foreach ($persons as $key => $person) {
			if (!$first || !$omitFirstTR) {
				echo '<tr>';
			}
			$first = FALSE;

			if ($includeFamilyLink) {
				?>
				<td>
					<a href="?view=families&familyid=<?php echo (int)$person['familyid']; ?>" class="med-popup">#<?php echo (int)$person['familyid']; ?></a>
				</td>
				<?php
			}
			if ($includePersonLink) {
				?>
				<td>
					<a href="?view=persons&personid=<?php echo (int)$key; ?>" class="med-popup">#<?php echo (int)$key; ?></a>
				</td>
				<?php

			}
			?>
			<td><?php echo ents($person['first_name']); ?></td>
			<td><?php echo ents($person['last_name']); ?></td>
			<td><?php echo ents($person['age_bracket']); ?></td>
			<td><?php echo ents(array_get($person, 'gender')); ?></td>
			<td><?php echo ents(array_get($person, 'congregation')); ?></td>
			<td><?php echo ents($person['status']); ?></td>
			<td><?php echo ents(array_get($person, 'email')); ?></td>
			<td><?php echo ents(array_get($person, 'mobile_tel')); ?></td>
			<td><?php echo ents(array_get($person, 'work_tel')); ?></td>
			<?php
			foreach ($this->_sess['used_custom_fields'] as $fieldid => $label) {
				?>
				<td><?php echo ents(array_get($person, $label, '')); ?></td>
				<?php
			}
			?>
			<td><?php echo ents(array_get($person, '_note', '')); ?></td>
			<td><?php echo implode('; ', array_map('ents', array_get($person, '_groups', Array()))); ?></td>
			<?php
			echo '</tr>';
		}
	}

	private function _finalisePerson($person, $row)
	{
		$text = array_get($row, '_note', '');
		if (strlen($text)) {
			$note = new Person_Note();
			$note->setValue('subject', 'Import note');
			$note->setValue('details', $text);
			$note->setValue('personid', $person->id);
			$note->createIfNew();
		}

		if (!empty($row['_groups'])) {
			foreach ($row['_groups'] as $groupname) {
				$gkey = self::_stringToKey($groupname);
				$this->_groups[$gkey]->addMember($person->id);
			}
		}

		if ($total_group = $this->_getTotalGroup()) {
			$total_group->addMember($person->id);
		}


	}
	
	/**
	 * @return Person_Group that all new/updated persons should be added to
	 */
	private function _getTotalGroup()
	{
		static $total_group = NULL;
		if ($total_group === NULL) {
			$total_group = FALSE;
			if ($this->_sess['groupid']) {
				$total_group = new Person_Group($this->_sess['groupid']);
			} else if ($this->_sess['new_group_name']) {
				$total_group = new Person_Group();
				$total_group->setValue('name', $this->_sess['new_group_name']);
				if (!empty($this->_sess['new_group_categoryid'])) {
					$total_group->setValue('categoryid', $this->_sess['new_group_categoryid']);
				}
				$total_group->create();
			}
		}
		return $total_group;
	}
	
	private function _printProgress($done, $todo)
	{
		if ($done % 5 == 0) {
			$message = ((int)(($done/$todo)*100)).'% complete';
			?>
			<script>
				var d = document.getElementById('progress');
				d.innerHTML = "<?php echo ents($message); ?>";
				d.style.width = '<?php echo (int)(($done/$todo)*100); ?>%';
			</script>
			<?php
			echo str_repeat('    ', 1024*4);
		}
	}

	private function _filterArrayKeys($dataArray, $desiredKeys)
	{
		foreach ($dataArray as $k => $v) {
			if (!in_array($k, $desiredKeys)) unset($dataArray[$k]);
		}
		return $dataArray;
	}

	/**
	 * Returns an array with all the key-value pairs from $x where the key DOESN'T exist in $y
	 * @param type $x
	 * @param type $y
	 */
	private function _arrayDiffAssoc($x, $y)
	{
		foreach ($x as $k => $v) {
			if (array_key_exists($k, $y)) unset($x[$k]);
		}
		return $x;
	}


	private function _pushIntoArray($from, &$to) {
		foreach ($from as $k => $v) {
			if (is_string($v) && strlen($v) && array_key_exists($k, $to) && !strlen($to[$k])) {
				// $to has this element but it's empty
				$to[$k] = $v;
			}
		}
	}

	private static function _stringToKey($string)
	{
		return str_replace(' ', '_', strtolower($string));
	}


	private function _isEmptyRow($row)
	{
		foreach ($row as $x) {
			if (!empty($x)) return FALSE;
		}
		return TRUE;
	}

	private function _isNewFamily($row, $current_family)
	{
		foreach (Array('family_name', 'address_street', 'address_suburb', 'address_state', 'home_tel') as $field) {
			if (!empty($row[$field]) && !empty($current_family[$field])) {
				if ($field == 'home_tel') {
					if (preg_replace('/[^0-9]/', '', $row[$field]) != preg_replace('/[^0-9]/', '',$current_family[$field])) {
						return TRUE;
					}
				} else {
					if (strtolower($row[$field]) != strtolower($current_family[$field])) {
						return TRUE;
					}
				}
			}
		}
		return FALSE;
	}

	/**
	 * Look for an existing person record matching the supplied import row
	 * Looks at the match_existing, match_mobile_tel and match_email flags in the request.
	 * @param array $row Data from import CSV
	 * @return Person|null
	 */
	private function _findExistingPerson($row)
	{
		if (empty($_REQUEST['match_existing'])) return NULL;
		$params = Array(
					'first_name' => $row['first_name'],
					'last_name' => $row['last_name'],
				);
		$matches = $GLOBALS['system']->getDBObjectData('person', $params, 'AND');
		$row['mobile_tel'] = preg_replace('/[^0-9]/', '', array_get($row, 'mobile_tel', ''));
		foreach (Array('email', 'mobile_tel') as $fieldName) {
			if (!empty($_REQUEST['match_'.$fieldName]) && strlen(array_get($row, $fieldName, ''))) {
				foreach ($matches as $id => $details) {
					if (strlen($details[$fieldName]) && strtolower($row[$fieldName]) != strtolower($details[$fieldName])) {
						// existing and imported values are both non-blank and are different
						unset($matches[$id]);
					}
				}
			}
		}
		if (count($matches) > 1) {
			add_message('"'.$row['first_name'].' '.$row['last_name'].'" could not be matched to an existing record because there are already several persons with that name', 'warning');
		}
		if (count($matches) == 1) {
			reset($matches);
			return $GLOBALS['system']->getDBObject('person', key($matches));
		}

		if (count($matches) > 1) {

		}
		return NULL;
	}

	private function _captureErrors($i=0)
	{
		if (empty($this->_captured_errors)) $this->_captured_errors = Array();
		$this->_error_index = $i;
		set_error_handler(Array($this, '_handleError'));
	}

	public function _handleError($errno, $errstr, $errfile, $errline)
	{
		if (in_array($errno, array(E_USER_NOTICE, E_USER_WARNING))) {
			$this->_captured_errors[$this->_error_index][] = $errstr;
		} else {
			$GLOBALS['system']->_handleError($errno, $errstr, $errfile, $errline);
		}
	}

	private function _getErrors()
	{
		$res = $this->_captured_errors;
		$this->_captured_errors = Array();
		restore_error_handler();
		return $res;
	}

	private function _haveErrors($i)
	{
		return !empty($this->_captured_errors[$i]);
	}

	public static function getSampleHeader($required_fields_only=FALSE)
	{
		$header = Array(
			'family_name',
			'last_name',
			'first_name',
			'congregation',
			'status',
			'age_bracket',
		);
		if ($required_fields_only) {
			return $header;
		}
		$header = array_merge($header, Array(
			'gender',
			'email',
			'mobile_tel',
			'work_tel',
			'home_tel',
			'address_street',
			'address_suburb',
			'address_state',
			'address_postcode',
		));
		$custom_fields = Person::getCustomFields();
		foreach ($custom_fields as $field) {
			$header[] = self::_stringToKey($field['name']);
		}
		$header[] = 'note';
		$map = array_flip($header);
		$header[] = 'group';
		$header[] = 'group';
		$header[] = 'group';
		return $header;
	}

}
