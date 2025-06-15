-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 08, 2025 at 01:34 PM
-- Server version: 8.0.30
-- PHP Version: 8.1.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `example_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `entity_files`
--

CREATE TABLE `entity_files` (
  `id` bigint UNSIGNED NOT NULL,
  `files_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `files_original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `files_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `files_mime` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `files_extension` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `files_size` int DEFAULT '0',
  `files_compression` tinyint(1) DEFAULT NULL,
  `files_folder` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `files_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `files_disk_storage` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'public',
  `files_path_is_url` tinyint(1) DEFAULT '0',
  `files_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `entity_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `entity_id` bigint DEFAULT NULL,
  `entity_file_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_id` bigint DEFAULT NULL COMMENT 'Refer table users',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `entity_files`
--

INSERT INTO `entity_files` (`id`, `files_name`, `files_original_name`, `files_type`, `files_mime`, `files_extension`, `files_size`, `files_compression`, `files_folder`, `files_path`, `files_disk_storage`, `files_path_is_url`, `files_description`, `entity_type`, `entity_id`, `entity_file_type`, `user_id`, `created_at`, `updated_at`) VALUES
(1, '9917dc2abb16cd8321bc3440e83f280108062025092524.jpg', '1_08June2025_092524.jpg', 'image', 'image/jpeg', 'jpg', 17896, 1, 'public/upload/directory/1/avatar', 'public/upload/directory/1/avatar/9917dc2abb16cd8321bc3440e83f280108062025092524.jpg', 'public', 0, NULL, 'user_profile', 1, 'USER_PROFILE', 1, '2025-06-08 12:32:25', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `master_roles`
--

CREATE TABLE `master_roles` (
  `id` bigint UNSIGNED NOT NULL,
  `role_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role_rank` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role_status` tinyint DEFAULT NULL COMMENT '0-Inactive, 1-Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `master_roles`
--

