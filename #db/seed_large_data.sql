-- Large Data Seed Script
-- Generates: 10 roles, 500,000 users (1 superadmin + 409,999 random), profiles, entity files
-- Run this script in MySQL/MariaDB

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
SET UNIQUE_CHECKS = 0;
SET AUTOCOMMIT = 0;

-- --------------------------------------------------------
-- Clear existing data (optional - comment out if you want to keep existing data)
-- --------------------------------------------------------

TRUNCATE TABLE `entity_files`;
TRUNCATE TABLE `user_profile`;
TRUNCATE TABLE `system_permission`;
TRUNCATE TABLE `system_abilities`;
TRUNCATE TABLE `system_login_attempt`;
TRUNCATE TABLE `system_login_history`;
DELETE FROM `users` WHERE id > 0;
DELETE FROM `master_roles` WHERE id > 0;

-- --------------------------------------------------------
-- Drop existing indexes if they exist (using procedure for compatibility)
-- --------------------------------------------------------

DROP PROCEDURE IF EXISTS drop_index_if_exists;
DELIMITER //
CREATE PROCEDURE drop_index_if_exists(IN tableName VARCHAR(128), IN indexName VARCHAR(128))
BEGIN
    DECLARE indexExists INT DEFAULT 0;
    SELECT COUNT(*) INTO indexExists FROM information_schema.statistics 
    WHERE table_schema = DATABASE() AND table_name = tableName AND index_name = indexName;
    IF indexExists > 0 THEN
        SET @sql = CONCAT('DROP INDEX `', indexName, '` ON `', tableName, '`');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Users table
CALL drop_index_if_exists('users', 'idx_users_email');
CALL drop_index_if_exists('users', 'idx_users_username');
CALL drop_index_if_exists('users', 'idx_users_status');
CALL drop_index_if_exists('users', 'idx_users_gender');
CALL drop_index_if_exists('users', 'idx_users_deleted');
CALL drop_index_if_exists('users', 'idx_users_status_deleted');

-- System login attempt table
CALL drop_index_if_exists('system_login_attempt', 'idx_login_attempt_composite');
CALL drop_index_if_exists('system_login_attempt', 'idx_login_attempt_user');

-- User profile table
CALL drop_index_if_exists('user_profile', 'idx_user_profile_user_status_main');
CALL drop_index_if_exists('user_profile', 'idx_user_profile_role');
CALL drop_index_if_exists('user_profile', 'idx_user_profile_role_status');
CALL drop_index_if_exists('user_profile', 'idx_user_profile_user');

-- Master roles table
CALL drop_index_if_exists('master_roles', 'idx_master_roles_status');
CALL drop_index_if_exists('master_roles', 'idx_master_roles_deleted');
CALL drop_index_if_exists('master_roles', 'idx_master_roles_status_deleted');

-- System permission table
CALL drop_index_if_exists('system_permission', 'idx_system_permission_role_abilities');
CALL drop_index_if_exists('system_permission', 'idx_system_permission_abilities');
CALL drop_index_if_exists('system_permission', 'idx_system_permission_role');

-- System abilities table
CALL drop_index_if_exists('system_abilities', 'idx_system_abilities_deleted');
CALL drop_index_if_exists('system_abilities', 'idx_system_abilities_slug');

-- Entity files table
CALL drop_index_if_exists('entity_files', 'idx_entity_files_composite');
CALL drop_index_if_exists('entity_files', 'idx_entity_files_entity');
CALL drop_index_if_exists('entity_files', 'idx_entity_files_user');

-- Master email templates table
CALL drop_index_if_exists('master_email_templates', 'idx_email_templates_type_status');
CALL drop_index_if_exists('master_email_templates', 'idx_email_templates_status');
CALL drop_index_if_exists('master_email_templates', 'idx_email_templates_type');

-- System login history table
CALL drop_index_if_exists('system_login_history', 'idx_login_history_user');
CALL drop_index_if_exists('system_login_history', 'idx_login_history_time');

DROP PROCEDURE IF EXISTS drop_index_if_exists;

ALTER TABLE `users` AUTO_INCREMENT = 1;
ALTER TABLE `master_roles` AUTO_INCREMENT = 1;
ALTER TABLE `user_profile` AUTO_INCREMENT = 1;
ALTER TABLE `system_abilities` AUTO_INCREMENT = 1;
ALTER TABLE `system_permission` AUTO_INCREMENT = 1;
ALTER TABLE `entity_files` AUTO_INCREMENT = 1;

-- --------------------------------------------------------
-- Insert 10 Roles
-- --------------------------------------------------------

