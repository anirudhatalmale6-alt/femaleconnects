-- ---------------------------------------------------------------------------
--  Girls Connect - database schema
--  MySQL 5.7+ / MariaDB 10.2+ / MySQL 8.x
--
--  Import with:  mysql -u USER -p DATABASE < schema.sql
--  (or paste into phpMyAdmin -> Import)
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';


-- ---------------------------------------------------------------------------
--  users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(60)  NOT NULL,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  birth_date      DATE         NULL,
  city            VARCHAR(80)  NULL,
  bio             VARCHAR(600) NULL,
  interests       VARCHAR(255) NULL,          -- comma separated tags
  avatar          VARCHAR(120) NULL,          -- file name inside /uploads/avatars
  role            ENUM('member','admin') NOT NULL DEFAULT 'member',
  status          ENUM('pending','active','suspended') NOT NULL DEFAULT 'active',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at    DATETIME     NULL,
  last_ip         VARCHAR(45)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_status (status),
  KEY ix_users_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  conversations - exactly one row per pair of members.
--  user_low_id is always the smaller of the two ids, so the UNIQUE key
--  makes a duplicate conversation impossible.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conversations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_low_id     INT UNSIGNED NOT NULL,
  user_high_id    INT UNSIGNED NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pair (user_low_id, user_high_id),
  KEY ix_conv_high (user_high_id),
  KEY ix_conv_recent (last_message_at),
  CONSTRAINT fk_conv_low  FOREIGN KEY (user_low_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_high FOREIGN KEY (user_high_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  messages
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id INT UNSIGNED NOT NULL,
  sender_id       INT UNSIGNED NOT NULL,
  body            TEXT         NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at         DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_msg_conv (conversation_id, id),
  KEY ix_msg_sender (sender_id),
  KEY ix_msg_unread (conversation_id, sender_id, read_at),
  CONSTRAINT fk_msg_conv   FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id)       REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  blocks - blocker no longer receives messages from blocked
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blocks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  blocker_id  INT UNSIGNED NOT NULL,
  blocked_id  INT UNSIGNED NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_block (blocker_id, blocked_id),
  KEY ix_block_blocked (blocked_id),
  CONSTRAINT fk_block_blocker FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_block_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  reports - a member flags another member (or a single message) to the admin
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reports (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id       INT UNSIGNED NOT NULL,
  reported_user_id  INT UNSIGNED NOT NULL,
  message_id        INT UNSIGNED NULL,
  reason            VARCHAR(60)  NOT NULL,
  details           VARCHAR(600) NULL,
  status            ENUM('open','actioned','dismissed') NOT NULL DEFAULT 'open',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by       INT UNSIGNED NULL,
  reviewed_at       DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_report_status (status, created_at),
  KEY ix_report_reported (reported_user_id),
  CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id)      REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_report_target   FOREIGN KEY (reported_user_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_report_message  FOREIGN KEY (message_id)       REFERENCES messages(id) ON DELETE SET NULL,
  CONSTRAINT fk_report_admin    FOREIGN KEY (reviewed_by)      REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  login_attempts - powers the "too many wrong passwords" lockout
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(190) NOT NULL,
  ip          VARCHAR(45)  NOT NULL,
  successful  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_attempt_email (email, created_at),
  KEY ix_attempt_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  admin_log - audit trail of everything an admin does
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id    INT UNSIGNED NULL,
  action      VARCHAR(60)  NOT NULL,
  target_type VARCHAR(30)  NULL,
  target_id   INT UNSIGNED NULL,
  details     VARCHAR(400) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_adminlog_time (created_at),
  CONSTRAINT fk_adminlog_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
