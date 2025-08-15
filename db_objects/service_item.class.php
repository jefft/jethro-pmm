<?php

class service_item extends db_object
{
	protected $_load_permission_level = 0; // want PERM_VIEWSERVICE | PERM_VIEWROSTER
	protected $_save_permission_level = 0; // FUTURE: PERM_EDITSERVICE;

	protected static function _getFields()
	{
		$fields = [
			'serviceid' => [
				'type' => 'reference',
				'references' => 'service',
				'label' => 'Service',
				'show_id' => false,
			],
			'rank' => [
				'type' => 'int',
			],
			'componentid' => [
				'type' => 'reference',
				'references' => 'service_component',
				'label' => 'Service Component',
				'show_id' => false,
				'allow_empty' => true,
			],
			'length_mins' => [
				'type' => 'int',
				'width' => 6,
			],

			// this is ony populated for ad-hoc items - otherwise the title comes from the component.
			'title' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
				'allow_empty' => true,
			],

			// this gets copied from the component on save.
			'show_in_handout' => [
				'type' => 'select',
				'options' => [
					'0' => 'No',
					'title' => 'Title only',
					'full' => 'Title and Content',
				],
				'label' => 'Show on Handout?',
				'editable' => true,
				'show_in_summary' => true,
				'note' => 'Items that are shown on the handout appear with numbers on the run sheet.',
			],

			'heading_text' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
			],
			'note' => [
				'type' => 'text',
				'width' => 80,
				'height' => 4,
				'initial_cap' => true,
			],
			'personnel' => [
				'type' => 'text',
				'width' => 80,
				'height' => 4,
			],
		];

		return $fields;
	}

	function _getUniqueKeys()
	{
		return [
			'servicerank' => ['serviceid', 'rank'],
		];
	}

	public function getForeignKeys()
	{
		return [
			'serviceid' => '`service` (`id`) ON DELETE CASCADE',
			'componentid' => '`service_component` (`id`) ON DELETE RESTRICT',
		];
	}

	function toString()
	{
		if (!empty($this->values['componentid'])) {
			return $this->getFormattedValue('componentid');
		} else {
			return $this->values['heading_text'];
		}
	}

	public static function getComponentStats($start_date, $end_date, $component_category_id, $congregationid = null)
	{
		$db = JethroDB::get();
		$SQL = 'SELECT sc.id, sc.title, sc.ccli_number, COUNT(distinct s.id) as usage_count
				FROM service_item si
				JOIN service s ON s.id = si.serviceid
				JOIN service_component sc ON sc.id = si.componentid
				WHERE sc.categoryid = '.(int) $component_category_id.'
				AND s.`date` BETWEEN '.$db->quote($start_date).' AND '.$db->quote($end_date);
		if ($congregationid) {
			$SQL .= '
				AND s.congregationid = '.(int) $congregationid;
		}
		$SQL .= '
				GROUP BY sc.id, sc.title
				ORDER BY usage_count DESC';

		return $db->queryAll($SQL);
	}
}
