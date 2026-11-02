<?php
include_once 'include/db_object.class.php';
class Custom_Field_Value extends DB_Object
{
	function getInitSQL($table_name=NULL) {
		return '
			CREATE TABLE `_custom_field_value` (
				id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
				personid INT(11) NOT NULL,
				fieldid INT(11) NOT NULL,
				value_text VARCHAR(255) DEFAULT NULL,
				value_date CHAR(10) DEFAULT NULL,
				value_optionid INT(11) DEFAULT NULL
			) Engine=InnoDB;';
	}

	/**
	 * Custom field values are visible only when the user can see the field and,
	 * for a sensitive field, holds the sensitive-fields permission.
	 * @return string
	 */
	public function getViewSQL()
	{
		return '
			CREATE OR REPLACE VIEW `custom_field_value` AS
			SELECT v.id, v.personid, v.fieldid, v.value_text, v.value_date, v.value_optionid
			FROM `_custom_field_value` v
			JOIN `custom_field` f ON f.id = v.fieldid
			WHERE currentUserHasDataPerm(\'VIEWCUSTOMFIELDS\')
			  AND (f.sensitive = 0 OR currentUserHasDataPerm(\'VIEWSENSITIVE\'));';
	}

	/**
	 *
	 * @return Array (columnName => referenceExpression) eg 'tagid' => 'tagoption.id ON DELETE CASCADE'
	 */
	public function getForeignKeys()
	{
		return Array(
			'_custom_field_value.personid' => '_person(id) ON DELETE CASCADE',
			'_custom_field_value.fieldid'  => 'custom_field(id) ON DELETE CASCADE',
			'_custom_field_value.value_optionid' => 'custom_field_option(id) ON DELETE CASCADE',
		);
	}
}
