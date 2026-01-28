<?php
/**
 * Store MM Designer Dashboard Shortcode
 * Shows designers their submissions and status
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

add_shortcode('store_mm_designer_dashboard', 'store_mm_render_designer_dashboard');

function store_mm_render_designer_dashboard($atts) {
    if (!is_user_logged_in()) {
        return '<p class="store-mm-error">' . esc_html__('Please log in to view your dashboard.', 'store-mm') . '</p>';
    }
    
    if (!store_mm_user_can_submit()) {
        return '<p class="store-mm-error">' . esc_html__('You do not have permission to view this dashboard.', 'store-mm') . '</p>';
    }
    
    $user = wp_get_current_user();
    $user_id = $user->ID;
    
    $args = [
        'post_type' => 'product',
        'post_status' => ['draft', 'publish', 'pending'],
        'posts_per_page' => -1,
        'author' => $user_id,
        'meta_query' => [
            [
                'key' => '_store_mm_workflow_state',
                'compare' => 'EXISTS',
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ];
    
    $query = new WP_Query($args);
    
    ob_start();
    ?>
    <div class="store-mm-designer-dashboard">
        <div class="store-mm-dashboard-header">
            <h1><?php _e('My Design Submissions', 'store-mm'); ?></h1>
            <a href="<?php echo home_url('/submit-design/'); ?>" class="store-mm-button store-mm-button-primary">
                <?php _e('+ Submit New Design', 'store-mm'); ?>
            </a>
        </div>
        
        <?php if (!$query->have_posts()): ?>
            <div class="store-mm-empty-state">
                <h3><?php _e('No submissions yet', 'store-mm'); ?></h3>
                <p><?php _e('You haven\'t submitted any designs yet. Get started by submitting your first design!', 'store-mm'); ?></p>
                <a href="<?php echo home_url('/submit-design/'); ?>" class="store-mm-button store-mm-button-primary">
                    <?php _e('Submit Your First Design', 'store-mm'); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="store-mm-submissions-grid">
                <?php while ($query->have_posts()): $query->the_post(); 
                    $product_id = get_the_ID();
                    $state = get_post_meta($product_id, '_store_mm_workflow_state', true);
                    if (!$state) $state = STORE_MM_STATE_DRAFT;
                    
                    $state_labels = [
                        STORE_MM_STATE_DRAFT => __('Draft', 'store-mm'),
                        STORE_MM_STATE_SUBMITTED => __('Under Review', 'store-mm'),
                        STORE_MM_STATE_CHANGES_REQUESTED => __('Changes Requested', 'store-mm'),
                        STORE_MM_STATE_PROTOTYPING => __('In Prototyping', 'store-mm'),
                        STORE_MM_STATE_APPROVED => __('Published', 'store-mm'),
                        STORE_MM_STATE_REJECTED => __('Rejected', 'store-mm')
                    ];
                    
                    $state_classes = [
                        STORE_MM_STATE_DRAFT => 'draft',
                        STORE_MM_STATE_SUBMITTED => 'submitted',
                        STORE_MM_STATE_CHANGES_REQUESTED => 'changes-requested',
                        STORE_MM_STATE_PROTOTYPING => 'prototyping',
                        STORE_MM_STATE_APPROVED => 'approved',
                        STORE_MM_STATE_REJECTED => 'rejected'
                    ];
                    
                    $can_edit = store_mm_user_can_edit_product($user_id, $product_id);
                    ?>
                    
                    <div class="store-mm-submission-card">
                        <div class="store-mm-submission-header">
                            <h3><?php the_title(); ?></h3>
                            <span class="store-mm-state-badge state-<?php echo $state_classes[$state]; ?>">
                                <?php echo $state_labels[$state]; ?>
                            </span>
                        </div>
                        
                        <div class="store-mm-submission-meta">
                            <div class="store-mm-meta-item">
                                <strong><?php _e('Submitted:', 'store-mm'); ?></strong>
                                <?php echo get_the_date(); ?>
                            </div>
                            <?php 
                            $price = get_post_meta($product_id, '_store_mm_estimated_price', true);
                            if ($price): ?>
                                <div class="store-mm-meta-item">
                                    <strong><?php _e('Price:', 'store-mm'); ?></strong>
                                    $<?php echo number_format($price, 2); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php 
                            $royalty = get_post_meta($product_id, '_store_mm_royalty_percentage', true);
                            if ($royalty): ?>
                                <div class="store-mm-meta-item">
                                    <strong><?php _e('Royalty:', 'store-mm'); ?></strong>
                                    <?php echo $royalty; ?>%
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="store-mm-submission-actions">
                            <?php if ($can_edit): ?>
                                <a href="<?php echo get_edit_post_link($product_id); ?>" class="store-mm-button store-mm-button-secondary">
                                    <?php _e('Edit Design', 'store-mm'); ?>
                                </a>
                            <?php else: ?>
                                <button class="store-mm-button store-mm-button-secondary" disabled>
                                    <?php _e('Edit Locked', 'store-mm'); ?>
                                </button>
                            <?php endif; ?>
                            
                            <a href="<?php the_permalink(); ?>" class="store-mm-button store-mm-button-secondary" target="_blank">
                                <?php _e('View', 'store-mm'); ?>
                            </a>
                        </div>
                        
                        <?php if ($state === STORE_MM_STATE_CHANGES_REQUESTED): 
                            $logs = get_post_meta($product_id, '_store_mm_submission_log', true);
                            $latest_note = '';
                            if (is_array($logs)) {
                                $reversed_logs = array_reverse($logs);
                                foreach ($reversed_logs as $log) {
                                    if ($log['to_state'] === STORE_MM_STATE_CHANGES_REQUESTED && !empty($log['notes'])) {
                                        $latest_note = $log['notes'];
                                        break;
                                    }
                                }
                            }
                            
                            if ($latest_note): ?>
                                <div class="store-mm-changes-requested">
                                    <h4><?php _e('Requested Changes:', 'store-mm'); ?></h4>
                                    <p><?php echo esc_html($latest_note); ?></p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endwhile; wp_reset_postdata(); ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    
    return ob_get_clean();
}