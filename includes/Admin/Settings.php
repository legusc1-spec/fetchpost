<?php
namespace AutoSyncPro\Admin;

use AutoSyncPro\Plugin;
use AutoSyncPro\Fetcher;
use AutoSyncPro\ImportLogger;

if (!defined('ABSPATH')) exit;

class Settings {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aspom_save_settings', [__CLASS__, 'save_settings']);
        add_action('wp_ajax_aspom_manual_run_ajax', [__CLASS__, 'ajax_manual_run']);
        add_action('wp_ajax_aspom_clear_logs', [__CLASS__, 'ajax_clear_logs']);
        add_action('wp_ajax_aspom_reschedule_cron', [__CLASS__, 'ajax_reschedule_cron']);
        add_action('admin_notices', [__CLASS__, 'show_cron_status']);
    }

    public static function enqueue_assets($hook) {
        // only on our pages
        if (strpos($hook, 'aspom-settings') === false && strpos($hook, 'toplevel_page_aspom-settings') === false && strpos($hook, 'aspom-sources') === false) {
            // still enqueue for plugin page
        }
        wp_enqueue_style('aspom-admin', plugin_dir_url(__DIR__) . '../assets/css/admin.css', [], '1.0');
        wp_enqueue_script('aspom-admin', plugin_dir_url(__DIR__) . '../assets/js/admin.js', ['jquery'], '1.0', true);
        wp_localize_script('aspom-admin', 'aspom_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aspom_manual_run_ajax')
        ]);
    }

    public static function menu() {
        add_menu_page('Auto Sync Pro', 'Auto Sync Pro', 'manage_options', 'aspom-settings', [__CLASS__, 'page'], 'dashicons-update', 76);
        add_submenu_page('aspom-settings', 'Sources', 'Sources', 'manage_options', 'aspom-sources', [__CLASS__, 'page_sources']);
        add_submenu_page('aspom-settings', 'Filters', 'Filters', 'manage_options', 'aspom-filters', [__CLASS__, 'page_filters']);
        add_submenu_page('aspom-settings', 'AI', 'AI', 'manage_options', 'aspom-ai', [__CLASS__, 'page_ai']);
        add_submenu_page('aspom-settings', 'Import Logs', 'Import Logs', 'manage_options', 'aspom-logs', [__CLASS__, 'page_logs']);
    }

    public static function show_cron_status() {
        if (!current_user_can('manage_options')) return;
        $screen = get_current_screen();
        if (!$screen || (strpos($screen->id, 'aspom') === false && strpos($screen->id, 'toplevel_page_aspom') === false)) return;

        $next_run = wp_next_scheduled(Plugin::CRON_HOOK);
        $last_run = get_option('aspom_last_run_time', 0);
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        $interval = intval($opts['interval_seconds'] ?: 300);

        if ($next_run) {
            $time_diff = $next_run - time();
            if ($time_diff > 0) {
                $next_run_display = 'in ' . human_time_diff(time(), $next_run);
                $status_color = '#00a32a';
                $status_icon = '&#10004;';
            } else {
                $next_run_display = 'overdue by ' . human_time_diff($next_run, time());
                $status_color = '#f0b849';
                $status_icon = '&#9888;';
            }
        } else {
            $next_run_display = 'Not scheduled - Click "Reschedule Cron" below';
            $status_color = '#dc3232';
            $status_icon = '&#10008;';
        }

        $last_run_display = $last_run ? human_time_diff($last_run) . ' ago' : 'Never';
        ?>
        <div class="notice notice-info" style="padding:15px; background: #fff; border-left: 4px solid <?php echo esc_attr($status_color); ?>;">
            <p style="margin: 0; font-size: 14px;">
                <span style="color: <?php echo esc_attr($status_color); ?>; font-size: 18px;"><?php echo $status_icon; ?></span>
                <strong>Auto Sync Pro Status:</strong>
                Next run: <code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($next_run_display); ?></code> |
                Last run: <code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($last_run_display); ?></code> |
                Interval: <code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($interval); ?>s</code>
                <?php if (!$next_run): ?>
                    <button type="button" id="aspom-reschedule-cron" class="button button-small" style="margin-left: 10px;">Reschedule Cron</button>
                <?php endif; ?>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#aspom-reschedule-cron').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Rescheduling...');
                $.post(ajaxurl, {
                    action: 'aspom_reschedule_cron',
                    nonce: '<?php echo wp_create_nonce('aspom_reschedule_cron'); ?>'
                }, function(resp) {
                    if (resp.success) {
                        location.reload();
                    } else {
                        alert('Failed to reschedule: ' + resp.data);
                        btn.prop('disabled', false).text('Reschedule Cron');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public static function page() {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        ?>
        <div class="wrap">
            <h1>Auto Sync Pro</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="aspom_save_settings" />
                <?php wp_nonce_field('aspom_save_settings_nonce'); ?>

                <h2>Scheduler</h2>
                <table class="form-table">
                    <tr valign="top"><th>Interval (seconds)</th>
                        <td><input type="number" name="interval_seconds" value="<?php echo esc_attr($opts['interval_seconds']); ?>" min="5" /></td>
                    </tr>
                </table>

                <h2>General</h2>
                <table class="form-table">
                    <tr valign="top"><th>Fetch featured image</th>
                        <td><input type="checkbox" name="fetch_featured_image" <?php checked($opts['fetch_featured_image']); ?> /></td>
                    </tr>
                    <tr valign="top"><th>Use custom featured image URL</th>
                        <td>
                            <input type="checkbox" name="use_custom_featured" <?php checked($opts['use_custom_featured']); ?> />
                            <br/>
                            <input type="url" name="custom_featured_url" value="<?php echo esc_attr($opts['custom_featured_url']); ?>" style="width:50%;" />
                        </td>
                    </tr>
                    <tr valign="top"><th>Strip all links from description</th>
                        <td><input type="checkbox" name="strip_all_links" <?php checked($opts['strip_all_links']); ?> /></td>
                    </tr>
                    <tr valign="top"><th>Debug logging</th>
                        <td><input type="checkbox" name="debug" <?php checked($opts['debug']); ?> /> <em>Enable error_log output for debugging</em></td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <hr/>
            <h2>Quick Actions</h2>
            <form method="post">
                <?php wp_nonce_field('aspom_manual_run_action'); ?>
                <button id="aspom-ajax-run" class="button button-primary">Run Now (AJAX)</button>
                <input type="button" id="aspom-php-run" class="button" value="Run Now (Server)" />
            </form>
            <p id="aspom-php-result"></p>

        </div>
        <?php
    }

    public static function page_sources() {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        $categories = get_categories(['hide_empty' => false]);
        ?>
        <div class="wrap">
            <h1>Sources</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="aspom_save_settings" />
                <?php wp_nonce_field('aspom_save_settings_nonce'); ?>
                <p>Configure REST API sources and assign categories to each source.</p>
                <table class="form-table">
                    <thead>
                        <tr>
                            <th style="width:60%;">REST API URL</th>
                            <th style="width:40%;">Category</th>
                        </tr>
                    </thead>
                    <tbody id="aspom-sources-list">
                        <?php
                        if (!empty($opts['sources'])) {
                            foreach ($opts['sources'] as $idx => $source) {
                                $cat_id = isset($opts['source_categories'][$idx]) ? intval($opts['source_categories'][$idx]) : 0;
                                ?>
                                <tr>
                                    <td><input type="text" name="sources[]" value="<?php echo esc_attr($source); ?>" style="width:100%;" placeholder="https://example.com/wp-json" /></td>
                                    <td>
                                        <select name="source_categories[]" style="width:100%;">
                                            <option value="0">-- No Category --</option>
                                            <?php foreach ($categories as $cat) { ?>
                                                <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($cat_id, $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
                                            <?php } ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            ?>
                            <tr>
                                <td><input type="text" name="sources[]" value="" style="width:100%;" placeholder="https://example.com/wp-json" /></td>
                                <td>
                                    <select name="source_categories[]" style="width:100%;">
                                        <option value="0">-- No Category --</option>
                                        <?php foreach ($categories as $cat) { ?>
                                            <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                                        <?php } ?>
                                    </select>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="aspom-add-source">Add Another Source</button></p>
                <p class="description">Examples: <code>https://example.com/wp-json</code> or <code>https://example.com/wp-json/wp/v2</code></p>
                <?php submit_button('Save Sources'); ?>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($){
            $('#aspom-add-source').on('click', function(){
                var row = '<tr>' +
                    '<td><input type="text" name="sources[]" value="" style="width:100%;" placeholder="https://example.com/wp-json" /></td>' +
                    '<td><select name="source_categories[]" style="width:100%;"><option value="0">-- No Category --</option><?php foreach ($categories as $cat) { ?><option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_js($cat->name); ?></option><?php } ?></select></td>' +
                    '</tr>';
                $('#aspom-sources-list').append(row);
            });
        });
        </script>
        <?php
    }

    public static function page_filters() {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        ?>
        <div class="wrap">
            <h1>Filters & Replacements</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="aspom_save_settings" />
                <?php wp_nonce_field('aspom_save_settings_nonce'); ?>

                <h2>Remove from title (one per line)</h2>
                <textarea name="remove_from_title_raw" rows="6" cols="80"><?php echo esc_textarea(implode("\n", $opts['remove_from_title'])); ?></textarea>

                <h2>Remove from description (one per line)</h2>
                <textarea name="remove_from_description_raw" rows="6" cols="80"><?php echo esc_textarea(implode("\n", $opts['remove_from_description'])); ?></textarea>

                <h2>Replacement pairs (one per line as <code>search => replace</code>)</h2>
                <textarea name="replacement_pairs_raw" rows="8" cols="80"><?php
                    $lines = [];
                    foreach ($opts['replacement_pairs'] as $p) { $lines[] = $p['search'] . ' => ' . $p['replace']; }
                    echo esc_textarea(implode("\n", $lines));
                ?></textarea>

                <?php submit_button('Save Filters'); ?>
            </form>
        </div>
        <?php
    }

    public static function page_ai() {
        $opts = get_option(Plugin::OPTION_KEY, Plugin::defaults());
        ?>
        <div class="wrap">
            <h1>AI Settings</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="aspom_save_settings" />
                <?php wp_nonce_field('aspom_save_settings_nonce'); ?>

                <table class="form-table">
                    <tr valign="top"><th>Enable AI features</th>
                        <td><input type="checkbox" name="ai_enabled" <?php checked($opts['ai_enabled']); ?> /></td>
                    </tr>
                    <tr valign="top"><th>Provider</th>
                        <td>
                            <select name="ai_provider">
                                <option value="">-- choose --</option>
                                <option value="openai" <?php selected($opts['ai_provider'], 'openai'); ?>>OpenAI</option>
                                <option value="openrouter" <?php selected($opts['ai_provider'], 'openrouter'); ?>>OpenRouter</option>
                                <option value="gemini" <?php selected($opts['ai_provider'], 'gemini'); ?>>Gemini</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top"><th>API Key</th>
                        <td><input type="text" name="ai_api_key" value="<?php echo esc_attr($opts['ai_api_key']); ?>" style="width:50%;" /></td>
                    </tr>
                    <tr valign="top"><th>Model</th>
                        <td><input type="text" name="ai_model" value="<?php echo esc_attr($opts['ai_model']); ?>" /></td>
                    </tr>
                    <tr valign="top"><th>Instruction for title</th>
                        <td><input type="text" name="ai_instruction_title" value="<?php echo esc_attr($opts['ai_instruction_title']); ?>" style="width:80%;" /></td>
                    </tr>
                    <tr valign="top"><th>Instruction for description</th>
                        <td><textarea name="ai_instruction_description" rows="4" cols="80"><?php echo esc_textarea($opts['ai_instruction_description']); ?></textarea></td>
                    </tr>
                </table>

                <?php submit_button('Save AI Settings'); ?>
            </form>
        </div>
        <?php
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('aspom_save_settings_nonce');

        $defaults = \AutoSyncPro\Plugin::defaults();
        $opts = get_option(\AutoSyncPro\Plugin::OPTION_KEY, $defaults);

        if (isset($_POST['sources'])) {
            $sources = array_map('trim', array_map('esc_url_raw', $_POST['sources']));
            $sources = array_filter($sources);
            $opts['sources'] = array_values($sources);

            $categories = isset($_POST['source_categories']) ? array_map('intval', $_POST['source_categories']) : [];
            $opts['source_categories'] = $categories;
        }

        if (isset($_POST['interval_seconds'])) {
            $opts['interval_seconds'] = intval($_POST['interval_seconds']);
            if ($opts['interval_seconds'] < 5) $opts['interval_seconds'] = 5;
        }

        if (isset($_POST['fetch_featured_image']) || isset($_POST['debug']) || isset($_POST['strip_all_links'])) {
            $opts['fetch_featured_image'] = isset($_POST['fetch_featured_image']) ? true : false;
            $opts['use_custom_featured'] = isset($_POST['use_custom_featured']) ? true : false;
            $opts['custom_featured_url'] = isset($_POST['custom_featured_url']) ? esc_url_raw($_POST['custom_featured_url']) : '';
            $opts['strip_all_links'] = isset($_POST['strip_all_links']) ? true : false;
            $opts['debug'] = isset($_POST['debug']) ? true : false;
        }

        if (isset($_POST['remove_from_title_raw'])) {
            $opts['remove_from_title'] = array_values(array_filter(array_map('trim', explode("\n", wp_unslash($_POST['remove_from_title_raw'])))));
        }
        if (isset($_POST['remove_from_description_raw'])) {
            $opts['remove_from_description'] = array_values(array_filter(array_map('trim', explode("\n", wp_unslash($_POST['remove_from_description_raw'])))));
        }
        if (isset($_POST['replacement_pairs_raw'])) {
            $pairs = [];
            $lines = array_filter(array_map('trim', explode("\n", wp_unslash($_POST['replacement_pairs_raw']))));
            foreach ($lines as $ln) {
                if (strpos($ln, '=>') !== false) {
                    list($s, $r) = array_map('trim', explode('=>', $ln, 2));
                    if ($s !== '') $pairs[] = ['search' => $s, 'replace' => $r];
                }
            }
            $opts['replacement_pairs'] = $pairs;
        }

        if (isset($_POST['ai_enabled']) || isset($_POST['ai_provider']) || isset($_POST['ai_api_key'])) {
            $opts['ai_enabled'] = isset($_POST['ai_enabled']) ? true : false;
            $opts['ai_provider'] = isset($_POST['ai_provider']) ? sanitize_text_field($_POST['ai_provider']) : $opts['ai_provider'];
            $opts['ai_api_key'] = isset($_POST['ai_api_key']) ? sanitize_text_field($_POST['ai_api_key']) : $opts['ai_api_key'];
            $opts['ai_model'] = isset($_POST['ai_model']) ? sanitize_text_field($_POST['ai_model']) : $opts['ai_model'];
            $opts['ai_instruction_title'] = isset($_POST['ai_instruction_title']) ? sanitize_text_field($_POST['ai_instruction_title']) : $opts['ai_instruction_title'];
            $opts['ai_instruction_description'] = isset($_POST['ai_instruction_description']) ? sanitize_text_field($_POST['ai_instruction_description']) : $opts['ai_instruction_description'];
        }

        update_option(\AutoSyncPro\Plugin::OPTION_KEY, $opts);

        \AutoSyncPro\Plugin::reschedule();

        $redirect = wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=aspom-settings');
        wp_safe_redirect($redirect);
        exit;
    }

    public static function ajax_manual_run() {
        check_ajax_referer('aspom_manual_run_ajax', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        Fetcher::handle(true);
        wp_send_json_success('Manual run complete');
    }

    public static function ajax_clear_logs() {
        check_ajax_referer('aspom_clear_logs_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $cleared = ImportLogger::clear_logs();
        if ($cleared) {
            wp_send_json_success('Logs cleared successfully');
        } else {
            wp_send_json_success('No logs to clear');
        }
    }

    public static function ajax_reschedule_cron() {
        check_ajax_referer('aspom_reschedule_cron', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        Plugin::reschedule();
        wp_send_json_success('Cron rescheduled successfully');
    }

    public static function page_logs() {
        $per_page = 50;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;

        $logs = ImportLogger::get_all_logs($per_page, $offset);
        $total_logs = ImportLogger::get_log_count();
        $file_size = ImportLogger::get_log_file_size();
        $total_pages = ceil($total_logs / $per_page);
        ?>
        <div class="wrap">
            <h1>Import Logs</h1>

            <div class="aspom-logs-header" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #1d2327;">Total Imports</h3>
                        <p style="font-size: 24px; font-weight: 600; margin: 0; color: #2271b1;"><?php echo number_format($total_logs); ?></p>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #1d2327;">Log File Size</h3>
                        <p style="font-size: 24px; font-weight: 600; margin: 0; color: #2271b1;"><?php echo esc_html(ImportLogger::format_file_size($file_size)); ?></p>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #1d2327;">Status</h3>
                        <p style="font-size: 24px; font-weight: 600; margin: 0; color: #00a32a;">Active</p>
                    </div>
                </div>
                <div style="margin-top: 20px;">
                    <button id="aspom-clear-logs" class="button button-secondary" style="background: #dc3232; color: #fff; border-color: #dc3232;">
                        Clear All Logs
                    </button>
                    <span id="aspom-clear-logs-result" style="margin-left: 10px; font-weight: 600;"></span>
                </div>
            </div>

            <?php if (empty($logs)): ?>
                <div class="notice notice-info" style="padding: 15px;">
                    <p>No imports logged yet. Start fetching posts to see logs here.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Date & Time</th>
                            <th style="width: 60px;">Post ID</th>
                            <th style="width: 200px;">URL Slug</th>
                            <th>Title</th>
                            <th style="width: 300px;">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log['timestamp']); ?></td>
                                <td style="text-align: center;">
                                    <small style="color: #646970;">
                                        <?php if ($log['post_id']): ?>
                                            <a href="<?php echo esc_url(get_edit_post_link($log['post_id'])); ?>" target="_blank" title="Edit post">
                                                <?php echo esc_html($log['post_id']); ?>
                                            </a>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <code style="font-size: 11px; background: #f0f0f1; padding: 2px 6px; border-radius: 3px;">
                                        <?php echo esc_html($log['slug']); ?>
                                    </code>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($log['title'] ? substr($log['title'], 0, 80) : '(no title)'); ?></strong>
                                    <?php if (strlen($log['title']) > 80): ?>...<?php endif; ?>
                                </td>
                                <td style="font-size: 11px; color: #646970;">
                                    <?php echo esc_html($log['source']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom" style="margin-top: 20px;">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php echo number_format($total_logs); ?> items</span>
                            <span class="pagination-links">
                                <?php
                                $base_url = admin_url('admin.php?page=aspom-logs');
                                if ($page > 1) {
                                    echo '<a class="button" href="' . esc_url($base_url . '&paged=' . ($page - 1)) . '">&laquo; Previous</a> ';
                                }
                                echo '<span class="paging-input">Page ' . $page . ' of ' . $total_pages . '</span>';
                                if ($page < $total_pages) {
                                    echo ' <a class="button" href="' . esc_url($base_url . '&paged=' . ($page + 1)) . '">Next &raquo;</a>';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#aspom-clear-logs').on('click', function(e) {
                e.preventDefault();

                if (!confirm('Are you sure you want to clear all import logs? This action cannot be undone.')) {
                    return;
                }

                var btn = $(this);
                btn.prop('disabled', true).text('Clearing...');

                $.post(ajaxurl, {
                    action: 'aspom_clear_logs',
                    nonce: '<?php echo wp_create_nonce('aspom_clear_logs_nonce'); ?>'
                }, function(resp) {
                    if (resp.success) {
                        $('#aspom-clear-logs-result').text('Logs cleared successfully!').css('color', '#00a32a');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $('#aspom-clear-logs-result').text('Error: ' + resp.data).css('color', '#dc3232');
                        btn.prop('disabled', false).text('Clear All Logs');
                    }
                }).fail(function() {
                    $('#aspom-clear-logs-result').text('Request failed').css('color', '#dc3232');
                    btn.prop('disabled', false).text('Clear All Logs');
                });
            });
        });
        </script>
        <?php
    }
}
