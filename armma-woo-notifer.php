<?php
/**
 * Plugin Name: ربات پیام‌رسان آرما (ARMMA Woo Notifer)
 * Plugin URI:  https://alirahimpoor.ir
 * Description: با هر تغییر وضعیت سفارش در ووکامرس، اطلاعات کامل مشتری (نام، آدرس، تلفن)، محصولات خریداری‌شده (به‌همراه تنوع/رنگ/سایز و کد محصول) و مبلغ سفارش به‌صورت خودکار به گروه(های) روبیکا و/یا بله متصل شما ارسال می‌شود.
 * Version:     1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 * Author:      علی رحیم پور
 * Author URI:  https://alirahimpoor.ir
 * Text Domain: armma-woo-notifer
 * Requires Plugins: woocommerce
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// جلوگیری از دسترسی مستقیم به فایل
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ARMMA_WOO_NOTIFER_VERSION', '1.0.0' );

/**
 * نسخه جداگانه فقط برای cache-busting فایل‌های CSS/JS (پارامتر ?ver= در آدرس فایل).
 * چون شماره نسخه رسمی افزونه بالا هنوز عمداً روی ۱.۰.۰ ثابت نگه داشته می‌شود (تا زمان
 * انتشار رسمی)، اگر همان مقدار برای cache-busting هم استفاده شود، مرورگر/افزونه‌های
 * کش با هر آپدیت فایل CSS/JS متوجه تغییر نمی‌شوند و نسخه قدیمی را نمایش می‌دهند. این
 * ثابت مستقل، با هر تغییری در assets/admin.css یا assets/admin.js، افزایش می‌یابد.
 */
define( 'ARMMA_WOO_NOTIFER_ASSETS_VERSION', '1.0.0-r4' );
define( 'ARMMA_WOO_NOTIFER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARMMA_WOO_NOTIFER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ARMMA_WOO_NOTIFER_PLUGIN_FILE', __FILE__ );

/**
 * اعلام سازگاری صریح با HPOS (High-Performance Order Storage) ووکامرس.
 * این افزونه فقط از متدهای استاندارد شیء سفارش (wc_get_order, $order->add_order_note,
 * $order->get_status و ...) استفاده می‌کند، نه توابع سراسری postmeta؛ پس با HPOS سازگار است.
 * بدون این اعلام، وردپرس یک هشدار «ناسازگار» بی‌دلیل به مدیر سایت نشان می‌دهد.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * بررسی فعال بودن ووکامرس قبل از اجرای افزونه
 */
function armma_woo_notifer_check_woocommerce_active() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'افزونه «ربات پیام‌رسان آرما» نیاز به فعال بودن ووکامرس دارد.', 'armma-woo-notifer' ); ?></p>
            </div>
            <?php
        } );
        return false;
    }
    return true;
}

/**
 * راه‌اندازی اصلی افزونه
 */
function armma_woo_notifer_init_plugin() {
    if ( ! armma_woo_notifer_check_woocommerce_active() ) {
        return;
    }

    armma_woo_notifer_maybe_migrate_old_settings();

    // ترتیب لود مهم است: کلاس پایه انتزاعی باید قبل از کلاس‌های فرزند و مصرف‌کننده‌ها لود شود
    require_once ARMMA_WOO_NOTIFER_PLUGIN_DIR . 'includes/class-armma-messenger-api.php';
    require_once ARMMA_WOO_NOTIFER_PLUGIN_DIR . 'includes/class-armma-rubika-api.php';
    require_once ARMMA_WOO_NOTIFER_PLUGIN_DIR . 'includes/class-armma-bale-api.php';
    require_once ARMMA_WOO_NOTIFER_PLUGIN_DIR . 'includes/class-armma-log.php';
    require_once ARMMA_WOO_NOTIFER_PLUGIN_DIR . 'includes/class-armma-settings.php';
    require_once ARMMA_WOO_NOTIFER_PLUGIN_DIR . 'includes/class-armma-order-notifier.php';
    require_once ARMMA_WOO_NOTIFER_PLUGIN_DIR . 'includes/class-armma-order-box.php';
    require_once ARMMA_WOO_NOTIFER_PLUGIN_DIR . 'includes/class-armma-product-broadcaster.php';

    // اطمینان از وجود جدول لاگ؛ هزینه این فراخوانی در حالت عادی تقریباً صفر است
    // (فقط یک بررسی get_option، مگر اینکه نسخه جدول تغییر کرده باشد). این فراخوانی
    // اضافه بر activation hook، برای کاربرانی است که فایل‌های افزونه را دستی/FTP
    // آپدیت می‌کنند بدون غیرفعال و فعال‌سازی مجدد.
    ARMMA_WOO_NOTIFER_Log::maybe_create_table();

    // راه‌اندازی کلاس تنظیمات (صفحه مدیریت)
    new ARMMA_WOO_NOTIFER_Settings();

    // راه‌اندازی کلاس ارسال‌کننده پیام سفارش
    new ARMMA_WOO_NOTIFER_Order_Notifier();

    // راه‌اندازی باکس وضعیت ارسال + دکمه ارسال مجدد در صفحه ویرایش سفارش
    new ARMMA_WOO_NOTIFER_Order_Box();

    // راه‌اندازی اطلاع‌رسانی محصول جدید به کانال بله/روبیکا
    new ARMMA_WOO_NOTIFER_Product_Broadcaster();

    // تعیین «زمان مبنا» برای اطلاع‌رسانی محصول (فقط یک‌بار اجرا می‌شود). روی سایت‌هایی
    // که افزونه از قبل فعال بوده و این مقدار ثبت نشده، همینجا انجام می‌شود.
    ARMMA_WOO_NOTIFER_Product_Broadcaster::maybe_set_broadcast_baseline();
}
add_action( 'plugins_loaded', 'armma_woo_notifer_init_plugin' );

