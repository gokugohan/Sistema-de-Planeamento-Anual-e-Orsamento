/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `is_test_data` tinyint(1) NOT NULL DEFAULT '0',
  `fiscal_year` year(4) NOT NULL DEFAULT '2027',
  `program_id` bigint(20) unsigned NOT NULL,
  `subprogram_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `directorate_id` bigint(20) unsigned NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `objective` text COLLATE utf8mb4_unicode_ci,
  `manager_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quarter` int(11) NOT NULL DEFAULT '1',
  `priority` enum('Alta','Média','Baixa') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Média',
  `planned_start` date NOT NULL,
  `planned_end` date NOT NULL,
  `execution_quarters` json DEFAULT NULL,
  `period_type` enum('quarterly','semi_annual','annual','custom') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'custom',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `version` smallint(5) unsigned NOT NULL DEFAULT '0',
  `revision_reason` text COLLATE utf8mb4_unicode_ci,
  `external_approval_required` tinyint(1) NOT NULL DEFAULT '0',
  `external_approval_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `procurement_required` tinyint(1) NOT NULL DEFAULT '0',
  `gender_responsive` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `budget_confirmed_at` timestamp NULL DEFAULT NULL,
  `budget_confirmed_by` bigint(20) unsigned DEFAULT NULL,
  `budget_justification` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activities_unit_year_code_unique` (`fiscal_year`,`directorate_id`,`code`),
  KEY `activities_approved_by_foreign` (`approved_by`),
  KEY `activities_is_test_data_index` (`is_test_data`),
  KEY `activities_reviewed_by_foreign` (`reviewed_by`),
  KEY `activities_year_status_idx` (`fiscal_year`,`status`),
  KEY `activities_directorate_idx` (`directorate_id`),
  KEY `activities_program_idx` (`program_id`),
  KEY `activities_year_prod_status_unit_idx` (`fiscal_year`,`is_test_data`,`status`,`directorate_id`),
  KEY `activities_year_prod_program_idx` (`fiscal_year`,`is_test_data`,`program_id`),
  KEY `activities_subprogram_id_foreign` (`subprogram_id`),
  KEY `activities_budget_confirmed_by_foreign` (`budget_confirmed_by`),
  KEY `activities_submitted_by_foreign` (`submitted_by`),
  CONSTRAINT `activities_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `activities_budget_confirmed_by_foreign` FOREIGN KEY (`budget_confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activities_directorate_id_foreign` FOREIGN KEY (`directorate_id`) REFERENCES `directorates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activities_program_id_foreign` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activities_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activities_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activities_subprogram_id_foreign` FOREIGN KEY (`subprogram_id`) REFERENCES `subprograms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` bigint(20) unsigned NOT NULL,
  `period_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period` tinyint(3) unsigned NOT NULL,
  `results` text COLLATE utf8mb4_unicode_ci,
  `constraints` text COLLATE utf8mb4_unicode_ci,
  `reasons_not_met` text COLLATE utf8mb4_unicode_ci,
  `corrective_measures` text COLLATE utf8mb4_unicode_ci,
  `rating` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compiled_results` text COLLATE utf8mb4_unicode_ci,
  `compiled_constraints` text COLLATE utf8mb4_unicode_ci,
  `compiled_by` bigint(20) unsigned DEFAULT NULL,
  `compiled_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `rejection_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_reports_activity_id_period_type_period_unique` (`activity_id`,`period_type`,`period`),
  KEY `activity_reports_submitted_by_foreign` (`submitted_by`),
  KEY `activity_reports_verified_by_foreign` (`verified_by`),
  KEY `activity_reports_compiled_by_foreign` (`compiled_by`),
  CONSTRAINT `activity_reports_activity_id_foreign` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activity_reports_compiled_by_foreign` FOREIGN KEY (`compiled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_reports_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_reports_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_signatures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_signatures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` bigint(20) unsigned NOT NULL,
  `stage` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `round` smallint(5) unsigned NOT NULL DEFAULT '1',
  `user_id` bigint(20) unsigned NOT NULL,
  `signed_by` bigint(20) unsigned DEFAULT NULL,
  `state` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `close_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_signatures_user_id_foreign` (`user_id`),
  KEY `activity_signatures_signed_by_foreign` (`signed_by`),
  KEY `activity_signatures_closed_by_foreign` (`closed_by`),
  KEY `activity_signatures_activity_id_index` (`activity_id`),
  KEY `activity_signatures_activity_id_round_stage_index` (`activity_id`,`round`,`stage`),
  CONSTRAINT `activity_signatures_activity_id_foreign` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activity_signatures_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_signatures_signed_by_foreign` FOREIGN KEY (`signed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_signatures_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` bigint(20) unsigned NOT NULL,
  `version` smallint(5) unsigned NOT NULL,
  `snapshot` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_versions_activity_id_version_unique` (`activity_id`,`version`),
  KEY `activity_versions_approved_by_foreign` (`approved_by`),
  CONSTRAINT `activity_versions_activity_id_foreign` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activity_versions_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `adjusted_budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `adjusted_budgets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `line_item_id` bigint(20) unsigned NOT NULL,
  `approved_amount` decimal(15,2) NOT NULL,
  `status` enum('Approved','Partial','Cut') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Approved',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `adjusted_budgets_line_item_idx` (`line_item_id`),
  KEY `adjusted_budgets_line_item_amount_idx` (`line_item_id`,`approved_amount`),
  CONSTRAINT `adjusted_budgets_budget_proposal_id_foreign` FOREIGN KEY (`line_item_id`) REFERENCES `line_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `alignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `alignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `alignable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alignable_id` bigint(20) unsigned NOT NULL,
  `framework` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alignments_alignable_type_alignable_id_index` (`alignable_type`,`alignable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_delegations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approval_delegations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `directorate_id` bigint(20) unsigned NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_delegations_directorate_id_foreign` (`directorate_id`),
  KEY `approval_delegations_created_by_foreign` (`created_by`),
  KEY `approval_delegations_user_id_starts_on_ends_on_index` (`user_id`,`starts_on`,`ends_on`),
  CONSTRAINT `approval_delegations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approval_delegations_directorate_id_foreign` FOREIGN KEY (`directorate_id`) REFERENCES `directorates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approval_delegations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approver_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `approver_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `directorate_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `step` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT '1',
  `sequential` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `approver_assignments_directorate_id_user_id_step_unique` (`directorate_id`,`user_id`,`step`),
  KEY `approver_assignments_user_id_foreign` (`user_id`),
  KEY `approver_assignments_created_by_foreign` (`created_by`),
  KEY `approver_assignments_directorate_id_step_index` (`directorate_id`,`step`),
  CONSTRAINT `approver_assignments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approver_assignments_directorate_id_foreign` FOREIGN KEY (`directorate_id`) REFERENCES `directorates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approver_assignments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `auditable_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auditable_id` bigint(20) unsigned DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_auditable_idx` (`auditable_type`,`auditable_id`),
  KEY `audit_logs_user_date_idx` (`user_id`,`created_at`),
  KEY `audit_logs_action_idx` (`action`),
  KEY `audit_logs_created_idx` (`created_at`),
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budget_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `budget_executions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `line_item_id` bigint(20) unsigned NOT NULL,
  `quarter` tinyint(3) unsigned NOT NULL,
  `month` tinyint(3) unsigned NOT NULL,
  `committed` decimal(15,2) NOT NULL DEFAULT '0.00',
  `paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `budget_executions_line_item_id_month_unique` (`line_item_id`,`month`),
  KEY `budget_executions_submitted_by_foreign` (`submitted_by`),
  KEY `budget_executions_verified_by_foreign` (`verified_by`),
  KEY `budget_executions_quarter_status_index` (`quarter`,`status`),
  KEY `budget_executions_month_status_index` (`month`,`status`),
  CONSTRAINT `budget_executions_line_item_id_foreign` FOREIGN KEY (`line_item_id`) REFERENCES `line_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_executions_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_executions_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `corrective_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `corrective_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` bigint(20) unsigned NOT NULL,
  `quarter` tinyint(3) unsigned NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_on` date DEFAULT NULL,
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Open',
  `closing_note` text COLLATE utf8mb4_unicode_ci,
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `corrective_actions_closed_by_foreign` (`closed_by`),
  KEY `corrective_actions_created_by_foreign` (`created_by`),
  KEY `corrective_actions_activity_id_status_index` (`activity_id`,`status`),
  CONSTRAINT `corrective_actions_activity_id_foreign` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `corrective_actions_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `corrective_actions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `directorates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `directorates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `administration` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `legal_form` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legal_basis` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `director_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `director_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `established_year` int(11) DEFAULT NULL,
  `dissolved_year` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `directorates_code_unique` (`code`),
  KEY `directorates_parent_id_foreign` (`parent_id`),
  CONSTRAINT `directorates_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `directorates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `economic_classifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `economic_classifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `category_type` enum('Current','Capital') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Current',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `economic_classifications_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `funding_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funding_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `funding_sources_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `indicator_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `indicator_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `indicator_id` bigint(20) unsigned NOT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT '1',
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `months` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `indicator_actions_indicator_id_position_index` (`indicator_id`,`position`),
  CONSTRAINT `indicator_actions_indicator_id_foreign` FOREIGN KEY (`indicator_id`) REFERENCES `indicators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `indicator_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `indicator_progress` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `indicator_id` bigint(20) unsigned NOT NULL,
  `quarter` int(11) NOT NULL,
  `achieved_value` decimal(15,2) NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `rejection_notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `indicator_progress_submitted_by_foreign` (`submitted_by`),
  KEY `indicator_progress_verified_by_foreign` (`verified_by`),
  KEY `indicator_progress_ind_status_idx` (`indicator_id`,`status`),
  CONSTRAINT `indicator_progress_indicator_id_foreign` FOREIGN KEY (`indicator_id`) REFERENCES `indicators` (`id`) ON DELETE CASCADE,
  CONSTRAINT `indicator_progress_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `indicator_progress_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `indicators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `indicators` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Output','Outcome') COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `measure` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cumulative',
  `baseline` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `annual_target` decimal(15,2) NOT NULL,
  `target_text` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `means_of_verification` text COLLATE utf8mb4_unicode_ci,
  `funding_source` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `due_quarters` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `indicators_activity_idx` (`activity_id`),
  CONSTRAINT `indicators_activity_id_foreign` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `legacy_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `legacy_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `legacy_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `legacy_role_permissions` (
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `role_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `legacy_permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `legacy_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `legacy_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `legacy_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `line_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `line_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `is_test_data` tinyint(1) NOT NULL DEFAULT '0',
  `activity_id` bigint(20) unsigned NOT NULL,
  `indicator_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `economic_classification_id` bigint(20) unsigned NOT NULL,
  `funding_source_id` bigint(20) unsigned DEFAULT NULL,
  `account_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estimated_amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `line_items_is_test_data_index` (`is_test_data`),
  KEY `line_items_activity_idx` (`activity_id`),
  KEY `line_items_class_idx` (`economic_classification_id`),
  KEY `line_items_activity_prod_class_amount_idx` (`activity_id`,`is_test_data`,`economic_classification_id`,`estimated_amount`),
  KEY `line_items_funding_source_id_foreign` (`funding_source_id`),
  KEY `line_items_indicator_id_foreign` (`indicator_id`),
  CONSTRAINT `budget_proposals_activity_id_foreign` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_proposals_economic_classification_id_foreign` FOREIGN KEY (`economic_classification_id`) REFERENCES `economic_classifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `line_items_funding_source_id_foreign` FOREIGN KEY (`funding_source_id`) REFERENCES `funding_sources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `line_items_indicator_id_foreign` FOREIGN KEY (`indicator_id`) REFERENCES `indicators` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `module` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plan_baselines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plan_baselines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_year` smallint(5) unsigned NOT NULL,
  `versions` json NOT NULL,
  `activity_count` int(10) unsigned NOT NULL,
  `total` decimal(15,2) NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plan_baselines_recorded_by_foreign` (`recorded_by`),
  KEY `plan_baselines_fiscal_year_index` (`fiscal_year`),
  CONSTRAINT `plan_baselines_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `programs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_year` smallint(5) unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `oge_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `manager_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `objective` text COLLATE utf8mb4_unicode_ci,
  `kpis` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `programs_fiscal_year_oge_code_unique` (`fiscal_year`,`oge_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reporting_windows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reporting_windows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_year` smallint(5) unsigned NOT NULL,
  `period_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period` tinyint(3) unsigned NOT NULL,
  `opens_on` date NOT NULL,
  `closes_on` date NOT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reporting_windows_fiscal_year_period_type_period_unique` (`fiscal_year`,`period_type`,`period`),
  KEY `reporting_windows_created_by_foreign` (`created_by`),
  CONSTRAINT `reporting_windows_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `result_indicators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `result_indicators` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `indicatable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `indicatable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `baseline` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `means_of_verification` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `result_indicators_indicatable_type_indicatable_id_index` (`indicatable_type`,`indicatable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sample_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sample_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dataset` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sample_records_model_type_model_id_unique` (`model_type`,`model_id`),
  KEY `sample_records_dataset_index` (`dataset`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subprograms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subprograms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `program_id` bigint(20) unsigned NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `objective` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subprograms_program_id_code_unique` (`program_id`,`code`),
  CONSTRAINT `subprograms_program_id_foreign` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `unit_plannings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `unit_plannings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_year` smallint(5) unsigned NOT NULL,
  `directorate_id` bigint(20) unsigned NOT NULL,
  `planning_by` bigint(20) unsigned DEFAULT NULL,
  `planning_at` timestamp NULL DEFAULT NULL,
  `planning_remark` text COLLATE utf8mb4_unicode_ci,
  `nil_return` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unit_plannings_fiscal_year_directorate_id_unique` (`fiscal_year`,`directorate_id`),
  KEY `unit_plannings_directorate_id_foreign` (`directorate_id`),
  KEY `unit_plannings_planning_by_foreign` (`planning_by`),
  CONSTRAINT `unit_plannings_directorate_id_foreign` FOREIGN KEY (`directorate_id`) REFERENCES `directorates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `unit_plannings_planning_by_foreign` FOREIGN KEY (`planning_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `two_factor_last_step` int(10) unsigned DEFAULT NULL,
  `directorate_id` bigint(20) unsigned DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_directorate_id_foreign` (`directorate_id`),
  CONSTRAINT `users_directorate_id_foreign` FOREIGN KEY (`directorate_id`) REFERENCES `directorates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_01_22_232232_create_directorates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_01_22_232232_create_programs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_01_22_232232_z_create_economic_classifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_01_22_232233_create_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_01_22_232234_create_indicators_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_01_22_232234_z_create_indicator_progress_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_01_22_232235_create_budget_proposals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_01_22_232236_create_fiscal_envelopes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_01_22_232237_create_envelope_allocations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_01_22_232238_create_adjusted_budgets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_01_22_234410_create_audit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_01_23_001254_add_is_active_to_fiscal_envelopes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_01_24_031259_add_details_to_fiscal_envelopes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_01_24_051951_create_fiscal_envelope_category_allocations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_01_24_060700_create_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_01_24_060701_create_permissions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_01_24_060702_create_role_permissions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_01_24_060703_add_role_id_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_01_24_063758_update_fiscal_envelope_status_enum',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_01_24_063800_update_fiscal_envelope_status_enum',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_01_24_065335_enhance_economic_categorization_and_tracking_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_01_24_070326_add_category_type_to_economic_classifications',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_01_24_080000_enhance_directorates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_01_24_090000_enhance_programs_activities_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_01_24_103059_rename_budget_proposals_to_line_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_01_24_103529_rename_budget_proposal_id_to_line_item_id_in_adjusted_budgets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_01_24_112110_add_validity_dates_to_fiscal_envelopes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_01_24_125000_rename_legacy_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_01_24_125015_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_01_24_125121_add_extra_columns_to_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_01_24_145056_drop_envelope_allocations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_01_24_154820_drop_legacy_role_columns_from_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_01_24_155452_create_system_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_01_24_193812_add_workflow_columns_to_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_01_25_030300_remove_is_active_from_fiscal_envelopes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_01_25_030301_simplify_fiscal_envelope_status',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_01_25_030302_add_testing_mode_support',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_01_26_082742_add_approval_columns_to_indicator_progress_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_01_26_082837_add_audit_columns_to_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_01_26_094219_create_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_01_26_100624_add_lifecycle_columns_to_directorates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_01_26_165825_add_parent_id_and_type_to_directorates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_10_08_000000_add_performance_indexes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_10_09_000000_extend_audit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_10_09_100000_add_legal_basis_to_fiscal_envelopes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_10_09_200000_create_budget_cycles_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_10_09_300000_create_sample_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_10_09_400000_add_must_change_password_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_10_09_500000_add_two_factor_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_10_10_100000_add_planning_fields_to_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_10_10_200000_add_administration_to_directorates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_10_10_300000_add_baseline_and_target_text_to_indicators_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_10_14_100000_add_composite_indexes_for_year_scoped_queries',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_10_15_100000_create_budget_executions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_10_16_100000_make_programs_per_fiscal_year',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_10_17_100000_add_planning_state_to_budget_cycles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_10_18_100000_add_measure_to_indicators',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_10_19_100000_create_subprograms_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_10_20_100000_enrich_indicators_and_add_result_indicators_and_alignments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_10_21_100000_make_budget_executions_monthly',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_10_22_100000_create_activity_reports_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_10_23_100000_create_reporting_windows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_10_24_100000_create_funding_sources_and_tag_line_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_10_25_100000_create_approval_delegations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_10_26_100000_add_compilation_to_activity_reports',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_10_27_100000_create_approver_assignments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_10_28_100000_add_budget_confirmation_to_activities',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_10_29_100000_add_implementation_plan_to_indicators',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_10_30_100000_create_indicator_actions_and_budget_by_indicator',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_10_31_100000_create_unit_plannings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_11_02_100000_add_sequential_signing',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_11_03_100000_version_signatures',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_11_04_100000_create_activity_versions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_11_04_110000_remove_fiscal_envelope_permissions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_11_05_100000_drop_envelope_and_budget_cycle_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_11_06_100000_rename_planner_role_label',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_11_10_100000_add_soft_delete_to_line_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_11_10_110000_make_activity_codes_unique_per_unit_and_year',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_11_10_120000_add_submitted_by_to_activities',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_11_12_100000_add_external_approval_to_activities',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_11_12_110000_add_nil_return_to_unit_plannings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_11_12_120000_create_corrective_actions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_11_12_130000_create_plan_baselines_table',1);
