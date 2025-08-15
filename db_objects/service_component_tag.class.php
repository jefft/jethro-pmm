<?php

class Service_Component_Tag extends db_object
{
	protected $_load_permission_level = 0; // want PERM_VIEWSERVICE | PERM_VIEWROSTER
	protected $_save_permission_level = 0; // FUTURE: PERM_EDITSERVICE;

	protected static function _getFields()
	{
		$fields = [
			'tag' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
			],
		];

		return $fields;
	}

	function toString()
	{
		return $this->values['tag'];
	}
}
