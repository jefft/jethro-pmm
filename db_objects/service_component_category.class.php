<?php

class Service_Component_Category extends db_object
{
	protected $_load_permission_level = 0; // want PERM_VIEWSERVICE | PERM_VIEWROSTER
	protected $_save_permission_level = 0; // FUTURE: PERM_EDITSERVICE;

	protected static function _getFields()
	{
		$fields = [
			'category_name' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
			],
			'runsheet_title_format' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
				'default' => '%title%',
			],
			'handout_title_format' => [
				'type' => 'text',
				'width' => 80,
				'initial_cap' => true,
				'default' => '%title%',
			],
			'show_in_handout_default' => [
				'type' => 'select',
				'options' => [
					'0' => 'No',
					'title' => 'Title only',
					'full' => 'Title and Content',
				],
				'default' => 'full',
			],
			'show_on_slide_default' => [
				'type' => 'select',
				'options' => ['No', 'Yes'],
				'default' => 1,
			],
			'personnel_default' => [
				'type' => 'text',
			],
			'length_mins_default' => [
				'type' => 'int',
				'width' => 6,
			],
		];

		return $fields;
	}

	function getInitSQL($table_name = null)
	{
		$res = (array) parent::getInitSQL();
		$res[] = 'INSERT INTO service_component_category
				  (category_name, runsheet_title_format, handout_title_format, length_mins_default, personnel_default)
				  VALUES 
				  ("Songs", "Song: %title%", "Song: %title%", 3, "%SONG_LEADER_FIRSTNAME%"),
				  ("Prayers", "%title%", "%title%", 2, "%SERVICE_LEADER_FIRSTNAME%"),
				  ("Creeds", "The %title%", "The %title%", 2, "%SERVICE_LEADER_FIRSTNAME%"),
				  ("Other", "%title%", "%title%", 1, "");';

		return $res;
	}

	function toString()
	{
		return $this->values['category_name'];
	}
}
