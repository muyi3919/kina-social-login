<?php
/**
 * Plugin Name: Kina 聚合登录
 * Description: 使用UR互联聚合登录接口,支持 QQ、微信、支付宝、GitHub，扫码免密登录，自动创建/绑定 WordPress 账号
 * Version: 3.0.0
 * Author: kina漫记
 * Author URI: https://kina.ink
 */

if (!defined('ABSPATH')) exit;

class Kina_Social_Login {

    private static $instance = null;
    private $table_name;
    private $api_base = 'http://uniqueker.top/connect.php';
    private $supported_types = ['qq', 'wx', 'alipay', 'github'];
    private $transient_prefix = 'kina_social_';

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'kina_social_accounts';

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'check_admin_callback']);
        add_action('show_user_profile', [$this, 'show_profile_bindings']);
        add_action('edit_user_profile', [$this, 'show_profile_bindings']);
        add_action('login_form', [$this, 'login_form_buttons']);
        add_action('login_enqueue_scripts', [$this, 'login_styles']);
        add_action('admin_enqueue_scripts', [$this, 'admin_styles']);

        add_action('init', [$this, 'handle_callback'], 5);

        add_action('admin_post_kina_social_bind', [$this, 'handle_bind']);
        add_action('admin_post_kina_social_unbind', [$this, 'handle_unbind']);
        add_action('admin_post_nopriv_kina_social_redirect', [$this, 'handle_redirect']);
        add_action('admin_post_kina_social_redirect', [$this, 'handle_redirect']);
        add_action('admin_post_nopriv_kina_social_do_bind', [$this, 'handle_do_bind']);
        add_action('admin_post_kina_social_do_bind', [$this, 'handle_do_bind']);
        add_action('admin_post_nopriv_kina_social_auto_register', [$this, 'handle_auto_register']);
        add_action('admin_post_kina_social_auto_register', [$this, 'handle_auto_register']);
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            social_type varchar(20) NOT NULL,
            social_uid varchar(100) NOT NULL,
            access_token varchar(255) DEFAULT NULL,
            nickname varchar(100) DEFAULT NULL,
            faceimg varchar(500) DEFAULT NULL,
            bind_time datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_social (social_type, social_uid),
            KEY user_id (user_id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function deactivate() {
        // 保留数据
    }

    public function init() {
        register_setting('kina_social_settings', 'kina_social_appid');
        register_setting('kina_social_settings', 'kina_social_appkey');
        register_setting('kina_social_settings', 'kina_social_auto_register');
    }

    public function admin_menu() {
        add_options_page(
            'Kina 社交登录设置',
            'Kina 社交登录',
            'manage_options',
            'kina-social-login',
            [$this, 'settings_page']
        );
    }

    public function check_admin_callback() {
        if (is_admin() && isset($_GET['kina_social_bind_callback']) && isset($_GET['code']) && isset($_GET['type'])) {
            $this->handle_callback();
        }
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Kina 社交登录设置</h1>
            <form method="post" action="options.php">
                <?php settings_fields('kina_social_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="kina_social_appid">AppID</label></th>
                        <td><input type="text" id="kina_social_appid" name="kina_social_appid" value="<?php echo esc_attr(get_option('kina_social_appid', '')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="kina_social_appkey">AppKey</label></th>
                        <td><input type="text" id="kina_social_appkey" name="kina_social_appkey" value="<?php echo esc_attr(get_option('kina_social_appkey', '')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th>自动注册</th>
                        <td>
                            <label>
                                <input type="checkbox" name="kina_social_auto_register" value="1" <?php checked(get_option('kina_social_auto_register', '1'), '1'); ?> />
                                首次扫码登录时自动创建 WordPress 账号（关闭则需手动绑定已有账号）
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('保存设置'); ?>
            </form>
            <p>支持的登录方式：QQ、微信、支付宝、GitHub</p>
            <p><strong>新表名：</strong><code><?php echo esc_html($this->table_name); ?></code>（与旧版数据完全隔离）</p>
            <hr style="margin: 20px 0; border: none; border-top: 1px solid #c3c4c7;">
            <h3>接口申请</h3>
            <p>本插件使用 <a href="https://uniqueker.top" target="_blank">uniqueker.top</a> 提供的社会化账号聚合登录系统。</p>
            <p>申请步骤：</p>
            <ol style="list-style: decimal; padding-left: 20px;">
                <li>访问 <a href="https://uniqueker.top" target="_blank">uniqueker.top</a> 注册账号</li>
                <li>在控制台创建应用，获取 <strong>AppID</strong> 和 <strong>AppKey</strong></li>
                <li>将上述信息填入上方表单并保存</li>
            </ol>
        </div>
        <?php
    }

    private function get_api_url($act, $params = []) {
        $appid = get_option('kina_social_appid', '');
        $appkey = get_option('kina_social_appkey', '');
        $params['act'] = $act;
        $params['appid'] = $appid;
        $params['appkey'] = $appkey;
        return add_query_arg($params, $this->api_base);
    }

    private function get_redirect_uri($is_bind = false) {
        if ($is_bind) {
            return admin_url('profile.php?kina_social_bind_callback=1');
        }
        return home_url('?kina_social_callback=1');
    }

    private function get_type_label($type) {
        $labels = ['qq' => 'QQ', 'wx' => '微信', 'alipay' => '支付宝', 'github' => 'GitHub'];
        return $labels[$type] ?? $type;
    }

    private function get_type_fa_icon($type) {
        $icons = [
            'qq' => 'fa-brands fa-qq',
            'wx' => 'fa-brands fa-weixin',
            'alipay' => 'fa-brands fa-alipay',
            'github' => 'fa-brands fa-github'
        ];
        return $icons[$type] ?? 'fa-solid fa-user';
    }

    private function get_type_color($type) {
        $colors = ['qq' => '#12B7F5', 'wx' => '#07C160', 'alipay' => '#1677FF', 'github' => '#24292F'];
        return $colors[$type] ?? '#666';
    }

    public function login_styles() {
        wp_enqueue_style('font-awesome', 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css', [], '6.5.1');
        ?>
        <style>
        #login .kina-social-login-wrap, .login .kina-social-login-wrap {
            margin: 20px 0 !important; padding: 20px 0 !important;
            border-top: 1px solid #ddd !important; text-align: center !important;
            background: none !important; box-shadow: none !important; border-radius: 0 !important;
        }
        #login .kina-social-login-wrap p, .login .kina-social-login-wrap p {
            color: #666 !important; font-size: 13px !important; margin-bottom: 12px !important; text-align: center !important;
        }
        #login .kina-social-buttons, .login .kina-social-buttons {
            display: flex !important; justify-content: center !important; gap: 12px !important;
            flex-wrap: wrap !important; align-items: center !important;
        }
        #login .kina-social-btn, .login .kina-social-btn, .kina-social-btn {
            display: inline-flex !important; align-items: center !important; justify-content: center !important;
            gap: 8px !important; padding: 10px 20px !important; border-radius: 6px !important;
            color: #fff !important; text-decoration: none !important; font-size: 14px !important;
            font-weight: 500 !important; transition: all 0.2s !important; border: none !important;
            cursor: pointer !important; line-height: 1.4 !important; height: auto !important;
            white-space: nowrap !important; width: auto !important; min-height: 0 !important;
            float: none !important; box-shadow: none !important; margin: 0 !important;
        }
        #login .kina-social-btn:hover, .login .kina-social-btn:hover, .kina-social-btn:hover {
            opacity: 0.9 !important; transform: translateY(-1px) !important; color: #fff !important;
        }
        #login .kina-social-btn i, .login .kina-social-btn i { font-size: 18px !important; }
        </style>
        <?php
    }

    public function admin_styles($hook) {
        if ($hook !== 'profile.php' && $hook !== 'user-edit.php') return;
        wp_enqueue_style('font-awesome', 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css', [], '6.5.1');
        ?>
        <style>
        .kina-bind-section { margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 8px; }
        .kina-bind-section h2 { margin-top: 0; font-size: 16px; }
        .kina-bind-list { display: flex; flex-direction: column; gap: 10px; }
        .kina-bind-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 15px; background: #fff; border-radius: 6px; border: 1px solid #e0e0e0; }
        .kina-bind-item-info { display: flex; align-items: center; gap: 12px; }
        .kina-bind-item-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; }
        .kina-bind-item-name { font-weight: 500; }
        .kina-bind-item-status { font-size: 12px; padding: 3px 10px; border-radius: 12px; }
        .kina-bind-item-status.bound { background: #d4edda; color: #155724; }
        .kina-bind-item-status.unbound { background: #f8f9fa; color: #6c757d; }
        .kina-bind-btn { padding: 6px 16px; border-radius: 4px; text-decoration: none; font-size: 13px; cursor: pointer; border: none; }
        .kina-bind-btn.bind { background: #2271b1; color: #fff; }
        .kina-bind-btn.unbind { background: #dc3545; color: #fff; }
        .kina-bind-btn:hover { opacity: 0.9; }
        </style>
        <?php
    }

    public function login_form_buttons() {
        $appid = get_option('kina_social_appid', '');
        if (empty($appid)) return;

        $ajax_url = admin_url('admin-post.php');
        ?>
        <div class="kina-social-login-wrap">
            <p>或使用以下方式登录</p>
            <div class="kina-social-buttons">
                <?php foreach ($this->supported_types as $type): 
                    $color = $this->get_type_color($type);
                    $icon = $this->get_type_fa_icon($type);
                ?>
                <a href="javascript:void(0);" class="kina-social-btn" style="background: <?php echo esc_attr($color); ?>;"
                   data-type="<?php echo esc_attr($type); ?>" onclick="kinaSocialLogin(this)">
                    <i class="<?php echo esc_attr($icon); ?>"></i>
                    <span><?php echo esc_html($this->get_type_label($type)); ?>登录</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        function kinaSocialLogin(btn) {
            var type = btn.getAttribute('data-type');
            var form = document.createElement('form');
            form.method = 'post';
            form.action = '<?php echo esc_js($ajax_url); ?>';
            form.target = 'kina_social_login';
            form.innerHTML = '<input type="hidden" name="action" value="kina_social_redirect"><input type="hidden" name="type" value="' + type + '">';
            document.body.appendChild(form);
            window.open('', 'kina_social_login', 'width=600,height=600,menubar=no,toolbar=no');
            form.submit();
            document.body.removeChild(form);
        }
        </script>
        <?php
    }

    public function handle_redirect() {
        $type = sanitize_text_field($_POST['type'] ?? '');
        if (!in_array($type, $this->supported_types)) {
            wp_die('不支持的登录方式');
        }

        $redirect_uri = urlencode($this->get_redirect_uri(false));
        $api_url = $this->get_api_url('login', ['type' => $type, 'redirect_uri' => $redirect_uri]);

        $response = wp_remote_get($api_url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            wp_die('请求失败: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body) || $body['code'] !== 0) {
            wp_die('获取登录地址失败: ' . ($body['msg'] ?? '未知错误'));
        }

        $jump_url = $body['url'] ?? '';
        if (empty($jump_url)) {
            wp_die('获取登录地址失败');
        }

        wp_redirect($jump_url);
        exit;
    }

    public function show_profile_bindings($user) {
        if (!current_user_can('edit_user', $user->ID)) return;

        $bindings = $this->get_user_bindings($user->ID);
        $appid = get_option('kina_social_appid', '');
        ?>
        <div class="kina-bind-section">
            <h2>社交账号绑定</h2>
            <div class="kina-bind-list">
                <?php foreach ($this->supported_types as $type): 
                    $is_bound = isset($bindings[$type]);
                    $bind_data = $is_bound ? $bindings[$type] : null;
                    $icon = $this->get_type_fa_icon($type);
                    $bind_url = wp_nonce_url(admin_url('admin-post.php?action=kina_social_bind&type=' . $type), 'kina_social_bind_' . $type);
                    $unbind_url = wp_nonce_url(admin_url('admin-post.php?action=kina_social_unbind&type=' . $type), 'kina_social_unbind_' . $type);
                ?>
                <div class="kina-bind-item">
                    <div class="kina-bind-item-info">
                        <div class="kina-bind-item-icon" style="background: <?php echo esc_attr($this->get_type_color($type)); ?>">
                            <i class="<?php echo esc_attr($icon); ?>"></i>
                        </div>
                        <div>
                            <div class="kina-bind-item-name"><?php echo esc_html($this->get_type_label($type)); ?></div>
                            <?php if ($is_bound): ?>
                                <div style="font-size: 12px; color: #666;"><?php echo esc_html($bind_data->nickname ?: '已绑定'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="kina-bind-item-status <?php echo $is_bound ? 'bound' : 'unbound'; ?>"><?php echo $is_bound ? '已绑定' : '未绑定'; ?></span>
                        <?php if ($is_bound): ?>
                            <a href="<?php echo esc_url($unbind_url); ?>" class="kina-bind-btn unbind" onclick="return confirm('确定要解绑<?php echo esc_js($this->get_type_label($type)); ?>吗？');">解绑</a>
                        <?php else: ?>
                            <a href="<?php echo esc_url($bind_url); ?>" class="kina-bind-btn bind" onclick="window.open(this.href, 'kina_social_bind', 'width=600,height=600,menubar=no,toolbar=no'); return false;">绑定</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($appid)): ?>
                <p style="color: #d63638; margin-top: 10px;">⚠️ 请先前往 <a href="<?php echo admin_url('options-general.php?page=kina-social-login'); ?>">设置页面</a> 配置 AppID 和 AppKey</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function get_user_bindings($user_id) {
        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE user_id = %d", $user_id));
        $bindings = [];
        foreach ($results as $row) { $bindings[$row->social_type] = $row; }
        return $bindings;
    }

    public function handle_bind() {
        if (!is_user_logged_in()) wp_die('请先登录');

        check_admin_referer('kina_social_bind_' . sanitize_text_field($_GET['type'] ?? ''));

        $type = sanitize_text_field($_GET['type'] ?? '');
        if (!in_array($type, $this->supported_types)) wp_die('不支持的登录方式');

        $redirect_uri = urlencode($this->get_redirect_uri(true));
        $api_url = $this->get_api_url('login', ['type' => $type, 'redirect_uri' => $redirect_uri]);

        $response = wp_remote_get($api_url, ['timeout' => 30]);
        if (is_wp_error($response)) wp_die('请求失败: ' . $response->get_error_message());

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body) || $body['code'] !== 0) wp_die('获取绑定地址失败: ' . ($body['msg'] ?? '未知错误'));

        $jump_url = $body['url'] ?? '';
        if (empty($jump_url)) wp_die('获取绑定地址失败');

        wp_redirect($jump_url);
        exit;
    }

    public function handle_unbind() {
        if (!is_user_logged_in()) wp_die('请先登录');

        check_admin_referer('kina_social_unbind_' . sanitize_text_field($_GET['type'] ?? ''));

        $type = sanitize_text_field($_GET['type'] ?? '');
        $user_id = get_current_user_id();

        global $wpdb;
        $wpdb->delete($this->table_name, ['user_id' => $user_id, 'social_type' => $type], ['%d', '%s']);

        wp_redirect(admin_url('profile.php?kina_unbind_success=1'));
        exit;
    }

    public function handle_callback() {
        $is_bind = isset($_GET['kina_social_bind_callback']);

        if (!$is_bind && !isset($_GET['kina_social_callback'])) return;

        $code = sanitize_text_field($_GET['code'] ?? '');
        $type = sanitize_text_field($_GET['type'] ?? '');

        if (empty($code) || empty($type)) {
            $this->show_error('参数错误'); return;
        }

        if (!in_array($type, $this->supported_types)) {
            $this->show_error('不支持的登录方式'); return;
        }

        $callback_url = $this->get_api_url('callback', ['type' => $type, 'code' => $code]);

        $response = wp_remote_get($callback_url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            $this->show_error('请求失败: ' . $response->get_error_message()); return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body) || $body['code'] !== 0) {
            $this->show_error('登录失败: ' . ($body['msg'] ?? '未知错误')); return;
        }

        $social_uid = sanitize_text_field($body['social_uid'] ?? '');
        if (empty($social_uid)) {
            $this->show_error('获取用户信息失败'); return;
        }

        if ($is_bind) {
            $this->process_bind($type, $social_uid, $body);
        } else {
            $this->process_login($type, $social_uid, $body);
        }
    }

    /**
     * 处理登录回调
     * 已绑定 -> 直接登录
     * 未绑定 -> 根据设置自动创建账号或显示绑定表单
     */
    private function process_login($type, $social_uid, $body) {
        global $wpdb;

        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$this->table_name} WHERE social_type = %s AND social_uid = %s",
            $type, $social_uid
        ));

        if ($user_id) {
            // 已绑定，更新 token 并登录
            $wpdb->update($this->table_name, [
                'access_token' => sanitize_text_field($body['access_token'] ?? ''),
                'nickname' => sanitize_text_field($body['nickname'] ?? ''),
                'faceimg' => esc_url_raw($body['faceimg'] ?? '')
            ], ['user_id' => $user_id, 'social_type' => $type], ['%s', '%s', '%s'], ['%d', '%s']);

            $user = get_user_by('id', $user_id);
            if ($user) {
                wp_set_auth_cookie($user_id, true);
                wp_set_current_user($user_id);
                $this->show_success_and_close('登录成功', admin_url());
            } else {
                $this->show_error('用户不存在');
            }
            return;
        }

        // 未绑定
        $auto_register = get_option('kina_social_auto_register', '1');
        if ($auto_register === '1') {
            // 自动创建账号
            $this->show_auto_register_form($type, $social_uid, $body);
        } else {
            // 手动绑定已有账号
            $this->show_bind_form($type, $social_uid, $body);
        }
    }

    /**
     * 自动注册/创建账号页面
     */
    private function show_auto_register_form($type, $social_uid, $body) {
        $bind_key = wp_generate_password(32, false);
        $transient_data = [
            'type' => $type,
            'social_uid' => $social_uid,
            'access_token' => $body['access_token'] ?? '',
            'nickname' => $body['nickname'] ?? '',
            'faceimg' => $body['faceimg'] ?? ''
        ];
        set_transient($this->transient_prefix . $bind_key, $transient_data, 300);

        $ajax_url = admin_url('admin-post.php');
        $label = $this->get_type_label($type);
        $color = $this->get_type_color($type);
        $icon = $this->get_type_fa_icon($type);
        $avatar = esc_url($body['faceimg'] ?? '');
        $nickname = esc_html($body['nickname'] ?? '未知用户');
        $suggested_username = sanitize_user($body['nickname'] ?? '', true);

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>快速登录 - <?php echo esc_html($label); ?></title>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh; display: flex; align-items: center; justify-content: center;
                    padding: 20px;
                }
                .box {
                    background: #fff; padding: 40px; border-radius: 16px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.15); width: 100%; max-width: 460px;
                }
                .social-header { text-align: center; margin-bottom: 25px; }
                .social-header .icon {
                    width: 64px; height: 64px; border-radius: 50%;
                    display: flex; align-items: center; justify-content: center;
                    margin: 0 auto 15px; color: #fff; font-size: 28px;
                    background: <?php echo esc_attr($color); ?>;
                }
                .social-header h2 { font-size: 20px; color: #333; margin-bottom: 5px; }
                .social-header p { color: #666; font-size: 14px; }
                .social-user {
                    display: flex; align-items: center; gap: 12px;
                    padding: 15px; background: #f8f9fa; border-radius: 10px; margin-bottom: 25px;
                }
                .social-user img { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; }
                .social-user .info { flex: 1; }
                .social-user .name { font-weight: 600; color: #333; }
                .social-user .tag { font-size: 12px; color: #888; margin-top: 2px; }
                .tabs { display: flex; gap: 0; margin-bottom: 25px; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0; }
                .tab-btn {
                    flex: 1; padding: 12px; border: none; background: #f5f5f5;
                    cursor: pointer; font-size: 14px; color: #666; font-weight: 500;
                    transition: all 0.2s;
                }
                .tab-btn.active { background: <?php echo esc_attr($color); ?>; color: #fff; }
                .tab-btn:hover:not(.active) { background: #eee; }
                .tab-panel { display: none; }
                .tab-panel.active { display: block; }
                .form-group { margin-bottom: 16px; }
                .form-group label { display: block; margin-bottom: 6px; font-size: 14px; color: #555; font-weight: 500; }
                .form-group input {
                    width: 100%; padding: 12px 15px; border: 1px solid #ddd;
                    border-radius: 8px; font-size: 14px; transition: border 0.2s;
                }
                .form-group input:focus { outline: none; border-color: <?php echo esc_attr($color); ?>; }
                .submit-btn {
                    width: 100%; padding: 14px; border: none; border-radius: 8px;
                    background: <?php echo esc_attr($color); ?>; color: #fff;
                    font-size: 15px; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
                }
                .submit-btn:hover { opacity: 0.9; }
                .submit-btn.secondary {
                    background: #f5f5f5; color: #333; border: 1px solid #ddd;
                }
                .submit-btn.secondary:hover { background: #eee; }
                .hint { text-align: center; margin-top: 15px; font-size: 13px; color: #888; }
                .error-msg {
                    background: #fff2f0; border: 1px solid #ffccc7;
                    color: #cf1322; padding: 10px 15px; border-radius: 8px;
                    margin-bottom: 15px; font-size: 13px; display: none;
                }
            </style>
        </head>
        <body>
            <div class="box">
                <div class="social-header">
                    <div class="icon"><i class="<?php echo esc_attr($icon); ?>"></i></div>
                    <h2>快速登录</h2>
                    <p>首次使用 <?php echo esc_html($label); ?> 登录</p>
                </div>
                <div class="social-user">
                    <?php if ($avatar): ?>
                        <img src="<?php echo $avatar; ?>" alt="头像">
                    <?php else: ?>
                        <div style="width:48px;height:48px;border-radius:50%;background:<?php echo esc_attr($color); ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;">
                            <i class="<?php echo esc_attr($icon); ?>"></i>
                        </div>
                    <?php endif; ?>
                    <div class="info">
                        <div class="name"><?php echo $nickname; ?></div>
                        <div class="tag"><?php echo esc_html($label); ?> 用户</div>
                    </div>
                </div>

                <div class="tabs">
                    <button type="button" class="tab-btn active" onclick="switchTab('register')" id="tab-register">
                        <i class="fa-solid fa-user-plus"></i> 创建新账号
                    </button>
                    <button type="button" class="tab-btn" onclick="switchTab('bind')" id="tab-bind">
                        <i class="fa-solid fa-link"></i> 绑定已有账号
                    </button>
                </div>

                <div class="error-msg" id="errorMsg"></div>

                <!-- 创建新账号 -->
                <div class="tab-panel active" id="panel-register">
                    <form method="post" action="<?php echo esc_url($ajax_url); ?>" onsubmit="return kinaSubmit(this, '创建失败');">
                        <input type="hidden" name="action" value="kina_social_auto_register">
                        <input type="hidden" name="bind_key" value="<?php echo esc_attr($bind_key); ?>">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" name="username" value="<?php echo esc_attr($suggested_username); ?>" placeholder="设置用户名" required>
                        </div>
                        <div class="form-group">
                            <label>邮箱（可选）</label>
                            <input type="email" name="email" placeholder="用于找回密码">
                        </div>
                        <button type="submit" class="submit-btn">
                            <i class="fa-solid fa-bolt"></i> 一键创建并登录
                        </button>
                    </form>
                </div>

                <!-- 绑定已有账号 -->
                <div class="tab-panel" id="panel-bind">
                    <form method="post" action="<?php echo esc_url($ajax_url); ?>" onsubmit="return kinaSubmit(this, '绑定失败');">
                        <input type="hidden" name="action" value="kina_social_do_bind">
                        <input type="hidden" name="bind_key" value="<?php echo esc_attr($bind_key); ?>">
                        <div class="form-group">
                            <label>用户名或邮箱</label>
                            <input type="text" name="username" placeholder="请输入用户名或邮箱" required>
                        </div>
                        <div class="form-group">
                            <label>密码</label>
                            <input type="password" name="password" placeholder="请输入密码" required>
                        </div>
                        <button type="submit" class="submit-btn secondary">
                            <i class="fa-solid fa-link"></i> 确认绑定并登录
                        </button>
                    </form>
                </div>
            </div>
            <script>
            function switchTab(tab) {
                document.querySelectorAll('.tab-btn').forEach(function(btn) { btn.classList.remove('active'); });
                document.querySelectorAll('.tab-panel').forEach(function(panel) { panel.classList.remove('active'); });
                document.getElementById('tab-' + tab).classList.add('active');
                document.getElementById('panel-' + tab).classList.add('active');
                document.getElementById('errorMsg').style.display = 'none';
            }
            function kinaSubmit(form, fallbackError) {
                var errorMsg = document.getElementById('errorMsg');
                errorMsg.style.display = 'none';
                var btn = form.querySelector('.submit-btn');
                var originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> 处理中...';
                btn.disabled = true;
                var formData = new FormData(form);
                fetch(form.getAttribute('action'), { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    if (html.indexOf('window.opener') !== -1 || html.indexOf('成功') !== -1) {
                        document.open(); document.write(html); document.close();
                    } else {
                        var match = html.match(/<p>([^<]+)<\/p>/);
                        errorMsg.textContent = match ? match[1] : fallbackError + '，请重试';
                        errorMsg.style.display = 'block';
                        btn.innerHTML = originalText; btn.disabled = false;
                    }
                })
                .catch(function(e) {
                    errorMsg.textContent = '网络错误，请重试';
                    errorMsg.style.display = 'block';
                    btn.innerHTML = originalText; btn.disabled = false;
                });
                return false;
            }
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * 处理自动注册
     */
    public function handle_auto_register() {
        $bind_key = sanitize_text_field($_POST['bind_key'] ?? '');
        $username = sanitize_text_field($_POST['username'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($bind_key) || empty($username)) {
            $this->show_bind_error('请填写用户名'); return;
        }

        $social_data = get_transient($this->transient_prefix . $bind_key);
        if (empty($social_data)) {
            $this->show_bind_error('登录已过期，请重新扫码'); return;
        }

        // 检查用户名是否已存在
        if (username_exists($username)) {
            $this->show_bind_error('该用户名已被占用，请更换'); return;
        }
        if ($email && email_exists($email)) {
            $this->show_bind_error('该邮箱已被注册'); return;
        }

        $type = $social_data['type'];
        $social_uid = $social_data['social_uid'];

        global $wpdb;

        // 检查社交账号是否已被绑定
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$this->table_name} WHERE social_type = %s AND social_uid = %s",
            $type, $social_uid
        ));
        if ($existing) {
            $this->show_bind_error('该社交账号已被绑定'); return;
        }

        // 创建 WordPress 用户
        $random_password = wp_generate_password(16, true, true);
        $user_data = [
            'user_login' => $username,
            'user_pass'  => $random_password,
            'user_nicename' => $username,
            'display_name' => $social_data['nickname'] ?: $username,
            'role' => 'subscriber'
        ];
        if ($email) {
            $user_data['user_email'] = $email;
        }

        $user_id = wp_insert_user($user_data);
        if (is_wp_error($user_id)) {
            $this->show_bind_error('创建账号失败: ' . $user_id->get_error_message()); return;
        }

        // 写入社交绑定表
        $result = $wpdb->insert($this->table_name, [
            'user_id' => $user_id,
            'social_type' => $type,
            'social_uid' => $social_uid,
            'access_token' => sanitize_text_field($social_data['access_token'] ?? ''),
            'nickname' => sanitize_text_field($social_data['nickname'] ?? ''),
            'faceimg' => esc_url_raw($social_data['faceimg'] ?? '')
        ], ['%d', '%s', '%s', '%s', '%s', '%s']);

        if ($result === false) {
            // 回滚：删除刚创建的用户
            wp_delete_user($user_id);
            $this->show_bind_error('绑定失败: ' . $wpdb->last_error);
            return;
        }

        delete_transient($this->transient_prefix . $bind_key);

        wp_set_auth_cookie($user_id, true);
        wp_set_current_user($user_id);

        $this->show_success_and_close('账号创建成功，正在登录...', admin_url());
    }

    /**
     * 绑定已有账号页面
     */
    private function show_bind_form($type, $social_uid, $body) {
        $bind_key = wp_generate_password(32, false);
        $transient_data = [
            'type' => $type,
            'social_uid' => $social_uid,
            'access_token' => $body['access_token'] ?? '',
            'nickname' => $body['nickname'] ?? '',
            'faceimg' => $body['faceimg'] ?? ''
        ];
        set_transient($this->transient_prefix . $bind_key, $transient_data, 300);

        $do_bind_url = admin_url('admin-post.php');
        $label = $this->get_type_label($type);
        $color = $this->get_type_color($type);
        $icon = $this->get_type_fa_icon($type);
        $avatar = esc_url($body['faceimg'] ?? '');
        $nickname = esc_html($body['nickname'] ?? '未知用户');

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>关联账号 - <?php echo esc_html($label); ?></title>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh; display: flex; align-items: center; justify-content: center;
                    padding: 20px;
                }
                .box {
                    background: #fff; padding: 40px; border-radius: 16px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.15); width: 100%; max-width: 420px;
                }
                .social-header { text-align: center; margin-bottom: 30px; }
                .social-header .icon {
                    width: 64px; height: 64px; border-radius: 50%;
                    display: flex; align-items: center; justify-content: center;
                    margin: 0 auto 15px; color: #fff; font-size: 28px;
                    background: <?php echo esc_attr($color); ?>;
                }
                .social-header h2 { font-size: 20px; color: #333; margin-bottom: 5px; }
                .social-header p { color: #666; font-size: 14px; }
                .social-user {
                    display: flex; align-items: center; gap: 12px;
                    padding: 15px; background: #f8f9fa; border-radius: 10px; margin-bottom: 25px;
                }
                .social-user img { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; }
                .social-user .info { flex: 1; }
                .social-user .name { font-weight: 600; color: #333; }
                .social-user .tag { font-size: 12px; color: #888; margin-top: 2px; }
                .divider { display: flex; align-items: center; gap: 12px; margin: 20px 0; color: #999; font-size: 13px; }
                .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e0e0e0; }
                .form-group { margin-bottom: 18px; }
                .form-group label { display: block; margin-bottom: 6px; font-size: 14px; color: #555; font-weight: 500; }
                .form-group input {
                    width: 100%; padding: 12px 15px; border: 1px solid #ddd;
                    border-radius: 8px; font-size: 14px; transition: border 0.2s;
                }
                .form-group input:focus { outline: none; border-color: <?php echo esc_attr($color); ?>; }
                .submit-btn {
                    width: 100%; padding: 14px; border: none; border-radius: 8px;
                    background: <?php echo esc_attr($color); ?>; color: #fff;
                    font-size: 15px; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
                }
                .submit-btn:hover { opacity: 0.9; }
                .hint { text-align: center; margin-top: 20px; font-size: 13px; color: #888; }
                .error-msg {
                    background: #fff2f0; border: 1px solid #ffccc7;
                    color: #cf1322; padding: 10px 15px; border-radius: 8px;
                    margin-bottom: 15px; font-size: 13px; display: none;
                }
            </style>
        </head>
        <body>
            <div class="box">
                <div class="social-header">
                    <div class="icon"><i class="<?php echo esc_attr($icon); ?>"></i></div>
                    <h2>关联已有账号</h2>
                    <p>该 <?php echo esc_html($label); ?> 账号尚未绑定</p>
                </div>
                <div class="social-user">
                    <?php if ($avatar): ?>
                        <img src="<?php echo $avatar; ?>" alt="头像">
                    <?php else: ?>
                        <div style="width:48px;height:48px;border-radius:50%;background:<?php echo esc_attr($color); ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;">
                            <i class="<?php echo esc_attr($icon); ?>"></i>
                        </div>
                    <?php endif; ?>
                    <div class="info">
                        <div class="name"><?php echo $nickname; ?></div>
                        <div class="tag"><?php echo esc_html($label); ?> 用户</div>
                    </div>
                </div>
                <div class="divider">绑定到已有账号</div>
                <div class="error-msg" id="errorMsg"></div>
                <form method="post" action="<?php echo esc_url($do_bind_url); ?>" onsubmit="return kinaDoBind(this);">
                    <input type="hidden" name="action" value="kina_social_do_bind">
                    <input type="hidden" name="bind_key" value="<?php echo esc_attr($bind_key); ?>">
                    <div class="form-group">
                        <label>用户名或邮箱</label>
                        <input type="text" name="username" placeholder="请输入用户名或邮箱" required>
                    </div>
                    <div class="form-group">
                        <label>密码</label>
                        <input type="password" name="password" placeholder="请输入密码" required>
                    </div>
                    <button type="submit" class="submit-btn">
                        <i class="fa-solid fa-link"></i> 确认绑定并登录
                    </button>
                </form>
                <div class="hint">还没有账号？请联系管理员创建账号后再绑定</div>
            </div>
            <script>
            function kinaDoBind(form) {
                var errorMsg = document.getElementById('errorMsg');
                errorMsg.style.display = 'none';
                var btn = form.querySelector('.submit-btn');
                var originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> 处理中...';
                btn.disabled = true;
                var formData = new FormData(form);
                fetch(form.getAttribute('action'), { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    if (html.indexOf('window.opener') !== -1 || html.indexOf('成功') !== -1) {
                        document.open(); document.write(html); document.close();
                    } else {
                        var match = html.match(/<p>([^<]+)<\/p>/);
                        errorMsg.textContent = match ? match[1] : '绑定失败，请重试';
                        errorMsg.style.display = 'block';
                        btn.innerHTML = originalText; btn.disabled = false;
                    }
                })
                .catch(function(e) {
                    errorMsg.textContent = '网络错误，请重试';
                    errorMsg.style.display = 'block';
                    btn.innerHTML = originalText; btn.disabled = false;
                });
                return false;
            }
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * 处理绑定已有账号
     */
    public function handle_do_bind() {
        $bind_key = sanitize_text_field($_POST['bind_key'] ?? '');
        $username = sanitize_text_field($_POST['username'] ?? '');
        $password = sanitize_text_field($_POST['password'] ?? '');

        if (empty($bind_key) || empty($username) || empty($password)) {
            $this->show_bind_error('请填写完整信息'); return;
        }

        $social_data = get_transient($this->transient_prefix . $bind_key);
        if (empty($social_data)) {
            $this->show_bind_error('绑定已过期，请重新扫码'); return;
        }

        $user = wp_authenticate($username, $password);
        if (is_wp_error($user)) {
            $this->show_bind_error('用户名或密码错误'); return;
        }

        $user_id = $user->ID;
        $type = $social_data['type'];
        $social_uid = $social_data['social_uid'];

        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$this->table_name} WHERE social_type = %s AND social_uid = %s",
            $type, $social_uid
        ));

        if ($existing && $existing != $user_id) {
            $this->show_bind_error('该社交账号已被其他用户绑定'); return;
        }

        $current_bind = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE user_id = %d AND social_type = %s",
            $user_id, $type
        ));

        if ($current_bind) {
            $result = $wpdb->update($this->table_name, [
                'social_uid' => $social_uid,
                'access_token' => sanitize_text_field($social_data['access_token'] ?? ''),
                'nickname' => sanitize_text_field($social_data['nickname'] ?? ''),
                'faceimg' => esc_url_raw($social_data['faceimg'] ?? '')
            ], ['id' => $current_bind], ['%s', '%s', '%s', '%s'], ['%d']);
        } else {
            $result = $wpdb->insert($this->table_name, [
                'user_id' => $user_id,
                'social_type' => $type,
                'social_uid' => $social_uid,
                'access_token' => sanitize_text_field($social_data['access_token'] ?? ''),
                'nickname' => sanitize_text_field($social_data['nickname'] ?? ''),
                'faceimg' => esc_url_raw($social_data['faceimg'] ?? '')
            ], ['%d', '%s', '%s', '%s', '%s', '%s']);
        }

        if ($result === false) {
            $this->show_bind_error('数据库写入失败: ' . $wpdb->last_error);
            return;
        }

        delete_transient($this->transient_prefix . $bind_key);

        wp_set_auth_cookie($user_id, true);
        wp_set_current_user($user_id);

        $this->show_success_and_close('绑定成功，正在登录...', admin_url());
    }

    /**
     * 后台绑定回调处理
     */
    private function process_bind($type, $social_uid, $body) {
        if (!is_user_logged_in()) { $this->show_error('请先登录'); return; }

        $user_id = get_current_user_id();
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$this->table_name} WHERE social_type = %s AND social_uid = %s",
            $type, $social_uid
        ));

        if ($existing && $existing != $user_id) { $this->show_error('该社交账号已被其他用户绑定'); return; }

        $current_bind = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE user_id = %d AND social_type = %s",
            $user_id, $type
        ));

        if ($current_bind) {
            $wpdb->update($this->table_name, [
                'social_uid' => $social_uid,
                'access_token' => sanitize_text_field($body['access_token'] ?? ''),
                'nickname' => sanitize_text_field($body['nickname'] ?? ''),
                'faceimg' => esc_url_raw($body['faceimg'] ?? '')
            ], ['id' => $current_bind], ['%s', '%s', '%s', '%s'], ['%d']);
        } else {
            $wpdb->insert($this->table_name, [
                'user_id' => $user_id,
                'social_type' => $type,
                'social_uid' => $social_uid,
                'access_token' => sanitize_text_field($body['access_token'] ?? ''),
                'nickname' => sanitize_text_field($body['nickname'] ?? ''),
                'faceimg' => esc_url_raw($body['faceimg'] ?? '')
            ], ['%d', '%s', '%s', '%s', '%s', '%s']);
        }

        $this->show_success_and_close('绑定成功');
    }

    private function show_bind_error($message) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>绑定失败</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
                .box { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 2px 20px rgba(0,0,0,0.1); text-align: center; max-width: 400px; }
                .box h2 { color: #d63638; margin-bottom: 15px; }
                .box p { color: #666; margin-bottom: 20px; }
                .box button { padding: 10px 24px; border: none; border-radius: 6px; background: #2271b1; color: #fff; cursor: pointer; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="box">
                <h2>❌ 绑定失败</h2>
                <p><?php echo esc_html($message); ?></p>
                <button onclick="history.back()">返回重试</button>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    private function show_success_and_close($message, $redirect_url = '') {
        ?>
        <!DOCTYPE html>
        <html>
        <head><title>成功</title></head>
        <body>
            <script>
                if (window.opener) {
                    <?php if ($redirect_url): ?>
                    window.opener.location.href = '<?php echo esc_js($redirect_url); ?>';
                    <?php else: ?>
                    window.opener.location.reload();
                    <?php endif; ?>
                }
                window.close();
            </script>
            <p style="text-align:center;padding:50px;font-family:sans-serif;"><?php echo esc_html($message); ?>，窗口即将关闭...</p>
        </body>
        </html>
        <?php
        exit;
    }

    private function show_error($message) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>错误</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
                .box { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 2px 20px rgba(0,0,0,0.1); text-align: center; max-width: 400px; }
                .box h2 { color: #d63638; margin-bottom: 15px; }
                .box p { color: #666; }
            </style>
        </head>
        <body>
            <div class="box">
                <h2>❌ 出错了</h2>
                <p><?php echo esc_html($message); ?></p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

Kina_Social_Login::get_instance();
