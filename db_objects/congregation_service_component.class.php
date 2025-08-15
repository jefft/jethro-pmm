<?php

include_once 'include/db_object.class.php';
class Congregation_Service_Component extends db_object
{
	protected static function _getFields()
	{
		$fields = [
			'congregationid' => [
				'type' => 'reference',
				'references' => 'congregation',
				'label' => 'Congregation',
				'show_id' => false,
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
			'congcomp' => ['congregationid', 'componentid'],
		];
	}

	public function getForeignKeys()
	{
		return [
			'congregationid' => '`congregation` (`id`) ON DELETE CASCADE',
			'componentid' => '`service_component` (`id`) ON DELETE CASCADE',
		];
	}

	function getInstancesQueryComps($params, $logic, $order)
	{
		$res = parent::getInstancesQueryComps($params, $logic, $order);
		$res['select'][] = 'cong.*';
		$res['from'] .= ' JOIN congregation cong ON cong.id = congregation_service_component.congregationid';

		return $res;
	}
}
