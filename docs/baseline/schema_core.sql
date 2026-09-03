Create Table: CREATE TABLE `clubs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `apply_title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`apply_title`)),
  `extended_title` varchar(255) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `zip_code` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `country` varchar(255) NOT NULL,
  `preferred_locale` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `website_url` text DEFAULT NULL,
  `primary_color` varchar(255) DEFAULT NULL,
  `logo_url` text DEFAULT NULL,
  `privacy_statement_url` text DEFAULT NULL,
  `contribution_statement_url` text DEFAULT NULL,
  `constitution_url` text DEFAULT NULL,
  `membership_start_cycle_type` varchar(255) DEFAULT NULL,
  `allow_voluntary_contribution` tinyint(1) DEFAULT NULL,
  `has_consented_media_publication_is_required` tinyint(1) NOT NULL DEFAULT 0,
  `has_consented_media_publication_default_value` tinyint(1) NOT NULL DEFAULT 0,
  `tax_account_chart_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clubs_slug_unique` (`slug`),
  KEY `clubs_tax_account_chart_id_foreign` (`tax_account_chart_id`),
  CONSTRAINT `clubs_tax_account_chart_id_foreign` FOREIGN KEY (`tax_account_chart_id`) REFERENCES `tax_account_charts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

Create Table: CREATE TABLE `membership_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`title`)),
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`description`)),
  `admission_fee` int(10) unsigned DEFAULT NULL,
  `monthly_fee` int(10) unsigned NOT NULL,
  `minimum_number_of_months` int(10) unsigned NOT NULL,
  `minimum_number_of_members` int(10) unsigned NOT NULL DEFAULT 1,
  `maximum_number_of_members` int(10) unsigned NOT NULL,
  `minimum_number_of_divisions` int(10) unsigned DEFAULT NULL,
  `maximum_number_of_divisions` int(10) unsigned DEFAULT NULL,
  `club_id` bigint(20) unsigned NOT NULL,
  `sort_order` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

Create Table: CREATE TABLE `members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `gender` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `zip_code` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `preferred_locale` varchar(255) DEFAULT NULL,
  `birthday` date NOT NULL,
  `phone_number` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `membership_id` bigint(20) unsigned DEFAULT NULL,
  `club_id` bigint(20) unsigned NOT NULL,
  `consented_media_publication_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

Create Table: CREATE TABLE `memberships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bank_iban` varchar(255) NOT NULL,
  `bank_account_holder` varchar(255) NOT NULL,
  `payment_period_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `membership_type_id` bigint(20) unsigned DEFAULT NULL,
  `club_id` bigint(20) unsigned NOT NULL,
  `owner_member_id` bigint(20) unsigned DEFAULT NULL,
  `voluntary_contribution` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `memberships_membership_type_id_foreign` (`membership_type_id`),
  CONSTRAINT `memberships_membership_type_id_foreign` FOREIGN KEY (`membership_type_id`) REFERENCES `membership_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

Create Table: CREATE TABLE `divisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`title`)),
  `club_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

Create Table: CREATE TABLE `division_member` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `division_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `division_member_division_id_foreign` (`division_id`),
  KEY `division_member_member_id_division_id_index` (`member_id`,`division_id`),
  CONSTRAINT `division_member_division_id_foreign` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `division_member_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

