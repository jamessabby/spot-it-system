-- ============================================================
-- S.P.O.T.-IT — Community Forum & Announcements DB Schema
-- Database: spotit_community_db
-- Tables:   announcements, forum_posts, forum_comments, forum_votes
--
-- MICROSERVICES: Completely separate DB from auth/monitor/lostfound/user.
-- user_id is a denormalized reference to spotit_auth_db.users.id.
-- detection_id is a denormalized reference to spotit_monitor_db.detections.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `spotit_community_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `spotit_community_db`;

-- ── announcements ─────────────────────────────────────────────────────────────
-- Official posts by admin only. Read-only for students/staff.
CREATE TABLE `announcements` (
  `announcement_id` int(11)      NOT NULL AUTO_INCREMENT,
  `author_id`       int(11)      NOT NULL COMMENT 'user_id from spotit_auth_db — admin only',
  `author_name`     varchar(200) NOT NULL COMMENT 'Denormalized for display speed',
  `title`           varchar(300) NOT NULL,
  `content`         text         NOT NULL,
  `category`        enum(
                      'laboratory_advisory',
                      'lost_and_found',
                      'claiming_schedule',
                      'maintenance',
                      'system_update',
                      'general'
                    ) NOT NULL DEFAULT 'general',
  `is_pinned`       tinyint(1)   NOT NULL DEFAULT 0,
  `is_published`    tinyint(1)   NOT NULL DEFAULT 1,
  `attachment_path` varchar(300) DEFAULT NULL,
  `view_count`      int(11)      NOT NULL DEFAULT 0,
  `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`),
  KEY `idx_category`  (`category`),
  KEY `idx_pinned`    (`is_pinned`),
  KEY `idx_published` (`is_published`),
  KEY `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── forum_posts ───────────────────────────────────────────────────────────────