/**
 * انتقال یک‌باره تنظیمات ذخیره‌شده با کلید نام‌گذاری قدیمی‌تر (armma_settings، از
 * نسخه‌های آزمایشی پیش از یکدست‌سازی کامل نام‌گذاری) به کلید جدید. این کار باعث
 * می‌شود توکن‌ها و شناسه‌های گروهی که قبلاً برای تست وارد کرده‌اید، با آپدیت افزونه
 * از بین نروند.
 */
function armma_woo_notifer_maybe_migrate_old_settings() {
    if ( false !== get_option( 'armma_woo_notifer_settings' ) ) {
        return;
    }
    $old_settings = get_option( 'armma_settings' );
    if ( false === $old_settings ) {
        return;
    }
    add_option( 'armma_woo_notifer_settings', $old_settings );
    delete_option( 'armma_settings' );
}

/**
 * افزودن لینک «تنظیمات» در صفحه لیست افزونه‌ها
 */
function armma_woo_notifer_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=armma-woo-notifer-settings' ) . '">' . esc_html__( 'تنظیمات', 'armma-woo-notifer' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'armma_woo_notifer_add_settings_link' );

/**
 * ایجاد مقادیر پیش‌فرض تنظیمات و جدول لاگ هنگام فعال‌سازی افزونه
 */
function armma_woo_notifer_activate_plugin() {
    if ( false === get_option( 'armma_woo_notifer_settings' ) ) {
        $default_platform = array(
            'enabled'        => 'no',
            'bot_token'      => '',
            'chat_id'        => '',
            'chat_title'     => '',
            'alt_enabled'    => 'no',
            'alt_chat_id'    => '',
            'alt_chat_title' => '',
        );

        $default_broadcast_platform = array(
            'enabled'    => 'no',
            'chat_id'    => '',
            'chat_title' => '',
        );

        add_option( 'armma_woo_notifer_settings', array(
            'enabled'              => 'no',
            'send_mode'            => 'cron',
            'order_statuses'       => array( 'processing' ), // پیش‌فرض: فقط سفارش پرداخت‌شده
            'high_value_enabled'   => 'no',
            'high_value_threshold' => '',
            'platforms'            => array(
                'rubika' => $default_platform,
                'bale'   => $default_platform,
            ),
            'product_broadcast'    => array(
                'trigger_mode' => 'first_publish',
                'header_text'  => '',
                'footer_text'  => '',
                'include'      => array(
                    'title'      => 'yes',
                    'excerpt'    => 'yes',
                    'price'      => 'yes',
                    'image'      => 'yes',
                    'attributes' => 'no',
                    'category'   => 'no',
                    'tags'       => 'no',
                ),
                'platforms'    => array(
                    'rubika' => $default_broadcast_platform,
                    'bale'   => $default_broadcast_platform,
                ),
            ),
        ) );
    }

    // ساخت جدول لاگ همین‌جا هم انجام شود تا از همان ابتدای فعال‌سازی آماده باشد
    require_once ARMMA_WOO_NOTIFER_PLUGIN_DIR . 'includes/class-armma-log.php';
    ARMMA_WOO_NOTIFER_Log::maybe_create_table();
}
register_activation_hook( __FILE__, 'armma_woo_notifer_activate_plugin' );

/**
 * پاکسازی هنگام غیرفعال‌سازی افزونه: حذف رویدادهای cron زمان‌بندی‌شده‌ای که هنوز
 * اجرا نشده‌اند (ارسال async پیام سفارش، و پاکسازی قفل‌های موقت)، تا بعد از
 * غیرفعال شدن افزونه اجرا نشوند و خطای «کلاس یافت نشد» ایجاد نکنند.
 */
function armma_woo_notifer_deactivate_plugin() {
    foreach ( array( 'armma_woo_notifer_send_order_notification_async', 'armma_woo_notifer_send_product_broadcast', 'armma_woo_notifer_cleanup_lock' ) as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        while ( false !== $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
            $timestamp = wp_next_scheduled( $hook );
        }
    }
}
register_deactivation_hook( __FILE__, 'armma_woo_notifer_deactivate_plugin' );
