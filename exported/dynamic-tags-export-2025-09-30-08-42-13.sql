-- WP Dynamic Tags Export
-- Generated: 2025-09-30 08:42:13
-- Site: http://test.local
-- Total Tags: 2
-- Format: SQL INSERT statements
--
-- Usage Instructions:
-- 1. Import this file into your WordPress database
-- 2. Update post_author IDs if needed
-- 3. Dynamic tags will use the [tagkey key="tag_name"] shortcode format
--

-- Disable foreign key checks for faster import
SET FOREIGN_KEY_CHECKS = 0;

-- Tag: tage2
-- Primary Shortcode: [tagkey key="tage2"]
-- Alternative Shortcodes: [test_group_tage2]
INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
VALUES (
  1,
  '2025-09-29 11:41:05',
  '2025-09-29 11:41:05',
  'tag 2',
  'tage2',
  '',
  'publish',
  'closed',
  'closed',
  '',
  'tage2',
  '',
  '',
  '2025-09-29 11:41:05',
  '2025-09-29 11:41:05',
  '',
  0,
  '',
  0,
  'dynamic_tag',
  '',
  0
);
SET @last_post_id = LAST_INSERT_ID();

-- Post meta for tag: tage2
INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
  (@last_post_id, '_dt_category', 'general'),
  (@last_post_id, '_dt_priority', '0'),
  (@last_post_id, '_dt_usage_count', '247');

-- Tag: tagname
-- Primary Shortcode: [tagkey key="tagname"]
-- Alternative Shortcodes: [group_tagname]
INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
VALUES (
  1,
  '2025-09-29 01:04:12',
  '2025-09-29 01:04:12',
  'testing the tag',
  'tagname',
  '',
  'publish',
  'closed',
  'closed',
  '',
  'tagname',
  '',
  '',
  '2025-09-29 01:24:02',
  '2025-09-29 01:24:02',
  '',
  0,
  '',
  0,
  'dynamic_tag',
  '',
  0
);
SET @last_post_id = LAST_INSERT_ID();

-- Post meta for tag: tagname
INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
  (@last_post_id, '_dt_category', 'general'),
  (@last_post_id, '_dt_priority', '0'),
  (@last_post_id, '_dt_usage_count', '990');

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Export completed successfully
-- Total tags exported: 2