INSERT INTO `master_roles` (`id`, `role_name`, `role_rank`, `role_status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Super Administrator', 9999, 1, NOW(), NULL, NULL),
(2, 'Administrator', 1000, 1, NOW(), NULL, NULL),
(3, 'Director', 800, 1, NOW(), NULL, NULL),
(4, 'Manager', 600, 1, NOW(), NULL, NULL),
(5, 'Supervisor', 400, 1, NOW(), NULL, NULL),
(6, 'Team Lead', 300, 1, NOW(), NULL, NULL),
(7, 'Senior Staff', 200, 1, NOW(), NULL, NULL),
(8, 'Staff', 100, 1, NOW(), NULL, NULL),
(9, 'Junior Staff', 50, 1, NOW(), NULL, NULL),
(10, 'User', 10, 1, NOW(), NULL, NULL);

-- --------------------------------------------------------
-- Insert Extended Abilities/Permissions
-- --------------------------------------------------------

INSERT INTO `system_abilities` (`id`, `abilities_name`, `abilities_slug`, `abilities_desc`, `created_at`) VALUES
-- General Access
(1, 'All Access', '*', 'User can access everything (FOR SUPERADMIN ONLY)', NOW()),
(2, 'View Dashboard', 'dashboard-view', 'User can view dashboard information', NOW()),
(3, 'View Analytics', 'analytics-view', 'User can view analytics and reports', NOW()),
(4, 'Export Data', 'data-export', 'User can export data to CSV/Excel', NOW()),

-- User Management
(5, 'List Users', 'user-view', 'User can view list of users', NOW()),
(6, 'Create User', 'user-create', 'User can create new users', NOW()),
(7, 'Update User', 'user-update', 'User can update user information', NOW()),
(8, 'Delete User', 'user-delete', 'User can delete users', NOW()),
(9, 'Assign Role', 'user-assign-role', 'User can assign roles to users', NOW()),
(10, 'Set Main Profile', 'user-default-profile', 'User can set default profile', NOW()),
(11, 'Delete Profile', 'user-delete-profile', 'User can delete user profiles', NOW()),
(12, 'Reset Password', 'user-reset-password', 'User can reset user passwords', NOW()),
(13, 'View User Logs', 'user-view-logs', 'User can view user activity logs', NOW()),

-- Role Management
(14, 'List Roles', 'role-view', 'User can view list of roles', NOW()),
(15, 'Create Role', 'role-create', 'User can create new roles', NOW()),
(16, 'Update Role', 'role-update', 'User can update role information', NOW()),
(17, 'Delete Role', 'role-delete', 'User can delete roles', NOW()),
(18, 'Assign Permissions', 'role-assign-permission', 'User can assign permissions to roles', NOW()),

-- Settings Management
(19, 'View Settings', 'settings-view', 'User can view system settings', NOW()),
(20, 'Update Settings', 'settings-update', 'User can update system settings', NOW()),
(21, 'View Info Settings', 'settings-view-info', 'User can view settings information', NOW()),
(22, 'Change Password', 'settings-change-password', 'User can change password settings', NOW()),
(23, 'Upload Image', 'settings-upload-image', 'User can upload profile images', NOW()),

-- File Management
(24, 'View Files', 'file-view', 'User can view uploaded files', NOW()),
(25, 'Upload Files', 'file-upload', 'User can upload files', NOW()),
(26, 'Delete Files', 'file-delete', 'User can delete files', NOW()),
(27, 'Download Files', 'file-download', 'User can download files', NOW()),

-- Email Management
(28, 'View Email Templates', 'email-view', 'User can view email templates', NOW()),
(29, 'Create Email Template', 'email-create', 'User can create email templates', NOW()),
(30, 'Update Email Template', 'email-update', 'User can update email templates', NOW()),
(31, 'Delete Email Template', 'email-delete', 'User can delete email templates', NOW()),
(32, 'Send Email', 'email-send', 'User can send emails', NOW()),

-- Report Management
(33, 'View Reports', 'report-view', 'User can view reports', NOW()),
(34, 'Generate Reports', 'report-generate', 'User can generate reports', NOW()),
(35, 'Export Reports', 'report-export', 'User can export reports', NOW()),

-- Audit & Logs
(36, 'View Audit Logs', 'audit-view', 'User can view audit logs', NOW()),
(37, 'Export Audit Logs', 'audit-export', 'User can export audit logs', NOW()),
(38, 'View Login History', 'login-history-view', 'User can view login history', NOW()),

-- System Management
(39, 'View System Info', 'system-info-view', 'User can view system information', NOW()),
(40, 'Manage Cache', 'system-cache', 'User can manage system cache', NOW()),
(41, 'View Database Stats', 'system-db-stats', 'User can view database statistics', NOW()),
(42, 'Backup System', 'system-backup', 'User can create system backups', NOW()),

-- API Management
(43, 'View API Keys', 'api-view', 'User can view API keys', NOW()),
(44, 'Create API Key', 'api-create', 'User can create API keys', NOW()),
(45, 'Revoke API Key', 'api-revoke', 'User can revoke API keys', NOW()),

-- Notification Management
(46, 'View Notifications', 'notification-view', 'User can view notifications', NOW()),
(47, 'Send Notifications', 'notification-send', 'User can send notifications', NOW()),
(48, 'Manage Notifications', 'notification-manage', 'User can manage notification settings', NOW()),

-- Management View
(49, 'Management View', 'management-view', 'User can see the management page', NOW()),
(50, 'Admin Panel Access', 'admin-panel-access', 'User can access admin panel', NOW());

-- --------------------------------------------------------
-- Insert permissions for all roles
-- --------------------------------------------------------

-- Super Admin: All access
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(1, 1, 1, NOW());

-- Administrator: Full management except system
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(2, 2, 1, NOW()), (2, 3, 1, NOW()), (2, 4, 1, NOW()), (2, 5, 1, NOW()), (2, 6, 1, NOW()),
(2, 7, 1, NOW()), (2, 8, 1, NOW()), (2, 9, 1, NOW()), (2, 10, 1, NOW()), (2, 11, 1, NOW()),
(2, 12, 1, NOW()), (2, 13, 1, NOW()), (2, 14, 1, NOW()), (2, 15, 1, NOW()), (2, 16, 1, NOW()),
(2, 17, 1, NOW()), (2, 18, 1, NOW()), (2, 19, 1, NOW()), (2, 20, 1, NOW()), (2, 21, 1, NOW()),
(2, 22, 1, NOW()), (2, 23, 1, NOW()), (2, 24, 1, NOW()), (2, 25, 1, NOW()), (2, 26, 1, NOW()),
(2, 27, 1, NOW()), (2, 28, 1, NOW()), (2, 29, 1, NOW()), (2, 30, 1, NOW()), (2, 31, 1, NOW()),
(2, 32, 1, NOW()), (2, 33, 1, NOW()), (2, 34, 1, NOW()), (2, 35, 1, NOW()), (2, 36, 1, NOW()),
(2, 37, 1, NOW()), (2, 38, 1, NOW()), (2, 49, 1, NOW()), (2, 50, 1, NOW());

-- Director: Strategic oversight
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(3, 2, 1, NOW()), (3, 3, 1, NOW()), (3, 4, 1, NOW()), (3, 5, 1, NOW()), (3, 7, 1, NOW()),
(3, 13, 1, NOW()), (3, 14, 1, NOW()), (3, 19, 1, NOW()), (3, 21, 1, NOW()), (3, 24, 1, NOW()),
(3, 27, 1, NOW()), (3, 28, 1, NOW()), (3, 33, 1, NOW()), (3, 34, 1, NOW()), (3, 35, 1, NOW()),
(3, 36, 1, NOW()), (3, 37, 1, NOW()), (3, 38, 1, NOW()), (3, 49, 1, NOW()), (3, 50, 1, NOW());

-- Manager: Team management
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(4, 2, 1, NOW()), (4, 3, 1, NOW()), (4, 4, 1, NOW()), (4, 5, 1, NOW()), (4, 6, 1, NOW()),
(4, 7, 1, NOW()), (4, 9, 1, NOW()), (4, 10, 1, NOW()), (4, 12, 1, NOW()), (4, 13, 1, NOW()),
(4, 14, 1, NOW()), (4, 21, 1, NOW()), (4, 22, 1, NOW()), (4, 23, 1, NOW()), (4, 24, 1, NOW()),
(4, 25, 1, NOW()), (4, 27, 1, NOW()), (4, 28, 1, NOW()), (4, 32, 1, NOW()), (4, 33, 1, NOW()),
(4, 34, 1, NOW()), (4, 38, 1, NOW()), (4, 46, 1, NOW()), (4, 47, 1, NOW()), (4, 49, 1, NOW());

-- Supervisor: Operational oversight
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(5, 2, 1, NOW()), (5, 3, 1, NOW()), (5, 4, 1, NOW()), (5, 5, 1, NOW()), (5, 7, 1, NOW()),
(5, 10, 1, NOW()), (5, 13, 1, NOW()), (5, 14, 1, NOW()), (5, 21, 1, NOW()), (5, 22, 1, NOW()),
(5, 23, 1, NOW()), (5, 24, 1, NOW()), (5, 25, 1, NOW()), (5, 27, 1, NOW()), (5, 33, 1, NOW()),
(5, 38, 1, NOW()), (5, 46, 1, NOW());

-- Team Lead: Team coordination
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(6, 2, 1, NOW()), (6, 3, 1, NOW()), (6, 5, 1, NOW()), (6, 7, 1, NOW()), (6, 13, 1, NOW()),
(6, 21, 1, NOW()), (6, 22, 1, NOW()), (6, 23, 1, NOW()), (6, 24, 1, NOW()), (6, 25, 1, NOW()),
(6, 27, 1, NOW()), (6, 33, 1, NOW()), (6, 46, 1, NOW());

-- Senior Staff: Extended staff permissions
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(7, 2, 1, NOW()), (7, 3, 1, NOW()), (7, 5, 1, NOW()), (7, 21, 1, NOW()), (7, 22, 1, NOW()),
(7, 23, 1, NOW()), (7, 24, 1, NOW()), (7, 25, 1, NOW()), (7, 27, 1, NOW()), (7, 33, 1, NOW()),
(7, 46, 1, NOW());

-- Staff: Basic operational
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(8, 2, 1, NOW()), (8, 5, 1, NOW()), (8, 21, 1, NOW()), (8, 22, 1, NOW()), (8, 23, 1, NOW()),
(8, 24, 1, NOW()), (8, 25, 1, NOW()), (8, 27, 1, NOW()), (8, 46, 1, NOW());

-- Junior Staff: Limited access
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(9, 2, 1, NOW()), (9, 21, 1, NOW()), (9, 22, 1, NOW()), (9, 23, 1, NOW()), (9, 24, 1, NOW()),
(9, 27, 1, NOW()), (9, 46, 1, NOW());

-- User: Basic user access
INSERT INTO `system_permission` (`role_id`, `abilities_id`, `access_device_type`, `created_at`) VALUES
(10, 2, 1, NOW()), (10, 21, 1, NOW()), (10, 22, 1, NOW()), (10, 23, 1, NOW()), (10, 46, 1, NOW());

-- --------------------------------------------------------
-- Create stored procedure to generate users, profiles and entity files
-- --------------------------------------------------------

DROP PROCEDURE IF EXISTS generate_users_and_profiles;

DELIMITER //

CREATE PROCEDURE generate_users_and_profiles()
BEGIN
    DECLARE i INT DEFAULT 2;
    DECLARE batch_size INT DEFAULT 5000;
    DECLARE total_users INT DEFAULT 500000;
    DECLARE profile_count INT;
    DECLARE file_count INT;
    DECLARE j INT;
    DECLARE role1 INT;
    DECLARE role2 INT;
    DECLARE role3 INT;
    DECLARE role4 INT;
    DECLARE role5 INT;
    DECLARE gender INT;
    DECLARE user_status INT;
    DECLARE birth_year INT;
    DECLARE birth_month INT;
    DECLARE birth_day INT;
    DECLARE file_type VARCHAR(20);
    DECLARE file_ext VARCHAR(10);
    DECLARE file_mime VARCHAR(50);
    -- Malaysian names: Malay, Chinese, Indian
    DECLARE first_names TEXT DEFAULT 'Ahmad,Ali,Muhammad,Mohd,Nurul,Siti,Nur,Amir,Hafiz,Syafiq,Farah,Aisyah,Sarah,Adam,Hakim,Farhan,Izzat,Danish,Alya,Amira,Hana,Fatimah,Zara,Yusuf,Ibrahim,Hassan,Hussein,Omar,Khalid,Rashid,Jamal,Tariq,Bilal,Samir,Zaid,Karim,Nadia,Layla,Mariam,Zahra,Aisha,Khadija,Yasmin,Rania,Dina,Huda,Salma,Lina,Maya,Sara,Noura,Faiza,Jamila,Samira,Latifa,Muna,Wafa,Basma,Dalal,Ghada,Haneen,Iman,Jannah,Lamia,Manal,Nawal,Reem,Sumaya,Tahira,Ulfat,Warda,Zainab,Abeer,Bushra,Duha,Esra,Fatin,Ghaida,Hibah,Isra,Jumana,Khawla,Lubna,Maryam,Nashwa,Qamar,Rabia,Shadia,Tala,Vian,Wijdan,Yara,Zulfa,Azlan,Azman,Azmi,Azhar,Aziz,Azizul,Azrul,Azwan,Badrul,Fairuz,Faizal,Firdaus,Hafizul,Hariz,Haziq,Helmi,Irwan,Iskandar,Izwan,Jazlan,Khairul,Khairuddin,Luqman,Mazlan,Nazri,Nizam,Norazlan,Rizal,Roslan,Shahrul,Shaiful,Shamsul,Suhaimi,Syahir,Taufik,Zainal,Zulkifli,Adibah,Aini,Aishah,Azizah,Azura,Balkis,Faridah,Fazilah,Haslinda,Hayati,Hidayah,Husna,Izzati,Jamilah,Kartini,Latifah,Mahirah,Marlina,Maziah,Nabilah,Nadiah,Nadzirah,Nazira,Nooraini,Norhayati,Norlia,Normala,Raihana,Rashidah,Rohana,Rosmah,Rozita,Saadiah,Safiah,Sakinah,Salwah,Shakira,Sharifah,Suriati,Syahirah,Tengku,Wardah,Yusrina,Zahirah,Zaleha,Zarith,Zulaikha,Wei,Ming,Jian,Hui,Xin,Yi,Jun,Chen,Hao,Zhi,Yong,Fang,Ling,Mei,Xia,Yan,Hong,Jing,Li,Qing,Shu,Ting,Wen,Xiao,Yu,Zhen,Ah,Beng,Cheng,Eng,Fong,Guan,Hua,Kah,Kai,Keat,Keng,Kok,Kwang,Lee,Leng,Leong,Meng,Peng,Seng,Seong,Siew,Swee,Tek,Teng,Wah,Wai,Wan,Wei,Yew,Yoke,Zheng,Ai,Bee,Chin,Choo,Eng,Gaik,Geok,Guat,Hooi,Imm,Joo,Kim,Kooi,Kuan,Lan,Lay,Lee,Lian,Lin,Ling,May,Mee,Mei,Mooi,Nee,Pei,Pheng,Poh,San,Sim,Soo,Suet,Sze,Tze,Wai,Wan,Yen,Yoke,Yun,Arun,Arjun,Bala,Chandran,Deva,Ganesh,Gopal,Hari,Karthik,Krishna,Kumar,Mani,Mohan,Muthu,Nair,Nanda,Navin,Pandian,Prakash,Raj,Rajan,Rajesh,Ravi,Samy,Shankar,Siva,Subramaniam,Suresh,Thiru,Velan,Vijay,Vinod,Anbu,Deepa,Devi,Gayathri,Indra,Jaya,Kala,Kamala,Kavitha,Lakshmi,Lalitha,Mala,Mangai,Meena,Nirmala,Padma,Priya,Radha,Rani,Revathi,Saroja,Savitha,Selvi,Shanti,Sonia,Suganthi,Sumathi,Usha,Vanitha,Vasanthi,Vimala';
    DECLARE last_names TEXT DEFAULT 'Abdullah,Rahman,Hassan,Hussein,Ibrahim,Ismail,Ahmad,Ali,Omar,Yusuf,Khalid,Rashid,Jamal,Tariq,Bilal,Samir,Zaid,Karim,Malik,Nasser,Qasim,Saleh,Tahir,Umar,Walid,Yasir,Zahir,Abbas,Bakr,Dawud,Elias,Faisal,Ghani,Habib,Idris,Jalal,Kamil,Latif,Majid,Nabil,Osman,Pasha,Rafiq,Sadiq,Tawfiq,Uthman,Wahid,Adnan,Bassam,Darwish,Essam,Fikri,Ghanem,Hamdi,Imran,Jawad,Khaled,Luqman,Mansour,Othman,Rizwan,Shams,Talal,Uzair,Wasim,Yasin,Zaman,Ahmed,Bakar,Dahlan,Ehsan,Fahmi,Ghazali,Hilmi,Irfan,Jameel,Kamran,Lutfi,Murad,Naeem,Owais,Rauf,Salam,Tanvir,Usman,Waqas,Yaqub,Zaidi,Aziz,Azman,Baharuddin,Daud,Hamzah,Hashim,Harun,Husin,Jalil,Johari,Kamaruddin,Kassim,Latiff,Lazim,Mahat,Mahmud,Malek,Mat,Mazlan,Musa,Mustafa,Noor,Nordin,Rahim,Ramli,Razak,Rosli,Saad,Salleh,Shafie,Sulaiman,Taib,Wahab,Yusoff,Zakaria,Zamri,Zin,Tan,Lim,Lee,Ng,Wong,Goh,Ong,Teh,Koh,Low,Khoo,Lai,Chong,Cheong,Chin,Chew,Chan,Chang,Choo,Chua,Foo,Ho,Heng,Hiew,Hor,Kam,Kee,Khaw,Khor,Koay,Kuan,Kwok,Lau,Law,Leow,Liew,Lok,Loo,Lye,Mak,Mok,Nah,Neoh,Ooi,Pang,Phang,Phua,Poon,Quah,Saw,Seah,Seng,Sia,Sim,Soo,Sow,Sun,Tay,Teoh,Ting,Tong,Wan,Wee,Woo,Yap,Yeo,Yew,Yong,Pillai,Nair,Menon,Nayar,Panicker,Nambiar,Kurup,Pillai,Chandran,Rajan,Krishnan,Govindan,Subramaniam,Muniandy,Maniam,Ramasamy,Suppiah,Nadarajah,Chelliah,Arumugam,Balasubramaniam,Ganesan,Karuppiah,Letchumanan,Muthusamy,Palani,Perumal,Ponnusamy,Ramachandran,Shanmugam,Sockalingam,Tamilselvan,Thangaraj,Thirunavukarasu,Veerasamy,Velayutham,Selvarajah,Sinnathamby,Sivakumar,Suppramaniam';
    
    -- Create temporary table for batch inserts
    DROP TEMPORARY TABLE IF EXISTS temp_users;
    CREATE TEMPORARY TABLE temp_users (
        `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` bigint UNSIGNED,
        `name` varchar(255),
        `user_preferred_name` varchar(20),
        `email` varchar(255),
        `user_gender` tinyint,
        `user_dob` date,
        `user_contact_no` varchar(15),
        `username` varchar(255),
        `password` varchar(255),
        `user_status` tinyint DEFAULT 1,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
    
    DROP TEMPORARY TABLE IF EXISTS temp_profiles;
    CREATE TEMPORARY TABLE temp_profiles (
        `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` bigint UNSIGNED,
        `role_id` bigint UNSIGNED,
        `is_main` tinyint(1),
        `profile_status` tinyint(1) DEFAULT 1,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
    
    DROP TEMPORARY TABLE IF EXISTS temp_files;
    CREATE TEMPORARY TABLE temp_files (
        `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `files_name` varchar(255),
        `files_original_name` varchar(255),
        `files_type` varchar(50),
        `files_mime` varchar(50),
        `files_extension` varchar(10),
        `files_size` int,
        `files_compression` tinyint(1),
        `files_folder` varchar(255),
        `files_path` varchar(255),
        `files_disk_storage` varchar(20) DEFAULT 'public',
        `files_path_is_url` tinyint(1) DEFAULT 0,
        `files_description` text,
        `entity_type` varchar(255),
        `entity_id` bigint,
        `entity_file_type` varchar(255),
        `user_id` bigint,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    -- --------------------------------------------------------
    -- Insert Super Administrator (User ID = 1) with full access
    -- --------------------------------------------------------
    INSERT INTO `users` (`id`, `name`, `user_preferred_name`, `email`, `user_gender`, `user_dob`, `user_contact_no`, `username`, `password`, `user_status`, `created_at`)
    VALUES (1, 'Super Administrator', 'superadmin', 'superadmin@admin.com', 1, '1990-01-01', '0123456789', 'superadmin', '$2a$12$YhBi14Zkk1y9LpA3nOU8qOIgfk5j8pOxBYj7GsybkmfChVcCO7U3S', 1, NOW());
    
    -- Super Admin profile with role 1 (Super Administrator)
    INSERT INTO `user_profile` (`user_id`, `role_id`, `is_main`, `profile_status`, `created_at`)
    VALUES (1, 1, 1, 1, NOW());
    
    -- Super Admin avatar file
    INSERT INTO `entity_files` (`files_name`, `files_original_name`, `files_type`, `files_mime`, `files_extension`, `files_size`, `files_compression`, `files_folder`, `files_path`, `files_disk_storage`, `files_path_is_url`, `files_description`, `entity_type`, `entity_id`, `entity_file_type`, `user_id`, `created_at`)
    VALUES (CONCAT(UUID(), '.jpg'), 'superadmin_avatar.jpg', 'avatar', 'image/jpeg', 'jpg', 102400, 0, 'uploads/avatars', CONCAT('uploads/avatars/', UUID(), '.jpg'), 'public', 0, 'Super Administrator profile picture', 'users', 1, 'avatar', 1, NOW());
    
    COMMIT;

    -- Generate remaining users in batches (starting from user 2)
    WHILE i <= total_users DO
        SET gender = IF(RAND() > 0.5, 1, 2);
        SET user_status = ELT(FLOOR(1 + RAND() * 10), 1, 1, 1, 1, 1, 1, 1, 0, 2, 4); -- 70% active, 10% inactive, 10% suspended, 10% unverified
        SET birth_year = FLOOR(1970 + RAND() * 40);
        SET birth_month = FLOOR(1 + RAND() * 12);
        SET birth_day = FLOOR(1 + RAND() * 28);
        
        INSERT INTO temp_users (`user_id`, `name`, `user_preferred_name`, `email`, `user_gender`, `user_dob`, `user_contact_no`, `username`, `password`, `user_status`, `created_at`)
        VALUES (
            i,
            CONCAT(
                SUBSTRING_INDEX(SUBSTRING_INDEX(first_names, ',', FLOOR(1 + RAND() * 200)), ',', -1),
                ' ',
                SUBSTRING_INDEX(SUBSTRING_INDEX(last_names, ',', FLOOR(1 + RAND() * 200)), ',', -1)
            ),
            CONCAT('user', i),
            CONCAT('user', i, '@example.com'),
            gender,
            DATE(CONCAT(birth_year, '-', LPAD(birth_month, 2, '0'), '-', LPAD(birth_day, 2, '0'))),
            CONCAT('01', LPAD(FLOOR(RAND() * 100000000), 8, '0')),
            CONCAT('user', i),
            '$2a$12$YhBi14Zkk1y9LpA3nOU8qOIgfk5j8pOxBYj7GsybkmfChVcCO7U3S', -- password: password
            user_status,
            DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 730) DAY)
        );
        
        -- Generate 4-5 profiles for each user
        SET profile_count = FLOOR(4 + RAND() * 2); -- 4 or 5 profiles
        -- Use roles 2-10 only (role 1 = Super Administrator is reserved for user 1)
        SET role1 = FLOOR(2 + RAND() * 9);
        SET role2 = FLOOR(2 + RAND() * 9);
        SET role3 = FLOOR(2 + RAND() * 9);
        SET role4 = FLOOR(2 + RAND() * 9);
        SET role5 = FLOOR(2 + RAND() * 9);
        
        -- Ensure different roles for each profile
        WHILE role2 = role1 DO
            SET role2 = FLOOR(2 + RAND() * 9);
        END WHILE;
        
        WHILE role3 = role1 OR role3 = role2 DO
            SET role3 = FLOOR(2 + RAND() * 9);
        END WHILE;
        
        WHILE role4 = role1 OR role4 = role2 OR role4 = role3 DO
            SET role4 = FLOOR(2 + RAND() * 9);
        END WHILE;
        
        WHILE role5 = role1 OR role5 = role2 OR role5 = role3 OR role5 = role4 DO
            SET role5 = FLOOR(2 + RAND() * 9);
        END WHILE;
        
        -- First profile (main)
        INSERT INTO temp_profiles (`user_id`, `role_id`, `is_main`, `profile_status`, `created_at`)
        VALUES (i, role1, 1, 1, DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 730) DAY));
        
        -- Second profile
        INSERT INTO temp_profiles (`user_id`, `role_id`, `is_main`, `profile_status`, `created_at`)
        VALUES (i, role2, 0, IF(RAND() > 0.15, 1, 0), DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 600) DAY));
        
        -- Third profile
        INSERT INTO temp_profiles (`user_id`, `role_id`, `is_main`, `profile_status`, `created_at`)
        VALUES (i, role3, 0, IF(RAND() > 0.2, 1, 0), DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 500) DAY));
        
        -- Fourth profile
        INSERT INTO temp_profiles (`user_id`, `role_id`, `is_main`, `profile_status`, `created_at`)
        VALUES (i, role4, 0, IF(RAND() > 0.25, 1, 0), DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 365) DAY));
        
        -- Fifth profile (only if profile_count = 5)
        IF profile_count = 5 THEN
            INSERT INTO temp_profiles (`user_id`, `role_id`, `is_main`, `profile_status`, `created_at`)
            VALUES (i, role5, 0, IF(RAND() > 0.3, 1, 0), DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 180) DAY));
        END IF;
        
        -- Generate 1-3 entity files for each user (profile avatar, documents, etc.)
        SET file_count = FLOOR(1 + RAND() * 3);
        SET j = 1;
        
        WHILE j <= file_count DO
            -- Randomize file type
            CASE FLOOR(1 + RAND() * 5)
                WHEN 1 THEN 
                    SET file_type = 'image';
                    SET file_ext = ELT(FLOOR(1 + RAND() * 4), 'jpg', 'png', 'jpeg', 'webp');
                    SET file_mime = CONCAT('image/', file_ext);
                WHEN 2 THEN 
                    SET file_type = 'document';
                    SET file_ext = 'pdf';
                    SET file_mime = 'application/pdf';
                WHEN 3 THEN 
                    SET file_type = 'document';
                    SET file_ext = ELT(FLOOR(1 + RAND() * 2), 'doc', 'docx');
                    SET file_mime = 'application/msword';
                WHEN 4 THEN 
                    SET file_type = 'spreadsheet';
                    SET file_ext = ELT(FLOOR(1 + RAND() * 2), 'xls', 'xlsx');
                    SET file_mime = 'application/vnd.ms-excel';
                ELSE 
                    SET file_type = 'avatar';
                    SET file_ext = 'jpg';
                    SET file_mime = 'image/jpeg';
            END CASE;
            
            INSERT INTO temp_files (
                `files_name`, `files_original_name`, `files_type`, `files_mime`, `files_extension`,
                `files_size`, `files_compression`, `files_folder`, `files_path`, `files_disk_storage`,
                `files_path_is_url`, `files_description`, `entity_type`, `entity_id`, `entity_file_type`,
                `user_id`, `created_at`
            ) VALUES (
                CONCAT(UUID(), '.', file_ext),
                CONCAT(ELT(FLOOR(1 + RAND() * 6), 'profile_photo', 'document', 'resume', 'certificate', 'id_card', 'attachment'), '_', i, '_', j, '.', file_ext),
                file_type,
                file_mime,
                file_ext,
                FLOOR(10240 + RAND() * 5242880), -- 10KB to 5MB
                IF(RAND() > 0.7, 1, 0),
                CONCAT('uploads/', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 365) DAY), '%Y/%m')),
                CONCAT('uploads/', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 365) DAY), '%Y/%m'), '/', UUID(), '.', file_ext),
                ELT(FLOOR(1 + RAND() * 3), 'public', 'private', 'local'),
                0,
                ELT(FLOOR(1 + RAND() * 5), 'Profile picture', 'Identity document', 'Work document', 'Personal file', 'Certificate'),
                ELT(FLOOR(1 + RAND() * 3), 'users', 'user_profile', 'documents'),
                i,
                ELT(FLOOR(1 + RAND() * 4), 'avatar', 'document', 'attachment', 'identity'),
                i,
                DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 365) DAY)
            );
            
            SET j = j + 1;
        END WHILE;
        
        -- Commit in batches
        IF i MOD batch_size = 0 THEN
            INSERT INTO `users` (`id`, `name`, `user_preferred_name`, `email`, `user_gender`, `user_dob`, `user_contact_no`, `username`, `password`, `user_status`, `created_at`)
            SELECT `user_id`, `name`, `user_preferred_name`, `email`, `user_gender`, `user_dob`, `user_contact_no`, `username`, `password`, `user_status`, `created_at`
            FROM temp_users;
            
            INSERT INTO `user_profile` (`user_id`, `role_id`, `is_main`, `profile_status`, `created_at`)
            SELECT `user_id`, `role_id`, `is_main`, `profile_status`, `created_at`
            FROM temp_profiles;
            
            INSERT INTO `entity_files` (`files_name`, `files_original_name`, `files_type`, `files_mime`, `files_extension`, `files_size`, `files_compression`, `files_folder`, `files_path`, `files_disk_storage`, `files_path_is_url`, `files_description`, `entity_type`, `entity_id`, `entity_file_type`, `user_id`, `created_at`)
            SELECT `files_name`, `files_original_name`, `files_type`, `files_mime`, `files_extension`, `files_size`, `files_compression`, `files_folder`, `files_path`, `files_disk_storage`, `files_path_is_url`, `files_description`, `entity_type`, `entity_id`, `entity_file_type`, `user_id`, `created_at`
            FROM temp_files;
            
            TRUNCATE TABLE temp_users;
            TRUNCATE TABLE temp_profiles;
            TRUNCATE TABLE temp_files;
            
            COMMIT;
            
            SELECT CONCAT('Progress: ', i, ' / ', total_users, ' users created (', ROUND(i * 100 / total_users, 1), '%)') AS status;
        END IF;
        
        SET i = i + 1;
    END WHILE;
    
    -- Insert remaining records
    INSERT INTO `users` (`id`, `name`, `user_preferred_name`, `email`, `user_gender`, `user_dob`, `user_contact_no`, `username`, `password`, `user_status`, `created_at`)
    SELECT `user_id`, `name`, `user_preferred_name`, `email`, `user_gender`, `user_dob`, `user_contact_no`, `username`, `password`, `user_status`, `created_at`
    FROM temp_users;
    
    INSERT INTO `user_profile` (`user_id`, `role_id`, `is_main`, `profile_status`, `created_at`)
    SELECT `user_id`, `role_id`, `is_main`, `profile_status`, `created_at`
    FROM temp_profiles;
    
    INSERT INTO `entity_files` (`files_name`, `files_original_name`, `files_type`, `files_mime`, `files_extension`, `files_size`, `files_compression`, `files_folder`, `files_path`, `files_disk_storage`, `files_path_is_url`, `files_description`, `entity_type`, `entity_id`, `entity_file_type`, `user_id`, `created_at`)
    SELECT `files_name`, `files_original_name`, `files_type`, `files_mime`, `files_extension`, `files_size`, `files_compression`, `files_folder`, `files_path`, `files_disk_storage`, `files_path_is_url`, `files_description`, `entity_type`, `entity_id`, `entity_file_type`, `user_id`, `created_at`
    FROM temp_files;
    
    COMMIT;
    
    -- Cleanup
    DROP TEMPORARY TABLE IF EXISTS temp_users;
    DROP TEMPORARY TABLE IF EXISTS temp_profiles;
    DROP TEMPORARY TABLE IF EXISTS temp_files;
    
    -- Final summary
    SELECT 
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM user_profile) AS total_profiles,
        (SELECT COUNT(*) FROM master_roles) AS total_roles,
        (SELECT COUNT(*) FROM entity_files) AS total_files,
        (SELECT COUNT(*) FROM system_abilities) AS total_abilities,
        (SELECT COUNT(*) FROM system_permission) AS total_permissions,
        (SELECT AVG(profile_count) FROM (SELECT user_id, COUNT(*) as profile_count FROM user_profile GROUP BY user_id) t) AS avg_profiles_per_user;
        
