-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 22, 2025 at 03:09 PM
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

-- --------------------------------------------------------

--
-- Table structure for table `master_email_templates`
--

CREATE TABLE `master_email_templates` (
  `id` bigint UNSIGNED NOT NULL,
  `email_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_header` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `email_footer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_cc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `email_bcc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `email_status` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `master_email_templates`
--

INSERT INTO `master_email_templates` (`id`, `email_type`, `email_subject`, `email_header`, `email_body`, `email_footer`, `email_cc`, `email_bcc`, `email_status`, `created_at`, `updated_at`) VALUES
(1, 'SECURE_LOGIN', 'SimplePHP: Secure Login', NULL, 'Hi %name%,\n									<br><br>\n									Your account <b>%email%</b> was just used to sign in from <b>%browsers% on %os%</b>.\n									<br><br>\n									%details%\n									<br><br>\n									Don\'t recognise this activity?\n									<br>\n									Secure your account, from this link.\n									<br>\n									<a href=\"%url%\"><b>Login.</b></a>\n									<br><br>\n									Why are we sending this?<br>We take security very seriously and we want to keep you in the loop on important actions in your account.\n									<br><br>\n									Sincerely,<br>\n									SimplePHP', NULL, NULL, NULL, 1, '2025-03-09 15:23:29', NULL),
(2, 'RESET_PASSWORD', 'SimplePHP: Reset Password', NULL, '<!DOCTYPE html>\n        <html>\n        <head>\n            <meta charset=\"UTF-8\">\n            <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n            <title>Password Reset</title>\n            <style>\n                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }\n                .container { max-width: 600px; margin: 0 auto; padding: 20px; }\n                .header { background: #007bff; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }\n                .content { background: #f8f9fa; padding: 30px; border: 1px solid #dee2e6; }\n                .password-box { background: #fff; border: 2px solid #007bff; padding: 15px; margin: 20px 0; text-align: center; border-radius: 5px; }\n                .password { font-size: 24px; font-weight: bold; color: #007bff; letter-spacing: 2px; }\n                .footer { background: #6c757d; color: white; padding: 15px; text-align: center; border-radius: 0 0 5px 5px; font-size: 12px; }\n                .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px; color: #856404; }\n            </style>\n        </head>\n        <body>\n            <div class=\"container\">\n                <div class=\"header\">\n                    <h1>%app_name%</h1>\n                    <h2>Password Reset</h2>\n                </div>\n                \n                <div class=\"content\">\n                    <h3>Hello %user_fullname%,</h3>\n                    \n                    <p>Your password has been successfully reset as requested. Below is your new temporary password:</p>\n                    \n                    <div class=\"password-box\">\n                        <div class=\"password\">%new_password%</div>\n                    </div>\n                    \n                    <div class=\"warning\">\n                        <strong>⚠️ Important Security Notice:</strong>\n                        <ul>\n                            <li>Please change this password immediately after logging in</li>\n                            <li>Do not share this password with anyone</li>\n                            <li>Use a strong, unique password for better security</li>\n                        </ul>\n                    </div>\n                    \n                    <p><strong>Next Steps:</strong></p>\n                    <ol>\n                        <li>Log in to your account using the new password above</li>\n                        <li>Go to your profile or account settings</li>\n                        <li>Change your password to something secure and memorable</li>\n                    </ol>\n                    \n                    <p>If you did not request this password reset, please contact our support team immediately.</p>\n                    \n                    <p>Thank you for using %app_name%!</p>\n                    \n                    <p>Best regards,<br>\n                    The %app_name% Team</p>\n                </div>\n                \n                <div class=\"footer\">\n                    <p>This is an automated message. Please do not reply to this email.</p>\n                    <p>&copy; %current_year% %app_name%. All rights reserved.</p>\n                </div>\n            </div>\n        </body>\n        </html>', NULL, NULL, NULL, 1, '2025-03-09 15:23:29', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `master_roles`
--

CREATE TABLE `master_roles` (
  `id` bigint UNSIGNED NOT NULL,
  `role_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role_rank` int DEFAULT NULL,
  `role_status` tinyint DEFAULT NULL COMMENT '0-Inactive, 1-Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `master_roles`
--

INSERT INTO `master_roles` (`id`, `role_name`, `role_rank`, `role_status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Super Administrator', 9999, 1, '2025-06-21 18:58:48', NULL, NULL),
(2, 'Administrator', 1000, 1, NULL, NULL, NULL);

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
(1, 1, 1, 1, '2025-06-21 06:58:16', NULL);

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
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
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
(1, 'Super Administrator', 'superadmin', 'superadmin@test.com', 1, '2001-11-09', '0139031145', 'superadmin', '$2a$12$BdUIhfMReSmmfacpPu0CNOMbM4QXx2FiEVkVPwTmIA5YLGa9MIVuO', 1, NULL, NULL, '2025-06-21 18:59:37', NULL, NULL),
(2, 'TEST USER 01', 'TU01', 'testuser1@simplephp.com', 1, NULL, '0189031045', 'tu01', '$2y$10$IEjReykcSvPStjQLBhpRquRHgwvhRFniRxW2.A32bgAT6cyxrKAyS', 1, NULL, NULL, NULL, '2025-06-22 14:05:02', NULL),
(3, 'TEST USER 02', 'TU02', 'testuser2@simplephp.com', 2, NULL, '0178596555', 'tu02', '$2y$10$6FHjFhbpu87t.oKtLtCcKObNH.CAms1pX8nzRX.e81IwB9qE24SPC', 0, NULL, NULL, NULL, '2025-06-22 10:35:09', NULL);

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
(1, 1, 1, 1, 1, '2025-06-21 19:01:59', NULL, NULL),
(2, 2, 2, 1, 1, '2025-06-21 20:05:40', '2025-06-21 20:06:42', NULL),
(3, 3, 2, 1, 1, '2025-06-22 10:07:16', '2025-06-22 10:35:09', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `entity_files`
--
ALTER TABLE `entity_files`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `master_email_templates`
--
ALTER TABLE `master_email_templates`
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
  ADD UNIQUE KEY `idx_users_id` (`id`);

--
-- Indexes for table `user_profile`
--
ALTER TABLE `user_profile`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `entity_files`
--
ALTER TABLE `entity_files`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `master_email_templates`
--
ALTER TABLE `master_email_templates`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `master_roles`
--
ALTER TABLE `master_roles`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `system_abilities`
--
ALTER TABLE `system_abilities`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `system_login_attempt`
--
ALTER TABLE `system_login_attempt`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_login_history`
--
ALTER TABLE `system_login_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_permission`
--
ALTER TABLE `system_permission`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `user_profile`
--
ALTER TABLE `user_profile`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
