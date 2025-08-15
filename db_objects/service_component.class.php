<?php
class Service_Component extends db_object
{
	protected $_load_permission_level = PERM_VIEWSERVICE;
	protected $_save_permission_level = PERM_EDITSERVICE;

	function __construct($id = 0)
	{
		parent::__construct($id);

		if (!empty($_REQUEST['categoryid'])) {
			$_SESSION['service_comp_categoryid'] = $_REQUEST['categoryid'];
		}
		if (empty($this->id) && !empty($_SESSION['service_comp_categoryid'])) {
			$this->values['categoryid'] = array_get($_SESSION, 'service_comp_categoryid');
		}
	}

	protected static function _getFields()
	{
		$fields = [
			'title' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
				'class' => 'autofocus',
				'allow_empty' => false,
			],
			'alt_title' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
				'placeholder' => '(Optional)',
			],
			'categoryid' => [
				'type' => 'reference',
				'references' => 'service_component_category',
				'label' => 'Category',
				'show_id' => false,
				'allow_empty' => false,
				'divider_above' => true,
			],
			'comments' => [
				'type' => 'text',
				'width' => 80,
				'height' => 3,
				'note' => 'Key, Tempo, Relevant URLs etc.',
				'add_links' => true,
			],
			'ccli_number' => [
				'label' => 'CCLI Number',
				'type' => 'int',
				'width' => 8,
				'allow_empty' => true,
			],
			'runsheet_title_format' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
				'placeholder' => '(Optional)',
				'note' => 'How should this component be shown on the run sheet.  Can include replacements such as the component\'s %title%, %SERVICE_TOPIC% or %NAME_OF_SOMEROSTERROLE%.  Leave blank to use the category\'s default.',
				'heading_before' => 'Run Sheet Appearance',
				'divider_before' => true,
				'label' => 'Run sheet format',
			],
			'personnel' => [
				'type' => 'text',
				'width' => 80,
				'placeholder' => '(Optional)',
				'note' => 'What to put in the run sheet\'s "personnel" column by default. Can include roster role keywords such as %SERVICE_LEADER%. Leave blank to use the category\'s default.',
			],
			'length_mins' => [
				'type' => 'int',
				'label' => 'Length (mins)',
				'default' => 0,
				'allow_empty' => false,
			],
			'show_in_handout' => [
				'type' => 'select',
				'options' => [
					'0' => 'None',
					'title' => 'Include Title only',
					'full' => 'Include Title and Content',
				],
				'label' => 'Handout Visibility',
				'editable' => true,
				'show_in_summary' => true,
				'note' => 'Items that appear in the service handout are given numbers on the run sheet.',
				'heading_before' => 'Service Handout Appearance',
				'divider_before' => true,
			],

			'handout_title_format' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
				'placeholder' => '(Optional)',
				'note' => 'How should this component be listed in the service handout.  Can include replacements such as the component\'s %title%, %SERVICE_TOPIC% or %NAME_OF_SOMEROSTERROLE%.  Leave blank to use the category\'s default.',
			],
			'show_on_slide' => [
				'type' => 'select',
				'options' => ['No', 'Yes'],
				'label' => 'Show on Slide',
				'editable' => false,
				'show_in_summary' => false,
			],
			'content_html' => [
				'type' => 'html',
				'label' => 'Content',
				'note' => 'When typing in lyrics, use Ctrl+Enter between lines and normal Enter between verses. Don\'t worry if pasted lyrics contain odd fonts etc; these will be stripped on save.',
			],
			'credits' => [
				'type' => 'text',
				'width' => 80,
				'height' => 3,
				'initial_cap' => true,
			],
		];

		return $fields;
	}

	public function getForeignKeys()
	{
		return [
			'categoryid' => '`service_component_category` (`id`) ON DELETE RESTRICT',
		];
	}

	public static function search($keyword, $tagid, $congregationid, $categoryid = null)
	{
		$conds = [];
		if (!empty($keyword)) {
			$conds['keyword'] = $keyword;
		}
		if (!empty($tagid)) {
			$conds['tagid'] = (int) $tagid;
		}
		if (!empty($congregationid)) {
			$conds['congregationid'] = (int) $congregationid;
		}
		if (!empty($categoryid)) {
			$conds['categoryid'] = (int) $categoryid;
		}

		return $GLOBALS['system']->getDBObjectData('service_component', $conds, 'AND', 'service_component.title');
	}

	/**
	 * Funny behaviour here:  tagid and congregationid are always ANDed even if $logic=or.
	 */
	function getInstancesQueryComps($params, $logic, $order)
	{
		if ($logic != 'OR') {
			$logic = 'AND';
		}
		$congid = array_get($params, 'congregationid');
		unset($params['congregationid']);

		$tagid = array_get($params, 'tagid');
		unset($params['tagid']);

		$keyword = array_get($params, 'keyword');
		unset($params['keyword']);

		$res = parent::getInstancesQueryComps($params, $logic, $order);

		foreach ($res['select'] as $k => $v) {
			if (substr($v, -12) == 'content_html') {
				unset($res['select'][$k]);
			}
		}
		$res['select'][] = 'GROUP_CONCAT(DISTINCT cong.name SEPARATOR ", ") as congregations';
		$res['from'] .= ' JOIN service_component_category cat ON cat.id = service_component.categoryid';
		$res['from'] .= ' LEFT JOIN congregation_service_component csc ON csc.componentid = service_component.id ';
		$res['from'] .= ' LEFT JOIN congregation cong ON cong.id = csc.congregationid ';
		$res['from'] .= ' LEFT JOIN service_item si ON si.componentid = service_component.id ';
		$res['from'] .= ' LEFT JOIN service svc ON svc.id = si.serviceid AND svc.congregationid = cong.id ';
		$res['from'] .= ' LEFT JOIN service svc12m ON svc12m.id = svc.id AND svc12m.date > NOW() - INTERVAL 12 MONTH ';
		$res['select'][] = 'IF (LENGTH(service_component.runsheet_title_format) = 0, cat.runsheet_title_format, service_component.runsheet_title_format) as runsheet_title_format ';
		$res['select'][] = 'IF (LENGTH(service_component.personnel) = 0, cat.personnel_default, service_component.personnel) as personnel ';
		$res['select'][] = 'COUNT(DISTINCT svc12m.id) AS usage_12m';
		$res['select'][] = 'MAX(svc.date) as lastused';
		$res['group_by'] = 'service_component.id';

		if ($res['where'] == '') {
			$res['where'] = '1=1';
		}
		$res['where'] = '('.$res['where'].') ';
		if ($congid === 0) {
			$res['where'] .= ' '.$logic.' cong.id IS NULL';
		} elseif ($congid !== null) {
			$res['where'] .= ' '.$logic.' cong.id = '.(int) $congid;
		} else {
			$res['where'] .= ' '.$logic.' cong.id IS NOT NULL';
		}
		if ($tagid) {
			$res['from'] .= ' LEFT JOIN service_component_tagging sct ON sct.componentid = service_component.id AND sct.tagid = '.(int) $tagid;
			$res['where'] .= ' '.$logic.' sct.tagid IS NOT NULL';
		}
		if ($keyword) {
			$qk = $GLOBALS['db']->quote("%{$keyword}%");
			$res['where'] .= ' '.$logic.' (service_component.title LIKE '.$qk.' OR alt_title LIKE '.$qk.' OR content_html LIKE '.$qk.')';
		}

		return $res;
	}

	public static function getAllByCCLINumber()
	{
		$SQL = 'SELECT ccli_number, id
				FROM service_component';
		$res = $GLOBALS['db']->queryAll($SQL, null, null, true, false);

		return $res;
	}

	protected function _printSummaryRows()
	{
		$oldFields = $this->fields;
		$this->fields = [];
		foreach ($oldFields as $k => $v) {
			$this->fields[$k] = $v;
			if ($k == 'categoryid') {
				$this->fields['congregationids'] = ['label' => 'Congregations'];
				$this->fields['tags'] = [];
			}
		}
		parent::_printSummaryRows();
		unset($this->fields['congregationids'], $this->fields['tags']);
	}

	public function printFieldValue($name, $value = null)
	{
		switch ($name) {
			case 'congregationids':
				$congs = $GLOBALS['system']->getDBObjectData('congregation_service_component', ['componentid' => $this->id], 'AND', 'meeting_time');
				$names = [];
				foreach ($congs as $cong) {
					$names[] = $cong['name'];
				}
				echo ents(implode(', ', $names));
				break;

			case 'tags':
				$tags = $GLOBALS['system']->getDBObjectData('service_component_tagging', ['componentid' => $this->id]);
				$names = [];
				foreach ($tags as $tag) {
					echo '<span class="label">'.ents($tag['tag']).'</span> ';
				}
				break;

			case 'ccli_number':
				if (defined('CCLI_DETAIL_URL') && ((int) $this->getValue($name) > 0)) {
					// Can't just use class=med-popup because it's loaded in an AJAX frame so the window.onload has already run
					echo '<a href="'.str_replace('__NUMBER__', $this->getValue($name), CCLI_DETAIL_URL).'" onclick="return TBLib.handleMedPopupLinkClick(this)">';
				}
				echo $this->getValue($name);
				if (defined('CCLI_DETAIL_URL') && ((int) $this->getValue($name) > 0)) {
					echo '</a>';
				}
				break;

			default:
				return parent::printFieldValue($name);
		}
	}

	function toString()
	{
		return $this->values['title'];
	}

	public function printForm($prefix = '', $fields = null)
	{
		$oldFields = $this->fields;
		$this->fields = [];
		foreach ($oldFields as $k => $v) {
			$this->fields[$k] = $v;
			$congs = $GLOBALS['system']->getDBObjectData('congregation', ['!meeting_time' => ''], 'AND');
			$options = [];
			foreach ($congs as $id => $detail) {
				$options[$id] = $detail['name'];
			}
			if ($k == 'categoryid') {
				$this->fields['congregationids'] = [
					'type' => 'select',
					'label' => 'Used By',
					'options' => $options,
					'order_by' => 'meeting_time',
					'allow_empty' => true,
					'allow_multiple' => true,
					'note' => 'If a component is no longer used by any congregation, unselect all options',
				];
				$this->fields['tags'] = [];
				if (empty($this->id)) {
					if (!empty($_REQUEST['congregationid'])) {
						$this->setValue('congregationids', [$_REQUEST['congregationid']]);
					} else {
						$this->values['congregationids'] = array_keys($congs);
					}
				}
			}
		}
		parent::printForm($prefix, $fields);
		unset($this->fields['congregationids'], $this->fields['tags']);
	}

	public function printFieldInterface($name, $prefix = '')
	{
		if ($name == 'tags') {
			$options = $GLOBALS['system']->getDBObjectData('service_component_tag', [], 'OR', 'tag');
			foreach ($options as $k => $v) {
				$options[$k] = $v['tag'];
			}
			$params = [
				'type' => 'select',
				'options' => $options,
				'label' => 'Tags',
				'references' => 'service_component_tag',
				'order_by' => 'tag',
				'allow_empty' => false,
				'allow_multiple' => false,
				'class' => 'tag-chooser',
				'empty_text' => '-- Select --',
			];

			?>
			<table class="expandable">
			<?php
			foreach (array_get($this->values, 'tags', []) as $tagid) {
				?>
				<tr>
					<td><?php print_widget('tags[]', $params, $tagid); ?></td>
					<td><img src="/resources/img/cross_red.png" class="icon delete-row" title="Delete this tag from the list" /></td>
				</tr>
				<?php
			}
			$params['allow_empty'] = true;
			$params['options']['_new_'] = '* Add new tag:';
			?>
				<tr>
					<td>
						<?php print_widget('tags[]', $params, null); ?>
						<input style="display: none" placeholder="Type new tag here" type="text" name="new_tags[]" />
					</td>
					<td><img src="/resources/img/cross_red.png" class="icon delete-row" title="Delete this tag from the list" /></td>
				</tr>
			</table>
			<p class="help-inline"><a href="?view=_manage_service_component_tags">Manage tag library</a></p>
			<?php
		} else {
			parent::printFieldInterface($name, $prefix);
		}
		if ($name == 'ccli_number') {
			if (defined('CCLI_SEARCH_URL')) {
				?>
				&nbsp; <a class="smallprint ccli-lookup" href="<?php echo CCLI_SEARCH_URL; ?>">Search CCLI</a>
				<?php
			}
		}
	}

	public function processForm($prefix = '', $fields = null)
	{
		$res = parent::processForm($prefix, $fields);
		$credits = $this->getValue('credits');
		if (str_contains($credits, '(c)')) {
			$this->setValue('credits', str_replace('(c)', '©', $credits));
		}
		$this->values['congregationids'] = array_get($_REQUEST, $prefix.'congregationids', []);
		$this->_tmp['tagids'] = [];
		if (!empty($_REQUEST['tags'])) {
			foreach ($_REQUEST['tags'] as $tagid) {
				if ($tagid && is_numeric($tagid)) {
					$this->_tmp['tagids'][] = $tagid;
				}
			}
		}

		if (!empty($_REQUEST['new_tags'])) {
			$GLOBALS['system']->includeDBClass('service_component_tag');
			foreach ($_REQUEST['new_tags'] as $tag) {
				$tag = trim($tag);
				if (strlen($tag)) {
					$tag = ucfirst($tag);
					$obj = new Service_Component_Tag();
					$obj->setValue('tag', $tag);
					$obj->create();
					$this->_tmp['tagids'][] = $obj->id;
				}
			}
		}

		return $res;
	}

	public function create()
	{
		$res = parent::create();
		if ($res && $this->id) {
			$this->_saveCongregations();
			$this->_saveTags();
		}

		return $res;
	}

	public function load($id)
	{
		$res = parent::load($id);
		if ($this->id) {
			$SQL = 'SELECT congregationid FROM congregation_service_component WHERE componentid = '.(int) $this->id;
			$this->values['congregationids'] = $GLOBALS['db']->queryCol($SQL);
			$SQL = 'SELECT t.id FROM service_component_tagging tt
						JOIN service_component_tag t ON tt.tagid = t.id
						WHERE componentid = '.(int) $this->id.'
						ORDER BY tag';
			$this->values['tags'] = $GLOBALS['db']->queryCol($SQL);
		}

		return $res;
	}

	public function save()
	{
		$res = parent::save();
		if ($res) {
			$this->_saveCongregations(true);
			$this->_saveTags(true);
		}

		return $res;
	}

	private function _saveCongregations($deleteOld = false)
	{
		if ($deleteOld) {
			$GLOBALS['db']->exec('DELETE FROM congregation_service_component WHERE componentid = '.(int) $this->id);
		}
		$sets = [];
		foreach (array_unique(array_get($this->values, 'congregationids', [])) as $congid) {
			$sets[] = '('.(int) $this->id.', '.(int) $congid.')';
		}
		if (!empty($sets)) {
			$SQL = 'INSERT INTO congregation_service_component
					(componentid, congregationid)
					VALUES
					'.implode(",\n", $sets);
			$x = $GLOBALS['db']->exec($SQL);
		}
	}

	private function _saveTags($deleteOld = false)
	{
		if ($deleteOld) {
			$GLOBALS['db']->exec('DELETE FROM service_component_tagging WHERE componentid = '.(int) $this->id);
		}
		$sets = [];
		foreach (array_unique(array_get($this->_tmp, 'tagids', [])) as $tagid) {
			$sets[] = '('.(int) $this->id.', '.(int) $tagid.')';
		}
		if (!empty($sets)) {
			$SQL = 'INSERT INTO service_component_tagging
					(componentid, tagid)
					VALUES
					'.implode(",\n", $sets);
			$x = $GLOBALS['db']->exec($SQL);
		}
	}

	public function addCongregation($newCong)
	{
		$this->values['congregationids'][] = $newCong;
		$this->values['congregationids'] = array_unique($this->values['congregationids']);
	}
}