END //

DELIMITER ;

-- --------------------------------------------------------
-- Execute the procedure
-- --------------------------------------------------------

CALL generate_users_and_profiles();

-- --------------------------------------------------------
-- Cleanup
-- --------------------------------------------------------

DROP PROCEDURE IF EXISTS generate_users_and_profiles;

SET FOREIGN_KEY_CHECKS = 1;
SET UNIQUE_CHECKS = 1;
SET AUTOCOMMIT = 1;

-- --------------------------------------------------------
-- Verify data
-- --------------------------------------------------------

SELECT 'Data Generation Complete!' AS message;
SELECT COUNT(*) AS total_users FROM users;
SELECT COUNT(*) AS total_profiles FROM user_profile;
SELECT COUNT(*) AS total_files FROM entity_files;
SELECT COUNT(*) AS total_abilities FROM system_abilities;
SELECT COUNT(*) AS total_permissions FROM system_permission;

-- Profiles per role
SELECT 
    mr.role_name,
    mr.role_rank,
    COUNT(up.id) AS profile_count
FROM master_roles mr
LEFT JOIN user_profile up ON mr.id = up.role_id
GROUP BY mr.id, mr.role_name, mr.role_rank
ORDER BY mr.role_rank DESC;

-- Files by type
SELECT 
    files_type,
    COUNT(*) AS file_count,
    ROUND(SUM(files_size) / 1024 / 1024, 2) AS total_size_mb
