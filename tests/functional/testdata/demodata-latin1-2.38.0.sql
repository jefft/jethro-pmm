-- MySQL dump 10.13  Distrib 8.4.10, for Linux (x86_64)
--
-- Host: localhost    Database: jethro_functest_walkthrough
-- ------------------------------------------------------
-- Server version	8.4.10

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `2fa_trust`
--

DROP TABLE IF EXISTS `2fa_trust`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `2fa_trust` (
  `userid` int NOT NULL,
  `token` varchar(255) NOT NULL,
  `expiry` datetime NOT NULL,
  KEY `2fatrust_person` (`userid`),
  CONSTRAINT `2fatrust_person` FOREIGN KEY (`userid`) REFERENCES `staff_member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `2fa_trust`
--

LOCK TABLES `2fa_trust` WRITE;
/*!40000 ALTER TABLE `2fa_trust` DISABLE KEYS */;
/*!40000 ALTER TABLE `2fa_trust` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `_abstract_note`
--

DROP TABLE IF EXISTS `_abstract_note`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_abstract_note` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) NOT NULL,
  `details` text NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'no_action',
  `status_last_changed` datetime DEFAULT NULL,
  `assignee` int DEFAULT NULL,
  `assignee_last_changed` datetime DEFAULT NULL,
  `action_date` date NOT NULL DEFAULT '2026-08-07',
  `creator` int NOT NULL DEFAULT '0',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `editor` int DEFAULT NULL,
  `edited` datetime DEFAULT NULL,
  `history` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `_abstract_noteassignee` (`assignee`),
  KEY `_abstract_notecreator` (`creator`),
  KEY `_abstract_noteeditor` (`editor`),
  CONSTRAINT `_abstract_noteassignee` FOREIGN KEY (`assignee`) REFERENCES `staff_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `_abstract_notecreator` FOREIGN KEY (`creator`) REFERENCES `staff_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `_abstract_noteeditor` FOREIGN KEY (`editor`) REFERENCES `staff_member` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `_abstract_note`
--

