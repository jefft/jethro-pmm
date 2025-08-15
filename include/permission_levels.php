<?php

/* These are bitmask values where some values include others - eg "edit note" includes "view note" */
/* WARNING: These numbers are referenced in the 2.28 upgrade script and installer */

$PERM_LEVELS = [
	1 => ['EDITPERSON',			'Persons & Families - add/edit',	''],

	2 => ['SENDSMS',			'SMS/Mailchimp - send',							''],

	4 => ['RUNREPORT',			'Reports - run reports & view stats',	''],
	12 => ['MANAGEREPORTS',		'Reports - save shared reports', ''],

	16 => ['VIEWMYNOTES',		'Notes - view&edit notes assigned to me', 'NOTES'],
	48 => ['VIEWNOTE',			'Notes - view all',						'NOTES'],
	112 => ['EDITNOTE',			'Notes - add/edit all',					'NOTES'],
	240 => ['BULKNOTE',			'Notes - bulk-assign',					'NOTES'],

	256 => ['VIEWATTENDANCE',		'Attendance - view and report',			'ATTENDANCE'],
	768 => ['EDITATTENDANCE',		'Attendance - record',					'ATTENDANCE'],

	1024 => ['EDITGROUP',			'Groups - add/edit/delete',				''],
	3072 => ['MANAGEGROUPCATS',	'Groups - manage categories',			''],

	4096 => ['VIEWROSTER',			'Rosters - view assignments',			'ROSTERS&SERVICES'],
	12288 => ['EDITROSTER',			'Rosters - edit assignments',			'ROSTERS&SERVICES'],
	28672 => ['MANAGEROSTERS',		'Rosters - manage roles & views',		'ROSTERS&SERVICES'],

	32768 => ['VIEWSERVICE',		'Services - view',						'ROSTERS&SERVICES'],
	98304 => ['EDITSERVICE',		'Services - edit individual',			'SERVICEDETAILS'],
	229376 => ['BULKSERVICE',		'Services - edit service schedule',		'ROSTERS&SERVICES'],
	360448 => ['SERVICECOMPS',		'Services - manage component library',  'SERVICEDETAILS'],
	// 311296 =>	Array('MANAGESONGS',		'Services - manage song repertoire',	'SERVICEDETAILS'),

	1048576 => ['EDITREC',			'Sermon recordings - manage',			'SERMONRECORDINGS'],

	2097152 => ['VIEWDOC',			'Documents & Folders - view',			'DOCUMENTS'],
	6291456 => ['EDITDOC',			'Documents & Folders- add/edit/delete',	'DOCUMENTS'],
	14680064 => ['SERVICEDOC',			'Service Documents - generate',			'SERVICEDOCUMENTS'],

	// room for some more here...
	2147483647 => ['SYSADMIN',			'SysAdmin - manage user accounts, congregations etc', ''],
];
