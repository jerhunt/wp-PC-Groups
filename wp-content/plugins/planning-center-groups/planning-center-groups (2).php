<?php
/**
 * Plugin Name: Planning Center Groups Embed
 * Description: Displays a list of active groups from Planning Center.
 * Version: .83
 * Author: Jeremy Hunt
 */

// Register settings
add_action('admin_menu', function() {
    add_options_page('Planning Center Settings', 'Planning Center', 'manage_options', 'planning-center-settings', 'pcg_settings_page');
});

add_action('admin_init', function() {
    register_setting('pcg_settings', 'pcg_client_id');
    register_setting('pcg_settings', 'pcg_client_secret');
    register_setting('pcg_settings', 'pcg_debug_mode');
    register_setting('pcg_settings', 'pcg_tag_filter');
    register_setting('pcg_settings', 'pcg_group_type_filter');
});

function pcg_settings_page() {
    ?>
    <div class="wrap">
        <h1>Planning Center Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('pcg_settings'); ?>
            <?php do_settings_sections('pcg_settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Client ID</th>
                    <td><input type="text" name="pcg_client_id" value="<?php echo esc_attr(get_option('pcg_client_id')); ?>" size="50" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Client Secret</th>
                    <td><input type="password" name="pcg_client_secret" value="<?php echo esc_attr(get_option('pcg_client_secret')); ?>" size="50" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable Debug Mode</th>
                    <td><input type="checkbox" name="pcg_debug_mode" value="1" <?php checked(1, get_option('pcg_debug_mode'), true); ?> /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Filter by Tag (optional)</th>
                    <td><input type="text" name="pcg_tag_filter" value="<?php echo esc_attr(get_option('pcg_tag_filter')); ?>" size="50" placeholder="e.g. Adult, Youth, Online" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Filter by Group Type (optional)</th>
                    <td><input type="text" name="pcg_group_type_filter" value="<?php echo esc_attr(get_option('pcg_group_type_filter')); ?>" size="50" placeholder="e.g. Small Group, Bible Study" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Shortcode to display groups
add_shortcode('planning_center_groups', function($atts) {
    $atts = shortcode_atts([
        'group_type' => '',
    ], $atts);

    $group_type_filter = $atts['group_type'];
    $client_id = trim(get_option('pcg_client_id'));
    $client_secret = trim(get_option('pcg_client_secret'));
    $debug = get_option('pcg_debug_mode');
    $tag_filter = get_option('pcg_tag_filter');

    if (!$client_id || !$client_secret) return '<p>Please set your Client ID and Secret in the Planning Center settings.</p>';

    $url = 'https://api.planningcenteronline.com/groups/v2/groups?include=group_tags,group_type';
    $auth = base64_encode("$client_id:$client_secret");

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ]
    ]);

    $status_code = wp_remote_retrieve_response_code($response);
    $raw = wp_remote_retrieve_body($response);
    $body = json_decode($raw);

    if ($debug) {
        return '<h3>Debug Output</h3>' .
               '<p><strong>Status Code:</strong> ' . esc_html($status_code) . '</p>' .
               '<pre>' . htmlspecialchars($raw) . '</pre>';
    }

    if (!isset($body->data)) return '<p>No groups found.</p>';

    // Build tag and group type lookup from included resources
    $tag_lookup = [];
    $type_lookup = [];
    if (isset($body->included)) {
        foreach ($body->included as $item) {
            if ($item->type === 'GroupTag') {
                $tag_lookup[$item->id] = $item->attributes->name;
            }
            if ($item->type === 'GroupType') {
                $type_lookup[$item->id] = $item->attributes->name;
            }
        }
    }

    $html = '<div class="pcg-group-grid" style="display: flex; flex-wrap: wrap; gap: 20px; justify-content: center;">';

    foreach ($body->data as $group) {
        if (!empty($group->attributes->archived_at)) continue;

        // Filter by tag if set
        if ($tag_filter) {
            $matching = false;
            if (isset($group->relationships->group_tags->data)) {
                foreach ($group->relationships->group_tags->data as $tag_ref) {
                    $tag_name = $tag_lookup[$tag_ref->id] ?? '';
                    if (stripos($tag_name, $tag_filter) !== false) {
                        $matching = true;
                        break;
                    }
                }
            }
            if (!$matching) continue;
        }

        // Filter by group type if set
        if ($group_type_filter) {
            $type_id = $group->relationships->group_type->data->id ?? '';
            $type_name = $type_lookup[$type_id] ?? '';
            if (stripos($type_name, $group_type_filter) === false) continue;
        }

        $name = esc_html($group->attributes->name);
        $link = esc_url($group->links->html);
        $image = esc_url($group->attributes->header_image->thumbnail ?? '');

        $html .= '<div class="pcg-group-card" style="flex: 0 1 calc(25% - 20px); border: 1px solid #ccc; padding: 10px; box-sizing: border-box; text-align: center;">';
        if ($image) {
            $html .= "<img src='$image' alt='$name' style='max-width:100%; height:auto;'><br>";
        } else {
            $html .= "<em>No image</em><br>";
        }
        $html .= "<a href='$link' target='_blank'><strong>$name</strong></a>";
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
});