LOCK TABLES `_abstract_note` WRITE;
/*!40000 ALTER TABLE `_abstract_note` DISABLE KEYS */;
INSERT INTO `_abstract_note` VALUES (1,'Welcome','','pending',NULL,1,NULL,'2026-08-07',1,'2026-08-06 14:15:25',NULL,NULL,'a:1:{i:1786025725;s:27:\"Created by Dennis Demo (#1)\";}'),(2,'Welcome','','pending',NULL,1,NULL,'2026-08-07',1,'2026-08-06 14:15:25',NULL,NULL,'a:1:{i:1786025725;s:27:\"Created by Dennis Demo (#1)\";}'),(3,'Ill health','','no_action','2026-08-07 00:16:01',NULL,'2026-08-07 00:16:01','2026-08-07',1,'2026-08-06 14:15:32',NULL,NULL,'a:2:{i:1786025732;s:27:\"Created by Dennis Demo (#1)\";i:1786025761;s:136:\"Updated by Dennis Demo (#1)\nStatus changed from \"Requires Action\" to \"No Action Required\"\nAssignee changed from \"Dennis Demo (#1)\" to \"\"\";}'),(4,'Welcome','','pending',NULL,1,NULL,'2026-08-07',1,'2026-08-06 14:15:45',NULL,NULL,'a:1:{i:1786025745;s:27:\"Created by Dennis Demo (#1)\";}'),(5,'Welcome','','pending',NULL,1,NULL,'2026-08-07',1,'2026-08-06 14:15:46',NULL,NULL,'a:1:{i:1786025746;s:27:\"Created by Dennis Demo (#1)\";}');
/*!40000 ALTER TABLE `_abstract_note` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `_person`
--

DROP TABLE IF EXISTS `_person`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_person` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL DEFAULT '',
  `last_name` varchar(255) NOT NULL DEFAULT '',
  `gender` varchar(64) NOT NULL DEFAULT '',
  `age_bracketid` int DEFAULT NULL,
  `email` varchar(255) NOT NULL DEFAULT '',
  `mobile_tel` varchar(12) NOT NULL DEFAULT '',
  `work_tel` varchar(12) NOT NULL DEFAULT '',
  `remarks` text NOT NULL,
  `status` int NOT NULL,
  `status_last_changed` datetime DEFAULT NULL,
  `history` text NOT NULL,
  `creator` int NOT NULL DEFAULT '0',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `congregationid` int DEFAULT NULL,
  `familyid` int NOT NULL DEFAULT '0',
  `member_password` varchar(255) DEFAULT NULL,
  `resethash` varchar(255) DEFAULT NULL,
  `resetexpires` datetime DEFAULT NULL,
  `feed_uuid` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `person_fn` (`first_name`),
  KEY `person_ln` (`last_name`),
  KEY `_personage_bracketid` (`age_bracketid`),
  KEY `_personstatus` (`status`),
  KEY `_personfamilyid` (`familyid`),
  KEY `_personcongregationid` (`congregationid`),
  CONSTRAINT `_personage_bracketid` FOREIGN KEY (`age_bracketid`) REFERENCES `age_bracket` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `_personcongregationid` FOREIGN KEY (`congregationid`) REFERENCES `congregation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `_personfamilyid` FOREIGN KEY (`familyid`) REFERENCES `family` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `_personstatus` FOREIGN KEY (`status`) REFERENCES `person_status` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `_person`
--

LOCK TABLES `_person` WRITE;
/*!40000 ALTER TABLE `_person` DISABLE KEYS */;
INSERT INTO `_person` VALUES (1,'Dennis','Demo','male',1,'support@easyjethro.com.au','','','',1,NULL,'a:1:{i:1786025705;s:7:\"Created\";}',0,'2026-08-06 14:15:05',1,1,NULL,NULL,NULL,''),(2,'John','Calvin','male',1,'','','','',1,NULL,'a:2:{i:1786025725;s:27:\"Created by Dennis Demo (#1)\";i:1786025748;s:118:\"Updated by Dennis Demo (#1)\nDate of Birth changed from \"\" to \"10 Jul 1985\"\nWWCC Number changed from \"\" to \"WWCC123456\"\";}',1,'2026-08-06 14:15:25',1,2,NULL,NULL,NULL,''),(3,'Idelette','de Bure','female',1,'','','','',1,NULL,'a:1:{i:1786025725;s:27:\"Created by Dennis Demo (#1)\";}',1,'2026-08-06 14:15:25',1,2,NULL,NULL,NULL,''),(4,'Martin','Luther','male',1,'','','','',1,NULL,'a:1:{i:1786025725;s:27:\"Created by Dennis Demo (#1)\";}',1,'2026-08-06 14:15:25',1,3,NULL,NULL,NULL,''),(5,'Katharina','von Bora','female',1,'','','','',1,NULL,'a:1:{i:1786025725;s:27:\"Created by Dennis Demo (#1)\";}',1,'2026-08-06 14:15:25',1,3,NULL,NULL,NULL,''),(6,'Theodore','Beza','male',1,'','','','',1,NULL,'a:1:{i:1786025745;s:27:\"Created by Dennis Demo (#1)\";}',1,'2026-08-06 14:15:45',3,4,NULL,NULL,NULL,''),(7,'Guillaume','Farel','male',1,'','','','',1,NULL,'a:1:{i:1786025746;s:27:\"Created by Dennis Demo (#1)\";}',1,'2026-08-06 14:15:46',3,5,NULL,NULL,NULL,'');
/*!40000 ALTER TABLE `_person` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `_person_group`
--

DROP TABLE IF EXISTS `_person_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_person_group` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `categoryid` int DEFAULT '0',
  `is_archived` varchar(255) NOT NULL DEFAULT '0',
  `owner` int DEFAULT NULL,
  `show_add_family` varchar(255) NOT NULL DEFAULT 'no',
  `share_member_details` varchar(255) NOT NULL DEFAULT '0',
  `attendance_recording_days` varchar(255) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `_person_groupcategoryid` (`categoryid`),
  CONSTRAINT `_person_groupcategoryid` FOREIGN KEY (`categoryid`) REFERENCES `person_group_category` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `_person_group`
--

LOCK TABLES `_person_group` WRITE;
/*!40000 ALTER TABLE `_person_group` DISABLE KEYS */;
INSERT INTO `_person_group` VALUES (1,'Newsletter',8,'0',NULL,'no','0','0'),(2,'Kids Church - Parents',5,'0',NULL,'no','0','0'),(3,'Band - Arvo',12,'0',NULL,'no','0','0');
/*!40000 ALTER TABLE `_person_group` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `abstract_note`
--

DROP TABLE IF EXISTS `abstract_note`;
/*!50001 DROP VIEW IF EXISTS `abstract_note`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `abstract_note` AS SELECT 
 1 AS `id`,
 1 AS `subject`,
 1 AS `details`,
 1 AS `status`,
 1 AS `status_last_changed`,
 1 AS `assignee`,
 1 AS `assignee_last_changed`,
 1 AS `action_date`,
 1 AS `creator`,
 1 AS `created`,
 1 AS `editor`,
 1 AS `edited`,
 1 AS `history`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `account_congregation_restriction`
--

DROP TABLE IF EXISTS `account_congregation_restriction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_congregation_restriction` (
  `personid` int NOT NULL,
  `congregationid` int NOT NULL,
  PRIMARY KEY (`personid`,`congregationid`),
  KEY `account_group_restriction_congregationid` (`congregationid`),
  CONSTRAINT `account_congregation_restriction_personid` FOREIGN KEY (`personid`) REFERENCES `staff_member` (`id`),
  CONSTRAINT `account_group_restriction_congregationid` FOREIGN KEY (`congregationid`) REFERENCES `congregation` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_congregation_restriction`
--

LOCK TABLES `account_congregation_restriction` WRITE;
/*!40000 ALTER TABLE `account_congregation_restriction` DISABLE KEYS */;
/*!40000 ALTER TABLE `account_congregation_restriction` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_group_restriction`
--

DROP TABLE IF EXISTS `account_group_restriction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_group_restriction` (
  `personid` int NOT NULL,
  `groupid` int NOT NULL,
  PRIMARY KEY (`personid`,`groupid`),
  KEY `account_group_restriction_groupid` (`groupid`),
  CONSTRAINT `account_group_restriction_groupid` FOREIGN KEY (`groupid`) REFERENCES `_person_group` (`id`),
  CONSTRAINT `account_group_restriction_personid` FOREIGN KEY (`personid`) REFERENCES `staff_member` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_group_restriction`
--

LOCK TABLES `account_group_restriction` WRITE;
/*!40000 ALTER TABLE `account_group_restriction` DISABLE KEYS */;
/*!40000 ALTER TABLE `account_group_restriction` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `action_plan`
--

DROP TABLE IF EXISTS `action_plan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `action_plan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `actions` text NOT NULL,
  `default_on_create_family` tinyint unsigned DEFAULT NULL,
  `default_on_add_person` tinyint unsigned DEFAULT NULL,
  `modified` datetime NOT NULL,
  `modifier` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `action_plan`
--

LOCK TABLES `action_plan` WRITE;
/*!40000 ALTER TABLE `action_plan` DISABLE KEYS */;
INSERT INTO `action_plan` VALUES (1,'1st Visit (Church)','a:7:{s:5:\"notes\";a:0:{}s:6:\"groups\";a:0:{}s:25:\"group_membership_statuses\";a:0:{}s:18:\"group_mark_present\";a:0:{}s:13:\"groups_remove\";a:0:{}s:5:\"dates\";a:0:{}s:10:\"attendance\";s:1:\"0\";}',0,0,'2026-08-07 00:15:22',1),(2,'Present last Sunday','a:7:{s:5:\"notes\";a:0:{}s:6:\"groups\";a:0:{}s:25:\"group_membership_statuses\";a:0:{}s:18:\"group_mark_present\";a:0:{}s:13:\"groups_remove\";a:0:{}s:5:\"dates\";a:0:{}s:10:\"attendance\";s:1:\"0\";}',0,0,'2026-08-07 00:15:23',1);
/*!40000 ALTER TABLE `action_plan` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `action_plan_age_bracket`
--

DROP TABLE IF EXISTS `action_plan_age_bracket`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `action_plan_age_bracket` (
  `action_planid` int NOT NULL,
  `age_bracketid` int NOT NULL,
  PRIMARY KEY (`action_planid`,`age_bracketid`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `action_plan_age_bracket`
--

LOCK TABLES `action_plan_age_bracket` WRITE;
/*!40000 ALTER TABLE `action_plan_age_bracket` DISABLE KEYS */;
/*!40000 ALTER TABLE `action_plan_age_bracket` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `age_bracket`
--

DROP TABLE IF EXISTS `age_bracket`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `age_bracket` (
  `id` int NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `rank` int NOT NULL DEFAULT '0',
  `is_adult` tinyint unsigned NOT NULL DEFAULT '0',
  `is_default` tinyint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `label` (`label`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `age_bracket`
--

LOCK TABLES `age_bracket` WRITE;
/*!40000 ALTER TABLE `age_bracket` DISABLE KEYS */;
INSERT INTO `age_bracket` VALUES (1,'Adult',0,1,1),(2,'High school',1,0,0),(3,'Primary school',2,0,0),(4,'Infants school',3,0,0),(5,'Preschool',4,0,0),(6,'Toddler',5,0,0),(7,'Baby',6,0,0);
/*!40000 ALTER TABLE `age_bracket` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_record`
--

DROP TABLE IF EXISTS `attendance_record`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_record` (
  `date` date NOT NULL,
  `personid` int NOT NULL,
  `groupid` int NOT NULL,
  `present` tinyint unsigned NOT NULL,
  `checkinid` int DEFAULT NULL,
  PRIMARY KEY (`date`,`personid`,`groupid`),
  KEY `attendance_recordpersonid` (`personid`),
  KEY `attendance_recordcheckinid` (`checkinid`),
  CONSTRAINT `attendance_recordcheckinid` FOREIGN KEY (`checkinid`) REFERENCES `checkin` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_recordpersonid` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_record`
--

LOCK TABLES `attendance_record` WRITE;
/*!40000 ALTER TABLE `attendance_record` DISABLE KEYS */;
INSERT INTO `attendance_record` VALUES ('2026-08-02',1,0,1,NULL);
/*!40000 ALTER TABLE `attendance_record` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `checkin`
--

DROP TABLE IF EXISTS `checkin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `checkin` (
  `id` int NOT NULL AUTO_INCREMENT,
  `venueid` int NOT NULL DEFAULT '0',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `name` varchar(255) NOT NULL,
  `tel` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `pax` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `checkin`
--

LOCK TABLES `checkin` WRITE;
/*!40000 ALTER TABLE `checkin` DISABLE KEYS */;
/*!40000 ALTER TABLE `checkin` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `congregation`
--

DROP TABLE IF EXISTS `congregation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `congregation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `long_name` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `holds_persons` varchar(255) NOT NULL DEFAULT '1',
  `attendance_recording_days` varchar(255) NOT NULL DEFAULT '127',
  `meeting_time` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `congregation`
--

LOCK TABLES `congregation` WRITE;
/*!40000 ALTER TABLE `congregation` DISABLE KEYS */;
INSERT INTO `congregation` VALUES (1,'4pm','4pm','1','1','1600_arvo'),(2,'4pm Kids','4pm Kids','1','33','1600_kids'),(3,'6pm','6pm','1','1','1800_night'),(4,'None','None','1','0',''),(5,'External Supporters','External Supporters','1','0','');
/*!40000 ALTER TABLE `congregation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `congregation_headcount`
--

DROP TABLE IF EXISTS `congregation_headcount`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `congregation_headcount` (
  `date` date NOT NULL,
  `congregationid` int NOT NULL,
  `number` int NOT NULL,
  PRIMARY KEY (`date`,`congregationid`),
  KEY `congregation_headcountcongregationid` (`congregationid`),
  CONSTRAINT `congregation_headcountcongregationid` FOREIGN KEY (`congregationid`) REFERENCES `congregation` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `congregation_headcount`
--

LOCK TABLES `congregation_headcount` WRITE;
/*!40000 ALTER TABLE `congregation_headcount` DISABLE KEYS */;
/*!40000 ALTER TABLE `congregation_headcount` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `congregation_service_component`
--

DROP TABLE IF EXISTS `congregation_service_component`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `congregation_service_component` (
  `id` int NOT NULL AUTO_INCREMENT,
  `congregationid` int NOT NULL DEFAULT '0',
  `componentid` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `congcomp` (`congregationid`,`componentid`),
  KEY `congregation_service_componentcomponentid` (`componentid`),
  CONSTRAINT `congregation_service_componentcomponentid` FOREIGN KEY (`componentid`) REFERENCES `service_component` (`id`) ON DELETE CASCADE,
  CONSTRAINT `congregation_service_componentcongregationid` FOREIGN KEY (`congregationid`) REFERENCES `congregation` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `congregation_service_component`
--

LOCK TABLES `congregation_service_component` WRITE;
/*!40000 ALTER TABLE `congregation_service_component` DISABLE KEYS */;
INSERT INTO `congregation_service_component` VALUES (1,1,1),(2,2,1),(3,3,1);
/*!40000 ALTER TABLE `congregation_service_component` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_field`
--

DROP TABLE IF EXISTS `custom_field`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_field` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `rank` int NOT NULL DEFAULT '0',
  `type` varchar(255) NOT NULL DEFAULT 'text',
  `allow_multiple` varchar(255) NOT NULL DEFAULT '0',
  `show_add_family` varchar(255) NOT NULL DEFAULT '0',
  `searchable` varchar(255) NOT NULL DEFAULT '0',
  `heading_before` varchar(255) NOT NULL DEFAULT '',
  `divider_before` varchar(255) NOT NULL DEFAULT '0',
  `params` text NOT NULL,
  `tooltip` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_field`
--

LOCK TABLES `custom_field` WRITE;
/*!40000 ALTER TABLE `custom_field` DISABLE KEYS */;
INSERT INTO `custom_field` VALUES (1,'Date of Birth',0,'date','','','','','','a:5:{s:10:\"allow_note\";b:0;s:16:\"allow_blank_year\";b:0;s:5:\"regex\";s:0:\"\";s:8:\"template\";s:0:\"\";s:11:\"allow_other\";b:0;}',''),(2,'WWCC Number',1,'text','','','','','','a:5:{s:10:\"allow_note\";b:0;s:16:\"allow_blank_year\";b:0;s:5:\"regex\";s:0:\"\";s:8:\"template\";s:0:\"\";s:11:\"allow_other\";b:0;}',''),(3,'Friend Of',2,'text','','','','','','a:5:{s:10:\"allow_note\";b:0;s:16:\"allow_blank_year\";b:0;s:5:\"regex\";s:0:\"\";s:8:\"template\";s:0:\"\";s:11:\"allow_other\";b:0;}','');
/*!40000 ALTER TABLE `custom_field` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_field_option`
--

DROP TABLE IF EXISTS `custom_field_option`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_field_option` (
  `id` int NOT NULL AUTO_INCREMENT,
  `value` varchar(255) NOT NULL,
  `rank` int NOT NULL DEFAULT '0',
  `fieldid` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `custom_field_optionfieldid` (`fieldid`),
  CONSTRAINT `custom_field_optionfieldid` FOREIGN KEY (`fieldid`) REFERENCES `custom_field` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_field_option`
--

LOCK TABLES `custom_field_option` WRITE;
/*!40000 ALTER TABLE `custom_field_option` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_field_option` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_field_value`
--

DROP TABLE IF EXISTS `custom_field_value`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_field_value` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personid` int NOT NULL,
  `fieldid` int NOT NULL,
  `value_text` varchar(255) DEFAULT NULL,
  `value_date` char(10) DEFAULT NULL,
  `value_optionid` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `custom_field_valuepersonid` (`personid`),
  KEY `custom_field_valuefieldid` (`fieldid`),
  KEY `custom_field_valuevalue_optionid` (`value_optionid`),
  CONSTRAINT `custom_field_valuefieldid` FOREIGN KEY (`fieldid`) REFERENCES `custom_field` (`id`) ON DELETE CASCADE,
  CONSTRAINT `custom_field_valuepersonid` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE,
  CONSTRAINT `custom_field_valuevalue_optionid` FOREIGN KEY (`value_optionid`) REFERENCES `custom_field_option` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_field_value`
--

LOCK TABLES `custom_field_value` WRITE;
/*!40000 ALTER TABLE `custom_field_value` DISABLE KEYS */;
INSERT INTO `custom_field_value` VALUES (1,2,1,'','1985-07-10',NULL),(2,2,2,'WWCC123456',NULL,NULL);
/*!40000 ALTER TABLE `custom_field_value` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `db_object_lock`
--

DROP TABLE IF EXISTS `db_object_lock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `db_object_lock` (
  `objectid` int NOT NULL DEFAULT '0',
  `userid` int NOT NULL DEFAULT '0',
  `lock_type` varchar(16) NOT NULL,
  `object_type` varchar(255) NOT NULL DEFAULT '',
  `expires` datetime NOT NULL,
  KEY `objectid` (`objectid`),
  KEY `userid` (`userid`),
  KEY `object_type` (`object_type`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `db_object_lock`
--

LOCK TABLES `db_object_lock` WRITE;
/*!40000 ALTER TABLE `db_object_lock` DISABLE KEYS */;
INSERT INTO `db_object_lock` VALUES (1,1,'','custom_field','2026-08-07 00:25:21'),(2,1,'','custom_field','2026-08-07 00:25:21'),(3,1,'','custom_field','2026-08-07 00:25:21'),(1,1,'','person_status','2026-08-07 00:26:22'),(2,1,'','person_status','2026-08-07 00:26:22'),(3,1,'','person_status','2026-08-07 00:26:22'),(4,1,'','person_status','2026-08-07 00:26:22'),(1,1,'','age_bracket','2026-08-07 00:26:22'),(2,1,'','age_bracket','2026-08-07 00:26:22'),(3,1,'','age_bracket','2026-08-07 00:26:22'),(4,1,'','age_bracket','2026-08-07 00:26:22'),(5,1,'','age_bracket','2026-08-07 00:26:22'),(6,1,'','age_bracket','2026-08-07 00:26:22'),(7,1,'','age_bracket','2026-08-07 00:26:22');
/*!40000 ALTER TABLE `db_object_lock` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `family`
--

DROP TABLE IF EXISTS `family`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `family` (
  `id` int NOT NULL AUTO_INCREMENT,
  `family_name` varchar(128) NOT NULL DEFAULT '',
  `address_street` varchar(255) NOT NULL DEFAULT '',
  `address_suburb` varchar(128) NOT NULL DEFAULT '',
  `address_state` varchar(64) NOT NULL DEFAULT '',
  `address_postcode` varchar(10) NOT NULL DEFAULT '',
  `home_tel` varchar(12) NOT NULL DEFAULT '',
  `status` varchar(64) NOT NULL DEFAULT '',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `creator` int NOT NULL DEFAULT '0',
  `history` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `family_name` (`family_name`,`address_suburb`,`address_postcode`,`home_tel`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `family`
--

LOCK TABLES `family` WRITE;
/*!40000 ALTER TABLE `family` DISABLE KEYS */;
INSERT INTO `family` VALUES (1,'Demo','','','NSW','','','current','2026-08-06 14:15:05',1,'a:1:{i:1786025705;s:7:\"Created\";}'),(2,'Calvin','11 Rue des Chanoines','Geneva','NSW','1204','0223101564','current','2026-08-06 14:15:25',1,'a:2:{i:1786025725;s:27:\"Created by Dennis Demo (#1)\";i:1786025753;s:199:\"Updated by Dennis Demo (#1)\nHome Tel changed from \"\" to \"(02) 2310-1564\"\nStreet Address changed from \"\" to \"11 Rue des Chanoines\"\nSuburb changed from \"\" to \"Geneva\"\nPostcode changed from \"\" to \"1204\"\";}'),(3,'Luther','','','NSW','','','current','2026-08-06 14:15:25',1,'a:1:{i:1786025725;s:27:\"Created by Dennis Demo (#1)\";}'),(4,'Beza','','','NSW','','','current','2026-08-06 14:15:45',1,'a:1:{i:1786025745;s:27:\"Created by Dennis Demo (#1)\";}'),(5,'Farel','','','NSW','','','current','2026-08-06 14:15:46',1,'a:1:{i:1786025746;s:27:\"Created by Dennis Demo (#1)\";}');
/*!40000 ALTER TABLE `family` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `family_note`
--

DROP TABLE IF EXISTS `family_note`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_note` (
  `familyid` int NOT NULL DEFAULT '0',
  `id` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`familyid`,`id`),
  KEY `family_noteid` (`id`),
  CONSTRAINT `family_notefamilyid` FOREIGN KEY (`familyid`) REFERENCES `family` (`id`) ON DELETE CASCADE,
  CONSTRAINT `family_noteid` FOREIGN KEY (`id`) REFERENCES `_abstract_note` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `family_note`
--

LOCK TABLES `family_note` WRITE;
/*!40000 ALTER TABLE `family_note` DISABLE KEYS */;
INSERT INTO `family_note` VALUES (2,1),(3,2);
/*!40000 ALTER TABLE `family_note` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `family_photo`
--

DROP TABLE IF EXISTS `family_photo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_photo` (
  `familyid` int NOT NULL,
  `photodata` mediumblob NOT NULL,
  PRIMARY KEY (`familyid`),
  CONSTRAINT `famliyphotofamilyid` FOREIGN KEY (`familyid`) REFERENCES `family` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `family_photo`
--

LOCK TABLES `family_photo` WRITE;
/*!40000 ALTER TABLE `family_photo` DISABLE KEYS */;
/*!40000 ALTER TABLE `family_photo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `member`
--

DROP TABLE IF EXISTS `member`;
/*!50001 DROP VIEW IF EXISTS `member`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `member` AS SELECT 
 1 AS `id`,
 1 AS `first_name`,
 1 AS `last_name`,
 1 AS `gender`,
 1 AS `age_bracketid`,
 1 AS `congregationid`,
 1 AS `email`,
 1 AS `mobile_tel`,
 1 AS `work_tel`,
 1 AS `familyid`,
 1 AS `family_name`,
 1 AS `address_street`,
 1 AS `address_suburb`,
 1 AS `address_state`,
 1 AS `address_postcode`,
 1 AS `home_tel`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `note_comment`
--

DROP TABLE IF EXISTS `note_comment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `note_comment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `noteid` int NOT NULL DEFAULT '0',
  `creator` int NOT NULL DEFAULT '0',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `contents` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `note_comment`
--

LOCK TABLES `note_comment` WRITE;
/*!40000 ALTER TABLE `note_comment` DISABLE KEYS */;
/*!40000 ALTER TABLE `note_comment` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `note_template`
--

DROP TABLE IF EXISTS `note_template`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `note_template` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `note_template`
--

LOCK TABLES `note_template` WRITE;
/*!40000 ALTER TABLE `note_template` DISABLE KEYS */;
INSERT INTO `note_template` VALUES (1,'Welcome Follow-up','Follow up after first visit');
/*!40000 ALTER TABLE `note_template` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `note_template_field`
--

DROP TABLE IF EXISTS `note_template_field`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `note_template_field` (
  `id` int NOT NULL AUTO_INCREMENT,
  `templateid` int NOT NULL DEFAULT '0',
  `rank` int NOT NULL DEFAULT '0',
  `customfieldid` int DEFAULT '0',
  `label` varchar(255) DEFAULT NULL,
  `type` varchar(255) NOT NULL,
  `params` text,
  PRIMARY KEY (`id`),
  KEY `note_template_fieldtemplateid` (`templateid`),
  KEY `note_template_fieldcustomfieldid` (`customfieldid`),
  CONSTRAINT `note_template_fieldcustomfieldid` FOREIGN KEY (`customfieldid`) REFERENCES `custom_field` (`id`) ON DELETE CASCADE,
  CONSTRAINT `note_template_fieldtemplateid` FOREIGN KEY (`templateid`) REFERENCES `note_template` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `note_template_field`
--

LOCK TABLES `note_template_field` WRITE;
/*!40000 ALTER TABLE `note_template_field` DISABLE KEYS */;
/*!40000 ALTER TABLE `note_template_field` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `person`
--

DROP TABLE IF EXISTS `person`;
/*!50001 DROP VIEW IF EXISTS `person`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `person` AS SELECT 
 1 AS `id`,
 1 AS `first_name`,
 1 AS `last_name`,
 1 AS `gender`,
 1 AS `age_bracketid`,
 1 AS `email`,
 1 AS `mobile_tel`,
 1 AS `work_tel`,
 1 AS `remarks`,
 1 AS `status`,
 1 AS `status_last_changed`,
 1 AS `history`,
 1 AS `creator`,
 1 AS `created`,
 1 AS `congregationid`,
 1 AS `familyid`,
 1 AS `member_password`,
 1 AS `resethash`,
 1 AS `resetexpires`,
 1 AS `feed_uuid`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `person_group`
--

DROP TABLE IF EXISTS `person_group`;
/*!50001 DROP VIEW IF EXISTS `person_group`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `person_group` AS SELECT 
 1 AS `id`,
 1 AS `name`,
 1 AS `categoryid`,
 1 AS `is_archived`,
 1 AS `owner`,
 1 AS `show_add_family`,
 1 AS `share_member_details`,
 1 AS `attendance_recording_days`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `person_group_category`
--

DROP TABLE IF EXISTS `person_group_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `person_group_category` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `parent_category` int DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `person_group_category`
--

LOCK TABLES `person_group_category` WRITE;
/*!40000 ALTER TABLE `person_group_category` DISABLE KEYS */;
INSERT INTO `person_group_category` VALUES (1,'MINISTRY',NULL),(2,'SUNDAY SERVICES',NULL),(3,'MATURITY',NULL),(4,'MISSION',NULL),(5,'KIDS',NULL),(6,'YOUTH',NULL),(7,'MEMBERSHIP',NULL),(8,'ADMIN',NULL),(9,'Demographics',NULL),(10,'Pastoral Care',NULL),(11,'Home Groups',3),(12,'Ministry Teams (4pm Church)',2);
/*!40000 ALTER TABLE `person_group_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `person_group_headcount`
--

DROP TABLE IF EXISTS `person_group_headcount`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `person_group_headcount` (
  `date` date NOT NULL,
  `person_groupid` int NOT NULL,
  `number` int NOT NULL,
  PRIMARY KEY (`date`,`person_groupid`),
  KEY `person_group_headcountperson_groupid` (`person_groupid`),
  CONSTRAINT `person_group_headcountperson_groupid` FOREIGN KEY (`person_groupid`) REFERENCES `_person_group` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `person_group_headcount`
--

LOCK TABLES `person_group_headcount` WRITE;
/*!40000 ALTER TABLE `person_group_headcount` DISABLE KEYS */;
/*!40000 ALTER TABLE `person_group_headcount` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `person_group_membership`
--

DROP TABLE IF EXISTS `person_group_membership`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `person_group_membership` (
  `personid` int NOT NULL DEFAULT '0',
  `groupid` int NOT NULL DEFAULT '0',
  `membership_status` int NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`personid`,`groupid`),
  KEY `personid` (`personid`),
  KEY `groupid` (`groupid`),
  KEY `membership_status_fk` (`membership_status`),
  CONSTRAINT `membership_status_fk` FOREIGN KEY (`membership_status`) REFERENCES `person_group_membership_status` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pgm_personid` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `person_group_membership`
--

LOCK TABLES `person_group_membership` WRITE;
/*!40000 ALTER TABLE `person_group_membership` DISABLE KEYS */;
INSERT INTO `person_group_membership` VALUES (2,1,1,'2026-08-06 14:15:50'),(4,1,1,'2026-08-06 14:15:51');
/*!40000 ALTER TABLE `person_group_membership` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `person_group_membership_status`
--

DROP TABLE IF EXISTS `person_group_membership_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `person_group_membership_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `rank` int NOT NULL DEFAULT '0',
  `is_default` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `label` (`label`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `person_group_membership_status`
--

LOCK TABLES `person_group_membership_status` WRITE;
/*!40000 ALTER TABLE `person_group_membership_status` DISABLE KEYS */;
INSERT INTO `person_group_membership_status` VALUES (1,'Member',0,1);
/*!40000 ALTER TABLE `person_group_membership_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `person_note`
--

DROP TABLE IF EXISTS `person_note`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `person_note` (
  `personid` int NOT NULL DEFAULT '0',
  `id` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`personid`,`id`),
  KEY `person_noteid` (`id`),
  CONSTRAINT `person_noteid` FOREIGN KEY (`id`) REFERENCES `_abstract_note` (`id`) ON DELETE CASCADE,
  CONSTRAINT `person_notepersonid` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `person_note`
--

LOCK TABLES `person_note` WRITE;
/*!40000 ALTER TABLE `person_note` DISABLE KEYS */;
INSERT INTO `person_note` VALUES (2,3),(6,4),(7,5);
/*!40000 ALTER TABLE `person_note` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `person_photo`
--

DROP TABLE IF EXISTS `person_photo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `person_photo` (
  `personid` int NOT NULL,
  `photodata` mediumblob NOT NULL,
  PRIMARY KEY (`personid`),
  CONSTRAINT `photo_personid` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `person_photo`
--

LOCK TABLES `person_photo` WRITE;
/*!40000 ALTER TABLE `person_photo` DISABLE KEYS */;
/*!40000 ALTER TABLE `person_photo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `person_query`
--

DROP TABLE IF EXISTS `person_query`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `person_query` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `creator` int NOT NULL DEFAULT '0',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `owner` int DEFAULT NULL,
  `params` text NOT NULL,
  `mailchimp_list_id` varchar(255) NOT NULL DEFAULT '',
  `show_on_homepage` varchar(12) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `person_query`
--

LOCK TABLES `person_query` WRITE;
/*!40000 ALTER TABLE `person_query` DISABLE KEYS */;
/*!40000 ALTER TABLE `person_query` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `person_status`
--

DROP TABLE IF EXISTS `person_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `person_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `rank` int NOT NULL DEFAULT '0',
  `active` tinyint unsigned DEFAULT '1',
  `is_default` tinyint unsigned DEFAULT '0',
  `is_archived` tinyint unsigned DEFAULT '0',
  `require_congregation` tinyint unsigned DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `label` (`label`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `person_status`
--

LOCK TABLES `person_status` WRITE;
/*!40000 ALTER TABLE `person_status` DISABLE KEYS */;
INSERT INTO `person_status` VALUES (1,'Core',0,1,1,0,1),(2,'Crowd',1,1,0,0,1),(3,'Contact',2,1,0,0,0),(4,'Archived',3,1,0,1,0);
/*!40000 ALTER TABLE `person_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `planned_absence`
--

DROP TABLE IF EXISTS `planned_absence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `planned_absence` (
  `id` int NOT NULL AUTO_INCREMENT,
  `personid` int NOT NULL DEFAULT '0',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `comment` text NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `creator` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `planned_absencepersonid` (`personid`),
  KEY `planned_absencecreator` (`creator`),
  CONSTRAINT `planned_absencecreator` FOREIGN KEY (`creator`) REFERENCES `_person` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `planned_absencepersonid` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `planned_absence`
--

LOCK TABLES `planned_absence` WRITE;
/*!40000 ALTER TABLE `planned_absence` DISABLE KEYS */;
/*!40000 ALTER TABLE `planned_absence` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roster_role`
--

DROP TABLE IF EXISTS `roster_role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roster_role` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `congregationid` int DEFAULT '0',
  `active` varchar(255) NOT NULL DEFAULT '1',
  `assign_multiple` varchar(255) NOT NULL DEFAULT '0',
  `volunteer_group` int DEFAULT '0',
  `details` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `roster_rolevolunteer_group` (`volunteer_group`),
  CONSTRAINT `roster_rolevolunteer_group` FOREIGN KEY (`volunteer_group`) REFERENCES `_person_group` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roster_role`
--

LOCK TABLES `roster_role` WRITE;
/*!40000 ALTER TABLE `roster_role` DISABLE KEYS */;
INSERT INTO `roster_role` VALUES (1,'Preacher',1,'1','0',NULL,''),(2,'Reader',1,'1','0',NULL,''),(3,'Band',1,'1','0',NULL,'');
/*!40000 ALTER TABLE `roster_role` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roster_role_assignment`
--

DROP TABLE IF EXISTS `roster_role_assignment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roster_role_assignment` (
  `assignment_date` date NOT NULL,
  `roster_role_id` int NOT NULL,
  `personid` int NOT NULL,
  `rank` int unsigned NOT NULL DEFAULT '0',
  `assigner` int NOT NULL,
  `assignedon` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`roster_role_id`,`assignment_date`,`personid`),
  KEY `rra_assiger` (`assigner`),
  KEY `rra_personid` (`personid`),
  CONSTRAINT `rra_assiger` FOREIGN KEY (`assigner`) REFERENCES `_person` (`id`),
  CONSTRAINT `rra_personid` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rra_roster_role_id` FOREIGN KEY (`roster_role_id`) REFERENCES `roster_role` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roster_role_assignment`
--

LOCK TABLES `roster_role_assignment` WRITE;
/*!40000 ALTER TABLE `roster_role_assignment` DISABLE KEYS */;
/*!40000 ALTER TABLE `roster_role_assignment` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roster_role_team`
--

DROP TABLE IF EXISTS `roster_role_team`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roster_role_team` (
  `roster_role_id` int NOT NULL,
  `person_group_id` int NOT NULL,
  PRIMARY KEY (`roster_role_id`,`person_group_id`),
  KEY `roster_role_team_person_group` (`person_group_id`),
  CONSTRAINT `roster_role_team_person_group` FOREIGN KEY (`person_group_id`) REFERENCES `_person_group` (`id`),
  CONSTRAINT `roster_role_team_roster_role` FOREIGN KEY (`roster_role_id`) REFERENCES `roster_role` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roster_role_team`
--

LOCK TABLES `roster_role_team` WRITE;
/*!40000 ALTER TABLE `roster_role_team` DISABLE KEYS */;
/*!40000 ALTER TABLE `roster_role_team` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roster_view`
--

DROP TABLE IF EXISTS `roster_view`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roster_view` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `visibility` varchar(255) NOT NULL DEFAULT '0',
  `show_on_run_sheet` varchar(255) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roster_view`
--

LOCK TABLES `roster_view` WRITE;
/*!40000 ALTER TABLE `roster_view` DISABLE KEYS */;
INSERT INTO `roster_view` VALUES (1,'4pm Church','','0');
/*!40000 ALTER TABLE `roster_view` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roster_view_role_membership`
--

DROP TABLE IF EXISTS `roster_view_role_membership`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roster_view_role_membership` (
  `roster_role_id` int NOT NULL,
  `roster_view_id` int NOT NULL,
  `order_num` int NOT NULL,
  PRIMARY KEY (`roster_role_id`,`roster_view_id`),
  KEY `roster_view_role_membershiproster_view_id` (`roster_view_id`),
  CONSTRAINT `roster_view_role_membershiproster_role_id` FOREIGN KEY (`roster_role_id`) REFERENCES `roster_role` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `roster_view_role_membershiproster_view_id` FOREIGN KEY (`roster_view_id`) REFERENCES `roster_view` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roster_view_role_membership`
--

LOCK TABLES `roster_view_role_membership` WRITE;
/*!40000 ALTER TABLE `roster_view_role_membership` DISABLE KEYS */;
/*!40000 ALTER TABLE `roster_view_role_membership` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roster_view_service_field`
--

DROP TABLE IF EXISTS `roster_view_service_field`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roster_view_service_field` (
  `roster_view_id` int NOT NULL,
  `congregationid` int NOT NULL,
  `service_field` varchar(32) NOT NULL,
  `order_num` int NOT NULL,
  PRIMARY KEY (`congregationid`,`roster_view_id`,`service_field`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roster_view_service_field`
--

LOCK TABLES `roster_view_service_field` WRITE;
/*!40000 ALTER TABLE `roster_view_service_field` DISABLE KEYS */;
/*!40000 ALTER TABLE `roster_view_service_field` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service`
--

DROP TABLE IF EXISTS `service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service` (
  `id` int NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `congregationid` int NOT NULL DEFAULT '0',
  `format_title` varchar(255) NOT NULL,
  `topic_title` varchar(255) NOT NULL,
  `notes` text NOT NULL,
  `comments` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `datecong` (`date`,`congregationid`),
  KEY `servicecongregationid` (`congregationid`),
  CONSTRAINT `servicecongregationid` FOREIGN KEY (`congregationid`) REFERENCES `congregation` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service`
--

LOCK TABLES `service` WRITE;
/*!40000 ALTER TABLE `service` DISABLE KEYS */;
INSERT INTO `service` VALUES (1,'2026-08-02',1,'','Shepherd','','');
/*!40000 ALTER TABLE `service` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_bible_reading`
--

DROP TABLE IF EXISTS `service_bible_reading`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_bible_reading` (
  `service_id` int NOT NULL,
  `order_num` int NOT NULL,
  `bible_ref` varchar(32) NOT NULL,
  `to_read` tinyint unsigned DEFAULT NULL,
  `to_preach` tinyint unsigned DEFAULT NULL,
  PRIMARY KEY (`service_id`,`order_num`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_bible_reading`
--

LOCK TABLES `service_bible_reading` WRITE;
/*!40000 ALTER TABLE `service_bible_reading` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_bible_reading` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_component`
--

DROP TABLE IF EXISTS `service_component`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_component` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `alt_title` varchar(255) NOT NULL,
  `categoryid` int NOT NULL DEFAULT '0',
  `comments` text NOT NULL,
  `ccli_number` int DEFAULT '0',
  `runsheet_title_format` varchar(255) NOT NULL,
  `personnel` varchar(255) NOT NULL,
  `length_mins` int NOT NULL DEFAULT '0',
  `show_in_handout` varchar(255) NOT NULL,
  `handout_title_format` varchar(255) NOT NULL,
  `show_on_slide` varchar(255) NOT NULL,
  `content_html` text NOT NULL,
  `credits` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `service_componentcategoryid` (`categoryid`),
  CONSTRAINT `service_componentcategoryid` FOREIGN KEY (`categoryid`) REFERENCES `service_component_category` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_component`
--

LOCK TABLES `service_component` WRITE;
/*!40000 ALTER TABLE `service_component` DISABLE KEYS */;
INSERT INTO `service_component` VALUES (1,'Confession AS1','',2,'',0,'','',2,'full','','','','');
/*!40000 ALTER TABLE `service_component` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_component_category`
--

DROP TABLE IF EXISTS `service_component_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_component_category` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) NOT NULL,
  `runsheet_title_format` varchar(255) NOT NULL DEFAULT '%title%',
  `handout_title_format` varchar(255) NOT NULL DEFAULT '%title%',
  `show_in_handout_default` varchar(255) NOT NULL DEFAULT 'full',
  `show_on_slide_default` varchar(255) NOT NULL DEFAULT '1',
  `personnel_default` varchar(255) NOT NULL,
  `length_mins_default` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_component_category`
--

LOCK TABLES `service_component_category` WRITE;
/*!40000 ALTER TABLE `service_component_category` DISABLE KEYS */;
INSERT INTO `service_component_category` VALUES (1,'Songs','Song: %title%','Song: %title%','full','1','%SONG_LEADER_FIRSTNAME%',3),(2,'Prayers','%title%','%title%','full','1','%SERVICE_LEADER_FIRSTNAME%',2),(3,'Creeds','The %title%','The %title%','full','1','%SERVICE_LEADER_FIRSTNAME%',2),(4,'Other','%title%','%title%','full','1','',1);
/*!40000 ALTER TABLE `service_component_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_component_tag`
--

DROP TABLE IF EXISTS `service_component_tag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_component_tag` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tag` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_component_tag`
--

LOCK TABLES `service_component_tag` WRITE;
/*!40000 ALTER TABLE `service_component_tag` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_component_tag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_component_tagging`
--

DROP TABLE IF EXISTS `service_component_tagging`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_component_tagging` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tagid` int NOT NULL DEFAULT '0',
  `componentid` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `comptag` (`tagid`,`componentid`),
  CONSTRAINT `service_component_taggingtagid` FOREIGN KEY (`tagid`) REFERENCES `service_component_tag` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_component_tagging`
--

LOCK TABLES `service_component_tagging` WRITE;
/*!40000 ALTER TABLE `service_component_tagging` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_component_tagging` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_item`
--

DROP TABLE IF EXISTS `service_item`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_item` (
  `id` int NOT NULL AUTO_INCREMENT,
  `serviceid` int NOT NULL DEFAULT '0',
  `rank` int NOT NULL DEFAULT '0',
  `componentid` int DEFAULT '0',
  `length_mins` int NOT NULL DEFAULT '0',
  `title` varchar(255) DEFAULT NULL,
  `show_in_handout` varchar(255) NOT NULL,
  `heading_text` varchar(255) NOT NULL,
  `note` text NOT NULL,
  `personnel` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `servicerank` (`serviceid`,`rank`),
  KEY `service_itemcomponentid` (`componentid`),
  CONSTRAINT `service_itemcomponentid` FOREIGN KEY (`componentid`) REFERENCES `service_component` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_itemserviceid` FOREIGN KEY (`serviceid`) REFERENCES `service` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_item`
--

LOCK TABLES `service_item` WRITE;
/*!40000 ALTER TABLE `service_item` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_item` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `setting`
--

DROP TABLE IF EXISTS `setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `setting` (
  `rank` int unsigned DEFAULT NULL,
  `heading` varchar(255) DEFAULT NULL,
  `symbol` varchar(255) NOT NULL,
  `note` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  UNIQUE KEY `setting_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setting`
--

LOCK TABLES `setting` WRITE;
/*!40000 ALTER TABLE `setting` DISABLE KEYS */;
INSERT INTO `setting` VALUES (46,'','2FA_EVEN_FOR_RESTRICTED_ACCTS','Require 2-factor auth even for accounts with group/congregation restrictions?','bool','0'),(41,'2-Factor Authentication','2FA_REQUIRED_PERMS','Users who hold permission levels selected here will be required to complete 2-factor authentication at login.','text',''),(56,'','2FA_SENDER_ID','Sender ID for 2-factor auth messages','text','Jethro'),(51,'','2FA_TRUST_DAYS','Users can tick a box to skip 2-factor auth for this many days. Set to 0 to disable.','int','30'),(166,'','ADDRESS_POSTCODE_LABEL','Label for the \'postcode\' field','text','Postcode'),(176,'','ADDRESS_POSTCODE_REGEX','Regex to validate postcodes; eg /^[0-9][0-9][0-9][0-9]$/ for 4 digits','text','/^[0-9][0-9][0-9][0-9]$/'),(171,'','ADDRESS_POSTCODE_WIDTH','Width of the postcode box','int','4'),(156,'','ADDRESS_STATE_DEFAULT','Default state','text','NSW'),(151,'','ADDRESS_STATE_LABEL','Label for the \'state\' field. (Leave blank to hide the state field)','text','State'),(146,'','ADDRESS_STATE_OPTIONS','(Leave blank to hide the state field)','multitext_cm','ACT,NSW,NT,QLD,SA,TAS,VIC,WA'),(161,'','ADDRESS_SUBURB_LABEL','Label for the \'suburb\' field','text','Suburb'),(131,'','AGE_BRACKET_OPTIONS','','',''),(106,'','ATTENDANCE_DEFAULT_DAY','Default day to record attendance','select[\"Sunday\",\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\",\"Saturday\"]','Sunday'),(111,'','ATTENDANCE_ORDER_DEFAULT','Default order for recording/showing attendance','select{\"status\":\"Status, then family name\",\"family_name\":\"Family name, then age bracket\",\"last_name\":\"Last name\",\"first_name\":\"First name\",\"age_bracket\":\"Age bracket\"}','status'),(266,'External Links','BIBLE_URL','URL Template for bible passage links, with the keyword __REFERENCE__','text','https://www.biblegateway.com/passage/?search=__REFERENCE__&version=NIVUK'),(276,'','CCLI_DETAIL_URL','URL Template for CCLI song details by song number, with the keyword __NUMBER__','text','https://songselect.ccli.com/songs/__NUMBER__'),(281,'','CCLI_REPORT_URL','URL Template for reporting usage to CCLI by song number, with keyword __NUMBER__','text','https://reporting.ccli.com/search?s=__NUMBER__&page=1&category=all'),(271,'','CCLI_SEARCH_URL','URL Template for searching CCLI, with the keyword __TITLE__','text','https://songselect.ccli.com/search/results?search=__TITLE__'),(91,'','CHUNK_SIZE','Batch size to aim for when dividing lists of items','int','100'),(66,'','DEFAULT_NOTE_STATUS','Default status when creating a new note','select{\"no_action\":\"No Action Required\",\"pending\":\"Requires Action\"}','pending'),(16,'','DEFAULT_PERMISSIONS','Permissions to grant to new user accounts by default','int','7995391'),(301,'','EMAIL_CHUNK_SIZE','When displaying mailto links for emails, divide into batches of this size','int','25'),(11,'Permissions and Security','ENABLED_FEATURES','Which Jethro features are visible to users?','multiselect{\"NOTES\":\"Notes\",\"PHOTOS\":\"Photos\",\"ATTENDANCE\":\"Attendance\",\"ROSTERS&SERVICES\":\"Rosters & Services\",\"SERVICEDETAILS\":\"Service Details\",\"DOCUMENTS\":\"Documents\",\"SERVICEDOCUMENTS\":\"Service documents\"}','NOTES,PHOTOS,ATTENDANCE,ROSTERS&SERVICES,SERVICEDETAILS,DOCUMENTS,SERVICEDOCUMENTS'),(121,'','ENVELOPE_HEIGHT_MM','Envelope height (mm)','int','110'),(116,'','ENVELOPE_WIDTH_MM','Envelope width (mm)','int','220'),(136,'','GROUP_MEMBERSHIP_STATUS_OPTIONS','','',''),(181,'','HOME_TEL_FORMATS','Valid formats for home phone; use X for a digit','multitext_nl','XXXX-XXXX\n(XX) XXXX-XXXX'),(76,'','LOCK_LENGTH','Number of minutes users have to edit an object before their lock expires','int','10'),(331,'Mailchimp Sync','MAILCHIMP_API_KEY','API Key for Mailchimp integration. NB the mailchimp sync script must also be called regularly by cron.','text',''),(291,'','MAP_LOOKUP_URL','URL template for map links, with the keywords __ADDRESS_STREET__, __ADDRESS_SUBURB__, __ADDRESS_POSTCODE__, __ADDRESS_STATE__','text','http://maps.google.com.au?q=__ADDRESS_STREET__,%20__ADDRESS_SUBURB__,%20__ADDRESS_STATE__,%20__ADDRESS_POSTCODE__'),(216,'','MEMBERS_SEE_AGE_BRACKET','Should members be able to see and edit the age bracket field?','bool','1'),(241,'','MEMBERS_SHARE_ADDRESS','Should addresses be visible in the members area?','bool','0'),(196,'Member area','MEMBER_LOGIN_ENABLED','Should church members be able to log in at <system_url>members ?','bool','0'),(236,'','MEMBER_PASSWORD_MIN_LENGTH','Minimum length for member passwords','int','7'),(206,'','MEMBER_REGO_EMAIL_FROM_ADDRESS','Sender address for member rego emails','text',''),(201,'','MEMBER_REGO_EMAIL_FROM_NAME','Sender name for member rego emails','text',''),(211,'','MEMBER_REGO_EMAIL_SUBJECT','Subject for member rego emails','text','Setting up your account'),(231,'','MEMBER_REGO_FAILURE_EMAIL','Address to notifiy when member rego fails','text',''),(226,'','MEMBER_REGO_HELP_EMAIL','Address that users can contact for assistance with member rego (optional)','text',''),(221,'','MEMBER_VISIBLE_FOLDERS','Folders in Documents which, if they exist, are visible to Members. Separate multiple directories with a pipe (|) character.','text','Member_Files'),(191,'','MOBILE_TEL_FORMATS','Valid formats for mobile phone; use X for a digit','multitext_nl','XXXX-XXX-XXX'),(306,'','MULTI_EMAIL_SEPARATOR','When displaying mailto links for emails, separate addresses using this character','text',','),(86,'','NOTES_LINK_TO_EDIT','Should the homepage notes list link to the edit-note page?','bool','0'),(71,'','NOTES_ORDER','Order to display person and family notes','select{\"ASC\":\"Oldest first\",\"DESC\":\"Newest first\"}','ASC'),(26,'','PASSWORD_MIN_LENGTH','Minimum password length','int','8'),(81,'','PERSON_LIST_SHOW_GROUPS','Show all groups when listing persons?','bool','0'),(126,'Data Structure options','PERSON_STATUS_OPTIONS','','',''),(286,'','POSTCODE_LOOKUP_URL','URL template for looking up postcodes, with the keyword __SUBURB__','text','https://auspost.com.au/postcode/__SUBURB__'),(251,'Public area','PUBLIC_AREA_ENABLED','Whether to allow public access to certain info at <system_url>public','bool',''),(261,'','PUBLIC_ROSTER_SECRET','Advanced: Only allow access to public rosters if the URL contains \"&secret=<this-secret>\"','text',''),(296,'','QR_CODE_GENERATOR_URL','URL template for generating QR codes, containing the placeholder __URL__','text','https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=__URL__'),(96,'','REPEAT_DATE_THRESHOLD','When a roster has this many columns, show the date on the right as well as the left','int','10'),(61,'Jethro Behaviour Options','REQUIRE_INITIAL_NOTE','Whether an initial note is required when adding new family','bool','1'),(21,'','RESTRICTED_USERS_CAN_ADD','Allow users with group/congregation restrictions to create new persons and families?','bool','0'),(246,'iCal feeds','ROSTER_FEEDS_ENABLED','Whether users can access their roster assignments via an ical feed with secret URL','bool','1'),(101,'','ROSTER_WEEKS_DEFAULT','Number of weeks to show in rosters by default','int','8'),(36,'','SESSION_MAXLENGTH_MINS','Every session will be logged out this many minutes after login','int','480'),(31,'','SESSION_TIMEOUT_MINS','Inactive sessions will be logged out after this number of minutes','int','90'),(256,'','SHOW_SERVICE_NOTES_PUBLICLY','Should service notes be visible in the public area at <system_url>public?','bool',''),(371,'','SMS_HTTP_HEADER_TEMPLATE','Template for the headers of a request to the SMS messaging service','text_ml',''),(376,'','SMS_HTTP_POST_TEMPLATE','Template for the body of a request to the SMS messaging service','text_ml',''),(391,'','SMS_HTTP_RESPONSE_ERROR_REGEX','Regex for recognising an API error','text_ml',''),(386,'','SMS_HTTP_RESPONSE_OK_REGEX','Regex for recognising a successful send','text_ml',''),(366,'','SMS_HTTP_URL','URL of the SMS messaging service','text',''),(401,'','SMS_INTERNATIONAL_PREFIX','Used for converting local to international numbers. eg +61','text','+61'),(396,'','SMS_LOCAL_PREFIX','Used for converting local to international numbers.  eg 0','text','0'),(361,'SMS Gateway','SMS_MAX_LENGTH','','int','160'),(381,'','SMS_RECIPIENT_ARRAY_PARAMETER','','text',''),(406,'','SMS_SAVE_TO_NOTE_BY_DEFAULT','Whether to save each sent SMS as a person note by default','bool',''),(411,'','SMS_SAVE_TO_NOTE_SUBJECT','','text','SMS Sent'),(416,'','SMS_SEND_LOGFILE','File on the server to save a log of sent SMS messages','text',''),(341,'','SMTP_ENCRYPTION','Encryption method for SMTP server','select{\"ssl\":\"SSL\",\"tls\":\"TLS\",\"\":\"(None)\"}',''),(351,'','SMTP_PASSWORD','Password for SMTP server','text',''),(356,'','SMTP_PORT','Port to connect to the SMTP server. Usually 25, 465 for SSL, or 587 for TLS.','int','25'),(336,'SMTP Email Server','SMTP_SERVER','SMTP server for sending emails','text',''),(346,'','SMTP_USERNAME','Username for SMTP server','text',''),(6,'','SYSTEM_NAME','Label displayed at the top of every page','text','St DemosVille'),(311,'Task Notifications','TASK_NOTIFICATION_ENABLED','(This feature also requires the task_reminder.php script to be called by cron every 5 minutes)','bool','0'),(321,'','TASK_NOTIFICATION_FROM_ADDRESS','Email address from which task notifications should be sent','text',''),(316,'','TASK_NOTIFICATION_FROM_NAME','Name from which task notifications should be sent','text','Jethro'),(326,'','TASK_NOTIFICATION_SUBJECT','','text','New notes assigned to you'),(141,'','TIMEZONE','','text','Australia/Sydney'),(186,'','WORK_TEL_FORMATS','Valid formats for work phone; use X for a digit','multitext_nl','XXXX-XXXX\n(XX) XXXX-XXXX');
/*!40000 ALTER TABLE `setting` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_member`
--

DROP TABLE IF EXISTS `staff_member`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff_member` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `active` varchar(255) NOT NULL DEFAULT '1',
  `permissions` varchar(255) NOT NULL DEFAULT '2147483647',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_member`
--

LOCK TABLES `staff_member` WRITE;
/*!40000 ALTER TABLE `staff_member` DISABLE KEYS */;
INSERT INTO `staff_member` VALUES (1,'demo','$2y$12$JWv0wQl8PW8NqEqiUzHY3e0eeneWyjWpmLe0x9Q7z7GzpHFt3ePFi','1','2147483647'),(2,'tom','$2y$12$yzTeamvUXI10fGLuAZko8ukQVvgxlWLZvjgtH3PlfjQWtrcD7.yje','1','2147483647'),(3,'jturner','$2y$12$xWL2tlXKb77Yo2gBSD4.QuSdYe8q1tDBvuGFo2qvdN68sUTsc.35C','1','2147483647');
/*!40000 ALTER TABLE `staff_member` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `venue`
--

DROP TABLE IF EXISTS `venue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `venue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `set_attendance` varchar(255) NOT NULL,
  `thanks_message` text NOT NULL,
  `is_archived` varchar(255) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `venue`
--

LOCK TABLES `venue` WRITE;
/*!40000 ALTER TABLE `venue` DISABLE KEYS */;
/*!40000 ALTER TABLE `venue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'jethro_functest_walkthrough'
--

--
-- Dumping routines for database 'jethro_functest_walkthrough'
--
/*!50003 DROP FUNCTION IF EXISTS `getCurrentUserID` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = latin1 */ ;
/*!50003 SET character_set_results = latin1 */ ;
/*!50003 SET collation_connection  = latin1_swedish_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
CREATE DEFINER=`jethro`@`localhost` FUNCTION `getCurrentUserID`() RETURNS int
    NO SQL
RETURN @current_user_id ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Final view structure for view `abstract_note`
--

/*!50001 DROP VIEW IF EXISTS `abstract_note`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = latin1 */;
/*!50001 SET character_set_results     = latin1 */;
/*!50001 SET collation_connection      = latin1_swedish_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`jethro`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `abstract_note` AS select `an`.`id` AS `id`,`an`.`subject` AS `subject`,`an`.`details` AS `details`,`an`.`status` AS `status`,`an`.`status_last_changed` AS `status_last_changed`,`an`.`assignee` AS `assignee`,`an`.`assignee_last_changed` AS `assignee_last_changed`,`an`.`action_date` AS `action_date`,`an`.`creator` AS `creator`,`an`.`created` AS `created`,`an`.`editor` AS `editor`,`an`.`edited` AS `edited`,`an`.`history` AS `history` from `_abstract_note` `an` where (((`an`.`assignee` = `getCurrentUserID`()) and (`an`.`status` = 'pending')) or (`getCurrentUserID`() = -(1)) or (48 = (select (`staff_member`.`permissions` & 48) from `staff_member` where (`staff_member`.`id` = `getCurrentUserID`())))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `member`
--

/*!50001 DROP VIEW IF EXISTS `member`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = latin1 */;
/*!50001 SET character_set_results     = latin1 */;
/*!50001 SET collation_connection      = latin1_swedish_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`jethro`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `member` AS select `mp`.`id` AS `id`,`mp`.`first_name` AS `first_name`,`mp`.`last_name` AS `last_name`,`mp`.`gender` AS `gender`,`mp`.`age_bracketid` AS `age_bracketid`,`mp`.`congregationid` AS `congregationid`,`mp`.`email` AS `email`,`mp`.`mobile_tel` AS `mobile_tel`,`mp`.`work_tel` AS `work_tel`,`mp`.`familyid` AS `familyid`,`mf`.`family_name` AS `family_name`,`mf`.`address_street` AS `address_street`,`mf`.`address_suburb` AS `address_suburb`,`mf`.`address_state` AS `address_state`,`mf`.`address_postcode` AS `address_postcode`,`mf`.`home_tel` AS `home_tel` from (((((((`_person` `mp` join `person_status` `mps` on((`mps`.`id` = `mp`.`status`))) join `family` `mf` on((`mf`.`id` = `mp`.`familyid`))) join `person_group_membership` `pgm1` on((`pgm1`.`personid` = `mp`.`id`))) join `_person_group` `pg` on(((`pg`.`id` = `pgm1`.`groupid`) and (`pg`.`share_member_details` = 1)))) join `person_group_membership` `pgm2` on((`pgm2`.`groupid` = `pg`.`id`))) join `_person` `up` on((`up`.`id` = `pgm2`.`personid`))) join `person_status` `ups` on((`ups`.`id` = `up`.`status`))) where ((`up`.`id` = `getCurrentUserID`()) and (0 = `mps`.`is_archived`) and (`mf`.`status` <> 'archived') and (0 = `ups`.`is_archived`)) union select `mp`.`id` AS `id`,`mp`.`first_name` AS `first_name`,`mp`.`last_name` AS `last_name`,`mp`.`gender` AS `gender`,`mp`.`age_bracketid` AS `age_bracketid`,`mp`.`congregationid` AS `congregationid`,`mp`.`email` AS `email`,`mp`.`mobile_tel` AS `mobile_tel`,`mp`.`work_tel` AS `work_tel`,`mp`.`familyid` AS `familyid`,`mf`.`family_name` AS `family_name`,`mf`.`address_street` AS `address_street`,`mf`.`address_suburb` AS `address_suburb`,`mf`.`address_state` AS `address_state`,`mf`.`address_postcode` AS `address_postcode`,`mf`.`home_tel` AS `home_tel` from ((((`_person` `mp` join `person_status` `mps` on((`mps`.`id` = `mp`.`status`))) join `family` `mf` on((`mf`.`id` = `mp`.`familyid`))) join `_person` `self` on((`self`.`familyid` = `mp`.`familyid`))) join `person_status` `selfs` on((`selfs`.`id` = `self`.`status`))) where ((`self`.`id` = `getCurrentUserID`()) and ((0 = `mps`.`is_archived`) or (`mp`.`id` = `self`.`id`)) and ((0 = `selfs`.`is_archived`) or (`mp`.`id` = `self`.`id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `person`
--

/*!50001 DROP VIEW IF EXISTS `person`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = latin1 */;
/*!50001 SET character_set_results     = latin1 */;
/*!50001 SET collation_connection      = latin1_swedish_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`jethro`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `person` AS select `p`.`id` AS `id`,`p`.`first_name` AS `first_name`,`p`.`last_name` AS `last_name`,`p`.`gender` AS `gender`,`p`.`age_bracketid` AS `age_bracketid`,`p`.`email` AS `email`,`p`.`mobile_tel` AS `mobile_tel`,`p`.`work_tel` AS `work_tel`,`p`.`remarks` AS `remarks`,`p`.`status` AS `status`,`p`.`status_last_changed` AS `status_last_changed`,`p`.`history` AS `history`,`p`.`creator` AS `creator`,`p`.`created` AS `created`,`p`.`congregationid` AS `congregationid`,`p`.`familyid` AS `familyid`,`p`.`member_password` AS `member_password`,`p`.`resethash` AS `resethash`,`p`.`resetexpires` AS `resetexpires`,`p`.`feed_uuid` AS `feed_uuid` from `_person` `p` where ((`getCurrentUserID`() is not null) and ((`p`.`id` = `getCurrentUserID`()) or (`getCurrentUserID`() = -(1)) or ((0 = (select count(`cr`.`congregationid`) from `account_congregation_restriction` `cr` where (`cr`.`personid` = `getCurrentUserID`()))) and (0 = (select count(`gr`.`groupid`) from `account_group_restriction` `gr` where (`gr`.`personid` = `getCurrentUserID`())))) or `p`.`congregationid` in (select `cr`.`congregationid` AS `congregationid` from `account_congregation_restriction` `cr` where (`cr`.`personid` = `getCurrentUserID`())) or `p`.`id` in (select `m`.`personid` AS `personid` from (`person_group_membership` `m` join `account_group_restriction` `gr` on((`m`.`groupid` = `gr`.`groupid`))) where (`gr`.`personid` = `getCurrentUserID`())))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `person_group`
--

/*!50001 DROP VIEW IF EXISTS `person_group`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = latin1 */;
/*!50001 SET character_set_results     = latin1 */;
/*!50001 SET collation_connection      = latin1_swedish_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`jethro`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `person_group` AS select `g`.`id` AS `id`,`g`.`name` AS `name`,`g`.`categoryid` AS `categoryid`,`g`.`is_archived` AS `is_archived`,`g`.`owner` AS `owner`,`g`.`show_add_family` AS `show_add_family`,`g`.`share_member_details` AS `share_member_details`,`g`.`attendance_recording_days` AS `attendance_recording_days` from `_person_group` `g` where ((`getCurrentUserID`() is not null) and ((`g`.`owner` is null) or (`g`.`owner` = `getCurrentUserID`())) and (exists(select 1 from `account_group_restriction` `gr` where (`gr`.`personid` = `getCurrentUserID`())) is false or `g`.`id` in (select `gr`.`groupid` from `account_group_restriction` `gr` where (`gr`.`personid` = `getCurrentUserID`())))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-07  0:31:18