INSERT INTO `master_roles` (`id`, `role_name`, `role_rank`, `role_status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Super Administrator', '9200', 1, '2025-03-09 15:23:19', NULL, NULL),
(7, 'TEST ADD', '2500', 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `system_abilities`
--

CREATE TABLE `system_abilities` (
  `id` bigint UNSIGNED NOT NULL,
  `abilities_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `abilities_slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `abilities_desc` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_abilities`
--

INSERT INTO `system_abilities` (`id`, `abilities_name`, `abilities_slug`, `abilities_desc`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'All access', '*', 'User can view everything (FOR SUPERADMIN ONLY)', '2025-03-09 15:23:32', NULL, NULL),
(2, 'View Dashboard', 'dashboard-view', 'User can view dashboard information', '2025-03-09 15:23:32', NULL, NULL),
(3, 'List User', 'user-view', 'User can view List user', '2025-03-09 15:23:32', NULL, NULL),
(4, 'Create New User', 'user-create', 'User can create new user', '2025-03-09 15:23:32', NULL, NULL),
(5, 'Update User', 'user-update', 'User can update user information', '2025-03-09 15:23:32', NULL, NULL),
(6, 'Delete User', 'user-delete', 'User can delete user information', '2025-03-09 15:23:32', NULL, NULL),
(7, 'User Assign Role', 'user-assign-role', 'User can assgin role to user', '2025-03-09 15:23:32', NULL, NULL),
(8, 'User Set Main Profile', 'user-default-profile', 'User can set default profile', '2025-03-09 15:23:32', NULL, NULL),
(9, 'User Delete Profile', 'user-delete-profile', 'User can delete user profile', '2025-03-09 15:23:32', NULL, NULL),
(10, 'View Info Settings', 'settings-view-info', 'User can view settings information', '2025-03-09 15:23:32', NULL, NULL),
(11, 'Change Password Settings', 'settings-change-password', 'User can view and change password settings information', '2025-03-09 15:23:32', NULL, NULL),
(12, 'Upload Image/Avatar Profile', 'settings-upload-image', 'User can upload profile image', '2025-03-09 15:23:32', NULL, NULL),
(13, 'Management View', 'management-view', 'User can see the management page', '2025-03-09 15:23:32', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `system_login_attempt`
--

CREATE TABLE `system_login_attempt` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint DEFAULT NULL COMMENT 'Refer table users',
  `ip_address` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `time` timestamp NULL DEFAULT NULL,
  `user_agent` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_login_history`
--

CREATE TABLE `system_login_history` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint DEFAULT NULL COMMENT 'Refer table users',
  `ip_address` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `login_type` tinyint(1) DEFAULT '1' COMMENT '1-CREDENTIAL, 2-SOCIALITE, 3-TOKEN',
  `operating_system` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `browsers` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `time` timestamp NULL DEFAULT NULL,
  `user_agent` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_login_history`
--

INSERT INTO `system_login_history` (`id`, `user_id`, `ip_address`, `login_type`, `operating_system`, `browsers`, `time`, `user_agent`, `created_at`, `updated_at`) VALUES
(1, 1, '::1', 1, 'Windows 10/11', 'Google Chrome 137.0.0', '2025-06-08 13:26:15', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `system_permission`
--

CREATE TABLE `system_permission` (
  `id` bigint UNSIGNED NOT NULL,
  `role_id` bigint DEFAULT NULL COMMENT 'Refer to master_roles',
  `abilities_id` bigint DEFAULT NULL COMMENT 'Refer to system_abilities',
  `access_device_type` tinyint(1) DEFAULT '1' COMMENT '1 - Web, 2 - Mobile',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_permission`
--

INSERT INTO `system_permission` (`id`, `role_id`, `abilities_id`, `access_device_type`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2025-06-06 15:54:16', NULL),
(2, 2, 2, 1, '2025-06-06 15:54:57', NULL),
(3, 2, 3, 1, '2025-06-06 15:56:03', NULL),
(4, 2, 4, 1, '2025-06-06 15:56:03', NULL),
(5, 2, 5, 1, '2025-06-06 15:56:03', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_preferred_name` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_gender` tinyint DEFAULT NULL COMMENT '1-Male, 2-Female',
  `user_dob` date DEFAULT NULL,
  `user_contact_no` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `username` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_status` tinyint DEFAULT '4' COMMENT '0-Inactive, 1-Active, 2-Suspended, 3-Deleted, 4-Unverified',
  `remember_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `user_preferred_name`, `email`, `user_gender`, `user_dob`, `user_contact_no`, `username`, `password`, `user_status`, `remember_token`, `email_verified_at`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'SUPER ADMINISTRATOR', 'S.ADMIN', 'superadmin@test.com', 1, '2025-03-09', '132456', 'superadmin', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-03-09 15:23:22', '2025-04-19 05:04:04', NULL),
(2, 'IT ADMINISTRATOR', 'ADMIN', 'sysadmin@test.com', 2, '2025-03-09', '12345678979798', 'sysadmin', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-24 22:13:00', '2025-04-04 04:30:36', NULL),
(3, 'User 3', 'User 3', 'test3@test.com', 1, '2007-08-21', '5550116906', 'test3', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(4, 'User 4', 'User 4', 'test4@test.com', 2, '2008-03-02', '5553182600', 'test4', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(5, 'User 5', 'User 5', 'test5@test.com', 2, '2024-08-01', '5552162574', 'test5', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(6, 'User 6', 'User 6', 'test6@test.com', 1, '2000-04-23', '5553972643', 'test6', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(7, 'User 7', 'User 7', 'test7@test.com', 1, '2009-02-02', '5557448070', 'test7', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(8, 'User 8', 'User 8', 'test8@test.com', 2, '2004-01-30', '5552904296', 'test8', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(9, 'User 9', 'User 9', 'test9@test.com', 2, '2010-10-20', '5552879968', 'test9', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(10, 'User 10', 'User 10', 'test10@test.com', 1, '2011-08-02', '5552581127', 'test10', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', '2025-05-14 00:02:54', NULL),
(11, 'User 11', 'User 11', 'test11@test.com', 1, '2010-02-20', '5552049278', 'test11', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(12, 'User 12', 'User 12', 'test12@test.com', 1, '2024-12-25', '5553831465', 'test12', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(13, 'User 13', 'User 13', 'test13@test.com', 1, '2005-04-07', '5558201191', 'test13', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(14, 'User 14', 'User 14', 'test14@test.com', 1, '2005-12-14', '5552660211', 'test14', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(15, 'User 15', 'User 15', 'test15@test.com', 1, '2005-05-04', '5558156159', 'test15', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(16, 'User 16', 'User 16', 'test16@test.com', 1, '2012-09-10', '5552085589', 'test16', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(17, 'User 17', 'User 17', 'test17@test.com', 2, '2011-02-22', '5554708296', 'test17', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(18, 'User 18', 'User 18', 'test18@test.com', 2, '2006-04-04', '5556013051', 'test18', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(19, 'User 19', 'User 19', 'test19@test.com', 2, '2016-09-17', '5551104832', 'test19', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(20, 'User 20', 'User 20', 'test20@test.com', 2, '2001-01-02', '5551900551', 'test20', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(21, 'User 21', 'User 21', 'test21@test.com', 2, '2009-04-17', '5552397891', 'test21', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(22, 'User 22', 'User 22', 'test22@test.com', 2, '2017-05-30', '5558532209', 'test22', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(23, 'User 23', 'User 23', 'test23@test.com', 1, '2021-10-06', '5552318576', 'test23', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(24, 'User 24', 'User 24', 'test24@test.com', 1, '2009-01-01', '5554162718', 'test24', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(25, 'User 25', 'User 25', 'test25@test.com', 1, '2015-09-05', '5550007759', 'test25', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(26, 'User 26', 'User 26', 'test26@test.com', 2, '1998-09-22', '5555481384', 'test26', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(27, 'User 27', 'User 27', 'test27@test.com', 2, '2022-06-23', '5559486309', 'test27', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(28, 'User 28', 'User 28', 'test28@test.com', 1, '2014-04-02', '5559812090', 'test28', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(29, 'User 29', 'User 29', 'test29@test.com', 2, '2006-10-01', '5557156146', 'test29', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(30, 'User 30', 'User 30', 'test30@test.com', 2, '2015-07-06', '5559779126', 'test30', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(31, 'User 31', 'User 31', 'test31@test.com', 1, '2014-06-14', '5554592505', 'test31', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(32, 'User 32', 'User 32', 'test32@test.com', 1, '2009-08-15', '5553120395', 'test32', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(33, 'User 33', 'User 33', 'test33@test.com', 1, '1999-05-31', '5558302723', 'test33', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(34, 'User 34', 'User 34', 'test34@test.com', 1, '2009-10-25', '5555082289', 'test34', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(35, 'User 35', 'User 35', 'test35@test.com', 2, '2024-01-12', '5550704896', 'test35', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(36, 'User 36', 'User 36', 'test36@test.com', 2, '2005-05-01', '5550114112', 'test36', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(37, 'User 37', 'User 37', 'test37@test.com', 1, '2024-03-01', '5552043966', 'test37', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(38, 'User 38', 'User 38', 'test38@test.com', 2, '2004-01-23', '5551837529', 'test38', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(39, 'User 39', 'User 39', 'test39@test.com', 1, '2015-08-28', '5554711447', 'test39', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(40, 'User 40', 'User 40', 'test40@test.com', 1, '2008-09-06', '5557186651', 'test40', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(41, 'User 41', 'User 41', 'test41@test.com', 2, '2016-12-09', '5553394282', 'test41', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(42, 'User 42', 'User 42', 'test42@test.com', 2, '2020-02-15', '5552120118', 'test42', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(43, 'User 43', 'User 43', 'test43@test.com', 2, '2004-08-06', '5552263876', 'test43', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(44, 'User 44', 'User 44', 'test44@test.com', 2, '2006-05-07', '5554780223', 'test44', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(45, 'User 45', 'User 45', 'test45@test.com', 1, '2002-11-20', '5556242823', 'test45', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(46, 'User 46', 'User 46', 'test46@test.com', 1, '2015-03-21', '5554090267', 'test46', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(47, 'User 47', 'User 47', 'test47@test.com', 1, '2007-08-28', '5557293057', 'test47', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(48, 'User 48', 'User 48', 'test48@test.com', 1, '2002-03-01', '5550207168', 'test48', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(49, 'User 49', 'User 49', 'test49@test.com', 2, '2017-05-27', '5550321097', 'test49', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(50, 'User 50', 'User 50', 'test50@test.com', 1, '2022-04-23', '5553355814', 'test50', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(51, 'User 51', 'User 51', 'test51@test.com', 2, '2004-06-21', '5555035574', 'test51', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(52, 'User 52', 'User 52', 'test52@test.com', 2, '2005-07-16', '5555381030', 'test52', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:10', NULL, NULL),
(53, 'User 53', 'User 53', 'test53@test.com', 1, '2022-04-15', '5554786282', 'test53', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(54, 'User 54', 'User 54', 'test54@test.com', 2, '2017-01-18', '5557987380', 'test54', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(55, 'User 55', 'User 55', 'test55@test.com', 1, '2022-12-23', '5551982653', 'test55', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(56, 'User 56', 'User 56', 'test56@test.com', 1, '2016-04-17', '5553072494', 'test56', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(57, 'User 57', 'User 57', 'test57@test.com', 2, '2009-07-23', '5553270631', 'test57', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(58, 'User 58', 'User 58', 'test58@test.com', 2, '2019-05-27', '5552954560', 'test58', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(59, 'User 59', 'User 59', 'test59@test.com', 1, '1999-06-03', '5558493277', 'test59', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(60, 'User 60', 'User 60', 'test60@test.com', 2, '2014-06-20', '5553874698', 'test60', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(61, 'User 61', 'User 61', 'test61@test.com', 2, '2003-09-14', '5551066385', 'test61', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(62, 'User 62', 'User 62', 'test62@test.com', 2, '2020-11-28', '5551919596', 'test62', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(63, 'User 63', 'User 63', 'test63@test.com', 2, '2004-11-21', '5552044621', 'test63', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(64, 'User 64', 'User 64', 'test64@test.com', 1, '2016-07-10', '5555817866', 'test64', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(65, 'User 65', 'User 65', 'test65@test.com', 1, '2019-08-21', '5559913998', 'test65', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(66, 'User 66', 'User 66', 'test66@test.com', 2, '2008-03-22', '5559254202', 'test66', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(67, 'User 67', 'User 67', 'test67@test.com', 1, '2002-05-05', '5551157729', 'test67', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(68, 'User 68', 'User 68', 'test68@test.com', 2, '2004-11-25', '5557510957', 'test68', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(69, 'User 69', 'User 69', 'test69@test.com', 1, '2017-11-04', '5552482541', 'test69', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(70, 'User 70', 'User 70', 'test70@test.com', 1, '2006-01-28', '5553124096', 'test70', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(71, 'User 71', 'User 71', 'test71@test.com', 1, '2013-08-04', '5550479414', 'test71', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(72, 'User 72', 'User 72', 'test72@test.com', 2, '2013-08-19', '5551387523', 'test72', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(73, 'User 73', 'User 73', 'test73@test.com', 2, '2021-02-16', '5557088191', 'test73', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(74, 'User 74', 'User 74', 'test74@test.com', 1, '2014-12-29', '5558860293', 'test74', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(75, 'User 75', 'User 75', 'test75@test.com', 2, '2013-10-18', '5554937088', 'test75', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(76, 'User 76', 'User 76', 'test76@test.com', 2, '2018-01-26', '5555737822', 'test76', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(77, 'User 77', 'User 77', 'test77@test.com', 2, '2020-07-07', '5558168872', 'test77', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(78, 'User 78', 'User 78', 'test78@test.com', 1, '2020-09-15', '5557159621', 'test78', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(79, 'User 79', 'User 79', 'test79@test.com', 1, '2024-04-24', '5554071549', 'test79', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(80, 'User 80', 'User 80', 'test80@test.com', 1, '2014-06-11', '5556612253', 'test80', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(81, 'User 81', 'User 81', 'test81@test.com', 2, '2005-02-11', '5558207670', 'test81', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(82, 'User 82', 'User 82', 'test82@test.com', 1, '2014-03-16', '5559638439', 'test82', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(83, 'User 83', 'User 83', 'test83@test.com', 1, '2000-04-22', '5551163361', 'test83', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(84, 'User 84', 'User 84', 'test84@test.com', 2, '2000-08-20', '5558319515', 'test84', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(85, 'User 85', 'User 85', 'test85@test.com', 2, '2006-10-19', '5559361739', 'test85', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(86, 'User 86', 'User 86', 'test86@test.com', 1, '2013-05-31', '5557196050', 'test86', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(87, 'User 87', 'User 87', 'test87@test.com', 1, '2003-04-09', '5559944329', 'test87', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(88, 'User 88', 'User 88', 'test88@test.com', 2, '2012-09-21', '5558025022', 'test88', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(89, 'User 89', 'User 89', 'test89@test.com', 2, '2024-11-23', '5557091960', 'test89', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(90, 'User 90', 'User 90', 'test90@test.com', 1, '2011-10-23', '5552515657', 'test90', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(91, 'User 91', 'User 91', 'test91@test.com', 1, '2012-06-27', '5558421717', 'test91', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(92, 'User 92', 'User 92', 'test92@test.com', 2, '2019-05-12', '5554880978', 'test92', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(93, 'User 93', 'User 93', 'test93@test.com', 2, '2021-05-03', '5552016571', 'test93', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(94, 'User 94', 'User 94', 'test94@test.com', 1, '2006-02-19', '5556324711', 'test94', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(95, 'User 95', 'User 95', 'test95@test.com', 1, '1998-05-11', '5556033953', 'test95', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(96, 'User 96', 'User 96', 'test96@test.com', 2, '2012-06-14', '5557344337', 'test96', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(97, 'User 97', 'User 97', 'test97@test.com', 1, '2000-11-15', '5550357701', 'test97', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(98, 'User 98', 'User 98', 'test98@test.com', 1, '2008-07-05', '5557800263', 'test98', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', '2025-05-14 00:07:02', NULL),
(99, 'User 99', 'User 99', 'test99@test.com', 2, '2005-07-20', '5556516571', 'test99', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(100, 'User 100', 'User 100', 'test100@test.com', 2, '2014-08-07', '5553362870', 'test100', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', '2025-04-05 12:20:03', NULL),
(101, 'User 101', 'User 101', 'test101@test.com', 2, '2018-07-03', '5555622307', 'test101', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(102, 'User 102', 'User 102', 'test102@test.com', 2, '2021-12-11', '5556058999', 'test102', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(103, 'User 103', 'User 103', 'test103@test.com', 2, '2009-10-26', '5552830033', 'test103', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(104, 'User 104', 'User 104', 'test104@test.com', 2, '2007-04-05', '5559922507', 'test104', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(105, 'User 105', 'User 105', 'test105@test.com', 2, '2001-08-18', '5554187545', 'test105', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(106, 'User 106', 'User 106', 'test106@test.com', 1, '2003-01-22', '5552604903', 'test106', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(107, 'User 107', 'User 107', 'test107@test.com', 2, '2019-01-27', '5554290834', 'test107', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(108, 'User 108', 'User 108', 'test108@test.com', 1, '2002-02-18', '5550759095', 'test108', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(109, 'User 109', 'User 109', 'test109@test.com', 2, '2012-10-19', '5552812114', 'test109', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(110, 'User 110', 'User 110', 'test110@test.com', 1, '2006-09-19', '5553068580', 'test110', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(111, 'User 111', 'User 111', 'test111@test.com', 2, '2012-12-13', '5554741818', 'test111', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(112, 'User 112', 'User 112', 'test112@test.com', 2, '2015-02-17', '5557888921', 'test112', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(113, 'User 113', 'User 113', 'test113@test.com', 2, '2011-01-21', '5551812888', 'test113', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(114, 'User 114', 'User 114', 'test114@test.com', 1, '1998-06-07', '5552818183', 'test114', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(115, 'User 115', 'User 115', 'test115@test.com', 2, '2022-08-16', '5559793183', 'test115', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(116, 'User 116', 'User 116', 'test116@test.com', 1, '2006-10-07', '5550788961', 'test116', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(117, 'User 117', 'User 117', 'test117@test.com', 2, '2000-05-19', '5557366193', 'test117', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(118, 'User 118', 'User 118', 'test118@test.com', 2, '1997-12-07', '5552698702', 'test118', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(119, 'User 119', 'User 119', 'test119@test.com', 2, '2004-06-28', '5558993221', 'test119', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(120, 'User 120', 'User 120', 'test120@test.com', 1, '2015-04-10', '5556080311', 'test120', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(121, 'User 121', 'User 121', 'test121@test.com', 2, '2006-07-09', '5557095882', 'test121', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(122, 'User 122', 'User 122', 'test122@test.com', 1, '2018-11-12', '5551382696', 'test122', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(123, 'User 123', 'User 123', 'test123@test.com', 2, '2005-10-09', '5559498337', 'test123', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(124, 'User 124', 'User 124', 'test124@test.com', 1, '2018-06-07', '5555931516', 'test124', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(125, 'User 125', 'User 125', 'test125@test.com', 1, '2000-12-15', '5555144805', 'test125', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(126, 'User 126', 'User 126', 'test126@test.com', 1, '2017-06-07', '5554215403', 'test126', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(127, 'User 127', 'User 127', 'test127@test.com', 2, '2020-07-01', '5559154432', 'test127', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(128, 'User 128', 'User 128', 'test128@test.com', 2, '2011-11-14', '5558522865', 'test128', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(129, 'User 129', 'User 129', 'test129@test.com', 1, '2006-07-08', '5551833713', 'test129', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(130, 'User 130', 'User 130', 'test130@test.com', 2, '2018-12-14', '5558611891', 'test130', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(131, 'User 131', 'User 131', 'test131@test.com', 1, '2008-10-30', '5555348116', 'test131', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(132, 'User 132', 'User 132', 'test132@test.com', 2, '2019-12-07', '5556840815', 'test132', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(133, 'User 133', 'User 133', 'test133@test.com', 1, '2021-05-14', '5553202372', 'test133', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(134, 'User 134', 'User 134', 'test134@test.com', 2, '2024-05-12', '5554307911', 'test134', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(135, 'User 135', 'User 135', 'test135@test.com', 2, '2004-08-04', '5558221530', 'test135', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(136, 'User 136', 'User 136', 'test136@test.com', 2, '2017-08-17', '5551044206', 'test136', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(137, 'User 137', 'User 137', 'test137@test.com', 1, '2011-03-24', '5552218869', 'test137', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(138, 'User 138', 'User 138', 'test138@test.com', 1, '2018-07-22', '5556491496', 'test138', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(139, 'User 139', 'User 139', 'test139@test.com', 2, '2011-07-17', '5556854962', 'test139', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(140, 'User 140', 'User 140', 'test140@test.com', 2, '2023-12-21', '5555440866', 'test140', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(141, 'User 141', 'User 141', 'test141@test.com', 1, '2009-06-19', '5550965492', 'test141', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(142, 'User 142', 'User 142', 'test142@test.com', 1, '2022-10-13', '5550453794', 'test142', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(143, 'User 143', 'User 143', 'test143@test.com', 2, '2016-02-25', '5557446529', 'test143', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(144, 'User 144', 'User 144', 'test144@test.com', 1, '2003-10-28', '5557402470', 'test144', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(145, 'User 145', 'User 145', 'test145@test.com', 2, '2007-09-15', '5555878590', 'test145', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(146, 'User 146', 'User 146', 'test146@test.com', 1, '2010-12-05', '5556756470', 'test146', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(147, 'User 147', 'User 147', 'test147@test.com', 1, '2007-12-09', '5551303510', 'test147', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(148, 'User 148', 'User 148', 'test148@test.com', 1, '2008-03-20', '5559830082', 'test148', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(149, 'User 149', 'User 149', 'test149@test.com', 1, '2017-03-07', '5556133918', 'test149', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(150, 'User 150', 'User 150', 'test150@test.com', 1, '2003-07-04', '5557697561', 'test150', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(151, 'User 151', 'User 151', 'test151@test.com', 1, '2006-09-24', '5553416357', 'test151', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(152, 'User 152', 'User 152', 'test152@test.com', 1, '2003-10-16', '5558308190', 'test152', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(153, 'User 153', 'User 153', 'test153@test.com', 2, '2020-08-26', '5552984461', 'test153', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(154, 'User 154', 'User 154', 'test154@test.com', 1, '2018-09-29', '5550706938', 'test154', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(155, 'User 155', 'User 155', 'test155@test.com', 2, '2025-02-08', '5550738534', 'test155', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(156, 'User 156', 'User 156', 'test156@test.com', 2, '2007-08-04', '5556055370', 'test156', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(157, 'User 157', 'User 157', 'test157@test.com', 2, '1999-11-12', '5557170858', 'test157', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(158, 'User 158', 'User 158', 'test158@test.com', 2, '2000-08-23', '5559119370', 'test158', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(159, 'User 159', 'User 159', 'test159@test.com', 2, '2017-05-29', '5557104821', 'test159', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(160, 'User 160', 'User 160', 'test160@test.com', 1, '2009-11-13', '5558284669', 'test160', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(161, 'User 161', 'User 161', 'test161@test.com', 2, '2008-11-11', '5556151276', 'test161', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(162, 'User 162', 'User 162', 'test162@test.com', 2, '2000-06-19', '5558829536', 'test162', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(163, 'User 163', 'User 163', 'test163@test.com', 2, '2022-04-01', '5550277643', 'test163', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(164, 'User 164', 'User 164', 'test164@test.com', 2, '2014-12-07', '5550039519', 'test164', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(165, 'User 165', 'User 165', 'test165@test.com', 1, '2012-02-29', '5551066262', 'test165', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(166, 'User 166', 'User 166', 'test166@test.com', 1, '2010-02-24', '5552526893', 'test166', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(167, 'User 167', 'User 167', 'test167@test.com', 1, '2009-07-10', '5550357217', 'test167', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(168, 'User 168', 'User 168', 'test168@test.com', 1, '2012-10-20', '5557847175', 'test168', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(169, 'User 169', 'User 169', 'test169@test.com', 1, '2011-02-20', '5552646754', 'test169', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(170, 'User 170', 'User 170', 'test170@test.com', 1, '2022-08-30', '5552215707', 'test170', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(171, 'User 171', 'User 171', 'test171@test.com', 1, '2003-11-06', '5555600687', 'test171', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(172, 'User 172', 'User 172', 'test172@test.com', 2, '2009-04-01', '5551563421', 'test172', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(173, 'User 173', 'User 173', 'test173@test.com', 2, '2018-05-13', '5552597896', 'test173', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(174, 'User 174', 'User 174', 'test174@test.com', 2, '2023-11-19', '5554441458', 'test174', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(175, 'User 175', 'User 175', 'test175@test.com', 1, '2001-06-19', '5552990830', 'test175', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(176, 'User 176', 'User 176', 'test176@test.com', 2, '2023-12-20', '5556096740', 'test176', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(177, 'User 177', 'User 177', 'test177@test.com', 2, '2003-05-01', '5558924695', 'test177', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(178, 'User 178', 'User 178', 'test178@test.com', 2, '2000-02-14', '5557334818', 'test178', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(179, 'User 179', 'User 179', 'test179@test.com', 1, '2023-03-01', '5552942548', 'test179', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(180, 'User 180', 'User 180', 'test180@test.com', 1, '2000-09-02', '5555173876', 'test180', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(181, 'User 181', 'User 181', 'test181@test.com', 2, '2001-05-24', '5556293306', 'test181', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(182, 'User 182', 'User 182', 'test182@test.com', 2, '2018-05-13', '5559520362', 'test182', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(183, 'User 183', 'User 183', 'test183@test.com', 1, '2002-12-25', '5555726601', 'test183', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(184, 'User 184', 'User 184', 'test184@test.com', 1, '2006-03-05', '5553074914', 'test184', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(185, 'User 185', 'User 185', 'test185@test.com', 1, '2019-09-28', '5550841098', 'test185', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(186, 'User 186', 'User 186', 'test186@test.com', 2, '2006-07-11', '5559428849', 'test186', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(187, 'User 187', 'User 187', 'test187@test.com', 1, '2014-02-21', '5555966498', 'test187', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(188, 'User 188', 'User 188', 'test188@test.com', 1, '2001-04-13', '5552772408', 'test188', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(189, 'User 189', 'User 189', 'test189@test.com', 2, '2010-10-05', '5557649911', 'test189', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(190, 'User 190', 'User 190', 'test190@test.com', 2, '2005-08-06', '5559354496', 'test190', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(191, 'User 191', 'User 191', 'test191@test.com', 2, '2012-08-06', '5558949575', 'test191', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(192, 'User 192', 'User 192', 'test192@test.com', 2, '2011-11-06', '5551990223', 'test192', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(193, 'User 193', 'User 193', 'test193@test.com', 1, '2009-08-03', '5557731280', 'test193', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(194, 'User 194', 'User 194', 'test194@test.com', 1, '2007-01-14', '5550621304', 'test194', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(195, 'User 195', 'User 195', 'test195@test.com', 1, '1998-10-21', '5556873650', 'test195', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(196, 'User 196', 'User 196', 'test196@test.com', 2, '2010-12-09', '5557336584', 'test196', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(197, 'User 197', 'User 197', 'test197@test.com', 1, '2020-08-14', '5559682866', 'test197', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(198, 'User 198', 'User 198', 'test198@test.com', 2, '2003-07-08', '5556960042', 'test198', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL),
(199, 'User 199', 'User 199', 'test199@test.com', 1, '2006-02-20', '5552982361', 'test199', '$2y$10$VP3Yoe5pmyIbvTCoESWXcOOo9fvwOg8V2.OOmtJ.vnNDvkOwoE2va', 1, NULL, NULL, '2025-04-05 01:17:45', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_profile`
--

CREATE TABLE `user_profile` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint DEFAULT NULL COMMENT 'Refer table users',
  `role_id` bigint DEFAULT NULL COMMENT 'Refer table master_roles',
  `is_main` tinyint(1) DEFAULT NULL COMMENT '0-No, 1-Yes',
  `profile_status` tinyint(1) DEFAULT NULL COMMENT '0-Inactive, 1-Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_profile`
--

INSERT INTO `user_profile` (`id`, `user_id`, `role_id`, `is_main`, `profile_status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 1, 1, 1, '2025-03-09 15:23:26', NULL, NULL),
(2, 1, 2, 0, 1, '2025-03-09 15:23:26', NULL, NULL),
(3, 1, 3, 0, 1, '2025-03-09 15:23:26', NULL, NULL),
(4, 2, 2, 1, 1, '2025-03-09 15:23:26', NULL, NULL),
(5, 1, 4, 0, 1, '2025-03-09 15:40:09', NULL, NULL),
(6, 3, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(7, 3, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(8, 4, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(9, 4, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(10, 4, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(11, 5, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(12, 6, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(13, 6, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(14, 7, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(15, 7, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(16, 7, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(17, 8, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(18, 8, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(19, 9, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(20, 9, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(21, 10, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(22, 11, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(23, 11, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(24, 11, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(25, 12, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(26, 12, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(27, 12, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(28, 13, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(29, 13, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(30, 13, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(31, 14, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(32, 15, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(33, 16, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(34, 17, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(35, 17, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(36, 18, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(37, 18, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(38, 19, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(39, 19, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(40, 20, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(41, 20, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(42, 21, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(43, 21, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(44, 22, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(45, 23, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(46, 23, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(47, 23, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(48, 24, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(49, 24, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(50, 25, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(51, 25, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(52, 26, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(53, 26, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(54, 27, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(55, 27, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(56, 28, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(57, 28, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(58, 29, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(59, 29, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(60, 29, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(61, 30, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(62, 31, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(63, 32, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(64, 33, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(65, 33, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(66, 34, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(67, 34, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(68, 34, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(69, 35, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(70, 35, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(71, 36, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(72, 36, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(73, 37, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(74, 37, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(75, 37, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(76, 38, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(77, 38, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(78, 39, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(79, 40, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(80, 40, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(81, 41, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(82, 42, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(83, 42, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(84, 42, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(85, 43, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(86, 43, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(87, 43, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(88, 44, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(89, 44, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(90, 44, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(91, 45, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(92, 45, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(93, 46, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(94, 46, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(95, 46, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(96, 47, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(97, 47, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(98, 47, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(99, 48, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(100, 48, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(101, 49, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(102, 49, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(103, 49, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(104, 50, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(105, 50, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(106, 50, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(107, 51, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(108, 51, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(109, 52, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(110, 53, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(111, 54, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(112, 54, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(113, 54, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(114, 55, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(115, 56, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(116, 56, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(117, 56, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(118, 57, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(119, 57, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(120, 57, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(121, 58, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(122, 58, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(123, 58, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(124, 59, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(125, 59, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(126, 60, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(127, 60, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(128, 61, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(129, 62, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(130, 63, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(131, 63, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(132, 63, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(133, 64, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(134, 64, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(135, 65, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(136, 65, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(137, 65, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(138, 66, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(139, 66, 3, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(140, 67, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(141, 68, 4, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(142, 69, 4, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(143, 69, 2, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(144, 70, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(145, 70, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(146, 70, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(147, 71, 3, 1, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(148, 71, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(149, 71, 2, 0, 1, '2025-04-05 01:56:03', '2025-04-05 01:56:03', NULL),
(150, 72, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(151, 72, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(152, 72, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(153, 73, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(154, 73, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(155, 73, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(156, 74, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(157, 74, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(158, 75, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(159, 75, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(160, 75, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(161, 76, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(162, 76, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(163, 77, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(164, 78, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(165, 78, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(166, 79, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(167, 80, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(168, 80, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(169, 80, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(170, 81, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(171, 82, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(172, 82, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(173, 82, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(174, 83, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(175, 83, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(176, 84, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(177, 84, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(178, 85, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(179, 85, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(180, 86, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(181, 86, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(182, 87, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(183, 87, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(184, 88, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(185, 88, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(186, 88, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(187, 89, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(188, 90, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(189, 90, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(190, 90, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(191, 91, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(192, 91, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(193, 91, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(194, 92, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(195, 92, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(196, 92, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(197, 93, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(198, 93, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(199, 93, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(200, 94, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(201, 95, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(202, 95, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(203, 95, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(204, 96, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(205, 96, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(206, 96, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(207, 97, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(208, 98, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(209, 99, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(210, 99, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(211, 100, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(212, 100, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(213, 100, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(214, 101, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(215, 101, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(216, 102, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(217, 102, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(218, 103, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(219, 104, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(220, 104, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(221, 105, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(222, 106, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(223, 106, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(224, 106, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(225, 107, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(226, 108, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(227, 109, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(228, 109, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(229, 109, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(230, 110, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(231, 110, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(232, 110, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(233, 111, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(234, 111, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(235, 111, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(236, 112, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(237, 112, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(238, 112, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(239, 113, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(240, 113, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(241, 114, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(242, 114, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(243, 114, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(244, 115, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(245, 115, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(246, 115, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(247, 116, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(248, 116, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(249, 117, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(250, 117, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(251, 118, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(252, 118, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(253, 119, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(254, 119, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(255, 119, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(256, 120, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(257, 120, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(258, 121, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(259, 121, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(260, 122, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(261, 123, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(262, 123, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(263, 123, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(264, 124, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(265, 124, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(266, 125, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(267, 126, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(268, 126, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(269, 127, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(270, 127, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(271, 127, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(272, 128, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(273, 128, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(274, 129, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(275, 130, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(276, 131, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(277, 131, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(278, 131, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(279, 132, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(280, 133, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(281, 134, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(282, 134, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(283, 135, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(284, 135, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(285, 135, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(286, 136, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(287, 137, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(288, 138, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(289, 139, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(290, 139, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(291, 139, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(292, 140, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(293, 140, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(294, 140, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(295, 141, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(296, 142, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(297, 142, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(298, 143, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(299, 143, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(300, 144, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(301, 144, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(302, 144, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(303, 145, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(304, 145, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(305, 145, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(306, 146, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(307, 146, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(308, 146, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(309, 147, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(310, 147, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(311, 147, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(312, 148, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(313, 149, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(314, 149, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(315, 150, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(316, 151, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(317, 152, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(318, 152, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(319, 152, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(320, 153, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(321, 154, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(322, 154, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(323, 154, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(324, 155, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(325, 155, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(326, 155, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(327, 156, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(328, 157, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(329, 158, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(330, 158, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(331, 159, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(332, 159, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(333, 160, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(334, 161, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(335, 162, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(336, 162, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(337, 162, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(338, 163, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(339, 163, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(340, 164, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(341, 164, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(342, 164, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(343, 165, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(344, 165, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(345, 166, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(346, 167, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(347, 167, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(348, 168, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(349, 169, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(350, 169, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(351, 170, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(352, 170, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(353, 170, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(354, 171, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(355, 172, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(356, 172, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(357, 172, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(358, 173, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(359, 173, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(360, 174, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(361, 175, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(362, 175, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(363, 175, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(364, 176, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(365, 176, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(366, 177, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(367, 177, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(368, 177, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(369, 178, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(370, 178, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(371, 178, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(372, 179, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(373, 179, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(374, 180, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(375, 180, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(376, 180, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(377, 181, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(378, 182, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(379, 182, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(380, 182, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(381, 183, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(382, 183, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(383, 184, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(384, 184, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(385, 185, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(386, 185, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(387, 185, 4, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(388, 186, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(389, 187, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(390, 187, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(391, 188, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(392, 188, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(393, 189, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(394, 189, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(395, 189, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(396, 190, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(397, 190, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(398, 190, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(399, 191, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(400, 191, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(401, 192, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(402, 192, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(403, 192, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(404, 193, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(405, 194, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(406, 194, 3, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(407, 194, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(408, 195, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(409, 195, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(410, 195, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(411, 196, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(412, 197, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(413, 198, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(414, 199, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(415, 200, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(416, 200, 2, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(417, 201, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(418, 202, 3, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(419, 203, 4, 1, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL),
(708, 344, 2, 0, 1, '2025-04-05 01:56:04', '2025-04-05 01:56:04', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `entity_files`
--
ALTER TABLE `entity_files`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `master_roles`
--
ALTER TABLE `master_roles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_abilities`
--
ALTER TABLE `system_abilities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_login_attempt`
--
ALTER TABLE `system_login_attempt`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_login_history`
--
ALTER TABLE `system_login_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_permission`
--
ALTER TABLE `system_permission`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_users_id` (`id`),
  ADD KEY `idx_user_status_gender` (`user_status`,`user_gender`);

--
-- Indexes for table `user_profile`
--
ALTER TABLE `user_profile`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `entity_files`
--
ALTER TABLE `entity_files`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `master_roles`
--
ALTER TABLE `master_roles`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `system_abilities`
--
ALTER TABLE `system_abilities`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `system_login_attempt`
--
ALTER TABLE `system_login_attempt`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_login_history`
--
ALTER TABLE `system_login_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `system_permission`
--
ALTER TABLE `system_permission`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=805053;

--
-- AUTO_INCREMENT for table `user_profile`
--
ALTER TABLE `user_profile`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=479283;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