-- Community posts. All roles can create. Can be linked to a detection event.
CREATE TABLE `forum_posts` (
  `post_id`          int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`          int(11)      NOT NULL,
  `author_name`      varchar(200) NOT NULL COMMENT 'Denormalized',
  `author_role`      varchar(30)  NOT NULL DEFAULT 'student',
  `title`            varchar(300) NOT NULL,
  `content`          text         NOT NULL,
  `category`         enum(
                       'lost_and_found',
                       'found_item',
                       'general',
                       'question',
                       'detection_thread'
                     ) NOT NULL DEFAULT 'general',
  `flair`            varchar(80)  DEFAULT NULL COMMENT 'Optional user-set flair label',
  -- Detection integration
  `detection_id`     int(11)      DEFAULT NULL COMMENT 'Links to spotit_monitor_db.detections',
  `detection_room`   varchar(30)  DEFAULT NULL COMMENT 'Denormalized room_id',
  `detection_item`   varchar(150) DEFAULT NULL COMMENT 'Detected item description',
  `detection_snap`   varchar(300) DEFAULT NULL COMMENT 'Snapshot URL from monitoring DB',
  `is_auto_generated` tinyint(1)  NOT NULL DEFAULT 0 COMMENT '1 = system created from detection',
  -- Vote tallies (denormalized for fast reads — kept in sync by triggers/handlers)
  `upvotes`          int(11)      NOT NULL DEFAULT 0,
  `downvotes`        int(11)      NOT NULL DEFAULT 0,
  `score`            int(11)      GENERATED ALWAYS AS (`upvotes` - `downvotes`) STORED,
  `comment_count`    int(11)      NOT NULL DEFAULT 0,
  `is_locked`        tinyint(1)   NOT NULL DEFAULT 0 COMMENT 'Admin can lock a thread',
  `is_removed`       tinyint(1)   NOT NULL DEFAULT 0,
  `created_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       datetime     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`post_id`),
  KEY `idx_user`      (`user_id`),
  KEY `idx_category`  (`category`),
  KEY `idx_score`     (`score`),
  KEY `idx_created`   (`created_at`),
  KEY `idx_detection` (`detection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── forum_comments ────────────────────────────────────────────────────────────
-- Nested comments on posts. Max nesting depth enforced at application level (5).
-- parent_comment_id = NULL means top-level comment on the post.
CREATE TABLE `forum_comments` (
  `comment_id`        int(11)  NOT NULL AUTO_INCREMENT,
  `post_id`           int(11)  NOT NULL,
  `user_id`           int(11)  NOT NULL,
  `author_name`       varchar(200) NOT NULL COMMENT 'Denormalized',
  `author_role`       varchar(30)  NOT NULL DEFAULT 'student',
  `parent_comment_id` int(11)  DEFAULT NULL COMMENT 'NULL = top-level; set = reply to comment',
  `depth`             tinyint(4) NOT NULL DEFAULT 0 COMMENT 'Nesting depth (0=top-level, max=5)',
  `content`           text     NOT NULL,
  `upvotes`           int(11)  NOT NULL DEFAULT 0,
  `downvotes`         int(11)  NOT NULL DEFAULT 0,
  `score`             int(11)  GENERATED ALWAYS AS (`upvotes` - `downvotes`) STORED,
  `is_removed`        tinyint(1) NOT NULL DEFAULT 0,
  `created_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`comment_id`),
  KEY `idx_post`       (`post_id`),
  KEY `idx_user`       (`user_id`),
  KEY `idx_parent`     (`parent_comment_id`),
  KEY `idx_depth`      (`depth`),
  KEY `idx_created`    (`created_at`),
  FOREIGN KEY (`post_id`) REFERENCES `forum_posts`(`post_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── forum_votes ───────────────────────────────────────────────────────────────
-- One vote per user per post, one vote per user per comment.
-- vote_type: 1 = upvote, -1 = downvote
-- Exactly one of post_id / comment_id is set per row (the other is NULL).
CREATE TABLE `forum_votes` (
  `vote_id`    int(11)   NOT NULL AUTO_INCREMENT,
  `user_id`    int(11)   NOT NULL,
  `post_id`    int(11)   DEFAULT NULL,
  `comment_id` int(11)   DEFAULT NULL,
  `vote_type`  tinyint(4) NOT NULL COMMENT '1 = upvote, -1 = downvote',
  `created_at` datetime  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`vote_id`),
  -- Enforce one vote per user per post
  UNIQUE KEY `uq_user_post`    (`user_id`, `post_id`),
  -- Enforce one vote per user per comment
  UNIQUE KEY `uq_user_comment` (`user_id`, `comment_id`),
  KEY `idx_post`    (`post_id`),
  KEY `idx_comment` (`comment_id`),
  KEY `idx_user`    (`user_id`),
  CONSTRAINT `chk_vote_target` CHECK (
    (`post_id` IS NOT NULL AND `comment_id` IS NULL) OR
    (`post_id` IS NULL AND `comment_id` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed: sample announcements ────────────────────────────────────────────────
INSERT INTO `announcements` (`author_id`, `author_name`, `title`, `content`, `category`, `is_pinned`, `created_at`) VALUES
(1, 'System Administrator',
 'S.P.O.T.-IT System Now Active in CEAT Building',
 'The S.P.O.T.-IT IoT monitoring system is now fully operational inside selected laboratory rooms of the MLH Building. The system will automatically detect and flag missing laboratory equipment and unattended personal items. Please ensure you retrieve your belongings before leaving any laboratory room.',
 'system_update', 1, NOW()),

(1, 'System Administrator',
 'Claiming Schedule: Dispensing Window Hours',
 'The CEAT dispensing window for lost-and-found item claims is open Monday to Friday, 8:00 AM to 5:00 PM. Students must present their university ID and provide an accurate description of their item to complete a claim.',
 'claiming_schedule', 0, NOW()),

(1, 'System Administrator',
 'MLH 306 Laboratory Maintenance Notice',
 'MLH 306 (Systems & Application Development Lab) will undergo scheduled network maintenance on June 20, 2026 from 12:00 PM to 2:00 PM. The CCTV monitoring system may be temporarily offline during this period.',
 'maintenance', 0, DATE_SUB(NOW(), INTERVAL 2 DAY));

-- ── Seed: sample forum posts ──────────────────────────────────────────────────
INSERT INTO `forum_posts` (`user_id`, `author_name`, `author_role`, `title`, `content`, `category`, `upvotes`, `downvotes`, `comment_count`, `is_auto_generated`, `detection_id`, `detection_room`, `detection_item`, `created_at`) VALUES
(2, 'System Bot', 'admin',
 '⚠️ Detection Alert — Monitor possibly missing in MLH 306',
 'The S.P.O.T.-IT system has detected a possible item deviation in **MLH 306 (Systems & App Dev Lab)**.\n\n**Detected:** Monitor removed from Workstation 7 zone\n**Room:** MLH 306\n**Time:** June 15, 2026 at 14:03:44\n**Status:** Confirmed Missing (1hr+ elapsed)\n\nHas this item been returned or does anyone have information about its location? Lab staff have been notified.',
 'detection_thread', 14, 1, 5, 1, 47, 'MLH306', 'Monitor — Workstation 7',
 DATE_SUB(NOW(), INTERVAL 1 HOUR)),

(3, 'Maria Santos', 'student',
 'Found: Black umbrella near MLH 306 workstation area',
 'I found a black umbrella near Workstation 7 in MLH 306 after our 2PM class. It has a hook handle. I left it with the lab staff. Please claim it at the CEAT dispensing window!',
 'found_item', 21, 0, 8, 0, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 3 HOUR)),

(4, 'Juan dela Cruz', 'student',
 'Lost my Casio calculator in the MLH area — reward if found',
 'Hi everyone, I lost my Casio fx-991EX scientific calculator sometime last Thursday. It has a small scratch on the back cover. If anyone finds it please message me or surrender it to the lab staff. Big thanks!',
 'lost_and_found', 9, 1, 3, 0, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 1 DAY)),

(5, 'Ana Reyes', 'student',
 'Question: How long does the claiming process take?',
 'First time using the S.P.O.T.-IT claiming station. I submitted a claim online yesterday for a charging cable — how long does it usually take for staff to verify and when can I pick it up?',
 'question', 7, 0, 6, 0, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 2 DAY));

COMMIT;
