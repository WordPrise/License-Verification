<?php
/*
 * Plugin Name:       License Verification
 * Description:       A robust WordPress solution to manage and verify licenses for driving, physical goods, and digital products with a sleek admin interface and intuitive frontend system.
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Version:           1.0
 * Author:            Wordprise
 * Author URI:        https://wordprise.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       license-verification
 *
 * @package           License Verification
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LV_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LV_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LV_OWNER_API_URL', 'https://wordprise.com/license-api.php');

// Include necessary files
$class_file = LV_PLUGIN_DIR . 'includes/class-license-verification.php';
if (!file_exists($class_file)) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p><strong>License Verification Plugin Error:</strong> Required file <code>includes/class-license-verification.php</code> is missing. Please ensure all plugin files are uploaded correctly.</p></div>';
    });
    return;
}
require_once $class_file;

// Initialize the plugin
function lv_init() {
    $plugin = new License_Verification();
    $plugin->init();
}
add_action('plugins_loaded', 'lv_init');

// Enqueue styles for admin
function lv_enqueue_admin_styles() {
    wp_enqueue_style('lv-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', [], null);
    
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && (strpos($screen->id, 'license-verification') !== false || 
        strpos($screen->id, 'lv-form-builder') !== false || 
        strpos($screen->id, 'lv-settings') !== false || 
        strpos($screen->id, 'lv-license') !== false)) {
        wp_enqueue_style('lv-admin-style', LV_PLUGIN_URL . 'assets/css/admin-style.css', [], '1.1.5');
    }
}
add_action('admin_enqueue_scripts', 'lv_enqueue_admin_styles');

// Enqueue styles for frontend
function lv_enqueue_frontend_styles() {
    wp_enqueue_style('lv-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', [], null);
}
add_action('wp_enqueue_scripts', 'lv_enqueue_frontend_styles');

// Enqueue scripts
function lv_enqueue_scripts() {
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-sortable');
}
add_action('admin_enqueue_scripts', 'lv_enqueue_scripts');
add_action('wp_enqueue_scripts', 'lv_enqueue_scripts');

// Function to check license status and expiry (unchanged)
function lv_is_plugin_activated($force_check = false) {
    $license_key = get_option('lv_license_key', '');
    $license_status = get_option('lv_license_status', 'inactive');
    $license_expiry = get_option('lv_license_expiry', 0);

    if (empty($license_key)) {
        update_option('lv_license_status', 'inactive');
        return false;
    }

    if ($license_status === 'active' && time() >= $license_expiry) {
        update_option('lv_license_status', 'inactive');
        set_transient('lv_license_alert', 'Your license has expired. Please renew it to continue using premium features. Existing entries remain intact.', 0);
        return false;
    }

    $last_check = get_transient('lv_last_license_check');
    if ($force_check || !$last_check || (time() - $last_check > 86400)) {
        $response = wp_remote_post(LV_OWNER_API_URL, [
            'body' => [
                'action' => 'verify',
                'license_key' => $license_key,
                'site_url' => home_url()
            ],
            'timeout' => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('License Check Error: ' . $error_message);
            if ($license_status === 'active' && time() < $license_expiry) {
                return true;
            }
            set_transient('lv_license_alert', 'Unable to verify license: ' . $error_message, 0);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('License Check Response - Code: ' . $response_code . ', Body: ' . $body);

        if ($response_code !== 200) {
            set_transient('lv_license_alert', 'Server returned HTTP ' . $response_code . ': ' . $body, 0);
            return ($license_status === 'active' && time() < $license_expiry);
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data)) {
            set_transient('lv_license_alert', 'Invalid server response: ' . ($body ?: 'Empty'), 0);
            return ($license_status === 'active' && time() < $license_expiry);
        }

        if (isset($data['status']) && $data['status'] === 'valid' && isset($data['expiry']) && isset($data['site_url'])) {
            $expiry = strtotime($data['expiry']);
            if ($expiry === false || $expiry <= time()) {
                error_log('Invalid or expired date: ' . $data['expiry']);
                set_transient('lv_license_alert', 'License key is expired or invalid.', 0);
                update_option('lv_license_status', 'inactive');
                update_option('lv_license_expiry', 0);
                update_option('lv_license_expiry_string', '');
                return false;
            }

            if (!empty($data['site_url']) && $data['site_url'] !== home_url()) {
                set_transient('lv_license_alert', 'License key is invalid or already used.', 0);
                update_option('lv_license_status', 'inactive');
                return false;
            }

            update_option('lv_license_key', $license_key);
            update_option('lv_license_status', 'active');
            update_option('lv_license_expiry', $expiry);
            update_option('lv_license_expiry_string', $data['expiry']);
            update_option('lv_registered_site_url', $data['site_url']);
            delete_transient('lv_license_alert');
            set_transient('lv_last_license_check', time(), 86400);
            lv_report_usage_time($license_key);
            return true;
        } else {
            $message = $data['message'] ?? 'License key is invalid or already used.';
            set_transient('lv_license_alert', $message, 0);
            update_option('lv_license_status', 'inactive');
            update_option('lv_license_expiry', 0);
            update_option('lv_license_expiry_string', '');
            set_transient('lv_last_license_check', time(), 86400);
            return false;
        }
    }

    return ($license_status === 'active' && time() < $license_expiry);
}

// Report usage time to owner's database (unchanged)
function lv_report_usage_time($license_key) {
    $last_reported = get_option('lv_last_usage_report', 0);
    $now = time();

    if ($now - $last_reported > 86400) {
        $response = wp_remote_post(LV_OWNER_API_URL, [
            'body' => [
                'action' => 'report-usage',
                'license_key' => $license_key,
                'site_url' => home_url(),
                'timestamp' => $now
            ],
            'timeout' => 5,
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            update_option('lv_last_usage_report', $now);
        }
    }
}


// Fetch all ad banners from owner's database
function lv_fetch_ad_banner() {
    $response = wp_remote_post(LV_OWNER_API_URL, [
        'body' => [
            'action' => 'get_ad_banner'
        ],
        'timeout' => 5,
        'sslverify' => true,
    ]);

    if (is_wp_error($response)) {
        error_log('Ad Banner Fetch Error: ' . $response->get_error_message());
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($response_code !== 200 || empty($body)) {
        error_log('Ad Banner Fetch Failed - Code: ' . $response_code . ', Body: ' . $body);
        return false;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['banners']) || !is_array($data['banners']) || empty($data['banners'])) {
        error_log('Ad Banner Invalid Response: ' . $body);
        return false;
    }

    // Sanitize and return all banners
    $banners = [];
    foreach ($data['banners'] as $banner) {
        if (!empty($banner['banner_url']) && !empty($banner['target_url'])) {
            $banners[] = [
                'banner_url' => esc_url_raw($banner['banner_url']),
                'target_url' => esc_url_raw($banner['target_url'])
            ];
        }
    }
    return $banners;
}
// Admin menu
function lv_admin_menu() {
    add_menu_page(
        'License Verification',
        'License Verification',
        'manage_options',
        'license-verification',
        'lv_entries_page_wrapper',
        'dashicons-id-alt',
        30
    );
    add_submenu_page(
        'license-verification',
        'Entries',
        'Entries',
        'manage_options',
        'license-verification',
        'lv_entries_page_wrapper'
    );
    add_submenu_page(
        'license-verification',
        'Form Builder',
        'Form Builder',
        'manage_options',
        'lv-form-builder',
        'lv_form_page_wrapper'
    );
    add_submenu_page(
        'license-verification',
        'Settings',
        'Settings',
        'manage_options',
        'lv-settings',
        'lv_settings_page_wrapper'
    );
    add_submenu_page(
        'license-verification',
        'License',
        'License',
        'manage_options',
        'lv-license',
        'lv_license_page_wrapper'
    );
    add_submenu_page(
        'license-verification',
        'Credits',
        'Credits',
        'manage_options',
        'lv-credits',
        'lv_credits_page_wrapper'
    );

    // Add Upgrade submenu conditionally
    $license_status = get_option('lv_license_status', 'inactive');
    $license_expiry = get_option('lv_license_expiry', 0);
    $is_free_or_expired = !lv_is_plugin_activated() || $license_status === 'inactive' || (time() >= $license_expiry);
    if ($is_free_or_expired) {
        add_submenu_page(
            'license-verification',
            'Upgrade',
            '<span style="color: #ffeb3b;">Upgrade</span>',
            'manage_options',
            'lv-upgrade',
            'lv_upgrade_page_wrapper' // Replaced redirect with a page wrapper function
        );
    }
}
add_action('admin_menu', 'lv_admin_menu');

// Upgrade page wrapper
// Upgrade page wrapper
function lv_upgrade_page_wrapper() {
    $is_activated = lv_is_plugin_activated();
    $banners = lv_fetch_ad_banner();
    ?>
    <div class="lv-wrap lv-admin">
        <?php if ($banners && !empty($banners)) : ?>
            <div class="lv-ad-space" id="lv-ad-space-upgrade">
                <a href="<?php echo $banners[0]['target_url']; ?>" target="_blank" id="lv-ad-link-upgrade">
                    <img src="<?php echo $banners[0]['banner_url']; ?>" alt="Advertisement" class="lv-ad-banner" id="lv-ad-image-upgrade">
                </a>
            </div>
            <script type="text/javascript">
                (function() {
                    const banners = <?php echo json_encode($banners); ?>;
                    let currentIndex = 0;
                    const adSpace = document.getElementById('lv-ad-space-upgrade');
                    const adLink = document.getElementById('lv-ad-link-upgrade');
                    const adImage = document.getElementById('lv-ad-image-upgrade');

                    function rotateBanner() {
                        currentIndex = (currentIndex + 1) % banners.length;
                        adLink.href = banners[currentIndex].target_url;
                        adImage.src = banners[currentIndex].banner_url;
                    }

                    if (banners.length > 1) {
                        setInterval(rotateBanner, 10000); // Rotate every 10 seconds
                    }
                })();
            </script>
        <?php endif; ?>
        <h1 class="lv-title">Upgrade to Premium <?php echo $is_activated ? '<span class="lv-premium-badge">Premium</span>' : '<span class="lv-free-badge">Free</span>'; ?></h1>
        <div class="lv-tab-content">
            <div class="lv-upgrade-action" style="background: #fff; padding: 10px; display: flex; align-items: center; gap: 10px;">
                <h3 style="margin: 0;">Looking for a premium license, or renew your license?</h3>
                <a href="https://wordprise.com/license-verification-plugin/" target="_blank" class="lv-button lv-button-primary">Click here</a>
            </div>
        </div>
    </div>
    <style>
        .lv-ad-space { display: block; width: 728px; max-width: 100%; height: 90px; margin: 0 auto 20px auto; text-align: center; }
        .lv-ad-space a { display: inline-block; }
        .lv-ad-banner { width: 100%; max-width: 728px; height: 90px; object-fit: cover; }
        @media (max-width: 768px) { 
            .lv-ad-space { width: 100%; height: auto; padding: 10px 0; }
            .lv-ad-banner { width: 100%; height: auto; max-width: 728px; } 
            .lv-upgrade-action { flex-direction: column; text-align: center; }
        }
    </style>
    <?php
}
// Display license alert at the top of admin pages (unchanged)
function lv_display_license_alert() {
    if ($alert = get_transient('lv_license_alert')) {
        echo '<div class="notice notice-error is-dismissible"><p><strong>License Verification Alert:</strong> ' . esc_html($alert) . ' Please check the <a href="' . esc_url(admin_url('admin.php?page=lv-license')) . '">License page</a>.</p></div>';
    }
}
add_action('admin_notices', 'lv_display_license_alert');

// Replace the lv_entries_page_wrapper function
function lv_entries_page_wrapper() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        auth_redirect();
        return;
    }

    $is_activated = lv_is_plugin_activated();
    $banners = lv_fetch_ad_banner();
    ?>
    <div class="lv-wrap lv-admin">
        <?php if ($banners && !empty($banners)) : ?>
            <div class="lv-ad-space" id="lv-ad-space-entries">
                <a href="<?php echo $banners[0]['target_url']; ?>" target="_blank" id="lv-ad-link-entries">
                    <img src="<?php echo $banners[0]['banner_url']; ?>" alt="Advertisement" class="lv-ad-banner" id="lv-ad-image-entries">
                </a>
            </div>
            <script type="text/javascript">
                (function() {
                    const banners = <?php echo json_encode($banners); ?>;
                    let currentIndex = 0;
                    const adSpace = document.getElementById('lv-ad-space-entries');
                    const adLink = document.getElementById('lv-ad-link-entries');
                    const adImage = document.getElementById('lv-ad-image-entries');

                    function rotateBanner() {
                        currentIndex = (currentIndex + 1) % banners.length;
                        adLink.href = banners[currentIndex].target_url;
                        adImage.src = banners[currentIndex].banner_url;
                    }

                    if (banners.length > 1) {
                        setInterval(rotateBanner, 10000); // Rotate every 10 seconds
                    }
                })();
            </script>
        <?php endif; ?>
        <h1 class="lv-title">Entries <?php echo $is_activated ? '<span class="lv-premium-badge">Premium</span>' : '<span class="lv-free-badge">Free</span>'; ?></h1>
        <div class="lv-tab-content">
            <div class="lv-section"><?php lv_entries_page($is_activated); ?></div>
        </div>
    </div>
    <?php
}

// Replace the lv_form_page_wrapper function
function lv_form_page_wrapper() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        auth_redirect();
        return;
    }

    $is_activated = lv_is_plugin_activated();
    $banners = lv_fetch_ad_banner();
    ?>
    <div class="lv-wrap lv-admin">
        <?php if ($banners && !empty($banners)) : ?>
            <div class="lv-ad-space" id="lv-ad-space-form">
                <a href="<?php echo $banners[0]['target_url']; ?>" target="_blank" id="lv-ad-link-form">
                    <img src="<?php echo $banners[0]['banner_url']; ?>" alt="Advertisement" class="lv-ad-banner" id="lv-ad-image-form">
                </a>
            </div>
            <script type="text/javascript">
                (function() {
                    const banners = <?php echo json_encode($banners); ?>;
                    let currentIndex = 0;
                    const adSpace = document.getElementById('lv-ad-space-form');
                    const adLink = document.getElementById('lv-ad-link-form');
                    const adImage = document.getElementById('lv-ad-image-form');

                    function rotateBanner() {
                        currentIndex = (currentIndex + 1) % banners.length;
                        adLink.href = banners[currentIndex].target_url;
                        adImage.src = banners[currentIndex].banner_url;
                    }

                    if (banners.length > 1) {
                        setInterval(rotateBanner, 10000); // Rotate every 10 seconds
                    }
                })();
            </script>
        <?php endif; ?>
        <h1 class="lv-title">Form Builder <?php echo $is_activated ? '<span class="lv-premium-badge">Premium</span>' : '<span class="lv-free-badge">Free</span>'; ?></h1>
        <div class="lv-tab-content">
            <div class="lv-section">
                <?php lv_form_page($is_activated); ?>
            </div>
        </div>
    </div>
    <?php
}

// Replace the lv_settings_page_wrapper function
function lv_settings_page_wrapper() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        auth_redirect();
        return;
    }

    $is_activated = lv_is_plugin_activated();
    $banners = lv_fetch_ad_banner();
    ?>
    <div class="lv-wrap lv-admin">
        <?php if ($banners && !empty($banners)) : ?>
            <div class="lv-ad-space" id="lv-ad-space-settings">
                <a href="<?php echo $banners[0]['target_url']; ?>" target="_blank" id="lv-ad-link-settings">
                    <img src="<?php echo $banners[0]['banner_url']; ?>" alt="Advertisement" class="lv-ad-banner" id="lv-ad-image-settings">
                </a>
            </div>
            <script type="text/javascript">
                (function() {
                    const banners = <?php echo json_encode($banners); ?>;
                    let currentIndex = 0;
                    const adSpace = document.getElementById('lv-ad-space-settings');
                    const adLink = document.getElementById('lv-ad-link-settings');
                    const adImage = document.getElementById('lv-ad-image-settings');

                    function rotateBanner() {
                        currentIndex = (currentIndex + 1) % banners.length;
                        adLink.href = banners[currentIndex].target_url;
                        adImage.src = banners[currentIndex].banner_url;
                    }

                    if (banners.length > 1) {
                        setInterval(rotateBanner, 10000); // Rotate every 10 seconds
                    }
                })();
            </script>
        <?php endif; ?>
        <h1 class="lv-title">Settings <?php echo $is_activated ? '<span class="lv-premium-badge">Premium</span>' : '<span class="lv-free-badge">Free</span>'; ?></h1>
        <div class="lv-tab-content">
            <div class="lv-section">
                <?php lv_settings_page(); ?>
            </div>
        </div>
    </div>
    <?php
}

// Replace the lv_license_page_wrapper function
function lv_license_page_wrapper() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        auth_redirect();
        return;
    }

    $is_activated = lv_is_plugin_activated();
    $banners = lv_fetch_ad_banner();
    ?>
    <div class="lv-wrap lv-admin">
        <?php if ($banners && !empty($banners)) : ?>
            <div class="lv-ad-space" id="lv-ad-space-license">
                <a href="<?php echo $banners[0]['target_url']; ?>" target="_blank" id="lv-ad-link-license">
                    <img src="<?php echo $banners[0]['banner_url']; ?>" alt="Advertisement" class="lv-ad-banner" id="lv-ad-image-license">
                </a>
            </div>
            <script type="text/javascript">
                (function() {
                    const banners = <?php echo json_encode($banners); ?>;
                    let currentIndex = 0;
                    const adSpace = document.getElementById('lv-ad-space-license');
                    const adLink = document.getElementById('lv-ad-link-license');
                    const adImage = document.getElementById('lv-ad-image-license');

                    function rotateBanner() {
                        currentIndex = (currentIndex + 1) % banners.length;
                        adLink.href = banners[currentIndex].target_url;
                        adImage.src = banners[currentIndex].banner_url;
                    }

                    if (banners.length > 1) {
                        setInterval(rotateBanner, 10000); // Rotate every 10 seconds
                    }
                })();
            </script>
        <?php endif; ?>
        <h1 class="lv-title">License <?php echo $is_activated ? '<span class="lv-premium-badge">Premium</span>' : '<span class="lv-free-badge">Free</span>'; ?></h1>
        <div class="lv-tab-content">
            <div class="lv-section"><?php lv_license_page(); ?></div>
        </div>
    </div>
    <?php
}

// Replace the lv_credits_page_wrapper function
function lv_credits_page_wrapper() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        auth_redirect();
        return;
    }

    $is_activated = lv_is_plugin_activated();
    $banners = lv_fetch_ad_banner();
    ?>
    <div class="lv-wrap lv-admin">
        <?php if ($banners && !empty($banners)) : ?>
            <div class="lv-ad-space" id="lv-ad-space-credits">
                <a href="<?php echo $banners[0]['target_url']; ?>" target="_blank" id="lv-ad-link-credits">
                    <img src="<?php echo $banners[0]['banner_url']; ?>" alt="Advertisement" class="lv-ad-banner" id="lv-ad-image-credits">
                </a>
            </div>
            <script type="text/javascript">
                (function() {
                    const banners = <?php echo json_encode($banners); ?>;
                    let currentIndex = 0;
                    const adSpace = document.getElementById('lv-ad-space-credits');
                    const adLink = document.getElementById('lv-ad-link-credits');
                    const adImage = document.getElementById('lv-ad-image-credits');

                    function rotateBanner() {
                        currentIndex = (currentIndex + 1) % banners.length;
                        adLink.href = banners[currentIndex].target_url;
                        adImage.src = banners[currentIndex].banner_url;
                    }

                    if (banners.length > 1) {
                        setInterval(rotateBanner, 10000); // Rotate every 10 seconds
                    }
                })();
            </script>
        <?php endif; ?>
        <h1 class="lv-title">Credits <?php echo $is_activated ? '<span class="lv-premium-badge">Premium</span>' : '<span class="lv-free-badge">Free</span>'; ?></h1>
        <div class="lv-tab-content">
            <div class="lv-section"><?php lv_credits_page(); ?></div>
        </div>
    </div>
    <?php
}
// Credits page
function lv_credits_page() {
    $plugin_version = '1.1.5'; // Current version from plugin header
    $last_updated = 'April 08, 2025'; // Hardcoded as per current date; adjust as needed
    ?>
    <div>
    <div class="lv-credits-container">
        <div class="lv-credits-container">
                <div class="lv-credits-section">
            <h3>About Plugin</h3>
            <p><strong>Plugin Name:</strong> License Verification</p>
            <p><strong>Description:</strong> Our all-in-one license verification plugin provides seamless solutions for businesses across all sectors, including education, manufacturing, digital marketing, and government agencies, ensuring secure, efficient, and reliable license management for every industry need. It simplifies verification processes with a user-friendly interface. The plugin supports diverse license types, from driving to digital products. It ensures compliance and authenticity effortlessly. Businesses benefit from robust features tailored to their needs. Whether small or large, organizations trust our plugin for accuracy. It’s designed to save time and enhance security. Experience seamless license management today.</p>
            <p><strong>Version:</strong> <?php echo esc_html($plugin_version); ?></p>
            <p><strong>Compatibility:</strong> Works with WordPress 5.0+ and popular themes like Astra and Elementor.</p>
        </div>
        
        <div class="lv-credits-section">
            <h3>Special Thanks</h3>
            <ul class="lv-credits-list">
                <li>WordPress Community - For the platform and support.</li>
                <li>Users - For feedback and encouragement.</li>
            </ul>
        </div>
        
    </div>
</div>
    <style>
		.lv-ad-space { display: block; width: 728px; max-width: 100%; height: 90px; margin: 0 auto 20px auto; text-align: center; }
.lv-ad-space a { display: inline-block; }
.lv-ad-banner { width: 100%; max-width: 728px; height: 90px; object-fit: cover; }
@media (max-width: 768px) { .lv-ad-space { width: 100%; height: auto; padding: 10px 0; }
    .lv-ad-banner { width: 100%; height: auto; max-width: 728px; } }
        .lv-section { background: #fff; padding: 24px; margin-bottom: 40px; }
        .lv-credits-container { max-width: 800px; margin: 0 auto; }
        .lv-credits-section { margin-bottom: 30px; }
        .lv-credits-section h3 { font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 12px; }
        .lv-credits-section p { font-size: 14px; color: #4a5568; margin-bottom: 10px; }
        .lv-credits-section p strong { color: #1a202c; }
        .lv-credits-list { list-style: none; padding: 0; }
        .lv-credits-list li { font-size: 14px; color: #4a5568; margin-bottom: 8px; padding-left: 20px; position: relative; }
        .lv-credits-list li:before { content: "✓"; color: #38a169; position: absolute; left: 0; }
        .lv-credits-section a { color: #3182ce; text-decoration: none; }
        .lv-credits-section a:hover { text-decoration: underline; }
    </style>
    <?php
}
// Clear authentication on logout (unchanged)
function lv_clear_authentication_on_logout($user_id) {
    delete_user_meta($user_id, 'lv_plugin_authenticated');
}

function lv_form_page($is_activated) {
    $default_fields = [
        ['label' => 'Name', 'type' => 'text', 'required' => true],
        ['label' => 'Father Name', 'type' => 'text', 'required' => true],
        ['label' => 'City', 'type' => 'text', 'required' => true],
        ['label' => 'Country', 'type' => 'text', 'required' => true],
        ['label' => 'License Number', 'type' => 'text', 'required' => true],
    ];
    $form_fields = get_option('lv_form_fields', $default_fields);
    if (empty($form_fields) || !is_array($form_fields)) {
        update_option('lv_form_fields', $default_fields);
        $form_fields = $default_fields;
    }

    $form_name = get_option('lv_form_name', 'License Verification');
    if (isset($_POST['lv_save_form_name'])) {
        $new_form_name = sanitize_text_field($_POST['form_name']);
        if (!empty($new_form_name)) {
            update_option('lv_form_name', $new_form_name);
            $form_name = $new_form_name;
            echo "<div class='lv-updated'><p>Form name updated successfully.</p></div>";
        }
    }

    if (isset($_POST['lv_save_form_fields']) && $is_activated) {
        $fields = [];
        if (!empty($_POST['field_labels']) && !empty($_POST['field_types'])) {
            $labels = array_map('sanitize_text_field', $_POST['field_labels']);
            $types = array_map('sanitize_text_field', $_POST['field_types']);
            $required = isset($_POST['field_required']) ? array_map('sanitize_text_field', $_POST['field_required']) : [];

            for ($i = 0; $i < count($labels); $i++) {
                if (!empty($labels[$i]) && !empty($types[$i])) {
                    $fields[] = [
                        'label' => $labels[$i],
                        'type' => $types[$i],
                        'required' => in_array($i, $required) ? true : false,
                    ];
                }
            }
        }
        update_option('lv_form_fields', $fields);
        echo "<div class='lv-updated'><p>Form fields updated successfully.</p></div>";
        $form_fields = $fields;
    }

    if (isset($_POST['lv_save_retrieval_field'])) {
        $new_retrieval_field = sanitize_text_field($_POST['retrieval_field']);
        if (!$is_activated && $new_retrieval_field !== 'License Number') {
            echo "<div class='lv-error'><p>Free version requires Retrieval Field to be set to 'License Number'.</p></div>";
        } else {
            update_option('lv_retrieval_field', $new_retrieval_field);
            echo "<div class='lv-updated'><p>Retrieval field setting updated successfully.</p></div>";
        }
    }

    $retrieval_field = get_option('lv_retrieval_field', 'License Number');
    if (!$is_activated && $retrieval_field !== 'License Number') {
        update_option('lv_retrieval_field', 'License Number');
        $retrieval_field = 'License Number';
    }
    ?>
    <div>
        <h2 class="lv-card-title" style="color: #016CEC;">Form Name</h2><br>
        <form method="post" action="" class="lv-form">
            <div class="lv-form-group">
                <label for="form_name">Form Name:</label>
                <input type="text" name="form_name" id="form_name" value="<?php echo esc_attr($form_name); ?>" placeholder="Enter form name" required class="lv-input">
            </div>
            <div class="lv-form-actions">
                <input type="submit" name="lv_save_form_name" class="lv-button lv-button-primary" value="Save Form Name" />
            </div>
        </form>
    </div>
    <br>
    <hr style="border-top: 3px dotted #bbb;">
    <br>
    <div>
        <h2 class="lv-card-title" style="color: #016CEC;">Customize Form Fields</h2><br>
        <?php if (!$is_activated) : ?>
            <p class="lv-premium-alert">Requires Premium Version</p>
        <?php endif; ?>
        <?php if ($is_activated) : ?>
            <form method="post" action="" class="lv-form">
                <div id="lv-form-fields" class="lv-form-fields-container">
                    <?php if (!empty($form_fields)) : ?>
                        <?php foreach ($form_fields as $index => $field) : ?>
                            <div class="lv-form-field-item" data-index="<?php echo $index; ?>">
                                <span class="lv-drag-handle">☰</span>
                                <div class="lv-form-group">
                                    <input type="text" name="field_labels[]" value="<?php echo esc_attr($field['label']); ?>" placeholder="Field Label" required class="lv-input">
                                </div>
                                <div class="lv-form-group">
                                    <select name="field_types[]" required class="lv-select">
                                        <option value="text" <?php selected($field['type'], 'text'); ?>>Text</option>
                                        <option value="number" <?php selected($field['type'], 'number'); ?>>Number</option>
                                        <option value="date" <?php selected($field['type'], 'date'); ?>>Date</option>
                                        <option value="file" <?php selected($field['type'], 'file'); ?>>File Upload</option>
                                        <option value="email" <?php selected($field['type'], 'email'); ?>>Email</option>
                                        <option value="url" <?php selected($field['type'], 'url'); ?>>Website</option>
                                    </select>
                                </div>
                                <div class="lv-form-group lv-checkbox-group">
                                    <label class="lv-checkbox-label">
                                        <input type="checkbox" name="field_required[]" value="<?php echo $index; ?>" <?php checked($field['required'], true); ?> class="lv-checkbox">
                                        Required
                                    </label>
                                </div>
                                <span class="lv-remove-field">✖</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p>No fields available. Please add a new field.</p>
                    <?php endif; ?>
                </div>
                <div class="lv-form-actions">
                    <button type="button" id="lv-add-field" class="lv-button lv-button-secondary">Add New Field</button>
                    <input type="submit" name="lv_save_form_fields" class="lv-button lv-button-primary" value="Save Form" />
                </div>
            </form>
        <?php endif; ?>
    </div>
    <br>
    <hr style="border-top: 3px dotted #bbb;">
    <br>
<div>
        <h2 class="lv-card-title" style="color: #016CEC;">Retrieval Setting</h2><br>
        <form method="post" action="" class="lv-form">
            <?php if ($is_activated) : ?>
                <div class="lv-form-group">
                    <label for="retrieval_field">Retrieve User Data Using:</label>
                    <select name="retrieval_field" id="retrieval_field" required class="lv-select">
                        <?php foreach ($form_fields as $field) : ?>
                            <?php if ($field['type'] !== 'file') : ?>
                                <option value="<?php echo esc_attr($field['label']); ?>" <?php selected($retrieval_field, $field['label']); ?>><?php echo esc_html($field['label']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lv-form-actions">
                    <input type="submit" name="lv_save_retrieval_field" class="lv-button lv-button-primary" value="Save Setting" />
                </div>
            <?php else : ?>
                <p class="lv-premium-alert">Requires Premium Version</p>
            <?php endif; ?>
        </form>
    </div>

    <style>
		
        .lv-premium-alert { font-size: 14px; color: #e53e3e; margin-top: 5px; }
        .lv-premium-notice { font-size: 12px; color: #e53e3e; margin-top: 5px; }
          .lv-ad-space { display: block; width: 728px; max-width: 100%; height: 90px; margin: 0 auto 20px auto; text-align: center; }
        .lv-ad-space a { display: inline-block; }
        .lv-ad-banner { width: 100%; max-width: 728px; height: 90px; object-fit: cover; }
        @media (max-width: 768px) { .lv-ad-space { width: 100%; height: auto; padding: 10px 0; }
            .lv-ad-banner { width: 100%; height: auto; max-width: 728px; } }
        .lv-section { background: #fff; padding: 24px; margin-bottom: 40px; }
        .lv-form-fields-container { margin-bottom: 20px; }
        .lv-form-field-item { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 12px; }
        .lv-drag-handle { font-size: 18px; color: #718096; cursor: move; padding: 0 8px; }
        .lv-form-group { flex: 1; min-width: 0; }
        .lv-input { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #1a202c; background: #fff; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        .lv-input:focus { border-color: #3182ce; outline: none; box-shadow: 0 0 0 2px rgba(49,130,206,0.2); }
        .lv-select { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #1a202c; background: #fff; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        .lv-select:focus { border-color: #3182ce; outline: none; box-shadow: 0 0 0 2px rgba(49,130,206,0.2); }
        .lv-checkbox-group { display: flex; align-items: center; }
        .lv-checkbox-label { display: flex; align-items: center; gap: 6px; font-size: 14px; color: #4a5568; }
        .lv-checkbox { width: 16px; height: 16px; }
        .lv-remove-field { font-size: 18px; color: #e53e3e; cursor: pointer; padding: 0 8px; transition: color 0.2s ease; }
        .lv-remove-field:hover { color: #c53030; }
        .lv-form-actions { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .lv-button-secondary { background: #edf2f7; color: #4a5568; border: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease; }
        .lv-button-secondary:hover { background: #e2e8f0; }
        .lv-form-field-placeholder { border: 2px dashed #d1d5db; border-radius: 6px; height: 60px; opacity: 0.5; }
        @media (max-width: 768px) {
            .lv-form-field-item { flex-direction: column; align-items: stretch; gap: 10px; }
            .lv-form-group { margin-bottom: 0; }
            .lv-form-actions { flex-direction: column; align-items: stretch; }
        }
    </style>
    <script>
        jQuery(document).ready(function($) {
            $('#lv-form-fields').sortable({
                handle: '.lv-drag-handle',
                placeholder: 'lv-form-field-placeholder',
                update: function(event, ui) {
                    $('.lv-form-field-item').each(function(index) {
                        $(this).attr('data-index', index);
                        $(this).find('input[name="field_required[]"]').val(index);
                    });
                }
            });

            $('#lv-add-field').on('click', function() {
                var index = $('.lv-form-field-item').length;
                var newField = `
                    <div class="lv-form-field-item" data-index="${index}">
                        <span class="lv-drag-handle">☰</span>
                        <div class="lv-form-group">
                            <input type="text" name="field_labels[]" placeholder="Field Label" required class="lv-input">
                        </div>
                        <div class="lv-form-group">
                            <select name="field_types[]" required class="lv-select">
                                <option value="text">Text</option>
                                <option value="number">Number</option>
                                <option value="date">Date</option>
                                <option value="file">File Upload</option>
                                <option value="email">Email</option>
                                <option value="url">Website</option>
                            </select>
                        </div>
                        <div class="lv-form-group lv-checkbox-group">
                            <label class="lv-checkbox-label">
                                <input type="checkbox" name="field_required[]" value="${index}" class="lv-checkbox">
                                Required
                            </label>
                        </div>
                        <span class="lv-remove-field">✖</span>
                    </div>`;
                $('#lv-form-fields').append(newField);
                updateRetrievalFieldDropdown();
            });

            $(document).on('click', '.lv-remove-field', function() {
                $(this).closest('.lv-form-field-item').remove();
                $('.lv-form-field-item').each(function(index) {
                    $(this).attr('data-index', index);
                    $(this).find('input[name="field_required[]"]').val(index);
                });
                updateRetrievalFieldDropdown();
            });

            function updateRetrievalFieldDropdown() {
                var $dropdown = $('#retrieval_field');
                var currentValue = $dropdown.val();
                $dropdown.empty();
                $('.lv-form-field-item').each(function() {
                    var label = $(this).find('input[name="field_labels[]"]').val();
                    var type = $(this).find('select[name="field_types[]"]').val();
                    if (label && type !== 'file') {
                        var option = $('<option></option>').val(label).text(label);
                        if (label === currentValue) {
                            option.prop('selected', true);
                        }
                        $dropdown.append(option);
                    }
                });
            }

            $(document).on('input', 'input[name="field_labels[]"]', updateRetrievalFieldDropdown);
            $(document).on('change', 'select[name="field_types[]"]', updateRetrievalFieldDropdown);
            updateRetrievalFieldDropdown();

            const alerts = document.querySelectorAll('.lv-updated, .lv-error');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 3000);
            });
        });
    </script>
    <?php
}
// Entries page
function lv_entries_page($is_activated) {
    // Default fields for free users
    $default_fields = [
        ['label' => 'Name', 'type' => 'text', 'required' => true],
        ['label' => 'Father Name', 'type' => 'text', 'required' => true],
        ['label' => 'City', 'type' => 'text', 'required' => true],
        ['label' => 'Country', 'type' => 'text', 'required' => true],
        ['label' => 'License Number', 'type' => 'text', 'required' => true],
    ];
    
    // Use custom fields for premium users, default fields for free users
    $form_fields = $is_activated ? get_option('lv_form_fields', $default_fields) : $default_fields;
    
    // Get current entries
    $entries = get_option('lv_entries', []);
    if (!is_array($entries)) {
        $entries = [];
    }
    $entry_count = count($entries);

    
    $free_entry_limit = (int)base64_decode('MTA=');
    $can_add_entry = $is_activated || $entry_count < $free_entry_limit;

    if (empty($form_fields)) {
        echo "<div class='lv-error'><p>No form fields defined. Please configure the form fields in the Form Builder page (requires premium version).</p></div>";
        return;
    }

    // Handle entry addition
    if (isset($_POST['lv_add_entry']) && $can_add_entry) {
        $entry = [];
        $has_error = false;
        $error_messages = [];

        foreach ($form_fields as $field) {
            $field_label = $field['label'];
            $field_type = $field['type'];
            $field_name = sanitize_key(str_replace(' ', '_', strtolower($field_label)));

            if ($field_type === 'file' && $is_activated) { // File uploads only for premium
                if (!empty($_FILES[$field_name]['name'])) {
                    $upload_overrides = ['test_form' => false];
                    $uploaded_file = wp_handle_upload($_FILES[$field_name], $upload_overrides);
                    if (isset($uploaded_file['error'])) {
                        $has_error = true;
                        $error_messages[] = "Error uploading file for " . esc_html($field_label) . ": " . esc_html($uploaded_file['error']);
                    } else {
                        $entry[$field_label] = $uploaded_file['url'];
                    }
                } elseif ($field['required']) {
                    $has_error = true;
                    $error_messages[] = esc_html($field_label) . " is required.";
                } else {
                    $entry[$field_label] = '';
                }
            } else {
                $value = isset($_POST[$field_name]) ? sanitize_text_field($_POST[$field_name]) : '';
                if ($field['required'] && empty($value)) {
                    $has_error = true;
                    $error_messages[] = esc_html($field_label) . " is required.";
                } else {
                    $entry[$field_label] = $value;
                }
            }
        }

        if (!$has_error) {
            $entries[] = $entry;
            update_option('lv_entries', $entries);
            echo "<div class='lv-updated'><p>Entry added successfully.</p></div>";
        } else {
            foreach ($error_messages as $message) {
                echo "<div class='lv-error'><p>" . $message . "</p></div>";
            }
        }
    } elseif (isset($_POST['lv_add_entry']) && !$can_add_entry) {
        echo "<div class='lv-error'><p>Free version limit reached (10 entries). Upgrade to premium for unlimited entries.</p></div>";
    }

    // Handle bulk delete, single delete, and edit logic (unchanged)
// Inside lv_entries_page function, replace the bulk delete logic:
if (isset($_POST['lv_bulk_delete']) && !empty($_POST['entry_ids']) && current_user_can('manage_options')) {
    $entry_ids = array_map('intval', $_POST['entry_ids']);
    $new_entries = array_values($entries); // Clone to maintain integrity
    $deleted = false;
    foreach ($entry_ids as $entry_id) {
        if (isset($new_entries[$entry_id])) {
            foreach ($form_fields as $field) {
                if ($field['type'] === 'file' && !empty($new_entries[$entry_id][$field['label']])) {
                    $upload_dir = wp_upload_dir();
                    $image_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $new_entries[$entry_id][$field['label']]);
                    if (file_exists($image_path)) {
                        unlink($image_path);
                    }
                }
            }
            unset($new_entries[$entry_id]);
            $deleted = true;
        }
    }
    if ($deleted) {
        $new_entries = array_values($new_entries); // Re-index after deletion
        update_option('lv_entries', $new_entries);
        $entries = get_option('lv_entries', []); // Refresh $entries to reflect the update
        echo "<div class='lv-updated'><p>Selected entries deleted successfully.</p></div>";
    } else {
        echo "<div class='lv-error'><p>No valid entries selected for deletion.</p></div>";
    }
}
 // Inside lv_entries_page function, replace the single delete logic:
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['entry_id']) && current_user_can('manage_options') && empty($_POST)) {
    $entry_id = intval($_GET['entry_id']);
    if (isset($entries[$entry_id])) {
        foreach ($form_fields as $field) {
            if ($field['type'] === 'file' && !empty($entries[$entry_id][$field['label']])) {
                $upload_dir = wp_upload_dir();
                $image_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $entries[$entry_id][$field['label']]);
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
            }
        }
        unset($entries[$entry_id]);
        $entries = array_values($entries);
        update_option('lv_entries', $entries);
        echo "<div class='lv-updated'><p>Entry deleted successfully.</p></div>";
    } else {
        echo "<div class='lv-error'><p>Invalid entry ID.</p></div>";
    }
}

    $edit_entry = null;
    $edit_entry_id = null;
    if (isset($_POST['lv_edit_entry']) && $is_activated) {
        $entry_id = intval($_POST['entry_id']);
        if (isset($entries[$entry_id])) {
            $updated_entry = [];
            foreach ($form_fields as $field) {
                $field_label = $field['label'];
                $field_name = sanitize_key(str_replace(' ', '_', strtolower($field_label)));
                if ($field['type'] === 'file' && !empty($_FILES[$field_name]['name'])) {
                    $upload = wp_handle_upload($_FILES[$field_name], ['test_form' => false]);
                    if (isset($upload['url'])) {
                        if (!empty($entries[$entry_id][$field_label])) {
                            $upload_dir = wp_upload_dir();
                            $old_image_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $entries[$entry_id][$field_label]);
                            if (file_exists($old_image_path)) {
                                unlink($old_image_path);
                            }
                        }
                        $updated_entry[$field_label] = $upload['url'];
                    }
                } elseif (isset($_POST[$field_name])) {
                    $updated_entry[$field_label] = sanitize_text_field($_POST[$field_name]);
                } else {
                    $updated_entry[$field_label] = $entries[$entry_id][$field_label] ?? '';
                }
            }
            $entries[$entry_id] = $updated_entry;
            update_option('lv_entries', $entries);
            echo "<div class='lv-updated'><p>Entry updated successfully.</p></div>";
        }
    } elseif (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['entry_id']) && $is_activated) {
        $edit_entry_id = intval($_GET['entry_id']);
        if (isset($entries[$edit_entry_id])) {
            $edit_entry = $entries[$edit_entry_id];
        }
    }

    ?>
    <div>
        <h2 class="lv-card-title" style="color: #016CEC;"><?php echo $edit_entry ? 'Edit Entry' : 'Add New Entry'; ?></h2>
        <form method="post" action="" enctype="multipart/form-data" class="lv-form lv-entry-form">
            <?php if ($edit_entry) : ?>
                <input type="hidden" name="entry_id" value="<?php echo $edit_entry_id; ?>">
            <?php endif; ?>
            <div class="lv-form-grid">
                <?php foreach ($form_fields as $field) {
                    $field_label = $field['label'];
                    $field_type = $field['type'];
                    $field_name = sanitize_key(str_replace(' ', '_', strtolower($field_label)));
                    $value = $edit_entry && isset($edit_entry[$field_label]) ? $edit_entry[$field_label] : '';
                    ?>
                    <div class="lv-form-group">
                        <label for="<?php echo esc_attr($field_name); ?>">
                            <?php echo esc_html($field_label); ?>
                            <?php echo $field['required'] ? '<span class="lv-required">*</span>' : ''; ?>
                        </label>
                        <?php if ($field_type === 'file' && $is_activated) : ?>
                            <?php if ($value) : ?>
                                <div class="lv-entry-image-preview">
                                    <img src="<?php echo esc_url($value); ?>" alt="<?php echo esc_attr($field_label); ?>">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" class="lv-file-input">
                        <?php else : ?>
                            <input type="<?php echo esc_attr($field_type); ?>" name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr($value); ?>" <?php echo $field['required'] ? 'required' : ''; ?> class="lv-input">
                        <?php endif; ?>
                    </div>
                <?php } ?>
            </div>
            <div class="lv-form-actions">
                <input type="submit" name="<?php echo $edit_entry ? 'lv_edit_entry' : 'lv_add_entry'; ?>" class="lv-button lv-button-primary" value="<?php echo $edit_entry ? 'Update Entry' : 'Add Entry'; ?>" <?php echo !$can_add_entry && !$edit_entry ? 'disabled' : ''; ?>>
                <?php if (!$is_activated) : ?>
                    <p class="lv-free-notice">Free version limited to 10 entries (<?php echo $entry_count; ?>/10 used). Upgrade to premium for unlimited entries and editing.</p>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Existing Entries Table (unchanged) -->
    <br>
    <hr style="border-top: 3px dotted #bbb;">
    <br>
    <div>
        <h2 class="lv-card-title" style="color: #016CEC;">Existing License Entries</h2><br>
        <?php if (!empty($entries)) : ?>
            <form method="post" action="" class="lv-entries-form">
                <div class="lv-entries-table-container">
                    <table class="lv-entries-table">
                        <thead>
                            <tr>
                                <th class="lv-table-checkbox"><input type="checkbox" id="lv-select-all" class="lv-checkbox"></th>
                                <?php foreach ($form_fields as $field) {
                                    echo '<th>' . esc_html($field['label']) . ($field['required'] ? ' <span class="lv-required">*</span>' : '') . '</th>';
                                } ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $index => $entry) : ?>
                                <tr class="lv-entry-row">
                                    <td class="lv-table-checkbox">
                                        <input type="checkbox" name="entry_ids[]" value="<?php echo $index; ?>" class="lv-checkbox">
                                    </td>
                                    <?php foreach ($form_fields as $field) : ?>
                                        <?php if ($field['type'] === 'file') : ?>
                                            <td class="lv-image-cell">
                                                <?php if (!empty($entry[$field['label']])) : ?>
                                                    <img src="<?php echo esc_url($entry[$field['label']]); ?>" alt="Entry Image" class="lv-entry-image">
                                                <?php else : ?>
                                                    <img src="https://i.postimg.cc/HWKxyfwh/Image-not-found.png" alt="Image Not Found" class="lv-entry-image lv-placeholder-image">
                                                <?php endif; ?>
                                            </td>
                                        <?php else : ?>
                                            <td><?php echo esc_html($entry[$field['label']] ?? '-'); ?></td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <td class="lv-table-actions">
                                        <div class="lv-actions-wrapper">
                                            <?php if ($is_activated) : ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=license-verification&action=edit&entry_id=' . $index)); ?>" class="lv-action-icon lv-action-edit" title="Edit Entry">✏️</a>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=license-verification&action=delete&entry_id=' . $index)); ?>" class="lv-action-icon lv-action-delete" onclick="return confirm('Are you sure you want to delete this entry?');" title="Delete Entry">⛔</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="lv-bulk-actions">
                    <input type="submit" name="lv_bulk_delete" class="lv-button lv-button-danger" value="Delete Selected" onclick="return confirm('Are you sure you want to delete the selected entries?');">
                </div>
            </form>
        <?php else : ?>
            <p class="lv-no-entries">No entries found. Use the form above to add a new entry.</p>
        <?php endif; ?>
    </div>

    <!-- Add Grid Styles -->
    <style>
        .lv-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .lv-form-group {
            display: flex;
            flex-direction: column;
        }
        .lv-form-group label {
            margin-bottom: 5px;
            font-weight: bold;
        }
        .lv-input, .lv-file-input {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }
        .lv-entry-image-preview img {
            max-width: 100%;
            height: auto;
        }
        .lv-form-actions {
            margin-top: 20px;
        }
        .lv-free-notice {
            color: #d63638;
            font-size: 14px;
            margin-top: 10px;
        }
    </style>
 
    <style>
	.lv-ad-space { display: block; width: 728px; max-width: 100%; height: 90px; margin: 0 auto 20px auto; text-align: center; }
.lv-ad-space a { display: inline-block; }
.lv-ad-banner { width: 100%; max-width: 728px; height: 90px; object-fit: cover; }
@media (max-width: 768px) { .lv-ad-space { width: 100%; height: auto; padding: 10px 0; }
    .lv-ad-banner { width: 100%; height: auto; max-width: 728px; } }
        .lv-entries-table-container { overflow-x: auto; margin-bottom: 24px; border-radius: 8px; }
        .lv-entries-table { width: 100%; border-collapse: collapse; border: 1px solid rgba(203, 213, 224, 0.5); }
        .lv-entries-table th, .lv-entries-table td { padding: 12px 16px; font-size: 14px; font-family: 'Helvetica', sans-serif; color: #1a202c; text-align: center; vertical-align: middle; border: 1px solid rgba(203, 213, 224, 0.5); }
        .lv-entries-table th { background: linear-gradient(135deg, #667eea, #4c51bf); color: #ffffff; font-weight: 600; letter-spacing: 0.2px; border-bottom: 2px solid rgba(76, 81, 191, 0.8); text-transform: uppercase; }
        .lv-entries-table tbody tr { background: #ffffff; transition: background-color 0.2s ease; }
        .lv-entries-table tbody tr:hover { background: #f7fafc; }
        .lv-table-checkbox { width: 50px; }
        .lv-checkbox { width: 16px; height: 16px; accent-color: #3182ce; cursor: pointer; }
        .lv-image-cell { width: 100px; }
        .lv-entry-image { max-width: 60px; max-height: 60px; height: auto; border-radius: 4px; border: 1px solid #d1d5db; object-fit: cover; }
        .lv-placeholder-image { opacity: 0.8; }
        .lv-table-actions { width: 120px; }
        .lv-actions-wrapper { display: flex; justify-content: center; align-items: center; gap: 12px; }
        .lv-action-icon { font-size: 18px; text-decoration: none; transition: color 0.2s ease; }
        .lv-action-edit { color: #3182ce; }
        .lv-action-edit:hover { color: #2b6cb0; }
        .lv-action-delete { color: #e53e3e; }
        .lv-action-delete:hover { color: #c53030; }
        .lv-required { color: #e53e3e; font-size: 14px; }
        .lv-bulk-actions { padding: 16px 0; display: flex; justify-content: flex-start; }
        .lv-button-danger { padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; background: #e53e3e; color: #fff; border: none; transition: background-color 0.2s ease; }
        .lv-button-danger:hover { background: #c53030; }
        .lv-no-entries { font-size: 14px; font-family: 'Helvetica', sans-serif; color: #718096; text-align: center; padding: 20px; }
        @media (max-width: 768px) {
            .lv-entries-table th, .lv-entries-table td { font-size: 13px; padding: 10px 12px; }
            .lv-table-checkbox { width: 40px; }
            .lv-image-cell { width: 80px; }
            .lv-entry-image { max-width: 50px; max-height: 50px; }
            .lv-table-actions { width: 100px; }
            .lv-actions-wrapper { gap: 10px; }
        }
    </style>

    <script>
        jQuery(document).ready(function($) {
            $('#lv-select-all').on('change', function() {
                $('.lv-entries-table tbody input[type="checkbox"]').prop('checked', this.checked);
            });

            const alerts = document.querySelectorAll('.lv-updated, .lv-error');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 3000);
            });
        });
    </script>
    <?php
}
 function lv_settings_page() {
    $is_activated = lv_is_plugin_activated();

    if (isset($_POST['lv_general_settings'])) {
        // Settings all users (free and premium) can save
        $verification_system = sanitize_text_field($_POST['verification_system']);
        update_option('lv_verification_system_active', $verification_system);

        $success_message = sanitize_text_field($_POST['success_message']);
        if (!empty($success_message)) {
            update_option('lv_success_message', $success_message);
        }

        // Premium-only setting
        if ($is_activated) {
            $show_city_eligibility = isset($_POST['show_city_eligibility']) ? 'yes' : 'no';
            update_option('lv_show_city_eligibility', $show_city_eligibility);
        }

        echo "<div class='lv-updated'><p>General settings updated successfully.</p></div>";
    }

    if (isset($_POST['lv_add_city']) && $is_activated) {
        if (!empty($_POST['city_name'])) {
            $city_name = sanitize_text_field($_POST['city_name']);
            $eligibility = sanitize_text_field($_POST['city_eligibility']);
            $cities = get_option('lv_cities', []);
            $cities[] = [
                'name' => $city_name,
                'eligibility' => $eligibility
            ];
            update_option('lv_cities', $cities);
            echo "<div class='lv-updated'><p>City added successfully.</p></div>";
        } else {
            echo "<div class='lv-error'><p>Please enter a city name.</p></div>";
        }
    }

    if (isset($_POST['lv_bulk_remove_cities']) && !empty($_POST['city_ids']) && $is_activated) {
        $city_ids = array_map('intval', $_POST['city_ids']);
        $cities = get_option('lv_cities', []);
        foreach ($city_ids as $id) {
            if (isset($cities[$id])) {
                unset($cities[$id]);
            }
        }
        update_option('lv_cities', array_values($cities));
        echo "<div class='lv-updated'><p>Selected cities removed successfully.</p></div>";
    }

 // Inside lv_settings_page function, replace the single city deletion logic:
if (isset($_GET['action']) && $_GET['action'] === 'remove_city' && isset($_GET['city_id']) && $is_activated && empty($_POST)) {
    $city_id = intval($_GET['city_id']);
    $cities = get_option('lv_cities', []);
    if (isset($cities[$city_id])) {
        unset($cities[$city_id]);
        $cities = array_values($cities); // Re-index array after deletion
        update_option('lv_cities', $cities);
        $cities = get_option('lv_cities', []); // Refresh $cities to reflect the update
        echo "<div class='lv-updated'><p>City removed successfully.</p></div>";
    } else {
        echo "<div class='lv-error'><p>Invalid city ID.</p></div>";
    }
}

    if (isset($_POST['lv_save_template']) && $is_activated) {
        $selected_template = sanitize_text_field($_POST['template']);
        update_option('lv_selected_template', $selected_template);
        echo "<div class='lv-updated'><p>Template updated successfully.</p></div>";
    }

    if (isset($_POST['lv_create_backup']) && $is_activated) {
        $entries = get_option('lv_entries', []);
        if (!empty($entries)) {
            $backup_data = [
                'entries' => $entries,
                'created_at' => current_time('mysql')
            ];
            $backups = get_option('lv_backups', []);
            if (!is_array($backups)) {
                $backups = [];
            }
            $backups[] = $backup_data;
            update_option('lv_backups', $backups);
            echo "<div class='lv-updated'><p>Backup created successfully.</p></div>";
        } else {
            echo "<div class='lv-error'><p>No entries available to backup.</p></div>";
        }
    }

    if (isset($_POST['lv_upload_backup']) && $is_activated) {
        if (!empty($_FILES['backup_file']['name'])) {
            $file = $_FILES['backup_file'];
            $file_content = file_get_contents($file['tmp_name']);
            $backup_data = json_decode($file_content, true);
            if (is_array($backup_data) && isset($backup_data['entries'])) {
                update_option('lv_entries', $backup_data['entries']);
                echo "<div class='lv-updated'><p>Backup uploaded and entries restored successfully.</p></div>";
            } else {
                echo "<div class='lv-error'><p>Invalid backup file format.</p></div>";
            }
        } else {
            echo "<div class='lv-error'><p>Please select a backup file to upload.</p></div>";
        }
    }

    $verification_system_active = get_option('lv_verification_system_active', 'yes');
    $show_city_eligibility = get_option('lv_show_city_eligibility', 'no');
    $cities = get_option('lv_cities', []);
    $selected_template = get_option('lv_selected_template', 'template1');
    $backups = get_option('lv_backups', []);

    ?>
    <div>
        <h2 class="lv-card-title" style="color: #016CEC;">General Settings</h2><br>
        <form method="post" action="" class="lv-form lv-grid-form">
            <div class="lv-grid-container">
                <div class="lv-form-group">
                    <label>Verification System:</label>
                    <div class="lv-radio-group">
                        <label><input type="radio" name="verification_system" value="yes" <?php checked($verification_system_active, 'yes'); ?>> Active</label>
                        <label><input type="radio" name="verification_system" value="no" <?php checked($verification_system_active, 'no'); ?>> Inactive</label>
                    </div>
                </div>
                <div class="lv-form-group">
                    <?php if ($is_activated) : ?>
                        <label><input type="checkbox" name="show_city_eligibility" value="yes" <?php checked($show_city_eligibility, 'yes'); ?>> Show City Eligibility Before Verification Form</label>
                    <?php else : ?>
                        <label>Show City Eligibility Before Verification Form</label>
                        <p class="lv-premium-alert">Requires Premium Version</p>
                    <?php endif; ?>
                </div>
                <div class="lv-form-group">
                    <label for="success_message">Frontend Success Message:</label>
                    <input type="text" name="success_message" id="success_message" value="<?php echo esc_attr(get_option('lv_success_message', 'License verified successfully!')); ?>" placeholder="Enter success message" class="lv-input">
                    <p class="description">Customize the message shown to users when a record is found.</p>
                </div>
            </div>
            <input type="submit" name="lv_general_settings" class="lv-button lv-button-blue lv-button-small" value="Save General Settings">
        </form>
    </div>
    <br>
    <hr style="border-top: 3px dotted #bbb;">
    <br>
    <div>
        <h2 class="lv-card-title" style="color: #016CEC;">City Management</h2><br>
        <?php if ($is_activated) : ?>
            <form method="post" action="" class="lv-form">
                <div class="lv-form-group lv-add-city-group">
                    <h4 style="color: #016CEC;">Add New City</h4>
                    <div class="lv-add-city-fields">
                        <div class="lv-form-subgroup">
                            <label for="city_name" >City Name:</label>
                            <input type="text" name="city_name" id="city_name" class="lv-input" required>
                        </div>
                        <div class="lv-form-subgroup">
                            <label>Eligibility:</label>
                            <div class="lv-radio-group">
                                <label><input type="radio" name="city_eligibility" value="eligible" checked> Eligible</label>
                                <label><input type="radio" name="city_eligibility" value="not_eligible"> Not Eligible</label>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="submit" name="lv_add_city" class="lv-button lv-button-blue lv-button-small" value="Add City">
            </form>
            <br>
            <h4 class="lv-section-subtitle" style="color: #016CEC;">Manage Cities</h4><br>
            <?php if (!empty($cities)) : ?>
                <form method="post" action="" class="lv-entries-form">
                    <div class="lv-entries-table-container">
                        <table class="lv-entries-table">
                            <thead>
                                <tr>
                                    <th class="lv-table-checkbox"><input type="checkbox" id="lv-select-all-cities" class="lv-checkbox"></th>
                                    <th>City Name</th>
                                    <th>Eligibility</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cities as $index => $city) : ?>
                                    <tr class="lv-entry-row">
                                        <td class="lv-table-checkbox">
                                            <input type="checkbox" name="city_ids[]" value="<?php echo $index; ?>" class="lv-checkbox">
                                        </td>
                                        <td><?php echo esc_html($city['name']); ?></td>
                                        <td><?php echo $city['eligibility'] === 'eligible' ? 'Eligible' : 'Not Eligible'; ?></td>
                                        <td class="lv-table-actions">
                                            <div class="lv-actions-wrapper">
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=lv-settings&action=remove_city&city_id=' . $index)); ?>" class="lv-action-icon lv-action-delete" onclick="return confirm('Are you sure you want to remove this city?');" title="Remove City">⛔</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="lv-bulk-actions">
                        <input type="submit" name="lv_bulk_remove_cities" class="lv-button lv-button-danger lv-button-small" value="Remove Selected" onclick="return confirm('Are you sure you want to remove the selected cities?');">
                    </div>
                </form>
            <?php else : ?>
                <p class="lv-no-entries">No cities added yet.</p>
            <?php endif; ?>
        <?php else : ?>
            <p class="lv-premium-alert">Requires Premium Version</p>
        <?php endif; ?>
    </div>
    <br>
    <hr style="border-top: 3px dotted #bbb;">
    <br>
    <div>
        <h2 class="lv-card-title" style="color: #016CEC;">Template Management</h2><br>
        <?php if ($is_activated) : ?>
            <form method="post" action="" class="lv-form">
                <div class="lv-form-group">
                    <label for="template">Choose Template:</label>
                    <select name="template" id="template" class="lv-select">
                        <option value="template1" <?php selected($selected_template, 'template1'); ?>>Template 1</option>
                        <option value="template2" <?php selected($selected_template, 'template2'); ?>>Template 2</option>
                    </select>
                </div>
                <input type="submit" name="lv_save_template" class="lv-button lv-button-blue lv-button-small" value="Save Template">
            </form>
        <?php else : ?>
            <p class="lv-premium-alert">Requires Premium Version</p>
        <?php endif; ?>
    </div>
    <br>
    <hr style="border-top: 3px dotted #bbb;">
    <br>
    <div>
        <h2 class="lv-card-title" style="color: #016CEC;">Backup Management</h2><br>
        <?php if ($is_activated) : ?>
            <div class="lv-backup-container">
                <div class="lv-backup-group">
                    <form method="post" action="" class="lv-form">
                        <h4 style="color: #016CEC;">Create Backup</h4>
                        <p>Create a backup of all current entries.</p>
                        <input type="submit" name="lv_create_backup" class="lv-button lv-button-blue lv-button-small" value="Create Backup">
                    </form>
                </div>
                <div class="lv-backup-group">
                    <h4 class="lv-section-subtitle" style="color: #016CEC;">Backup List</h4><br>
                    <?php if (!empty($backups)) : ?>
                        <div class="lv-entries-table-container">
                            <table class="lv-entries-table">
                                <thead>
                                    <tr>
                                        <th>Backup Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $index => $backup) : ?>
                                        <tr class="lv-entry-row">
                                            <td><?php echo esc_html($backup['created_at']); ?></td>
                                            <td class="lv-table-actions">
                                                <div class="lv-actions-wrapper">
                                                    <a href="<?php echo esc_url(admin_url('admin.php?page=lv-settings&action=download_backup&backup_id=' . $index)); ?>" class="lv-action-icon lv-action-download" title="Download Backup">⬇️</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <p class="lv-no-entries">No backups available yet.</p>
                    <?php endif; ?>
                </div>
                <div class="lv-backup-group">
                    <form method="post" action="" class="lv-form" enctype="multipart/form-data">
                        <h4 style="color: #016CEC;">Upload Backup</h4>
                        <p>Upload a previously created backup file to restore entries.</p>
                        <input type="file" name="backup_file" id="backup_file" class="lv-file-input" accept=".json" required>
                        <input type="submit" name="lv_upload_backup" class="lv-button lv-button-blue lv-button-small" value="Upload Backup">
                    </form>
                </div>
            </div>
        <?php else : ?>
            <p class="lv-premium-alert">Requires Premium Version</p>
        <?php endif; ?>
    </div>

    <style>
        .lv-premium-alert { font-size: 14px; color: #e53e3e; margin-top: 5px; }
        .lv-premium-notice { font-size: 12px; color: #e53e3e; margin-top: 5px; }
    </style>

    <script>
        jQuery(document).ready(function($) {
            const alerts = document.querySelectorAll('.lv-updated, .lv-error');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 3000);
            });

            $('#lv-select-all-cities').on('change', function() {
                $('.lv-entries-table tbody input[type="checkbox"]').prop('checked', this.checked);
            });
        });
    </script>

    <style>
        .lv-ad-space { display: block; width: 728px; max-width: 100%; height: 90px; margin: 0 auto 20px auto; text-align: center; }
        .lv-ad-space a { display: inline-block; }
        .lv-ad-banner { width: 100%; max-width: 728px; height: 90px; object-fit: cover; }
        @media (max-width: 768px) { .lv-ad-space { width: 100%; height: auto; padding: 10px 0; }
            .lv-ad-banner { width: 100%; height: auto; max-width: 728px; } }
        .lv-section { background: #fff; padding: 24px; margin-bottom: 40px; }
        .lv-form { padding: 0; }
        .lv-grid-form .lv-grid-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .lv-grid-form .lv-form-group {
            margin-bottom: 0;
            padding: 15px;
            border: 1px solid rgba(203, 213, 224, 0.5);
            border-radius: 6px;
            background: #fff;
        }
        .lv-backup-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .lv-backup-group {
            padding: 15px;
            border: 1px solid rgba(203, 213, 224, 0.5);
            border-radius: 6px;
            background: #fff;
        }
        .lv-backup-group h4 {
            font-size: 16px;
            font-weight: 500;
            color: #1a202c;
            margin-bottom: 12px;
        }
        .lv-backup-group p {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 12px;
        }
        .lv-form-group { margin-bottom: 20px; }
        .lv-form-group label { font-size: 14px; font-weight: 500; color: #1a202c; display: block; margin-bottom: 6px; }
        .lv-input { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #1a202c; background: #fff; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        .lv-input:focus { border-color: #3182ce; outline: none; box-shadow: 0 0 0 2px rgba(49,130,206,0.2); }
        .lv-file-input { width: 100%; padding: 10px; border: 1px dashed #d1d5db; border-radius: 6px; font-size: 14px; color: #4a5568; background: #f7fafc; cursor: pointer; transition: border-color 0.2s ease; }
        .lv-file-input:hover { border-color: #a0aec0; }
        .lv-file-input:focus { border-color: #3182ce; outline: none; }
        .lv-grid-form .lv-radio-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .lv-radio-group label { display: flex; align-items: center; gap: 6px; font-size: 14px; color: #4a5568; }
        .lv-add-city-group h4 { font-size: 16px; font-weight: 500; color: #1a202c; margin-bottom: 12px; }
        .lv-add-city-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .lv-form-subgroup { display: flex; flex-direction: column; gap: 6px; }
        .lv-section-subtitle { font-size: 16px; font-weight: 500; color: #1a202c; margin: 0 0 10px; }
        .lv-button { display: inline-block; width: 155px; padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease; text-align: center; }
        .lv-button-blue { background: #3182ce; color: #fff; border: none; }
        .lv-button-blue:hover { background: #2b6cb0; }
        .lv-button-danger { background: #e53e3e; color: #fff; border: none; }
        .lv-button-danger:hover { background: #c53030; }
        .lv-button-small { width: 155px; padding: 8px 16px; font-size: 12px; }
        .lv-entries-table-container { overflow-x: auto; margin-bottom: 24px; border-radius: 8px; }
        .lv-entries-table { width: 100%; border-collapse: collapse; border: 1px solid rgba(203, 213, 224, 0.5); }
        .lv-entries-table th, .lv-entries-table td { padding: 12px 16px; font-size: 14px; font-family: 'Helvetica', sans-serif; color: #1a202c; text-align: center; vertical-align: middle; border: 1px solid rgba(203, 213, 224, 0.5); }
        .lv-entries-table th { background: linear-gradient(135deg, #667eea, #4c51bf); color: #ffffff; font-weight: 600; letter-spacing: 0.2px; border-bottom: 2px solid rgba(76, 81, 191, 0.8); text-transform: uppercase; }
        .lv-entries-table tbody tr { background: #ffffff; transition: background-color 0.2s ease; }
        .lv-entries-table tbody tr:hover { background: #f7fafc; }
        .lv-table-checkbox { width: 50px; }
        .lv-checkbox { width: 16px; height: 16px; accent-color: #3182ce; cursor: pointer; }
        .lv-table-actions { width: 120px; }
        .lv-actions-wrapper { display: flex; justify-content: center; align-items: center; gap: 12px; }
        .lv-action-icon { font-size: 18px; text-decoration: none; transition: color 0.2s ease; }
        .lv-action-delete { color: #e53e3e; }
        .lv-action-delete:hover { color: #c53030; }
        .lv-action-download { color: #3182ce; }
        .lv-action-download:hover { color: #2b6cb0; }
        .lv-bulk-actions { padding: 16px 0; display: flex; justify-content: flex-start; }
        .lv-no-entries { font-size: 14px; font-family: 'Helvetica', sans-serif; color: #718096; text-align: center; padding: 20px; }
        @media (max-width: 768px) {
            .lv-grid-form .lv-grid-container {
                grid-template-columns: 1fr;
            }
            .lv-backup-container {
                grid-template-columns: 1fr;
            }
            .lv-add-city-fields { grid-template-columns: 1fr; }
            .lv-entries-table th, .lv-entries-table td { font-size: 13px; padding: 10px 12px; }
            .lv-table-actions { width: 100px; }
        }
    </style>
    <?php
}
// Handle backup download
function lv_handle_backup_download() {
    if (isset($_GET['action']) && $_GET['action'] === 'download_backup' && isset($_GET['backup_id']) && current_user_can('manage_options')) {
        $backup_id = intval($_GET['backup_id']);
        $backups = get_option('lv_backups', []);
        if (isset($backups[$backup_id])) {
            $backup_data = $backups[$backup_id];
            $filename = 'license_verification_backup_' . date('Ymd_His', strtotime($backup_data['created_at'])) . '.json';
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo json_encode($backup_data);
            exit;
        }
    }
}
add_action('admin_init', 'lv_handle_backup_download');
// License page
function lv_license_page() {
    $license_key = get_option('lv_license_key', '');
    $license_status = get_option('lv_license_status', 'inactive');
    $license_expiry = get_option('lv_license_expiry', 0);
    $license_expiry_string = get_option('lv_license_expiry_string', '');

    $is_activated = lv_is_plugin_activated(true);

    if (isset($_POST['lv_activate_license'])) {
        $new_license_key = sanitize_text_field($_POST['license_key']);
        if (empty($new_license_key)) {
            echo "<div class='lv-error'><p>Please enter a license key.</p></div>";
        } else {
            $response = wp_remote_post(LV_OWNER_API_URL, [
                'body' => [
                    'action' => 'verify',
                    'license_key' => $new_license_key,
                    'site_url' => home_url()
                ],
                'timeout' => 15,
                'sslverify' => true,
            ]);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('License Activation Error: ' . $error_message);
                echo "<div class='lv-error'><p>Failed to connect to license server: " . esc_html($error_message) . "</p></div>";
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                error_log('License Activation Response - Code: ' . $response_code . ', Body: ' . $body);

                if ($response_code !== 200) {
                    echo "<div class='lv-error'><p>Server returned HTTP " . esc_html($response_code) . ": " . esc_html($body) . "</p></div>";
                } else {
                    $data = json_decode($body, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                        echo "<div class='lv-error'><p>Invalid server response format: " . esc_html($body ?: 'Empty response') . "</p></div>";
                    } elseif (empty($data)) {
                        echo "<div class='lv-error'><p>Empty server response.</p></div>";
                    } elseif (!isset($data['status']) || $data['status'] !== 'valid') {
                        $message = isset($data['message']) ? $data['message'] : 'License key is invalid or already used.';
                        update_option('lv_license_status', 'inactive');
                        update_option('lv_license_expiry', 0);
                        update_option('lv_license_expiry_string', '');
                        echo "<div class='lv-error'><p>" . esc_html($message) . "</p></div>";
                    } else {
                        $expiry = strtotime($data['expiry']);
                        if ($expiry === false || $expiry <= time()) {
                            update_option('lv_license_status', 'inactive');
                            update_option('lv_license_expiry', 0);
                            update_option('lv_license_expiry_string', '');
                            echo "<div class='lv-error'><p>License key is expired or invalid.</p></div>";
                        } else {
                            update_option('lv_license_key', $new_license_key);
                            update_option('lv_license_status', 'active');
                            update_option('lv_license_expiry', $expiry);
                            update_option('lv_license_expiry_string', $data['expiry']);
                            update_option('lv_registered_site_url', $data['site_url']);
                            delete_transient('lv_license_alert');
                            set_transient('lv_last_license_check', time(), 86400);
                            lv_report_usage_time($new_license_key);
                            echo "<div class='lv-updated'><p>License activated successfully! Expires on: " . esc_html($data['expiry']) . "</p></div>";
                            $license_key = $new_license_key;
                            $license_status = 'active';
                            $license_expiry = $expiry;
                            $license_expiry_string = $data['expiry'];
                            $is_activated = true;
                        }
                    }
                }
            }
        }
    }

    if (isset($_POST['lv_deactivate_license']) && !empty($license_key)) {
        update_option('lv_license_status', 'inactive');
        update_option('lv_license_expiry', 0);
        update_option('lv_license_expiry_string', '');
        update_option('lv_registered_site_url', '');
        delete_transient('lv_last_license_check');
        delete_transient('lv_license_alert');
        echo "<div class='lv-updated'><p>License deactivated successfully.</p></div>";
        $license_status = 'inactive';
        $license_expiry = 0;
        $license_expiry_string = '';
        $is_activated = false;
    }

    ?>
    <div class="lv-wrap lv-admin">
        <h1 class="lv-title" style="color: #016CEC;">License Management</h1>
        <div class="lv-license-columns">
            <div class="lv-license-column">
                <h3 style="color: #016CEC;">Input License Key:</h3>
                <form method="post" action="" class="lv-form">
                    <div class="lv-form-group">
                        <input type="text" name="license_key" id="license_key" value="<?php echo esc_attr($license_key); ?>" placeholder="Enter your license key" required>
                    </div>
                    <div class="lv-form-actions">
                        <?php if ($is_activated) : ?>
                            <input type="submit" name="lv_deactivate_license" class="lv-button lv-button-danger" value="Deactivate License">
                        <?php else : ?>
                            <input type="submit" name="lv_activate_license" class="lv-button lv-button-primary" value="Activate License">
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="lv-license-column">
                <h3 style="color: #016CEC;">License Status</h3>
                <div class="lv-license-status">
                    <p><strong>Status:</strong> <?php echo $is_activated ? '<span class="lv-status-active">Active</span>' : '<span class="lv-status-inactive">Inactive</span>'; ?></p>
                    <?php if ($license_key) : ?>
                        <p><strong>License Key:</strong> <?php echo esc_html($license_key); ?></p>
                    <?php endif; ?>
                    <?php if ($license_expiry_string && $is_activated) : ?>
                        <p><strong>Expires On:</strong> <?php echo esc_html($license_expiry_string); ?></p>
                        <p><strong>Time Remaining:</strong> <span id="lv-countdown"></span></p>
                    <?php endif; ?>
                    <?php if (!$is_activated && $license_expiry > 0 && time() >= $license_expiry) : ?>
                        <p class="lv-expired">Your license has expired on <?php echo esc_html($license_expiry_string); ?>. Please renew it to regain premium features.</p>
                    <?php endif; ?>
                    </div>
            </div>
        </div>
        <hr style="border-top: 3px dotted #bbb; margin: 20px 0;">
        <div class="lv-license-action" style="display: flex; align-items: center; gap: 10px;">
            <h3 style="margin: 0;">Looking for a premium license, or renew your license?</h3>
            <a href="https://wordprise.com/license-verification-plugin/" target="_blank" class="lv-button lv-button-primary">Click here</a>
        </div>
    </div>

    <style>
		.lv-ad-space { display: block; width: 728px; max-width: 100%; height: 90px; margin: 0 auto 20px auto; text-align: center; }
        .lv-ad-space a { display: inline-block; }
        .lv-ad-banner { width: 100%; max-width: 728px; height: 90px; object-fit: cover; }
        @media (max-width: 768px) { 
            .lv-ad-space { width: 100%; height: auto; padding: 10px 0; }
            .lv-ad-banner { width: 100%; height: auto; max-width: 728px; } 
        }
        .lv-section { background: #fff; padding: 24px; margin-bottom: 40px; }
        .lv-license-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 20px; }
        .lv-license-column h3 { font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 16px; }
        .lv-form-group { margin-bottom: 20px; }
        .lv-form-group input { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #1a202c; background: #fff; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        .lv-form-group input:focus { border-color: #3182ce; outline: none; box-shadow: 0 0 0 2px rgba(49,130,206,0.2); }
        .lv-form-actions { margin-top: 20px; }
        .lv-button { padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease; }
        .lv-button-primary { background: #3182ce; color: #fff; border: none; }
        .lv-button-primary:hover { background: #0071e885; }
        .lv-button-danger { background: #e53e3e; color: #fff; border: none; }
        .lv-button-danger:hover { background: #c53030; }
        .lv-license-status p { font-size: 14px; color: #4a5568; margin-bottom: 10px; }
        .lv-license-status strong { color: #1a202c; }
        .lv-status-active { color: #38a169; font-weight: 600; }
        .lv-status-inactive { color: #e53e3e; font-weight: 600; }
        .lv-expired { color: #e53e3e; font-style: italic; }
        @media (max-width: 768px) {
            .lv-license-columns { grid-template-columns: 1fr; }
            .lv-license-action { flex-direction: column; text-align: center; }
        }
    </style>

    <?php if ($license_expiry > 0 && $is_activated) : ?>
    <script>
        function updateCountdown() {
            const expiry = <?php echo $license_expiry; ?> * 1000;
            const now = new Date().getTime();
            const distance = expiry - now;

            if (distance < 0) {
                document.getElementById("lv-countdown").innerHTML = "Expired";
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            document.getElementById("lv-countdown").innerHTML = `${days}d ${hours}h ${minutes}m ${seconds}s`;
        }

        setInterval(updateCountdown, 1000);
        updateCountdown();
    </script>
    <?php endif; ?>

    <script>
        jQuery(document).ready(function($) {
            const alerts = document.querySelectorAll('.lv-updated, .lv-error');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 3000);
            });
        });
    </script>
    <?php
}
// Shortcode for frontend verification form
function lv_verification_shortcode($atts) {
    $atts = shortcode_atts([], $atts, 'license_verification');
    $form_fields = get_option('lv_form_fields', []);
    $retrieval_field = get_option('lv_retrieval_field', 'CNIC');
    $verification_system_active = get_option('lv_verification_system_active', 'yes');
    $show_city_eligibility = get_option('lv_show_city_eligibility', 'no');
    $cities = get_option('lv_cities', []);
    $selected_template = get_option('lv_selected_template', 'template1');
    $form_name = get_option('lv_form_name', 'Verify Your License');
    $login_access_required = get_option('lv_login_access_required', 'yes');

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    ob_start();

    if ($verification_system_active !== 'yes') {
        echo '<p class="lv-frontend-message">Verification system is currently disabled.</p>';
        return ob_get_clean();
    }

    if (empty($form_fields)) {
        echo '<p class="lv-frontend-message">Form fields are not configured. Please contact the site administrator.</p>';
        return ob_get_clean();
    }

    $retrieval_field_exists = false;
    foreach ($form_fields as $field) {
        if ($field['label'] === $retrieval_field && $field['type'] !== 'file') {
            $retrieval_field_exists = true;
            break;
        }
    }

    if (!$retrieval_field_exists) {
        echo '<p class="lv-frontend-message">Retrieval field is not properly configured. Please contact the site administrator.</p>';
        return ob_get_clean();
    }
	
       // Check if city eligibility is enabled and no eligible city has been selected yet
    if ($show_city_eligibility === 'yes' && !empty($cities) && !isset($_SESSION['lv_eligible_city'])) {
        if (!isset($_POST['lv_check_city'])) {
            ?>
            <div class="lv-city-eligibility-wrap">
                <h2 class="lv-city-eligibility-title"><?php echo esc_html($form_name); ?></h2>
                <form method="post" action="" class="lv-city-eligibility-form">
                    <div class="lv-city-form-group">
                        <label for="city_name">Select Your City:</label>
                        <select name="city_name" id="city_name" required class="lv-city-input">
                            <option value="">-- Select City --</option>
                            <?php foreach ($cities as $city) : ?>
                                <option value="<?php echo esc_attr($city['name']); ?>"><?php echo esc_html($city['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="submit" name="lv_check_city" class="lv-city-button-primary" value="Check Eligibility">
                </form>
            </div>
            <?php
        } else {
            $city_name = sanitize_text_field($_POST['city_name']);
            $city_eligible = false;
            foreach ($cities as $city) {
                if ($city['name'] === $city_name && $city['eligibility'] === 'eligible') {
                    $city_eligible = true;
                    break;
                }
            }
            if (!$city_eligible) {
                ?>
                <div class="lv-city-eligibility-wrap">
                    <p class="lv-city-message lv-city-error">Sorry, your state ('<?php echo esc_html($city_name); ?>') is not eligible for verification at this time.</p>
                    <h2 class="lv-city-eligibility-title"><?php echo esc_html($form_name); ?></h2>
                    <form method="post" action="" class="lv-city-eligibility-form">
                        <div class="lv-city-form-group">
                            <label for="city_name">Select Your City:</label>
                            <select name="city_name" id="city_name" required class="lv-city-input">
                                <option value="">-- Select City --</option>
                                <?php foreach ($cities as $city) : ?>
                                    <option value="<?php echo esc_attr($city['name']); ?>"><?php echo esc_html($city['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="submit" name="lv_check_city" class="lv-city-button-primary" value="Check Eligibility">
                    </form>
                </div>
                <script>
                    alert('Sorry, your city ("<?php echo esc_js($city_name); ?>") is not eligible for verification at this time.');
                </script>
                <?php
            } else {
                $_SESSION['lv_eligible_city'] = $city_name; // Store eligible city in session
            }
        }
    }

    // If an eligible city is selected or city eligibility is not required, show the verification form
    if (($show_city_eligibility === 'yes' && isset($_SESSION['lv_eligible_city'])) || $show_city_eligibility !== 'yes') {
        ?>
        <div class="lv-frontend-form-wrap">
            <h2 class="lv-frontend-title"><?php echo esc_html($form_name); ?></h2>
            <form method="post" action="" class="lv-frontend-form">
                <div class="lv-form-group">
                    <label for="<?php echo esc_attr(sanitize_key(str_replace(' ', '_', strtolower($retrieval_field)))); ?>">
                        <?php echo esc_html($retrieval_field); ?>:
                    </label>
                    <input type="text" name="<?php echo esc_attr(sanitize_key(str_replace(' ', '_', strtolower($retrieval_field)))); ?>" id="<?php echo esc_attr(sanitize_key(str_replace(' ', '_', strtolower($retrieval_field)))); ?>" required class="lv-input" placeholder="Enter your <?php echo esc_attr($retrieval_field); ?>">
                </div>
                <input type="submit" name="lv_verify" class="lv-button lv-button-primary" value="Verify">
            </form>

            <?php if (isset($_POST['lv_verify'])) : ?>
                <?php
                $retrieval_value = sanitize_text_field($_POST[sanitize_key(str_replace(' ', '_', strtolower($retrieval_field)))]);
                $entries = get_option('lv_entries', []);
                $found = false;
                $entry_data = null;
                foreach ($entries as $entry) {
                    if (isset($entry[$retrieval_field]) && $entry[$retrieval_field] === $retrieval_value) {
                        $found = true;
                        $entry_data = $entry;
                        break;
                    }
                }

                if ($found) {
                    ?>
                    <div class="lv-result-card">
                        <?php if ($selected_template === 'template1') : ?>
                            <div class="lv-template1-container">
                                <?php
                                $image_field = array_filter($form_fields, function($field) {
                                    return $field['type'] === 'file';
                                });
                                $image_field = !empty($image_field) ? reset($image_field)['label'] : '';
                                if ($image_field && !empty($entry_data[$image_field])) : ?>
                                    <div class="lv-template1-image">
                                        <img src="<?php echo esc_url($entry_data[$image_field]); ?>" alt="License Image" class="lv-result-image">
                                    </div>
                                <?php endif; ?>
                                <table class="lv-template1-table">
                                    <tbody>
                                        <?php foreach ($form_fields as $field) : ?>
                                            <?php if (isset($entry_data[$field['label']]) && $field['type'] !== 'file') : ?>
                                                <tr>
                                                    <td class="lv-template1-label"><?php echo esc_html($field['label']); ?></td>
                                                    <td class="lv-template1-value"><?php echo esc_html($entry_data[$field['label']] ?: '-'); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($selected_template === 'template2') : ?>
                            <div class="lv-template2-container">
                                <div class="lv-template2-left">
                                    <table class="lv-template2-table">
                                        <tbody>
                                            <?php foreach ($form_fields as $field) : ?>
                                                <?php if (isset($entry_data[$field['label']]) && $field['type'] !== 'file') : ?>
                                                    <tr>
                                                        <td class="lv-template2-label"><?php echo esc_html($field['label']); ?></td>
                                                        <td class="lv-template2-value"><?php echo esc_html($entry_data[$field['label']] ?: '-'); ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="lv-template2-right">
                                    <?php
                                    $image_field = array_filter($form_fields, function($field) {
                                        return $field['type'] === 'file';
                                    });
                                    $image_field = !empty($image_field) ? reset($image_field)['label'] : '';
                                    if ($image_field && !empty($entry_data[$image_field])) : ?>
                                        <div class="lv-template2-image">
                                            <img src="<?php echo esc_url($entry_data[$image_field]); ?>" alt="License Image" class="lv-result-image">
                                        </div>
                                    <?php else : ?>
                                        <div class="lv-template2-no-image">
                                            <span>No Image Available</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="lv-frontend-message lv-success lv-result-alert"><?php echo esc_html(get_option('lv_success_message', 'License verified successfully!')); ?></p>
                    <?php
                } else {
                    ?>
                    <p class="lv-frontend-message lv-error lv-result-alert">No matching record found for <?php echo esc_html($retrieval_field); ?>: <?php echo esc_html($retrieval_value); ?>.</p>
                    <?php
                }
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    ?>
    <style>
        .lv-login-required { max-width: 500px; margin: 20px auto; padding: 20px; text-align: center; }
        .lv-login-message { font-size: 16px; color: #1a202c; margin-bottom: 20px; }
        .login-form { max-width: 300px; margin: 0 auto; }
        .login-form p { margin-bottom: 15px; }
        .login-form label { display: block; font-size: 14px; color: #1a202c; margin-bottom: 5px; }
        .login-form input[type="text"],
        .login-form input[type="password"] { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        .login-form input[type="submit"] { background: #3182ce; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; }
        .login-form input[type="submit"]:hover { background: #2b6cb0; }
        .lv-city-eligibility-wrap { max-width: 500px; margin: 0 auto; padding: 20px; }
        .lv-city-eligibility-title { font-size: 24px; font-weight: 600; color: #1a202c; text-align: center; margin-bottom: 20px; }
        .lv-city-eligibility-form { display: flex; flex-direction: column; gap: 20px; }
        .lv-city-form-group { display: flex; flex-direction: column; gap: 8px; }
        .lv-city-form-group label { font-size: 14px; font-weight: 500; color: #1a202c; }
        .lv-city-input { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #1a202c; background: #fff; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        .lv-city-input:focus { border-color: #3182ce; outline: none; box-shadow: 0 0 0 2px rgba(49,130,206,0.2); }
        .lv-city-button-primary { background: #3182ce; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease; }
        .lv-city-button-primary:hover { background: #2b6cb0; }
        .lv-city-message { font-size: 14px; padding: 12px; border-radius: 6px; text-align: center; }
        .lv-city-success { background: #f0fff4; color: #38a169; border: 1px solid #38a169; }
        .lv-city-error { background: #fff5f5; color: #e53e3e; border: 1px solid #e53e3e; }
        .lv-frontend-form-wrap { max-width: 500px; margin: 0 auto; padding: 20px; }
        .lv-frontend-title { font-size: 24px; font-weight: 600; color: #1a202c; text-align: center; margin-bottom: 20px; }
        .lv-frontend-form { display: flex; flex-direction: column; gap: 20px; }
        .lv-form-group { display: flex; flex-direction: column; gap: 8px; }
        .lv-form-group label { font-size: 14px; font-weight: 500; color: #1a202c; }
        .lv-input { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #1a202c; background: #fff; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        .lv-input:focus { border-color: #3182ce; outline: none; box-shadow: 0 0 0 2px rgba(49,130,206,0.2); }
        .lv-button-primary { background: #3182ce; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease; }
        .lv-button-primary:hover { background: #0071e885; }
        .lv-frontend-message { font-size: 14px; padding: 12px; border-radius: 6px; text-align: center; }
        .lv-success { background: #f0fff4; color: #38a169; border: 1px solid #38a169; }
        .lv-error { background: #fff5f5; color: #e53e3e; border: 1px solid #e53e3e; }
        .lv-result-card { max-width: 700px; margin: 20px auto; padding: 20px; background: #ffffff; border: 2px solid rgba(74, 85, 104, 0.5); border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }
        .lv-result-alert { max-width: 700px; margin: 0 auto 20px; }
        .lv-template1-container { text-align: center; margin-top: 20px; }
        .lv-template1-image { margin: 0 auto 20px; display: flex; justify-content: center; }
        .lv-template1-image img { border-radius: 50%; max-width: 150px; max-height: 150px; }
        .lv-template1-table { width: 100%; max-width: 600px; margin: 0 auto; border-collapse: collapse; }
        .lv-template1-table td { padding: 10px; border: 1px solid #d1d5db; }
        .lv-template1-label { font-weight: 500; color: #1a202c; background: #f7fafc; text-align: left; }
        .lv-template1-value { color: #4a5568; text-align: left; }
        .lv-template2-container { display: flex; justify-content: center; gap: 20px; flex-wrap: nowrap; margin-top: 20px; align-items: stretch; }
        .lv-template2-left { flex: 7; min-width: 0; padding: 15px; }
        .lv-template2-right { flex: 3; min-width: 0; padding: 15px; display: flex; align-items: center; justify-content: center; }
        .lv-template2-table { width: 100%; border-collapse: collapse; }
        .lv-template2-table td { padding: 8px; border: 1px solid #d1d5db; }
        .lv-template2-label { font-weight: 500; color: #1a202c; background: #f7fafc; text-align: left; }
        .lv-template2-value { color: #4a5568; text-align: left; }
        .lv-template2-image img { width: 150px; height: 150px; object-fit: cover; border: 1px solid #4a5568; border-radius: 4px; }
        .lv-template2-no-image { width: 150px; height: 150px; display: flex; align-items: center; justify-content: center; border: 1px dashed #4a5568; border-radius: 4px; background: #fff; color: #718096; font-size: 14px; }
        @media (max-width: 768px) {
            .lv-city-eligibility-wrap, .lv-frontend-form-wrap { padding: 10px; }
            .lv-result-card { margin: 10px auto; padding: 15px; max-width: 100%; }
            .lv-template1-image img { max-width: 120px; max-height: 120px; }
            .lv-template1-table td { padding: 8px; font-size: 13px; }
            .lv-template2-container { flex-direction: row; gap: 10px; padding: 0; width: 100%; max-width: 100%; }
            .lv-template2-left { padding: 10px; flex: 7; }
            .lv-template2-right { padding: 10px; flex: 3; }
            .lv-template2-table td { padding: 6px; font-size: 12px; }
            .lv-template2-image img, .lv-template2-no-image { width: 100px; height: 100px; font-size: 12px; }
        }
        @media (max-width: 480px) {
            .lv-template2-table td { padding: 5px; font-size: 11px; }
            .lv-template2-image img, .lv-template2-no-image { width: 80px; height: 80px; font-size: 11px; }
        }
    </style>
    <?php

    return ob_get_clean();
}

add_shortcode('license_verification', 'lv_verification_shortcode');

// Activation hook
function lv_activate() {
    $default_fields = [
        ['label' => 'Name', 'type' => 'text', 'required' => true],
        ['label' => 'Father Name', 'type' => 'text', 'required' => true],
        ['label' => 'City', 'type' => 'text', 'required' => true],
        ['label' => 'Country', 'type' => 'text', 'required' => true],
        ['label' => 'License Number', 'type' => 'text', 'required' => true],
    ];
    // Always set the default fields on activation, overriding any existing ones
    update_option('lv_form_fields', $default_fields);
    update_option('lv_retrieval_field', 'License Number'); // Set default retrieval field
    if (!get_option('lv_form_name')) {
        update_option('lv_form_name', 'License Verification');
    }
    if (!get_option('lv_success_message')) {
        update_option('lv_success_message', 'License verified successfully!');
    }
}
register_activation_hook(__FILE__, 'lv_activate');

// Deactivation hook
function lv_deactivate() {
    // No cleanup needed for now
}
register_deactivation_hook(__FILE__, 'lv_deactivate');

// Uninstall hook
function lv_uninstall() {
    delete_option('lv_license_key');
    delete_option('lv_license_status');
    delete_option('lv_license_expiry');
    delete_option('lv_license_expiry_string');
    delete_option('lv_last_license_check');
    delete_option('lv_last_usage_report');
    delete_option('lv_registered_site_url');
    delete_option('lv_verification_system_active');
    delete_option('lv_show_city_eligibility');
    delete_option('lv_backups'); // Add this line
    delete_transient('lv_license_alert');
    delete_option('lv_success_message');

}
register_uninstall_hook(__FILE__, 'lv_uninstall');

// Ensure proper file upload handling
add_filter('upload_mimes', function($mimes) {
    $mimes['pdf'] = 'application/pdf';
    return $mimes;
});

// Add settings link on plugin page
function lv_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=lv-settings') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'lv_plugin_action_links');
