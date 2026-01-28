<?php
/**
 * Store MM Workflow Enforcement
 * Handles product locking and state-based restrictions
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

function store_mm_is_product_locked($product_id) {
    $state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    
    $locked_states = [
        STORE_MM_STATE_PROTOTYPING,
        STORE_MM_STATE_APPROVED,
        STORE_MM_STATE_REJECTED
    ];
    
    return in_array($state, $locked_states);
}

function store_mm_user_can_edit_product($user_id, $product_id) {
    $user = get_user_by('id', $user_id);
    
    if (store_mm_user_can_moderate($user)) {
        return true;
    }
    
    $designer_id = get_post_meta($product_id, '_store_mm_designer_id', true);
    
    if ($designer_id != $user_id) {
        return false;
    }
    
    if (store_mm_is_product_locked($product_id)) {
        return false;
    }
    
    $allowed_states = [
        STORE_MM_STATE_DRAFT,
        STORE_MM_STATE_SUBMITTED,
        STORE_MM_STATE_CHANGES_REQUESTED
    ];
    
    $state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    return in_array($state, $allowed_states);
}

add_action('add_meta_boxes', 'store_mm_add_internal_notes_metabox');
function store_mm_add_internal_notes_metabox() {
    if (!store_mm_user_can_moderate()) {
        return;
    }
    
    add_meta_box(
        'store-mm-internal-notes',
        __('Store MM Internal Notes', 'store-mm'),
        'store_mm_render_internal_notes_metabox',
        'product',
        'normal',
        'high'
    );
}

function store_mm_render_internal_notes_metabox($post) {
    $internal_notes = get_post_meta($post->ID, '_store_mm_internal_notes', true);
    if (!is_array($internal_notes)) {
        $internal_notes = [];
    }
    
    $submission_logs = get_post_meta($post->ID, '_store_mm_submission_log', true);
    if (!is_array($submission_logs)) {
        $submission_logs = [];
    }
    
    wp_nonce_field('store_mm_save_internal_notes', 'store_mm_internal_notes_nonce');
    ?>
    <div class="store-mm-internal-notes-wrapper">
        <div class="store-mm-add-note-section" style="margin-bottom: 30px;">
            <h3><?php _e('Add New Internal Note', 'store-mm'); ?></h3>
            <textarea id="store-mm-new-note" rows="4" style="width: 100%; margin-bottom: 10px;"></textarea>
            <button type="button" class="button button-primary" id="store-mm-add-note-btn">
                <?php _e('Add Note', 'store-mm'); ?>
            </button>
        </div>
        
        <div class="store-mm-notes-list">
            <h3><?php _e('Internal Notes', 'store-mm'); ?></h3>
            <?php if (empty($internal_notes)): ?>
                <p><?php _e('No internal notes yet.', 'store-mm'); ?></p>
            <?php else: ?>
                <div class="store-mm-notes-container">
                    <?php foreach (array_reverse($internal_notes) as $note): ?>
                        <div class="store-mm-note-item" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; background: #f9f9f9;">
                            <div class="store-mm-note-header" style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <strong><?php 
                                    $user = get_user_by('id', $note['user_id']);
                                    echo $user ? esc_html($user->display_name) : 'Unknown';
                                ?></strong>
                                <span style="color: #666; font-size: 12px;">
                                    <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($note['date'])); ?>
                                </span>
                            </div>
                            <div class="store-mm-note-content" style="white-space: pre-wrap;"><?php echo esc_html($note['content']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="store-mm-submission-logs" style="margin-top: 30px;">
            <h3><?php _e('Submission History', 'store-mm'); ?></h3>
            <?php if (empty($submission_logs)): ?>
                <p><?php _e('No submission history.', 'store-mm'); ?></p>
            <?php else: ?>
                <table class="widefat fixed" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th><?php _e('Date', 'store-mm'); ?></th>
                            <th><?php _e('User', 'store-mm'); ?></th>
                            <th><?php _e('Action', 'store-mm'); ?></th>
                            <th><?php _e('Notes', 'store-mm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($submission_logs) as $log): ?>
                            <tr>
                                <td style="font-size: 12px;">
                                    <?php echo date_i18n('M j, Y g:i a', strtotime($log['date'])); ?>
                                </td>
                                <td><?php echo esc_html($log['user_name']); ?></td>
                                <td>
                                    <?php 
                                    switch ($log['action']) {
                                        case 'design_submitted':
                                            echo __('Design Submitted', 'store-mm');
                                            break;
                                        case 'state_changed':
                                            echo sprintf(
                                                __('State changed: %s → %s', 'store-mm'),
                                                str_replace('_', ' ', $log['from_state']),
                                                str_replace('_', ' ', $log['to_state'])
                                            );
                                            break;
                                        default:
                                            echo esc_html($log['action']);
                                    }
                                    ?>
                                </td>
                                <td style="font-size: 12px;">
                                    <?php 
                                    if (!empty($log['notes'])) {
                                        echo esc_html($log['notes']);
                                    }
                                    if (!empty($log['reason'])) {
                                        echo '<br><em>' . esc_html($log['reason']) . '</em>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        $('#store-mm-add-note-btn').on('click', function() {
            var note = $('#store-mm-new-note').val().trim();
            if (!note) {
                alert('Please enter a note.');
                return;
            }
            
            var data = {
                action: 'store_mm_add_internal_note',
                nonce: '<?php echo wp_create_nonce("store_mm_admin_nonce"); ?>',
                product_id: <?php echo $post->ID; ?>,
                note: note
            };
            
            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            });
        });
    });
    </script>
    <?php
}

add_action('wp_ajax_store_mm_add_internal_note', 'store_mm_ajax_add_internal_note');
function store_mm_ajax_add_internal_note() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_admin_nonce')) {
        store_mm_send_json_response('Security check failed.', false);
    }
    
    if (!store_mm_user_can_moderate()) {
        store_mm_send_json_response('Insufficient permissions.', false);
    }
    
    $product_id = intval($_POST['product_id']);
    $note = sanitize_textarea_field($_POST['note']);
    $user = wp_get_current_user();
    
    if (!$product_id || empty($note)) {
        store_mm_send_json_response('Missing parameters.', false);
    }
    
    $existing_notes = get_post_meta($product_id, '_store_mm_internal_notes', true);
    if (!is_array($existing_notes)) {
        $existing_notes = [];
    }
    
    $new_note = [
        'date' => current_time('mysql'),
        'user_id' => $user->ID,
        'user_name' => $user->display_name,
        'content' => $note
    ];
    
    $existing_notes[] = $new_note;
    update_post_meta($product_id, '_store_mm_internal_notes', $existing_notes);
    
    store_mm_send_json_response('Note added successfully.');
}

add_action('load-post.php', 'store_mm_restrict_product_editing');
function store_mm_restrict_product_editing() {
    global $pagenow, $post;
    
    if ($pagenow !== 'post.php' || !isset($_GET['post']) || get_post_type($_GET['post']) !== 'product') {
        return;
    }
    
    $post_id = intval($_GET['post']);
    $user_id = get_current_user_id();
    
    if (!store_mm_user_can_edit_product($user_id, $post_id)) {
        $state = get_post_meta($post_id, '_store_mm_workflow_state', true);
        
        switch ($state) {
            case STORE_MM_STATE_PROTOTYPING:
                $message = __('This design is currently in the prototyping phase and cannot be edited.', 'store-mm');
                break;
            case STORE_MM_STATE_APPROVED:
                $message = __('This design has been approved and published. Please contact an administrator for changes.', 'store-mm');
                break;
            case STORE_MM_STATE_REJECTED:
                $message = __('This design has been rejected and cannot be edited.', 'store-mm');
                break;
            default:
                $message = __('You do not have permission to edit this design.', 'store-mm');
        }
        
        wp_die($message, __('Access Denied', 'store-mm'), ['response' => 403]);
    }
}

add_action('add_meta_boxes', 'store_mm_add_workflow_state_metabox');
function store_mm_add_workflow_state_metabox() {
    add_meta_box(
        'store-mm-workflow-state',
        __('Store MM Workflow State', 'store-mm'),
        'store_mm_render_workflow_state_metabox',
        'product',
        'side',
        'high'
    );
}

function store_mm_render_workflow_state_metabox($post) {
    $state = get_post_meta($post->ID, '_store_mm_workflow_state', true);
    if (!$state) {
        $state = STORE_MM_STATE_DRAFT;
    }
    
    $states = [
        STORE_MM_STATE_DRAFT => __('Draft', 'store-mm'),
        STORE_MM_STATE_SUBMITTED => __('Submitted', 'store-mm'),
        STORE_MM_STATE_CHANGES_REQUESTED => __('Changes Requested', 'store-mm'),
        STORE_MM_STATE_PROTOTYPING => __('Prototyping', 'store-mm'),
        STORE_MM_STATE_APPROVED => __('Approved', 'store-mm'),
        STORE_MM_STATE_REJECTED => __('Rejected', 'store-mm')
    ];
    
    $state_labels = [
        STORE_MM_STATE_DRAFT => 'info',
        STORE_MM_STATE_SUBMITTED => 'warning',
        STORE_MM_STATE_CHANGES_REQUESTED => 'warning',
        STORE_MM_STATE_PROTOTYPING => 'info',
        STORE_MM_STATE_APPROVED => 'success',
        STORE_MM_STATE_REJECTED => 'error'
    ];
    
    echo '<div class="misc-pub-section">';
    echo '<strong>' . __('Current State:', 'store-mm') . '</strong> ';
    echo '<span class="state-label state-' . esc_attr($state_labels[$state]) . '">';
    echo esc_html($states[$state]);
    echo '</span>';
    echo '</div>';
    
    $designer_id = get_post_meta($post->ID, '_store_mm_designer_id', true);
    if ($designer_id) {
        $designer = get_user_by('id', $designer_id);
        if ($designer) {
            echo '<div class="misc-pub-section">';
            echo '<strong>' . __('Designer:', 'store-mm') . '</strong> ';
            echo esc_html($designer->display_name);
            echo '</div>';
        }
    }
    
    $submission_date = get_post_meta($post->ID, '_store_mm_submission_date', true);
    if ($submission_date) {
        echo '<div class="misc-pub-section">';
        echo '<strong>' . __('Submitted:', 'store-mm') . '</strong> ';
        echo date_i18n(get_option('date_format'), strtotime($submission_date));
        echo '</div>';
    }
}

add_action('admin_enqueue_scripts', 'store_mm_enqueue_workflow_styles');
function store_mm_enqueue_workflow_styles($hook) {
    if ($hook !== 'post.php' && $hook !== 'post-new.php') {
        return;
    }
    
    if (get_post_type() !== 'product') {
        return;
    }
    
    wp_add_inline_style('wp-admin', '
        .state-label {
            padding: 4px 8px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }
        .state-info {
            background: #f0f9ff;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        .state-warning {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }
        .state-success {
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .state-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
    ');
}

add_action('wp_ajax_store_mm_check_product_lock', 'store_mm_ajax_check_product_lock');
add_action('wp_ajax_nopriv_store_mm_check_product_lock', 'store_mm_ajax_check_product_lock');
function store_mm_ajax_check_product_lock() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_ajax_nonce')) {
        store_mm_send_json_response('Security check failed.', false);
    }
    
    $product_id = intval($_POST['product_id']);
    
    if (!$product_id) {
        store_mm_send_json_response('Invalid product ID.', false);
    }
    
    $state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    if (!$state) {
        $state = STORE_MM_STATE_DRAFT;
    }
    
    $locked = store_mm_is_product_locked($product_id);
    $state_labels = [
        STORE_MM_STATE_DRAFT => __('Draft', 'store-mm'),
        STORE_MM_STATE_SUBMITTED => __('Submitted for Review', 'store-mm'),
        STORE_MM_STATE_CHANGES_REQUESTED => __('Changes Requested', 'store-mm'),
        STORE_MM_STATE_PROTOTYPING => __('In Prototyping', 'store-mm'),
        STORE_MM_STATE_APPROVED => __('Approved & Published', 'store-mm'),
        STORE_MM_STATE_REJECTED => __('Rejected', 'store-mm')
    ];
    
    $response = [
        'locked' => $locked,
        'state' => $state,
        'state_label' => $state_labels[$state],
        'message' => '',
        'actions' => []
    ];
    
    switch ($state) {
        case STORE_MM_STATE_PROTOTYPING:
            $response['message'] = __('This design is currently being prototyped. It will be available for purchase once approved.', 'store-mm');
            break;
        case STORE_MM_STATE_APPROVED:
            $response['message'] = __('This design has been approved and is available for purchase.', 'store-mm');
            break;
        case STORE_MM_STATE_REJECTED:
            $response['message'] = __('This design has been rejected and is not available for purchase.', 'store-mm');
            break;
        case STORE_MM_STATE_CHANGES_REQUESTED:
            $response['message'] = __('The designer is making requested changes to this design.', 'store-mm');
            break;
        case STORE_MM_STATE_SUBMITTED:
            $response['message'] = __('This design is under review by our team.', 'store-mm');
            break;
    }
    
    store_mm_send_json_response($response);
}

add_action('wp_ajax_store_mm_get_product_lock_status', 'store_mm_ajax_get_product_lock_status');
function store_mm_ajax_get_product_lock_status() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_admin_nonce')) {
        store_mm_send_json_response('Security check failed.', false);
    }
    
    $product_id = intval($_POST['product_id']);
    $user_id = get_current_user_id();
    
    if (!$product_id) {
        store_mm_send_json_response('Invalid product ID.', false);
    }
    
    $state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    if (!$state) {
        $state = STORE_MM_STATE_DRAFT;
    }
    
    $state_labels = [
        STORE_MM_STATE_DRAFT => __('Draft', 'store-mm'),
        STORE_MM_STATE_SUBMITTED => __('Submitted', 'store-mm'),
        STORE_MM_STATE_CHANGES_REQUESTED => __('Changes Requested', 'store-mm'),
        STORE_MM_STATE_PROTOTYPING => __('Prototyping', 'store-mm'),
        STORE_MM_STATE_APPROVED => __('Approved', 'store-mm'),
        STORE_MM_STATE_REJECTED => __('Rejected', 'store-mm')
    ];
    
    $response = [
        'locked' => store_mm_is_product_locked($product_id),
        'state' => $state,
        'state_label' => $state_labels[$state],
        'product_id' => $product_id,
        'user_id' => $user_id,
        'can_edit' => store_mm_user_can_edit_product($user_id, $product_id)
    ];
    
    store_mm_send_json_response($response);
}

add_action('updated_postmeta', 'store_mm_auto_lock_on_prototyping', 10, 4);
function store_mm_auto_lock_on_prototyping($meta_id, $post_id, $meta_key, $meta_value) {
    if ($meta_key === '_store_mm_workflow_state' && $meta_value === STORE_MM_STATE_PROTOTYPING) {
        $post = get_post($post_id);
        
        if ($post && $post->post_type === 'product') {
            if ($post->post_status !== 'draft') {
                wp_update_post([
                    'ID' => $post_id,
                    'post_status' => 'draft'
                ]);
            }
            
            $existing_notes = get_post_meta($post_id, '_store_mm_internal_notes', true);
            if (!is_array($existing_notes)) {
                $existing_notes = [];
            }
            
            $user = wp_get_current_user();
            $new_note = [
                'date' => current_time('mysql'),
                'user_id' => $user->ID,
                'user_name' => $user->display_name,
                'content' => 'Product automatically locked for prototyping. Design files and core information are now read-only.'
            ];
            
            $existing_notes[] = $new_note;
            update_post_meta($post_id, '_store_mm_internal_notes', $existing_notes);
            
            $existing_logs = get_post_meta($post_id, '_store_mm_submission_log', true);
            if (!is_array($existing_logs)) {
                $existing_logs = [];
            }
            
            $log_entry = [
                'date' => current_time('mysql'),
                'action' => 'auto_lock_prototyping',
                'user_id' => $user->ID,
                'user_name' => $user->display_name,
                'notes' => 'Product locked for prototyping phase'
            ];
            
            $existing_logs[] = $log_entry;
            update_post_meta($post_id, '_store_mm_submission_log', $existing_logs);
        }
    }
}

add_filter('pre_update_postmeta', 'store_mm_filter_state_transition', 10, 4);
function store_mm_filter_state_transition($new_value, $old_value, $meta_key, $post_id) {
    if ($meta_key !== '_store_mm_workflow_state') {
        return $new_value;
    }
    
    if ($new_value === $old_value) {
        return $new_value;
    }
    
    $user = wp_get_current_user();
    
    if (!store_mm_validate_state_transition($old_value, $new_value, $user)) {
        add_action('admin_notices', function() use ($old_value, $new_value) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . sprintf(
                __('Invalid workflow transition: %s → %s. This transition is not allowed.', 'store-mm'),
                str_replace('_', ' ', $old_value),
                str_replace('_', ' ', $new_value)
            ) . '</p>';
            echo '</div>';
        });
        
        return $old_value;
    }
    
    return $new_value;
}