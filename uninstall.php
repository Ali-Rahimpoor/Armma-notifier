<?php
/**
 * پاکسازی کامل داده‌های افزونه هنگام حذف (نه صرفاً غیرفعال‌سازی) از پیشخوان وردپرس.
 *
 * وردپرس این فایل را به‌طور خودکار فقط در لحظه «حذف» افزونه (نه غیرفعال‌سازی) اجرا
 * می‌کند، به شرطی که ثابت WP_UNINSTALL_PLUGIN تعریف شده باشد (که وردپرس خودش این کار
 * را می‌کند). اگر این فایل مستقیماً (بدون درخواست حذف واقعی از وردپرس) لود شود، از
 * اجرا شدن جلوگیری می‌کنیم.
 *
 * این فایل هر چیزی را که افزونه (نسخه فعلی و همچنین نسخه‌های قدیمی‌تر/آزمایشی با
 * نام‌گذاری wtn_* یا armma_* بدون پسوند) در دیتابیس ذخیره کرده پاک می‌کند:
 * ۱) رکورد اصلی تنظیمات (armma_woo_notifer_settings) و نسخه جدول لاگ (armma_woo_notifer_log_db_version)
 * ۲) جدول اختصاصی لاگ ارسال‌ها (wp_armma_woo_notifer_notification_log)
 * ۳) تمام قفل‌های موقت جلوگیری از ارسال تکراری (armma_woo_notifer_queued_{order_id}_{status})
 * ۴) هر رویداد cron زمان‌بندی‌شده و اجرا‌نشده (ارسال async و پاکسازی قفل)
 * ۵) باقیمانده‌های احتمالی نسخه‌های قدیمی‌تر/آزمایشی افزونه (wtn_* و armma_* بدون پسوند)
 *
 * سازگار با هر دو حالت Multisite و تک‌سایتی.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * پاکسازی کامل داده‌های افزونه برای یک سایت مشخص (در حالت تک‌سایتی همان سایت جاری،
 * در حالت Multisite هر سایتی که تابع برایش صدا زده شود)
 */
function armma_uninstall_cleanup_for_site() {
    global $wpdb;

    // ۱) حذف تنظیمات اصلی و نسخه جدول لاگ
    delete_option( 'armma_woo_notifer_settings' );
    delete_option( 'armma_woo_notifer_log_db_version' );
    delete_option( 'armma_woo_notifer_broadcast_baseline' );

    // ۲) حذف جدول اختصاصی لاگ ارسال‌ها
    $log_table = $wpdb->prefix . 'armma_woo_notifer_notification_log';
    $wpdb->query( "DROP TABLE IF EXISTS {$log_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- نام جدول از ورودی کاربر نیست

    // ۳) حذف تمام قفل‌های موقت armma_woo_notifer_queued_* از جدول wp_options.
    // این قفل‌ها به ازای هر سفارش-وضعیت یک ردیف جداگانه در wp_options ایجاد می‌کنند؛
    // تعدادشان می‌تواند در طول زمان زیاد شود، پس با یک کوئری مستقیم روی الگوی نام
    // (LIKE) همه را یکجا حذف می‌کنیم تا هم افزونه بهینه پاک شود و هم جدول options
    // بی‌دلیل بزرگ نماند.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'armma_woo_notifer_queued_' ) . '%'
        )
    );

    // حذف متادیتای «اولین اطلاع‌رسانی محصول قبلاً ارسال شده» و «ارسال این محصول به
    // گروه فعال/غیرفعال است» از تمام محصولات
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
            '_armma_first_broadcast_done',
            '_armma_broadcast_enabled'
        )
    );

    // ۵) باقیمانده‌های احتمالی نسخه‌های قدیمی‌تر/آزمایشی افزونه:
    // - wtn_* مربوط به نسخه‌های خیلی قدیمی (پیش از بازسازی کامل در ۱.۰.۰)
    // - armma_settings/armma_notification_log/armma_queued_* مربوط به یک نسخه آزمایشی
    //   میانی از ۱.۰.۰ که پیش از یکدست‌سازی کامل نام‌گذاری (armma_woo_notifer_*) منتشر شده بود
    delete_option( 'wtn_settings' );
    delete_option( 'armma_settings' );
    delete_option( 'armma_log_db_version' );
    $old_log_table = $wpdb->prefix . 'armma_notification_log';
    $wpdb->query( "DROP TABLE IF EXISTS {$old_log_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- نام جدول از ورودی کاربر نیست
    foreach ( array( 'wtn_queued_', 'armma_queued_' ) as $old_lock_prefix ) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $old_lock_prefix ) . '%'
            )
        );
    }

    // ۴) حذف هر رویداد cron زمان‌بندی‌شده و هنوز اجرا‌نشده مربوط به این افزونه.
    // wp_unschedule_hook (وردپرس ۴.۹+) تمام رویدادهای یک هوک را در یک فراخوانی حذف
    // می‌کند؛ اگر به هر دلیلی در دسترس نبود (نسخه بسیار قدیمی وردپرس)، با یک حلقه
    // دستی روی wp_next_scheduled/wp_unschedule_event همان نتیجه را می‌گیریم.
    $cron_hooks = array(
        'armma_woo_notifer_send_order_notification_async',
        'armma_woo_notifer_send_product_broadcast',
        'armma_woo_notifer_cleanup_lock',
        // نام‌های نسخه‌های قدیمی‌تر/آزمایشی، برای اطمینان از پاکسازی کامل در ارتقاء
        'armma_send_order_notification_async',
        'armma_cleanup_lock',
        'wtn_send_order_notification_async',
        'wtn_cleanup_lock',
    );
    foreach ( $cron_hooks as $hook ) {
        if ( function_exists( 'wp_unschedule_hook' ) ) {
            wp_unschedule_hook( $hook );
        } else {
            $timestamp = wp_next_scheduled( $hook );
            while ( false !== $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
                $timestamp = wp_next_scheduled( $hook );
            }
        }
    }
}

if ( is_multisite() ) {
    // در حالت Multisite، افزونه ممکن است روی چند سایت شبکه فعال بوده باشد؛
    // برای هرکدام باید جدول options و جدول لاگ مخصوص همان سایت پاکسازی شود.
    $site_ids = get_sites( array( 'fields' => 'ids' ) );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        armma_uninstall_cleanup_for_site();
        restore_current_blog();
    }
} else {
    armma_uninstall_cleanup_for_site();
}