FROM entity_files
GROUP BY files_type
ORDER BY file_count DESC;

-- Users per status
SELECT 
    CASE user_status
        WHEN 0 THEN 'Inactive'
        WHEN 1 THEN 'Active'
        WHEN 2 THEN 'Suspended'
        WHEN 3 THEN 'Deleted'
        WHEN 4 THEN 'Unverified'
        ELSE 'Unknown'
    END AS status_name,
    COUNT(*) AS user_count
FROM users
GROUP BY user_status
ORDER BY user_count DESC;

-- Average profiles per user
SELECT 
    ROUND(AVG(profile_count), 2) AS avg_profiles_per_user,
    MIN(profile_count) AS min_profiles,
    MAX(profile_count) AS max_profiles
FROM (
    SELECT user_id, COUNT(*) as profile_count 
    FROM user_profile 
    GROUP BY user_id
) t;

-- --------------------------------------------------------
-- Create Indexes for Optimized Query Performance
-- Based on controller queries analysis
-- --------------------------------------------------------

-- Users table indexes
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_status ON users(user_status);
CREATE INDEX idx_users_gender ON users(user_gender);
CREATE INDEX idx_users_deleted ON users(deleted_at);
CREATE INDEX idx_users_status_deleted ON users(user_status, deleted_at);

