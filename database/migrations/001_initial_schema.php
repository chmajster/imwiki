<?php
declare(strict_types=1);

return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}users` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(100) NOT NULL UNIQUE,
 first_name VARCHAR(100) NOT NULL DEFAULT '',
 last_name VARCHAR(100) NOT NULL DEFAULT '',
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 status ENUM('active','blocked','disabled') NOT NULL DEFAULT 'active',
 force_password_change TINYINT(1) NOT NULL DEFAULT 0,
 language VARCHAR(10) NOT NULL DEFAULT 'pl',
 timezone VARCHAR(100) NOT NULL DEFAULT 'Europe/Warsaw',
 theme ENUM('light','dark','system') NOT NULL DEFAULT 'system',
 last_login_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 deleted_at DATETIME NULL,
 INDEX idx_users_status(status), INDEX idx_users_deleted(deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}roles` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 label VARCHAR(150) NOT NULL,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}permissions` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL UNIQUE,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}role_permissions` (
 role_id BIGINT UNSIGNED NOT NULL,
 permission_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(role_id, permission_id),
 FOREIGN KEY(role_id) REFERENCES `{{prefix}}roles`(id) ON DELETE CASCADE,
 FOREIGN KEY(permission_id) REFERENCES `{{prefix}}permissions`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}user_roles` (
 user_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id, role_id),
 FOREIGN KEY(user_id) REFERENCES `{{prefix}}users`(id) ON DELETE CASCADE,
 FOREIGN KEY(role_id) REFERENCES `{{prefix}}roles`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}groups` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 label VARCHAR(150) NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}group_users` (
 group_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(group_id,user_id),
 FOREIGN KEY(group_id) REFERENCES `{{prefix}}groups`(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES `{{prefix}}users`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}spaces` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 space_key VARCHAR(50) NOT NULL UNIQUE,
 description TEXT NULL,
 icon VARCHAR(100) NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 visibility ENUM('logged_in','private','restricted') NOT NULL DEFAULT 'logged_in',
 archived_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 deleted_at DATETIME NULL,
 INDEX idx_spaces_visibility(visibility),
 FOREIGN KEY(owner_id) REFERENCES `{{prefix}}users`(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}space_permissions` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 space_id BIGINT UNSIGNED NOT NULL,
 subject_type ENUM('user','group') NOT NULL,
 subject_id BIGINT UNSIGNED NOT NULL,
 can_view TINYINT(1) NOT NULL DEFAULT 0,
 can_create_page TINYINT(1) NOT NULL DEFAULT 0,
 can_edit_page TINYINT(1) NOT NULL DEFAULT 0,
 can_delete_page TINYINT(1) NOT NULL DEFAULT 0,
 can_comment TINYINT(1) NOT NULL DEFAULT 0,
 can_manage TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY uq_space_subject(space_id,subject_type,subject_id),
 FOREIGN KEY(space_id) REFERENCES `{{prefix}}spaces`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}pages` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 space_id BIGINT UNSIGNED NOT NULL,
 parent_id BIGINT UNSIGNED NULL,
 title VARCHAR(255) NOT NULL,
 slug VARCHAR(255) NOT NULL,
 content MEDIUMTEXT NOT NULL,
 status ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
 version_no INT UNSIGNED NOT NULL DEFAULT 1,
 author_id BIGINT UNSIGNED NOT NULL,
 last_editor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 deleted_at DATETIME NULL,
 UNIQUE KEY uq_page_slug(space_id,slug,deleted_at),
 INDEX idx_pages_space_parent(space_id,parent_id), INDEX idx_pages_updated(updated_at),
 FULLTEXT KEY ft_pages(title,content),
 FOREIGN KEY(space_id) REFERENCES `{{prefix}}spaces`(id),
 FOREIGN KEY(parent_id) REFERENCES `{{prefix}}pages`(id) ON DELETE SET NULL,
 FOREIGN KEY(author_id) REFERENCES `{{prefix}}users`(id),
 FOREIGN KEY(last_editor_id) REFERENCES `{{prefix}}users`(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}page_permissions` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 page_id BIGINT UNSIGNED NOT NULL,
 subject_type ENUM('user','group') NOT NULL,
 subject_id BIGINT UNSIGNED NOT NULL,
 can_view TINYINT(1) NOT NULL DEFAULT 0,
 can_edit TINYINT(1) NOT NULL DEFAULT 0,
 can_delete TINYINT(1) NOT NULL DEFAULT 0,
 can_comment TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY uq_page_subject(page_id,subject_type,subject_id),
 FOREIGN KEY(page_id) REFERENCES `{{prefix}}pages`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}page_versions` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 page_id BIGINT UNSIGNED NOT NULL,
 version_no INT UNSIGNED NOT NULL,
 title VARCHAR(255) NOT NULL,
 content MEDIUMTEXT NOT NULL,
 author_id BIGINT UNSIGNED NOT NULL,
 change_comment VARCHAR(500) NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_page_version(page_id,version_no),
 FOREIGN KEY(page_id) REFERENCES `{{prefix}}pages`(id) ON DELETE CASCADE,
 FOREIGN KEY(author_id) REFERENCES `{{prefix}}users`(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}drafts` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 page_id BIGINT UNSIGNED NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(255) NOT NULL,
 content MEDIUMTEXT NOT NULL,
 base_version INT UNSIGNED NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_draft(page_id,user_id),
 FOREIGN KEY(page_id) REFERENCES `{{prefix}}pages`(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES `{{prefix}}users`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}attachments` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 page_id BIGINT UNSIGNED NOT NULL,
 uploader_id BIGINT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 stored_name VARCHAR(255) NOT NULL UNIQUE,
 mime_type VARCHAR(190) NOT NULL,
 size_bytes BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 deleted_at DATETIME NULL,
 INDEX idx_attach_page(page_id),
 FOREIGN KEY(page_id) REFERENCES `{{prefix}}pages`(id) ON DELETE CASCADE,
 FOREIGN KEY(uploader_id) REFERENCES `{{prefix}}users`(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}comments` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 page_id BIGINT UNSIGNED NOT NULL,
 parent_id BIGINT UNSIGNED NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 body TEXT NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 deleted_at DATETIME NULL,
 INDEX idx_comments_page(page_id,created_at),
 FOREIGN KEY(page_id) REFERENCES `{{prefix}}pages`(id) ON DELETE CASCADE,
 FOREIGN KEY(parent_id) REFERENCES `{{prefix}}comments`(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES `{{prefix}}users`(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}labels` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}page_labels` (
 page_id BIGINT UNSIGNED NOT NULL,
 label_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(page_id,label_id),
 FOREIGN KEY(page_id) REFERENCES `{{prefix}}pages`(id) ON DELETE CASCADE,
 FOREIGN KEY(label_id) REFERENCES `{{prefix}}labels`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}favorites` (
 user_id BIGINT UNSIGNED NOT NULL,
 page_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(user_id,page_id),
 FOREIGN KEY(user_id) REFERENCES `{{prefix}}users`(id) ON DELETE CASCADE,
 FOREIGN KEY(page_id) REFERENCES `{{prefix}}pages`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}page_views` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 page_id BIGINT UNSIGNED NOT NULL,
 viewed_at DATETIME NOT NULL,
 INDEX idx_views_user(user_id,viewed_at),
 FOREIGN KEY(user_id) REFERENCES `{{prefix}}users`(id) ON DELETE CASCADE,
 FOREIGN KEY(page_id) REFERENCES `{{prefix}}pages`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}watchers` (
 user_id BIGINT UNSIGNED NOT NULL,
 resource_type ENUM('page','space') NOT NULL,
 resource_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(user_id,resource_type,resource_id),
 FOREIGN KEY(user_id) REFERENCES `{{prefix}}users`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}templates` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 description VARCHAR(255) NULL,
 content MEDIUMTEXT NOT NULL,
 is_system TINYINT(1) NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}settings` (
 setting_key VARCHAR(190) PRIMARY KEY,
 setting_value MEDIUMTEXT NULL,
 is_secret TINYINT(1) NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}activity_log` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 resource_type VARCHAR(100) NOT NULL,
 resource_id BIGINT UNSIGNED NULL,
 description VARCHAR(500) NOT NULL,
 created_at DATETIME NOT NULL,
 INDEX idx_activity_created(created_at),
 FOREIGN KEY(user_id) REFERENCES `{{prefix}}users`(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}audit_log` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 resource_type VARCHAR(100) NOT NULL,
 resource_id BIGINT UNSIGNED NULL,
 description VARCHAR(500) NOT NULL,
 ip_address VARCHAR(64) NOT NULL,
 user_agent VARCHAR(500) NOT NULL,
 created_at DATETIME NOT NULL,
 INDEX idx_audit_created(created_at), INDEX idx_audit_action(action),
 FOREIGN KEY(user_id) REFERENCES `{{prefix}}users`(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}slug_redirects` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 page_id BIGINT UNSIGNED NOT NULL,
 old_slug VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_old_slug(page_id,old_slug),
 FOREIGN KEY(page_id) REFERENCES `{{prefix}}pages`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
