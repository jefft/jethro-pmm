<?php

include_once 'include/db_object.class.php';
class Service_Component_Tagging extends db_object
{
	protected static function _getFields()
	{
		$fields = [
			'tagid' => [
				'type' => 'reference',
				'references' => 'service_component_tag',
				'label' => 'Tag',
				'show_id' => true,
			],
			'componentid' => [
				'type' => 'reference',
				'references' => 'service_component',
				'label' => 'Component',
				'show_id' => true,
			],
		];

		return $fields;
	}

	function _getUniqueKeys()
	{
		return [
			'comptag' => ['tagid', 'componentid'],
		];
	}

	public function getForeignKeys()
	{
		return ['tagid' => 'service_component_tag(id) ON DELETE CASCADE'];
	}

	function getInstancesQueryComps($params, $logic, $order)
	{
		$res = parent::getInstancesQueryComps($params, $logic, $order);
		$res['from'] .= ' JOIN service_component_tag sct ON sct.id = tagid ';
		$res['select'][] = 'sct.tag';

		return $res;
	}
}