-- System login attempt table indexes
CREATE INDEX idx_login_attempt_composite ON system_login_attempt(ip_address, user_id, time);
CREATE INDEX idx_login_attempt_user ON system_login_attempt(user_id);

-- User profile table indexes
CREATE INDEX idx_user_profile_user_status_main ON user_profile(user_id, profile_status, is_main);
CREATE INDEX idx_user_profile_role ON user_profile(role_id);
CREATE INDEX idx_user_profile_role_status ON user_profile(role_id, profile_status);
CREATE INDEX idx_user_profile_user_role ON user_profile (user_id, role_id);
CREATE INDEX idx_user_profile_user ON user_profile(user_id);

-- Master roles table indexes
CREATE INDEX idx_master_roles_status ON master_roles(role_status);
CREATE INDEX idx_master_roles_deleted ON master_roles(deleted_at);
CREATE INDEX idx_master_roles_status_deleted ON master_roles(role_status, deleted_at);

-- System permission table indexes
CREATE INDEX idx_system_permission_role_abilities ON system_permission(role_id, abilities_id);
CREATE INDEX idx_system_permission_abilities ON system_permission(abilities_id);
CREATE INDEX idx_system_permission_role ON system_permission(role_id);

-- System abilities table indexes
CREATE INDEX idx_system_abilities_deleted ON system_abilities(deleted_at);
CREATE INDEX idx_system_abilities_slug ON system_abilities(abilities_slug);

-- Entity files table indexes
CREATE INDEX idx_entity_files_composite ON entity_files(entity_type, entity_file_type, entity_id);
CREATE INDEX idx_entity_files_entity ON entity_files(entity_id, entity_file_type);
CREATE INDEX idx_entity_files_user ON entity_files(user_id);

-- Master email templates table indexes
CREATE INDEX idx_email_templates_type_status ON master_email_templates(email_type, email_status);
CREATE INDEX idx_email_templates_status ON master_email_templates(email_status);
CREATE INDEX idx_email_templates_type ON master_email_templates(email_type);

-- System login history table indexes (for future queries)
CREATE INDEX idx_login_history_user ON system_login_history(user_id);
CREATE INDEX idx_login_history_time ON system_login_history(time);

SELECT 'All indexes created successfully!' AS message;
