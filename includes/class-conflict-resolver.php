<?php
/**
 * Conflict Resolver for WP Dynamic Tags
 *
 * Handles duplicate tags, shortcode conflicts, and provides resolution mechanisms
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Conflict_Resolver {

    private $post_type = 'dynamic_tag';
    private $taxonomy = 'tag_groups';

    public function __construct() {
        // Hook into post save to check for conflicts
        add_action('save_post', array($this, 'check_conflicts_on_save'), 20, 1);

        // Add admin notices for conflicts
        add_action('admin_notices', array($this, 'show_conflict_notices'));

        // AJAX handlers for conflict resolution
        add_action('wp_ajax_dt_resolve_conflict', array($this, 'ajax_resolve_conflict'));
        add_action('wp_ajax_dt_merge_tags', array($this, 'ajax_merge_tags'));
    }

    /**
     * Detect all current conflicts in the system
     */
    public function detect_all_conflicts() {
        $conflicts = array();

        // Get all published dynamic tags
        $all_tags = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ));

        // Group by tag title (case-insensitive)
        $grouped_tags = array();
        foreach ($all_tags as $tag) {
            $key = strtolower(trim($tag->post_title));
            if (!isset($grouped_tags[$key])) {
                $grouped_tags[$key] = array();
            }
            $grouped_tags[$key][] = $tag;
        }

        // Find duplicates
        foreach ($grouped_tags as $tag_key => $tags) {
            if (count($tags) > 1) {
                $conflicts[$tag_key] = $this->analyze_tag_conflict($tags);
            }
        }

        return $conflicts;
    }

    /**
     * Analyze a specific tag conflict
     */
    private function analyze_tag_conflict($tags) {
        $conflict_data = array(
            'tags' => array(),
            'shortcodes' => array(),
            'recommended_action' => '',
            'severity' => 'medium'
        );

        foreach ($tags as $tag) {
            $groups = wp_get_post_terms($tag->ID, $this->taxonomy, array('fields' => 'names'));
            $is_grouped = !empty($groups);

            $tag_info = array(
                'id' => $tag->ID,
                'title' => $tag->post_title,
                'content' => $tag->post_content,
                'groups' => $groups,
                'is_grouped' => $is_grouped,
                'shortcode' => $this->generate_shortcode_for_tag($tag, $groups),
                'modified' => $tag->post_modified,
                'status' => 'conflict'
            );

            $conflict_data['tags'][] = $tag_info;
            $conflict_data['shortcodes'][] = $tag_info['shortcode'];
        }

        // Determine recommended action
        $conflict_data['recommended_action'] = $this->determine_recommended_action($conflict_data['tags']);
        $conflict_data['severity'] = $this->determine_conflict_severity($conflict_data['tags']);

        return $conflict_data;
    }

    /**
     * Generate shortcode for a specific tag
     */
    private function generate_shortcode_for_tag($tag, $groups = null) {
        if ($groups === null) {
            $groups = wp_get_post_terms($tag->ID, $this->taxonomy, array('fields' => 'names'));
        }

        $sanitized_title = $this->sanitize_tag_key($tag->post_title);

        if (!empty($groups)) {
            // Grouped shortcode
            $group_name = $this->sanitize_tag_key($groups[0]);
            return $group_name . '_' . $sanitized_title;
        } else {
            // Ungrouped shortcode
            return $sanitized_title;
        }
    }

    /**
     * Sanitize tag key for shortcode use
     */
    private function sanitize_tag_key($title) {
        $sanitized = strtolower(trim($title));
        $sanitized = preg_replace('/[^a-z0-9_]/', '_', $sanitized);
        $sanitized = preg_replace('/_+/', '_', $sanitized);
        $sanitized = trim($sanitized, '_');
        return $sanitized;
    }

    /**
     * Determine recommended action for conflict resolution
     */
    private function determine_recommended_action($tags) {
        $grouped_count = 0;
        $ungrouped_count = 0;
        $different_content = false;
        $base_content = null;

        foreach ($tags as $tag) {
            if ($tag['is_grouped']) {
                $grouped_count++;
            } else {
                $ungrouped_count++;
            }

            if ($base_content === null) {
                $base_content = $tag['content'];
            } elseif ($base_content !== $tag['content']) {
                $different_content = true;
            }
        }

        if ($grouped_count > 0 && $ungrouped_count > 0) {
            if ($different_content) {
                return 'merge_with_content_choice';
            } else {
                return 'delete_ungrouped';
            }
        } elseif ($grouped_count > 1) {
            return 'consolidate_groups';
        } elseif ($ungrouped_count > 1) {
            return 'assign_to_group';
        }

        return 'manual_review';
    }

    /**
     * Determine conflict severity
     */
    private function determine_conflict_severity($tags) {
        $has_different_content = false;
        $has_mixed_groups = false;
        $base_content = null;

        foreach ($tags as $tag) {
            if ($base_content === null) {
                $base_content = $tag['content'];
            } elseif ($base_content !== $tag['content']) {
                $has_different_content = true;
            }

            if (!empty($tag['groups'])) {
                $has_mixed_groups = true;
            }
        }

        if ($has_different_content) {
            return 'high';
        } elseif ($has_mixed_groups) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Auto-resolve simple conflicts
     */
    public function auto_resolve_simple_conflicts() {
        $conflicts = $this->detect_all_conflicts();
        $resolved = array();

        foreach ($conflicts as $tag_key => $conflict) {
            if ($conflict['severity'] === 'low' &&
                $conflict['recommended_action'] === 'delete_ungrouped') {

                $result = $this->resolve_conflict_delete_ungrouped($conflict['tags']);
                if ($result['success']) {
                    $resolved[$tag_key] = $result;
                }
            }
        }

        return $resolved;
    }

    /**
     * Resolve conflict by deleting ungrouped duplicates
     */
    private function resolve_conflict_delete_ungrouped($tags) {
        $deleted_ids = array();
        $kept_tags = array();

        foreach ($tags as $tag) {
            if (!$tag['is_grouped']) {
                // Delete ungrouped tag
                $result = wp_delete_post($tag['id'], true);
                if ($result) {
                    $deleted_ids[] = $tag['id'];
                }
            } else {
                $kept_tags[] = $tag;
            }
        }

        return array(
            'success' => !empty($deleted_ids),
            'deleted_ids' => $deleted_ids,
            'kept_tags' => $kept_tags,
            'message' => sprintf('Deleted %d ungrouped duplicate(s)', count($deleted_ids))
        );
    }

    /**
     * Merge multiple tags into one
     */
    public function merge_tags($tag_ids, $primary_tag_id, $merge_options = array()) {
        $primary_tag = get_post($primary_tag_id);
        if (!$primary_tag) {
            return array('success' => false, 'message' => 'Primary tag not found');
        }

        $merged_data = array(
            'content_parts' => array(),
            'groups' => array(),
            'deleted_ids' => array()
        );

        foreach ($tag_ids as $tag_id) {
            if ($tag_id == $primary_tag_id) continue;

            $tag = get_post($tag_id);
            if (!$tag) continue;

            // Collect content if different
            if ($tag->post_content !== $primary_tag->post_content) {
                $merged_data['content_parts'][] = $tag->post_content;
            }

            // Collect groups
            $tag_groups = wp_get_post_terms($tag_id, $this->taxonomy, array('fields' => 'names'));
            $merged_data['groups'] = array_merge($merged_data['groups'], $tag_groups);

            // Delete the tag
            $result = wp_delete_post($tag_id, true);
            if ($result) {
                $merged_data['deleted_ids'][] = $tag_id;
            }
        }

        // Update primary tag if needed
        $primary_groups = wp_get_post_terms($primary_tag_id, $this->taxonomy, array('fields' => 'names'));
        $all_groups = array_unique(array_merge($primary_groups, $merged_data['groups']));

        if (count($all_groups) > count($primary_groups)) {
            $group_terms = array();
            foreach ($all_groups as $group_name) {
                $term = get_term_by('name', $group_name, $this->taxonomy);
                if ($term) {
                    $group_terms[] = (int) $term->term_id;
                }
            }
            wp_set_post_terms($primary_tag_id, $group_terms, $this->taxonomy);
        }

        return array(
            'success' => true,
            'primary_tag_id' => $primary_tag_id,
            'deleted_count' => count($merged_data['deleted_ids']),
            'merged_groups' => $all_groups,
            'message' => sprintf('Merged %d tags into primary tag', count($merged_data['deleted_ids']))
        );
    }

    /**
     * Check for conflicts when a post is saved
     */
    public function check_conflicts_on_save($post_id) {
        if (get_post_type($post_id) !== $this->post_type) {
            return;
        }

        $post = get_post($post_id);
        $conflicts = $this->find_conflicts_for_tag($post);

        if (!empty($conflicts)) {
            // Store conflict data as transient for admin notice
            set_transient('dt_conflict_notice_' . get_current_user_id(), array(
                'post_id' => $post_id,
                'conflicts' => $conflicts
            ), 300); // 5 minutes
        }
    }

    /**
     * Find conflicts for a specific tag
     */
    private function find_conflicts_for_tag($tag) {
        $conflicts = array();
        $tag_key = strtolower(trim($tag->post_title));

        // Find other tags with same title
        $duplicate_tags = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'post__not_in' => array($tag->ID),
            'meta_query' => array(
                array(
                    'key' => '_dt_sanitized_title',
                    'value' => $tag_key,
                    'compare' => '='
                )
            )
        ));

        // Also search by title directly (fallback)
        if (empty($duplicate_tags)) {
            $duplicate_tags = get_posts(array(
                'post_type' => $this->post_type,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'post__not_in' => array($tag->ID),
                'title' => $tag->post_title
            ));
        }

        if (!empty($duplicate_tags)) {
            $all_tags = array_merge(array($tag), $duplicate_tags);
            $conflicts = $this->analyze_tag_conflict($all_tags);
        }

        return $conflicts;
    }

    /**
     * Show admin notices for conflicts
     */
    public function show_conflict_notices() {
        $user_id = get_current_user_id();
        $conflict_data = get_transient('dt_conflict_notice_' . $user_id);

        if ($conflict_data && is_array($conflict_data)) {
            $post_title = get_the_title($conflict_data['post_id']);
            ?>
            <div class="notice notice-warning is-dismissible">
                <h4><?php _e('Dynamic Tag Conflict Detected', 'wp-dynamic-tags'); ?></h4>
                <p>
                    <?php printf(__('The tag "%s" conflicts with existing tags. This may cause shortcode issues.', 'wp-dynamic-tags'), esc_html($post_title)); ?>
                </p>
                <p>
                    <a href="<?php echo admin_url('edit.php?post_type=' . $this->post_type . '&page=dt-conflict-resolver'); ?>" class="button">
                        <?php _e('Resolve Conflicts', 'wp-dynamic-tags'); ?>
                    </a>
                </p>
            </div>
            <?php
            // Clear the transient
            delete_transient('dt_conflict_notice_' . $user_id);
        }
    }

    /**
     * AJAX handler for conflict resolution
     */
    public function ajax_resolve_conflict() {
        check_ajax_referer('dt_resolve_conflict', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $action = sanitize_text_field($_POST['resolution_action']);
        $tag_ids = array_map('intval', $_POST['tag_ids']);

        $result = array('success' => false, 'message' => 'Unknown action');

        switch ($action) {
            case 'delete_ungrouped':
                $result = $this->handle_delete_ungrouped($tag_ids);
                break;
            case 'merge_tags':
                $primary_id = intval($_POST['primary_tag_id']);
                $result = $this->merge_tags($tag_ids, $primary_id);
                break;
            case 'assign_to_group':
                $group_id = intval($_POST['group_id']);
                $result = $this->handle_assign_to_group($tag_ids, $group_id);
                break;
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Handle delete ungrouped action
     */
    private function handle_delete_ungrouped($tag_ids) {
        $deleted = 0;

        foreach ($tag_ids as $tag_id) {
            $groups = wp_get_post_terms($tag_id, $this->taxonomy);
            if (empty($groups)) {
                $result = wp_delete_post($tag_id, true);
                if ($result) {
                    $deleted++;
                }
            }
        }

        return array(
            'success' => $deleted > 0,
            'message' => sprintf('Deleted %d ungrouped tag(s)', $deleted),
            'deleted_count' => $deleted
        );
    }

    /**
     * Handle assign to group action
     */
    private function handle_assign_to_group($tag_ids, $group_id) {
        $assigned = 0;

        foreach ($tag_ids as $tag_id) {
            $result = wp_set_post_terms($tag_id, array($group_id), $this->taxonomy);
            if (!is_wp_error($result)) {
                $assigned++;
            }
        }

        return array(
            'success' => $assigned > 0,
            'message' => sprintf('Assigned %d tag(s) to group', $assigned),
            'assigned_count' => $assigned
        );
    }

    /**
     * Get conflict statistics
     */
    public function get_conflict_statistics() {
        $conflicts = $this->detect_all_conflicts();

        $stats = array(
            'total_conflicts' => count($conflicts),
            'high_severity' => 0,
            'medium_severity' => 0,
            'low_severity' => 0,
            'auto_resolvable' => 0
        );

        foreach ($conflicts as $conflict) {
            $stats[$conflict['severity'] . '_severity']++;

            if (in_array($conflict['recommended_action'], array('delete_ungrouped', 'assign_to_group'))) {
                $stats['auto_resolvable']++;
            }
        }

        return $stats;
    }

    /**
     * Smart group assignment based on tag patterns and content analysis
     */
    public function suggest_smart_group_assignment($tag_id) {
        $tag = get_post($tag_id);
        if (!$tag) {
            return false;
        }

        $suggestions = array();

        // Analyze tag title for patterns
        $title_suggestions = $this->analyze_title_patterns($tag->post_title);
        if ($title_suggestions) {
            $suggestions = array_merge($suggestions, $title_suggestions);
        }

        // Analyze content for keywords
        $content_suggestions = $this->analyze_content_keywords($tag->post_content);
        if ($content_suggestions) {
            $suggestions = array_merge($suggestions, $content_suggestions);
        }

        // Find similar tags that are already grouped
        $similar_suggestions = $this->find_similar_grouped_tags($tag);
        if ($similar_suggestions) {
            $suggestions = array_merge($suggestions, $similar_suggestions);
        }

        // Score and rank suggestions
        return $this->rank_group_suggestions($suggestions);
    }

    /**
     * Analyze title patterns for group suggestions
     */
    private function analyze_title_patterns($title) {
        $suggestions = array();
        $title_lower = strtolower($title);

        // Common patterns
        $patterns = array(
            // Date patterns
            '/date\d*/' => array('group' => 'dates', 'confidence' => 0.8),
            '/time\d*/' => array('group' => 'dates', 'confidence' => 0.7),

            // Contact patterns
            '/contact|email|phone|address/' => array('group' => 'contact', 'confidence' => 0.9),

            // SEO patterns
            '/meta|seo|title|description/' => array('group' => 'seo', 'confidence' => 0.8),

            // Event patterns
            '/event|olympiad|competition|contest/' => array('group' => 'events', 'confidence' => 0.7),

            // Company/branding patterns
            '/company|brand|logo|name/' => array('group' => 'branding', 'confidence' => 0.6),
        );

        foreach ($patterns as $pattern => $data) {
            if (preg_match($pattern, $title_lower)) {
                $suggestions[] = array(
                    'group_name' => $data['group'],
                    'confidence' => $data['confidence'],
                    'reason' => 'Title pattern match: ' . $pattern
                );
            }
        }

        return $suggestions;
    }

    /**
     * Analyze content for keyword-based group suggestions
     */
    private function analyze_content_keywords($content) {
        $suggestions = array();
        $content_lower = strtolower($content);

        // Keyword groups
        $keyword_groups = array(
            'dates' => array('january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', '2024', '2025', '2026'),
            'contact' => array('@', '.com', 'email', 'phone', 'address', 'contact'),
            'events' => array('olympiad', 'competition', 'contest', 'tournament', 'championship'),
            'locations' => array('street', 'avenue', 'road', 'city', 'state', 'country', 'zip'),
            'branding' => array('inc', 'ltd', 'llc', 'corp', 'company', 'brand')
        );

        foreach ($keyword_groups as $group => $keywords) {
            $matches = 0;
            foreach ($keywords as $keyword) {
                if (strpos($content_lower, $keyword) !== false) {
                    $matches++;
                }
            }

            if ($matches > 0) {
                $confidence = min(0.9, ($matches / count($keywords)) + 0.3);
                $suggestions[] = array(
                    'group_name' => $group,
                    'confidence' => $confidence,
                    'reason' => "Content keywords match ({$matches} keywords found)"
                );
            }
        }

        return $suggestions;
    }

    /**
     * Find similar tags that are already grouped
     */
    private function find_similar_grouped_tags($tag) {
        $suggestions = array();

        // Find tags with similar titles
        $similar_tags = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'post__not_in' => array($tag->ID),
            's' => $tag->post_title,
            'tax_query' => array(
                array(
                    'taxonomy' => $this->taxonomy,
                    'operator' => 'EXISTS'
                )
            )
        ));

        foreach ($similar_tags as $similar_tag) {
            $groups = wp_get_post_terms($similar_tag->ID, $this->taxonomy, array('fields' => 'names'));

            if (!empty($groups)) {
                $similarity = $this->calculate_title_similarity($tag->post_title, $similar_tag->post_title);

                foreach ($groups as $group_name) {
                    $suggestions[] = array(
                        'group_name' => $group_name,
                        'confidence' => $similarity * 0.8,
                        'reason' => "Similar to '{$similar_tag->post_title}' in group '{$group_name}'"
                    );
                }
            }
        }

        return $suggestions;
    }

    /**
     * Calculate similarity between two titles
     */
    private function calculate_title_similarity($title1, $title2) {
        $title1_words = array_filter(explode(' ', strtolower($title1)));
        $title2_words = array_filter(explode(' ', strtolower($title2)));

        $common_words = array_intersect($title1_words, $title2_words);
        $total_words = array_unique(array_merge($title1_words, $title2_words));

        return count($common_words) / count($total_words);
    }

    /**
     * Rank group suggestions by confidence
     */
    private function rank_group_suggestions($suggestions) {
        if (empty($suggestions)) {
            return array();
        }

        // Group by group name and combine confidence scores
        $grouped_suggestions = array();
        foreach ($suggestions as $suggestion) {
            $group_name = $suggestion['group_name'];

            if (!isset($grouped_suggestions[$group_name])) {
                $grouped_suggestions[$group_name] = array(
                    'group_name' => $group_name,
                    'confidence' => 0,
                    'reasons' => array()
                );
            }

            // Combine confidence scores (weighted average)
            $existing_confidence = $grouped_suggestions[$group_name]['confidence'];
            $new_confidence = $suggestion['confidence'];
            $grouped_suggestions[$group_name]['confidence'] = ($existing_confidence + $new_confidence) / 2;

            $grouped_suggestions[$group_name]['reasons'][] = $suggestion['reason'];
        }

        // Sort by confidence
        usort($grouped_suggestions, function($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });

        return array_slice($grouped_suggestions, 0, 3); // Return top 3 suggestions
    }

    /**
     * Auto-assign ungrouped tags to suggested groups
     */
    public function auto_assign_ungrouped_tags($confidence_threshold = 0.7) {
        $ungrouped_tags = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => $this->taxonomy,
                    'operator' => 'NOT EXISTS'
                )
            )
        ));

        $assigned = array();

        foreach ($ungrouped_tags as $tag) {
            $suggestions = $this->suggest_smart_group_assignment($tag->ID);

            if (!empty($suggestions) && $suggestions[0]['confidence'] >= $confidence_threshold) {
                $group_name = $suggestions[0]['group_name'];

                // Get or create the group
                $group_term = get_term_by('name', $group_name, $this->taxonomy);
                if (!$group_term) {
                    $group_result = wp_insert_term($group_name, $this->taxonomy);
                    if (!is_wp_error($group_result)) {
                        $group_term = get_term($group_result['term_id'], $this->taxonomy);
                    }
                }

                if ($group_term) {
                    $result = wp_set_post_terms($tag->ID, array($group_term->term_id), $this->taxonomy);
                    if (!is_wp_error($result)) {
                        $assigned[] = array(
                            'tag_id' => $tag->ID,
                            'tag_title' => $tag->post_title,
                            'group_name' => $group_name,
                            'confidence' => $suggestions[0]['confidence'],
                            'reason' => $suggestions[0]['reasons'][0]
                        );
                    }
                }
            }
        }

        return $assigned;
    }

    /**
     * Create default groups based on common patterns
     */
    public function create_default_groups() {
        $default_groups = array(
            'contact' => array(
                'name' => 'Contact Information',
                'description' => 'Email addresses, phone numbers, addresses',
                'color' => '#28a745'
            ),
            'dates' => array(
                'name' => 'Dates & Times',
                'description' => 'Event dates, deadlines, schedules',
                'color' => '#007cba'
            ),
            'events' => array(
                'name' => 'Events',
                'description' => 'Competitions, tournaments, olympiads',
                'color' => '#ffc107'
            ),
            'seo' => array(
                'name' => 'SEO & Meta',
                'description' => 'Meta titles, descriptions, keywords',
                'color' => '#6f42c1'
            ),
            'branding' => array(
                'name' => 'Branding',
                'description' => 'Company information, logos, names',
                'color' => '#fd7e14'
            ),
            'general' => array(
                'name' => 'General',
                'description' => 'Miscellaneous tags',
                'color' => '#6c757d'
            )
        );

        $created = array();

        foreach ($default_groups as $slug => $data) {
            // Check if group already exists
            $existing = get_term_by('name', $data['name'], $this->taxonomy);
            if (!$existing) {
                $result = wp_insert_term($data['name'], $this->taxonomy, array(
                    'description' => $data['description']
                ));

                if (!is_wp_error($result)) {
                    // Set group color
                    update_term_meta($result['term_id'], 'color', $data['color']);
                    $created[] = $data['name'];
                }
            }
        }

        return $created;
    }
}