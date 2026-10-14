<?php
/**
 * مدیریت جدول اختصاصی لاگ ارسال‌ها در دیتابیس.
 * ثبت هر تلاش ارسال پیام (موفق یا ناموفق) برای هر سفارش/پیام‌رسان، تا در باکس
 * مخصوص آرما داخل صفحه ویرایش سفارش قابل مشاهده و ارسال مجدد باشد.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ARMMA_WOO_NOTIFER_Log {

    const DB_VERSION_OPTION = 'armma_woo_notifer_log_db_version';
    const DB_VERSION        = '1.0';

    /**
     * حداکثر تعداد ردیف نگهداری‌شده در جدول لاگ؛ فراتر از این تعداد، ردیف‌های قدیمی‌تر
     * به‌مرور هرس می‌شوند تا جدول با گذر زمان بی‌رویه بزرگ نشود.
     */
    const MAX_ROWS = 300;

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'armma_woo_notifer_notification_log';
    }

    /**
     * ساخت/به‌روزرسانی جدول در صورت نیاز. فراخوانی این متد هزینه‌ی تقریباً صفر دارد
     * (فقط یک get_option) مگر اینکه نسخه جدول تغییر کرده باشد؛ به همین دلیل هم در
     * activation hook و هم به‌عنوان اطمینان اضافه در بوت‌استرپ افزونه فراخوانی می‌شود
     * (برای کاربرانی که فایل‌های افزونه را دستی/FTP آپدیت می‌کنند بدون غیرفعال‌سازی).
     */
    public static function maybe_create_table() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name      = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(20) NOT NULL,
            order_status VARCHAR(50) NOT NULL,
            chat_id VARCHAR(100) NOT NULL DEFAULT '',
            success TINYINT(1) NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY platform (platform)
        ) {$charset_collate};";

        dbDelta( $sql );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * ثبت یک تلاش ارسال در جدول لاگ
     */
    public static function insert( $order_id, $platform, $order_status, $chat_id, $success, $error_message = '' ) {
        global $wpdb;

        $wpdb->insert(
            self::get_table_name(),
            array(
                'order_id'      => (int) $order_id,
                'platform'      => $platform,
                'order_status'  => $order_status,
                'chat_id'       => (string) $chat_id,
                'success'       => $success ? 1 : 0,
                'error_message' => $error_message ? $error_message : '',
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        // هرس دوره‌ای (نه در هر بار درج، فقط با احتمال ۲٪) تا هزینه اضافه به هر ارسال تحمیل نشود
        if ( 1 === wp_rand( 1, 50 ) ) {
            self::prune();
        }
    }

    /**
     * حذف ردیف‌های مازاد بر MAX_ROWS (قدیمی‌ترین‌ها)
     */
    public static function prune() {
        global $wpdb;
        $table = self::get_table_name();
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM {$table} ORDER BY id DESC LIMIT %d ) armma_keep )",
                self::MAX_ROWS
            )
        );
    }

    /**
     * آخرین ردیف‌های لاگ مربوط به یک سفارش مشخص (برای نمایش در باکس سفارش)
     */
    public static function get_last_for_order( $order_id, $limit = 10 ) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT %d", (int) $order_id, $limit )
        );
    }

    /**
     * حذف کامل جدول (فقط هنگام حذف کامل افزونه از uninstall.php فراخوانی می‌شود)
     */
    public static function drop_table() {
        global $wpdb;
        $table = self::get_table_name();
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::DB_VERSION_OPTION );
    }
}
