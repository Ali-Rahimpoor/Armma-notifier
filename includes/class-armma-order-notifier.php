<?php
/**
 * کلاس گوش‌دادن به تغییر وضعیت سفارش‌های ووکامرس و ارسال پیام به پیام‌رسان‌های متصل
 * (روبیکا و/یا بله). هر پیام‌رسان به‌طور مستقل فعال/غیرفعال و پیکربندی می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ARMMA_WOO_NOTIFER_Order_Notifier {

    // نام هوک cron که پردازش واقعی ارسال پیام (تماس شبکه‌ای کند) را در یک درخواست
    // پس‌زمینه جدا انجام می‌دهد، تا درخواست اصلی (ثبت سفارش/تغییر وضعیت از پیشخوان)
    // منتظر پاسخ پیام‌رسان نماند.
    const CRON_HOOK = 'armma_woo_notifer_send_order_notification_async';

    const OPTION_KEY = 'armma_woo_notifer_settings';

    /**
     * وضعیت‌هایی که به‌صورت پیش‌فرض «مشکل‌دار» تلقی می‌شوند و در صورت فعال بودن مقصد
     * جایگزین برای یک پیام‌رسان، پیام برای همین وضعیت‌ها به آن مقصد جایگزین می‌رود
     * (مثلاً ارسال هشدار سفارش‌های لغوشده به گروه پشتیبانی به‌جای گروه فروش).
     */
    const PROBLEM_STATUSES = array( 'cancelled', 'refunded', 'failed' );

    /**
     * نمونه فعلی کلاس، برای دسترسی سایر کلاس‌ها (مثلاً باکس سفارش) به متد ارسال مجدد دستی
     * @var ARMMA_WOO_NOTIFER_Order_Notifier|null
     */
    private static $instance = null;

    public function __construct() {
        self::$instance = $this;

        // هوک اصلی: با هر تغییر وضعیت سفارش (پرداخت موفق، در انتظار، لغو و ...) اجرا می‌شود.
        add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_queue_notification' ), 10, 4 );

        // هوک‌های اختصاصی هر وضعیت: در برخی نسخه‌های ووکامرس یا هنگام تغییر دستی وضعیت از
        // پیشخوان، هوک عمومی بالا همیشه فایر نمی‌شود؛ برای اطمینان کامل، تمام وضعیت‌های
        // استاندارد سفارش را جداگانه هم قلاب می‌کنیم تا هیچ تغییر وضعیتی از دست نرود.
        $all_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
        foreach ( $all_statuses as $status_key => $label ) {
            $status_slug = str_replace( 'wc-', '', $status_key );
            add_action( 'woocommerce_order_status_' . $status_slug, array( $this, 'handle_specific_status_hook' ), 10, 2 );
        }

        // هندلر واقعی ارسال پیام؛ این هوک توسط wp_schedule_single_event در یک
        // درخواست جدا (نه در همان درخواستی که سفارش را ثبت/تغییر داد) اجرا می‌شود.
        add_action( self::CRON_HOOK, array( $this, 'send_notification_now' ), 10, 2 );

        // پاکسازی قفل‌های موقت قدیمی از جدول wp_options (رجوع کنید به یادداشت در send_notification_now)
        add_action( 'armma_woo_notifer_cleanup_lock', array( $this, 'cleanup_lock' ), 10, 1 );
    }

    /**
     * دسترسی به نمونه فعلی کلاس (برای فراخوانی manual_resend از کلاس‌های دیگر)
     */
    public static function get_instance() {
        return self::$instance;
    }

    /**
     * حذف یک قفل موقت پس از اتمام دوره نگهداری آن (فراخوانی‌شده توسط cron)
     */
    public function cleanup_lock( $lock_key ) {
        delete_option( $lock_key );
    }

    /**
     * پل ارتباطی برای هوک‌های اختصاصی هر وضعیت (که فقط order_id و order را می‌دهند، نه old/new status)
     * این تابع پارامترها را به شکل یکسان به maybe_queue_notification تحویل می‌دهد.
     */
    public function handle_specific_status_hook( $order_id, $order = null ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        $new_status = $order->get_status();
        $this->maybe_queue_notification( $order_id, '', $new_status, $order );
    }

    /**
     * بررسی می‌کند آیا برای این وضعیت سفارش باید پیام ارسال شود یا نه، و بر اساس حالت
     * ارسال انتخاب‌شده در تنظیمات (send_mode) یکی از دو روش زیر را انجام می‌دهد:
     * - 'cron' (پیش‌فرض): یک رویداد cron برای ارسال در پس‌زمینه زمان‌بندی می‌کند، تا
     *   درخواست فعلی (ثبت سفارش/تغییر وضعیت) معطل تماس با API پیام‌رسان نماند. این روش
     *   به درستی کار کردن WP-Cron روی هاست وابسته است.
     * - 'direct': پیام بلافاصله و در همین درخواست ارسال می‌شود؛ هیچ وابستگی‌ای به
     *   WP-Cron ندارد، اما درخواست فعلی چند صدم ثانیه تا چند ثانیه کندتر می‌شود.
     *
     * جلوگیری از ارسال تکراری با یک عملیات اتمیک انجام می‌شود: به‌جای «چک‌کردن،
     * سپس نوشتن» (که بین این دو مرحله امکان اجرای همزمان دو هوک برای همان
     * سفارش-وضعیت وجود دارد)، از add_option() با آرگومان $autoload=false استفاده
     * می‌کنیم. این تابع در سطح دیتابیس (با تکیه بر UNIQUE KEY ستون option_name)
     * تضمین می‌کند فقط اولین فراخوانی موفق به درج مقدار شود؛ فراخوانی‌های بعدی
     * (حتی اگر تقریباً همزمان باشند) false برمی‌گردانند. این روش برخلاف
     * add_post_meta، کاملاً مستقل از جدول postmeta است و با HPOS هم سازگار است.
     *
     * نکته: این قفل به‌ازای «سفارش + وضعیت» است، نه به‌ازای پیام‌رسان؛ یعنی اگر چند
     * پیام‌رسان همزمان فعال باشند، همه آن‌ها در یک رویداد واحد (یا یک درخواست مستقیم
     * واحد) پردازش می‌شوند، نه چند قفل جداگانه.
     */
    public function maybe_queue_notification( $order_id, $old_status, $new_status, $order ) {
        $settings = get_option( self::OPTION_KEY, array() );

        // اگر افزونه غیرفعال است، کاری انجام نده
        if ( empty( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
            return;
        }

        $selected_statuses = isset( $settings['order_statuses'] ) && is_array( $settings['order_statuses'] )
            ? $settings['order_statuses']
            : array( 'processing' );

        // اگر وضعیت جدید سفارش جزو وضعیت‌های انتخاب‌شده در تنظیمات نیست، کاری انجام نده
        if ( ! in_array( $new_status, $selected_statuses, true ) ) {
            return;
        }

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
        }

        // قفل اتمیک: تلاش برای درج یک option یکتا برای همین سفارش-وضعیت.
        //
        // نکته مهم سازگاری با HPOS: در ابتدا این قفل با add_post_meta() روی شناسه
        // سفارش پیاده شده بود. این روش زمانی که ووکامرس HPOS (ذخیره‌سازی سفارش با
        // کارایی بالا، پیش‌فرض از نسخه ۸.۲ به بعد) فعال باشد و «حالت سازگاری» را روشن
        // نکرده باشید، اصلاً کار نمی‌کند: چون در آن حالت سفارش‌ها دیگر در جدول
        // wp_posts/wp_postmeta ذخیره نمی‌شوند، بلکه در جدول اختصاصی wc_orders هستند.
        // برای رفع این مشکل، قفل با add_option() روی یک کلید کاملاً مستقل از جدول
        // postmeta/سفارش پیاده شده است (جدول wp_options که همیشه یکسان است، فارغ از
        // روشن یا خاموش بودن HPOS).
        $lock_key      = 'armma_woo_notifer_queued_' . $order_id . '_' . $new_status;
        $lock_acquired = add_option( $lock_key, 'yes', '', false );

        if ( ! $lock_acquired ) {
            // یا قبلاً صف شده/ارسال شده، یا هم‌اکنون توسط فراخوانی همزمان دیگری در حال صف‌بندی است
            return;
        }

        $send_mode = isset( $settings['send_mode'] ) && 'direct' === $settings['send_mode'] ? 'direct' : 'cron';

        if ( 'direct' === $send_mode ) {
            // ارسال بلافاصله در همین درخواست؛ بدون هیچ وابستگی به WP-Cron.
            $this->send_notification_now( $order_id, $new_status );
            return;
        }

        // حالت پیش‌فرض: زمان‌بندی اجرای فوری (بدون تأخیر) در یک درخواست پس‌زمینه جدا
        wp_schedule_single_event( time(), self::CRON_HOOK, array( $order_id, $new_status ) );

        // نکته مهم: WP-Cron یک زمان‌بند واقعی سطح سیستم‌عامل نیست؛ به‌طور پیش‌فرض فقط با
        // بازدید بعدی یک کاربر از سایت «تریگر» می‌شود. برای رفع این وابستگی، همینجا
        // صریحاً یک درخواست HTTP غیرمنتظر (async) به wp-cron.php ارسال می‌کنیم تا
        // رویداد فوراً و بدون نیاز به بازدید بعدی اجرا شود. (اگر WP-Cron روی هاست شما
        // اصلاً کار نمی‌کند، از «حالت ارسال: مستقیم» در تنظیمات استفاده کنید.)
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    /**
     * اجرای واقعی ارسال پیام به تمام پیام‌رسان‌های فعال؛ توسط رویداد cron که در
     * maybe_queue_notification زمان‌بندی شد صدا زده می‌شود (یا مستقیماً در حالت «مستقیم»).
     */
    public function send_notification_now( $order_id, $new_status ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // زمان‌بندی حذف قفل موقت (رجوع کنید به یادداشت در maybe_queue_notification) پس از
        // ۷ روز، تا جدول wp_options با گذشت زمان و انباشت سفارش‌های زیاد بی‌رویه بزرگ نشود.
        $lock_key = 'armma_woo_notifer_queued_' . $order_id . '_' . $new_status;
        if ( ! wp_next_scheduled( 'armma_woo_notifer_cleanup_lock', array( $lock_key ) ) ) {
            wp_schedule_single_event( time() + 7 * DAY_IN_SECONDS, 'armma_woo_notifer_cleanup_lock', array( $lock_key ) );
        }

        $settings  = get_option( self::OPTION_KEY, array() );
        $platforms = isset( $settings['platforms'] ) && is_array( $settings['platforms'] ) ? $settings['platforms'] : array();

        foreach ( array_keys( ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms() ) as $platform_id ) {
            $platform_settings = isset( $platforms[ $platform_id ] ) ? $platforms[ $platform_id ] : array();
            if ( empty( $platform_settings['enabled'] ) || 'yes' !== $platform_settings['enabled'] ) {
                continue;
            }
            $this->send_to_platform( $order, $new_status, $platform_id, false );
        }
    }

    /**
     * ارسال دستی/مجدد پیام یک سفارش به یک پیام‌رسان مشخص (برای دکمه «ارسال مجدد» در
     * باکس آرما در صفحه ویرایش سفارش). برخلاف مسیر خودکار، این مسیر هیچ وابستگی‌ای
     * به قفل جلوگیری از تکرار ندارد؛ کاربر آگاهانه درخواست ارسال مجدد داده است.
     *
     * @param int    $order_id
     * @param string $platform_id
     * @return true|WP_Error
     */
    public function manual_resend( $order_id, $platform_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'armma_no_order', 'سفارش یافت نشد.' );
        }
        return $this->send_to_platform( $order, $order->get_status(), $platform_id, true );
    }

    /**
     * منطق مشترک ارسال پیام یک سفارش به یک پیام‌رسان مشخص: تعیین مقصد (اصلی یا
     * جایگزین)، ساخت پیام، ارسال، ثبت در جدول لاگ و یادداشت سفارش.
     *
     * @param WC_Order $order
     * @param string   $status_for_message وضعیتی که پیام بر اساس آن ساخته می‌شود
     * @param string   $platform_id
     * @param bool     $is_manual آیا این یک ارسال مجدد دستی است (برای متن یادداشت متفاوت)
     * @return true|WP_Error
     */
    private function send_to_platform( $order, $status_for_message, $platform_id, $is_manual = false ) {
        $settings           = get_option( self::OPTION_KEY, array() );
        $platform_settings  = isset( $settings['platforms'][ $platform_id ] ) ? $settings['platforms'][ $platform_id ] : array();
        $platform_label     = ARMMA_WOO_NOTIFER_Messenger_API::get_platform_label( $platform_id );

        if ( $is_manual && ( empty( $platform_settings['enabled'] ) || 'yes' !== $platform_settings['enabled'] ) ) {
            return new WP_Error( 'armma_platform_disabled', sprintf( 'پیام‌رسان %s در تنظیمات غیرفعال است.', $platform_label ) );
        }

        $token = isset( $platform_settings['bot_token'] ) ? $platform_settings['bot_token'] : '';

        // اگر مقصد جایگزین برای این پیام‌رسان فعال است و وضعیت فعلی جزو وضعیت‌های
        // «مشکل‌دار» است (لغوشده/مرجوعی/ناموفق)، به‌جای گروه اصلی به گروه جایگزین ارسال شود.
        $use_alt = ! empty( $platform_settings['alt_enabled'] )
            && 'yes' === $platform_settings['alt_enabled']
            && in_array( $status_for_message, self::PROBLEM_STATUSES, true )
            && ! empty( $platform_settings['alt_chat_id'] );

        $chat_id = $use_alt ? $platform_settings['alt_chat_id'] : ( isset( $platform_settings['chat_id'] ) ? $platform_settings['chat_id'] : '' );

        if ( empty( $token ) || empty( $chat_id ) ) {
            $note = sprintf( 'ARMMA: پیام به %s ارسال نشد چون توکن بات یا شناسه گروه تنظیم نشده است.', $platform_label );
            $order->add_order_note( $note );
            ARMMA_WOO_NOTIFER_Log::insert( $order->get_id(), $platform_id, $status_for_message, $chat_id, false, 'توکن یا شناسه گروه تنظیم نشده است.' );
            return new WP_Error( 'armma_missing_config', $note );
        }

        $message = $this->build_order_message( $order, $status_for_message, $platform_id, $settings );

        /**
         * فیلتر متن نهایی پیام پیش از ارسال؛ برای سفارشی‌سازی توسط توسعه‌دهنده بدون
         * نیاز به دست‌زدن به هسته افزونه (مثلاً افزودن اطلاعات اختصاصی یک پروژه خاص).
         *
         * @param string   $message
         * @param WC_Order $order
         * @param string   $status_for_message
         * @param string   $platform_id
         */
        $message = apply_filters( 'armma_woo_notifer_order_message', $message, $order, $status_for_message, $platform_id );

        $api = ARMMA_WOO_NOTIFER_Messenger_API::create( $platform_id, $token );

        do_action( 'armma_woo_notifer_before_send_notification', $order, $platform_id, $chat_id, $message );
        $result = $api->send_message( $chat_id, $message );
        do_action( 'armma_woo_notifer_after_send_notification', $order, $platform_id, $chat_id, $result );

        if ( is_wp_error( $result ) ) {
            $note = $is_manual
                ? sprintf( 'ارسال مجدد دستی پیام به %s ناموفق بود: %s', $platform_label, $result->get_error_message() )
                : sprintf( 'ارسال پیام به %s ناموفق بود: %s', $platform_label, $result->get_error_message() );
            $order->add_order_note( $note );
            ARMMA_WOO_NOTIFER_Log::insert( $order->get_id(), $platform_id, $status_for_message, $chat_id, false, $result->get_error_message() );
            return $result;
        }

        if ( $is_manual ) {
            $order->add_order_note( sprintf( 'ارسال مجدد دستی پیام به %s با موفقیت انجام شد.', $platform_label ) );
        }
        ARMMA_WOO_NOTIFER_Log::insert( $order->get_id(), $platform_id, $status_for_message, $chat_id, true, '' );

        return true;
    }

    /**
     * تبدیل امن هر مقدار متنی به متن ساده و خوانا:
     * - دیکد کردن HTML entity ها (مثل &nbsp; یا کدهای یونیکد فارسی &#x062A;)
     * - حذف تگ‌های HTML باقی‌مانده
     * - نرمال‌سازی فاصله‌های اضافه
     */
    private function clean_text( $value ) {
        if ( '' === $value || null === $value ) {
            return '';
        }
        // اگر <br> یا <br/> وجود دارد، قبل از حذف تگ‌ها آن را به خط جدید تبدیل می‌کنیم
        $value = preg_replace( '#<br\s*/?>#i', "\n", $value );
        $value = wp_strip_all_tags( $value );
        // دیکد entity های HTML (نصف اول) و entity های عددی/یونیکد (نصف دوم)
        $value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
        // فاصله‌های سخت (nbsp دیکدشده) را به فاصله معمولی تبدیل می‌کنیم
        $value = str_replace( "\xc2\xa0", ' ', $value ); // U+00A0 NBSP در UTF-8
        // چند فاصله پشت سر هم را یکی می‌کنیم، ولی خطوط جدید را حفظ می‌کنیم
        $value = preg_replace( '/[ \t]+/', ' ', $value );
        return trim( $value );
    }

    /**
     * ساخت آدرس کامل به‌صورت دستی، فیلد به فیلد، تا کدپستی و بقیه اجزا حتماً درست نمایش داده شوند
     */
    private function build_address_text( $order ) {
        // اگر آدرس ارسال (shipping) خالی باشد از آدرس صورتحساب (billing) استفاده می‌کنیم
        $use_shipping = (bool) $order->get_shipping_address_1();

        $get = function( $field ) use ( $order, $use_shipping ) {
            $method = ( $use_shipping ? 'get_shipping_' : 'get_billing_' ) . $field;
            return method_exists( $order, $method ) ? $order->$method() : '';
        };

        $address_1 = $get( 'address_1' );
        $address_2 = $get( 'address_2' );
        $city      = $get( 'city' );
        $state     = $get( 'state' );
        $postcode  = $get( 'postcode' );
        $country   = $get( 'country' );

        // نام کامل استان از کد آن (مثلاً KHZ => خوزستان) در صورت وجود
        if ( $state && function_exists( 'WC' ) ) {
            $states = WC()->countries->get_states( $country );
            if ( is_array( $states ) && isset( $states[ $state ] ) ) {
                $state = $states[ $state ];
            }
        }
        // نام کامل کشور از کد آن
        if ( $country && function_exists( 'WC' ) ) {
            $countries = WC()->countries->get_countries();
            if ( isset( $countries[ $country ] ) ) {
                $country = $countries[ $country ];
            }
        }

        $lines = array();
        if ( $address_1 ) {
            $lines[] = $this->clean_text( $address_1 );
        }
        if ( $address_2 ) {
            $lines[] = $this->clean_text( $address_2 );
        }

        $city_line = array_filter( array( $this->clean_text( $city ), $this->clean_text( $state ) ) );
        if ( ! empty( $city_line ) ) {
            $lines[] = implode( '، ', $city_line );
        }

        if ( $country ) {
            $lines[] = $this->clean_text( $country );
        }
        if ( $postcode ) {
            $lines[] = 'کدپستی: ' . $this->clean_text( $postcode );
        }

        if ( empty( $lines ) ) {
            return 'ثبت نشده';
        }

        return implode( "\n", $lines );
    }

    /**
     * ساخت متن توصیف یک ردیف سفارش شامل نام محصول، ویژگی‌های تنوع (رنگ/سایز و ...) و SKU.
     * پیش‌تر فقط $item->get_name() استفاده می‌شد که برای محصولات متغیر (variable product)
     * ویژگی انتخاب‌شده (مثلاً سایز/رنگ) را برنمی‌گرداند؛ این اطلاعات جدا در متادیتای آیتم
     * سفارش ذخیره می‌شوند و باید صریحاً خوانده شوند.
     */
    private function build_item_line( $item ) {
        $product_name   = $this->clean_text( $item->get_name() );
        $quantity       = $item->get_quantity();
        $line_total_raw = wc_price( $item->get_total(), array( 'currency' => $item->get_order()->get_currency() ) );
        $line_total     = $this->clean_text( $line_total_raw );

        $line = sprintf( "• %s  ×%d  —  %s\n", $product_name, $quantity, $line_total );

        // ویژگی‌های تنوع محصول (مثل سایز، رنگ) که در متادیتای نمایشی آیتم سفارش ذخیره شده‌اند
        $meta_data = $item->get_formatted_meta_data( '_' );
        if ( ! empty( $meta_data ) ) {
            $meta_parts = array();
            foreach ( $meta_data as $meta ) {
                $key   = $this->clean_text( wp_strip_all_tags( $meta->display_key ) );
                $value = $this->clean_text( wp_strip_all_tags( $meta->display_value ) );
                if ( '' !== $key && '' !== $value ) {
                    $meta_parts[] = $key . ': ' . $value;
                }
            }
            if ( ! empty( $meta_parts ) ) {
                $line .= '   (' . implode( '، ', $meta_parts ) . ")\n";
            }
        }

        // کد محصول (SKU)، در صورت وجود
        $product = $item->get_product();
        if ( $product instanceof WC_Product ) {
            $sku = $product->get_sku();
            if ( ! empty( $sku ) ) {
                $line .= '   کد محصول: ' . $this->clean_text( $sku ) . "\n";
            }
        }

        return $line;
    }

    /**
     * عنوان مناسب پیام بر اساس وضعیت سفارش. پیش‌تر این خط همیشه «سفارش جدید در سایت»
     * بود، حتی وقتی سفارش لغو یا مرجوع می‌شد که کاملاً گمراه‌کننده بود؛ حالا متناسب با
     * وضعیت واقعی سفارش تغییر می‌کند. برای وضعیت‌های سفارشی (ساخته‌شده توسط افزونه‌های
     * دیگر) که در این لیست نیستند، یک عنوان خنثی و عمومی برگردانده می‌شود.
     */
    private function get_status_headline( $new_status ) {
        $headlines = array(
            'pending'    => '🕒 سفارش جدید ثبت شد (در انتظار پرداخت)',
            'processing' => '🛒 سفارش جدید و پرداخت‌شده',
            'on-hold'    => '⏸ سفارش در انتظار بررسی',
            'completed'  => '✅ سفارش تکمیل شد',
            'cancelled'  => '❌ سفارش لغو شد',
            'refunded'   => '↩️ سفارش مرجوع شد',
            'failed'     => '⚠️ پرداخت سفارش ناموفق بود',
        );

        return isset( $headlines[ $new_status ] ) ? $headlines[ $new_status ] : '🔔 به‌روزرسانی وضعیت سفارش';
    }

    /**
     * ساخت متن پیام سفارش (فرمت متن ساده؛ پیام‌رسان‌ها الزاماً از مارک‌داون پشتیبانی نمی‌کنند)
     */
    private function build_order_message( $order, $new_status, $platform_id, $settings ) {
        $status_labels = wc_get_order_statuses();
        $status_label  = isset( $status_labels[ 'wc-' . $new_status ] ) ? $status_labels[ 'wc-' . $new_status ] : $new_status;

        // اطلاعات مشتری
        $full_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $full_name = $this->clean_text( $full_name );
        $phone     = $this->clean_text( $order->get_billing_phone() );
        $email     = $this->clean_text( $order->get_billing_email() );

        // آدرس کامل، ساخته‌شده دستی فیلد به فیلد (شامل کدپستی)
        $address = $this->build_address_text( $order );

        // لیست محصولات خریداری‌شده، به همراه ویژگی تنوع و SKU هر ردیف
        $items_text = '';
        foreach ( $order->get_items() as $item ) {
            $items_text .= $this->build_item_line( $item );
        }

        $order_total    = $this->clean_text( $order->get_formatted_order_total() );
        $order_number   = $order->get_order_number();
        $payment_method = $this->clean_text( $order->get_payment_method_title() );
        $order_date     = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y/m/d H:i' ) : '';
        $edit_url       = $order->get_edit_order_url();

        // برچسب سفارش پرمبلغ، در صورت فعال بودن و رسیدن مبلغ سفارش به آستانه تنظیم‌شده
        $high_value_banner = '';
        if ( ! empty( $settings['high_value_enabled'] ) && 'yes' === $settings['high_value_enabled'] ) {
            $threshold = isset( $settings['high_value_threshold'] ) ? (float) $settings['high_value_threshold'] : 0;
            if ( $threshold > 0 && (float) $order->get_total() >= $threshold ) {
                $high_value_banner = "⭐️ سفارش ویژه (مبلغ بالا)\n";
            }
        }

        $message  = $high_value_banner;
        $message .= $this->get_status_headline( $new_status ) . "\n\n";
        $message .= "📦 شماره سفارش: #{$order_number}\n";
        $message .= "📌 وضعیت: {$status_label}\n";
        $message .= "🗓 تاریخ: {$order_date}\n";
        $message .= "\n👤 مشتری: " . ( $full_name ?: 'ثبت نشده' ) . "\n";
        $message .= "📞 تلفن: " . ( $phone ?: 'ثبت نشده' ) . "\n";
        if ( ! empty( $email ) ) {
            $message .= "✉️ ایمیل: " . $email . "\n";
        }
        $message .= "📍 آدرس: \n" . $address . "\n";
        $message .= "\n🧾 محصولات: \n" . $items_text;

        // یادداشت مشتری (در صورت وجود)
        $customer_note = $this->clean_text( $order->get_customer_note() );
        if ( ! empty( $customer_note ) ) {
            $message .= "\n📝 یادداشت مشتری: \n" . $customer_note . "\n";
        }

        // کدهای تخفیف استفاده‌شده (در صورت وجود)
        $coupon_codes = $order->get_coupon_codes();
        if ( ! empty( $coupon_codes ) ) {
            $message .= "\n🏷 کد تخفیف: " . implode( '، ', array_map( array( $this, 'clean_text' ), $coupon_codes ) ) . "\n";
        }

        $message .= "\n💰 مبلغ کل: {$order_total}\n";
        if ( ! empty( $payment_method ) ) {
            $message .= "💳 روش پرداخت: " . $payment_method . "\n";
        }
        if ( $edit_url ) {
            // متن ساده بدون مارک‌داون، لینک خام درج می‌شود
            $message .= "\nمشاهده سفارش در پنل مدیریت:\n{$edit_url}";
        }

        return $message;
    }
}
