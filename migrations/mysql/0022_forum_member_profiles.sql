CREATE TABLE ce_forum_member_profiles (
 user_id CHAR(36) PRIMARY KEY,
 display_name VARCHAR(60) NOT NULL DEFAULT '',
 bio VARCHAR(2000) NOT NULL DEFAULT '',
 location VARCHAR(120) NOT NULL DEFAULT '',
 website VARCHAR(500) NOT NULL DEFAULT '',
 signature VARCHAR(500) NOT NULL DEFAULT '',
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE ce_forum_member_preferences (
 user_id CHAR(36) PRIMARY KEY,
 profile_visibility VARCHAR(10) NOT NULL DEFAULT 'public' CHECK (profile_visibility IN ('public','members','private')),
 activity_visibility VARCHAR(10) NOT NULL DEFAULT 'public' CHECK (activity_visibility IN ('public','members','private')),
 show_groups INTEGER NOT NULL DEFAULT 0 CHECK (show_groups IN (0,1)),
 show_signatures INTEGER NOT NULL DEFAULT 1 CHECK (show_signatures IN (0,1)),
 theme VARCHAR(10) NOT NULL DEFAULT 'system' CHECK (theme IN ('system','light','dark')),
 locale VARCHAR(10) NOT NULL DEFAULT 'ru-RU' CHECK (locale IN ('ru-RU','en-US')),
 timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE ce_forum_profile_images (
 user_id CHAR(36) NOT NULL,
 kind VARCHAR(10) NOT NULL CHECK (kind IN ('avatar','cover')),
 image_data MEDIUMTEXT NOT NULL,
 PRIMARY KEY (user_id,kind),
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE ce_forum_profile_fields (
 id CHAR(36) PRIMARY KEY,
 label VARCHAR(80) NOT NULL,
 visibility VARCHAR(10) NOT NULL CHECK (visibility IN ('public','members','private')),
 position INTEGER NOT NULL DEFAULT 0 CHECK (position>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE ce_forum_profile_field_values (
 user_id CHAR(36) NOT NULL,
 field_id CHAR(36) NOT NULL,
 field_value VARCHAR(500) NOT NULL,
 PRIMARY KEY (user_id,field_id),
 FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE,
 FOREIGN KEY (field_id) REFERENCES ce_forum_profile_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ce_forum_profile_field_lookup ON ce_forum_profile_field_values(field_id,user_id);
