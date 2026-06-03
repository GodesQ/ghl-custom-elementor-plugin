<?php
/**
 * WordPress dashboard configuration page for the GHL integration.
 *
 * @package GHL_Elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

class GHL_Elementor_Admin_Page
{
    const MENU_SLUG = 'ghl-elementor-config';
    const NONCE_ACTION = 'ghl_elementor_save_config';

    /**
     * @var GHL_Elementor_Settings
     */
    private $settings_repository;

    /**
     * @var GHL_Logger
     */
    private $logger;

    public function __construct(GHL_Elementor_Settings $settings_repository)
    {
        $this->settings_repository = $settings_repository;
        $this->logger = new GHL_Logger();
    }

    /**
     * Register dashboard hooks.
     */
    public function register()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_ghl_elementor_save_config', [$this, 'handle_save']);
    }

    /**
     * Add top-level WordPress dashboard menu item.
     */
    public function register_menu()
    {
        add_menu_page(
            __('GHL Config', 'ghl-elementor'),
            __('GHL Config', 'ghl-elementor'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-rest-api',
            56
        );
    }

    /**
     * Render the admin configuration page.
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ghl-elementor'));
        }

        $settings = $this->settings_repository->get();
        $users = is_array($settings['users']) ? $settings['users'] : [];
        $pipelines = is_array($settings['pipelines']) ? $settings['pipelines'] : [];
        $stages = is_array($settings['stages']) ? $settings['stages'] : [];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('GHL Config', 'ghl-elementor'); ?></h1>
            <?php $this->render_notice(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="ghl_elementor_save_config">

                <h2><?php esc_html_e('Connection', 'ghl-elementor'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="ghl-token"><?php esc_html_e('Private Integration Token', 'ghl-elementor'); ?></label>
                            </th>
                            <td>
                                <textarea id="ghl-token" name="ghl_settings[token]" class="large-text" rows="3"><?php echo esc_textarea($settings['token']); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ghl-location-id"><?php esc_html_e('Location ID', 'ghl-elementor'); ?></label>
                            </th>
                            <td>
                                <input id="ghl-location-id" name="ghl_settings[location_id]" type="text" class="regular-text" value="<?php echo esc_attr($settings['location_id']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ghl-pipeline-id"><?php esc_html_e('Active Pipeline', 'ghl-elementor'); ?></label>
                            </th>
                            <td>
                                <input id="ghl-pipeline-id" name="ghl_settings[pipeline_id]" list="ghl-pipeline-options" type="text" class="regular-text" value="<?php echo esc_attr($settings['pipeline_id']); ?>">
                                <datalist id="ghl-pipeline-options">
                                    <?php foreach ($pipelines as $pipeline) : ?>
                                        <option value="<?php echo esc_attr($pipeline['id']); ?>"><?php echo esc_html($pipeline['name']); ?></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ghl-message-field-id"><?php esc_html_e('Message Custom Field ID', 'ghl-elementor'); ?></label>
                            </th>
                            <td>
                                <input id="ghl-message-field-id" name="ghl_settings[message_custom_field_id]" type="text" class="regular-text" value="<?php echo esc_attr($settings['message_custom_field_id']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ghl-redirect-url"><?php esc_html_e('Redirect URL', 'ghl-elementor'); ?></label>
                            </th>
                            <td>
                                <input id="ghl-redirect-url" name="ghl_settings[redirect_url]" type="url" class="regular-text" value="<?php echo esc_attr($settings['redirect_url']); ?>">
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Routing Defaults', 'ghl-elementor'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="ghl-default-user"><?php esc_html_e('Default User', 'ghl-elementor'); ?></label>
                            </th>
                            <td>
                                <?php $this->render_user_select('ghl_settings[default_user_id]', $settings['default_user_id'], $users, 'ghl-default-user'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ghl-default-stage"><?php esc_html_e('Default Pipeline Stage', 'ghl-elementor'); ?></label>
                            </th>
                            <td>
                                <?php $this->render_stage_select('ghl_settings[default_pipeline_stage_id]', $settings['default_pipeline_stage_id'], $stages, 'ghl-default-stage'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Sales State Routing', 'ghl-elementor'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Sales State', 'ghl-elementor'); ?></th>
                            <th><?php esc_html_e('Assigned User', 'ghl-elementor'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->settings_repository->get_state_options() as $state => $abbr) : ?>
                            <tr>
                                <td><?php echo esc_html(ucwords($state) . ' (' . $abbr . ')'); ?></td>
                                <td>
                                    <?php
                                    $this->render_user_select(
                                        'ghl_settings[state_user_map][' . esc_attr($state) . ']',
                                        $settings['state_user_map'][$state] ?? '',
                                        $users
                                    );
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Users', 'ghl-elementor'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('User', 'ghl-elementor'); ?></th>
                            <th><?php esc_html_e('User ID', 'ghl-elementor'); ?></th>
                            <th><?php esc_html_e('Assigned Pipeline Stage', 'ghl-elementor'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)) : ?>
                            <tr>
                                <td colspan="3"><?php esc_html_e('No GHL users loaded yet. Save connection details, then refresh from GHL.', 'ghl-elementor'); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($users as $user) : ?>
                                <tr>
                                    <td><?php echo esc_html($user['name']); ?></td>
                                    <td><code><?php echo esc_html($user['id']); ?></code></td>
                                    <td>
                                        <?php
                                        $this->render_stage_select(
                                            'ghl_settings[user_stage_map][' . esc_attr($user['id']) . ']',
                                            $settings['user_stage_map'][$user['id']] ?? '',
                                            $stages
                                        );
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Sales Stages', 'ghl-elementor'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Stage', 'ghl-elementor'); ?></th>
                            <th><?php esc_html_e('Stage ID', 'ghl-elementor'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stages)) : ?>
                            <tr>
                                <td colspan="2"><?php esc_html_e('No pipeline stages loaded yet. Enter an active pipeline ID, then refresh from GHL.', 'ghl-elementor'); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($stages as $stage) : ?>
                                <tr>
                                    <td><?php echo esc_html($stage['name']); ?></td>
                                    <td><code><?php echo esc_html($stage['id']); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!empty($settings['last_sync'])) : ?>
                    <p><?php echo esc_html(sprintf(__('Last GHL refresh: %s', 'ghl-elementor'), $settings['last_sync'])); ?></p>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" name="ghl_submit" value="save" class="button button-primary">
                        <?php esc_html_e('Save Configuration', 'ghl-elementor'); ?>
                    </button>
                    <button type="submit" name="ghl_submit" value="refresh" class="button">
                        <?php esc_html_e('Refresh from GHL', 'ghl-elementor'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Handle settings save and optional GHL refresh.
     */
    public function handle_save()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save this configuration.', 'ghl-elementor'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $input = isset($_POST['ghl_settings']) && is_array($_POST['ghl_settings']) ? wp_unslash($_POST['ghl_settings']) : [];
        $settings = $this->settings_repository->sanitize_input($input);
        $message = __('Configuration saved.', 'ghl-elementor');
        $status = 'updated';

        if (($settings['token'] ?? '') !== '') {
            $settings['token'] = preg_replace('/^Bearer\s+/i', '', $settings['token']);
            $settings['token'] = preg_replace('/\s+/', '', $settings['token']);
        }

        $this->settings_repository->save($settings);

        if (sanitize_text_field($_POST['ghl_submit'] ?? '') === 'refresh') {
            $result = $this->refresh_external_data($settings);
            $settings = $result['settings'];
            $message = $result['message'];
            $status = $result['status'];
            $this->settings_repository->save($settings);
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => self::MENU_SLUG,
                    'ghl_status' => $status,
                    'ghl_message' => rawurlencode($message),
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Fetch users, pipelines, and stages from GHL without clearing old data on failure.
     *
     * @param array $settings Current settings.
     * @return array
     */
    private function refresh_external_data(array $settings)
    {
        if (empty($settings['token']) || empty($settings['location_id'])) {
            return [
                'settings' => $settings,
                'status' => 'error',
                'message' => __('Token and Location ID are required before refreshing from GHL.', 'ghl-elementor'),
            ];
        }

        $api_client = new GHL_API_Client($settings['token'], $this->logger);
        $had_error = false;
        $messages = [];

        $users_response = $api_client->get_users_by_location($settings['location_id']);

        if (is_wp_error($users_response)) {
            $had_error = true;
            $messages[] = sprintf(
                __('Users could not be refreshed: %s', 'ghl-elementor'),
                $users_response->get_error_message()
            );
        } else {
            $settings['users'] = $api_client->normalize_users($users_response);
            $messages[] = sprintf(
                __('Loaded %d users.', 'ghl-elementor'),
                count($settings['users'])
            );
        }

        $pipelines_response = $api_client->get_pipelines($settings['location_id']);

        if (is_wp_error($pipelines_response)) {
            $had_error = true;
            $messages[] = sprintf(
                __('Pipelines could not be refreshed: %s', 'ghl-elementor'),
                $pipelines_response->get_error_message()
            );
        } else {
            $settings['pipelines'] = $api_client->normalize_pipelines($pipelines_response);
            $settings['stages'] = $api_client->get_pipeline_stages($settings['pipelines'], $settings['pipeline_id']);

            if (empty($settings['stages']) && !empty($settings['pipeline_id'])) {
                $had_error = true;
                $messages[] = __('Selected pipeline was not found in the GHL response.', 'ghl-elementor');
            } else {
                $messages[] = sprintf(
                    __('Loaded %d pipeline stages.', 'ghl-elementor'),
                    count($settings['stages'])
                );
            }
        }

        if (!$had_error) {
            $settings['last_sync'] = current_time('mysql');
        }

        return [
            'settings' => $settings,
            'status' => $had_error ? 'error' : 'updated',
            'message' => implode(' ', $messages),
        ];
    }

    /**
     * Render a saved action notice.
     */
    private function render_notice()
    {
        if (empty($_GET['ghl_message'])) {
            return;
        }

        $status = sanitize_key($_GET['ghl_status'] ?? 'updated');
        $class = $status === 'error' ? 'notice notice-error' : 'notice notice-success';
        $message = sanitize_text_field(rawurldecode($_GET['ghl_message']));
        ?>
        <div class="<?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    /**
     * Render a GHL user select control.
     *
     * @param string $name Selected field name.
     * @param string $selected Selected user ID.
     * @param array  $users Users.
     * @param string $id Optional field ID.
     */
    private function render_user_select($name, $selected, array $users, $id = '')
    {
        ?>
        <select <?php echo $id ? 'id="' . esc_attr($id) . '"' : ''; ?> name="<?php echo esc_attr($name); ?>">
            <option value=""><?php esc_html_e('Select user', 'ghl-elementor'); ?></option>
            <?php foreach ($users as $user) : ?>
                <option value="<?php echo esc_attr($user['id']); ?>" <?php selected($selected, $user['id']); ?>>
                    <?php echo esc_html($user['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render a pipeline stage select control.
     *
     * @param string $name Selected field name.
     * @param string $selected Selected stage ID.
     * @param array  $stages Stages.
     * @param string $id Optional field ID.
     */
    private function render_stage_select($name, $selected, array $stages, $id = '')
    {
        ?>
        <select <?php echo $id ? 'id="' . esc_attr($id) . '"' : ''; ?> name="<?php echo esc_attr($name); ?>">
            <option value=""><?php esc_html_e('Select stage', 'ghl-elementor'); ?></option>
            <?php foreach ($stages as $stage) : ?>
                <option value="<?php echo esc_attr($stage['id']); ?>" <?php selected($selected, $stage['id']); ?>>
                    <?php echo esc_html($stage['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
}
