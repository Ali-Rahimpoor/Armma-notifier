<?php
/**
 * کلاس صفحات مدیریت افزونه در پیشخوان وردپرس
 *
 * از این نسخه، افزونه یک منوی اصلی مستقل با سه زیرمنو دارد:
 *   ۱) تنظیمات و اتصال  ۲) کانال فروشگاه  ۳) راهنما
 * هر زیرمنو فرم مستقل خودش را دارد و هنگام ذخیره، فقط بخش مربوط به همان صفحه
 * به‌روزرسانی می‌شود (بقیه تنظیمات دست‌نخورده باقی می‌ماند).
 *
 * توجه: کلیدهای option، نام فیلدهای فرم و اکشن‌های Ajax عیناً مثل قبل هستند؛
 * تغییرات این نسخه فقط در لایه نمایش (ساختار منو، چیدمان و استایل) است.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ARMMA_WOO_NOTIFER_Settings {

    const OPTION_KEY        = 'armma_woo_notifer_settings';
    const NONCE_ACTION      = 'armma_woo_notifer_settings_save';
    const AJAX_NONCE_ACTION = 'armma_woo_notifer_ajax_action';

    // اسلاگ صفحه تنظیمات عمداً تغییر نکرده تا لینک «تنظیمات» در لیست افزونه‌ها و
    // هر بوکمارک قبلی ادمین همچنان کار کند.
    const MENU_SLUG    = 'armma-woo-notifer-settings';
    const CHANNEL_SLUG = 'armma-woo-notifer-channel';
    const HELP_SLUG    = 'armma-woo-notifer-help';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'handle_form_submit' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // اکشن‌های Ajax برای دکمه‌های تست توکن / دریافت خودکار Chat ID / ارسال پیام آزمایشی
        add_action( 'wp_ajax_armma_woo_notifer_test_token', array( $this, 'ajax_test_token' ) );
        add_action( 'wp_ajax_armma_woo_notifer_fetch_chat_id', array( $this, 'ajax_fetch_chat_id' ) );
        add_action( 'wp_ajax_armma_woo_notifer_send_test_message', array( $this, 'ajax_send_test_message' ) );
    }

    /**
     * فهرست صفحات افزونه؛ مبنای ساخت منو و نوار تب‌های بالای صفحه
     */
    private function get_pages() {
        return array(
            self::MENU_SLUG    => 'تنظیمات و اتصال',
            self::CHANNEL_SLUG => 'کانال فروشگاه',
            self::HELP_SLUG    => 'راهنما',
        );
    }

    /**
     * افزودن منوی اصلی «پیام‌رسان آرما» و زیرمنوهای آن
     */
    public function add_settings_page() {
        add_menu_page(
            'ربات پیام‌رسان آرما',
            'پیام‌رسان آرما',
            'manage_woocommerce',
            self::MENU_SLUG,
            array( $this, 'render_settings_page' ),
            'dashicons-format-chat',
            56.7 // بلافاصله بعد از ووکامرس/محصولات
        );

        add_submenu_page( self::MENU_SLUG, 'تنظیمات و اتصال — ربات پیام‌رسان آرما', 'تنظیمات و اتصال', 'manage_woocommerce', self::MENU_SLUG, array( $this, 'render_settings_page' ) );
        add_submenu_page( self::MENU_SLUG, 'کانال فروشگاه — ربات پیام‌رسان آرما', 'کانال فروشگاه', 'manage_woocommerce', self::CHANNEL_SLUG, array( $this, 'render_channel_page' ) );
        add_submenu_page( self::MENU_SLUG, 'راهنما — ربات پیام‌رسان آرما', 'راهنما', 'manage_woocommerce', self::HELP_SLUG, array( $this, 'render_help_page' ) );
    }

    /**
     * آیا کاربر در یکی از صفحات خود افزونه است؟
     */
    private function is_plugin_screen() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        return array_key_exists( $page, $this->get_pages() );
    }

    /**
     * بارگذاری اسکریپت و استایل فقط در صفحات خود افزونه
     */
    public function enqueue_assets( $hook ) {
        if ( ! $this->is_plugin_screen() ) {
            return;
        }

        wp_enqueue_style( 'armma-admin-style', ARMMA_WOO_NOTIFER_PLUGIN_URL . 'assets/admin.css', array(), ARMMA_WOO_NOTIFER_ASSETS_VERSION );
        wp_enqueue_script( 'armma-admin-script', ARMMA_WOO_NOTIFER_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), ARMMA_WOO_NOTIFER_ASSETS_VERSION, true );

        wp_localize_script( 'armma-admin-script', 'armmaAjax', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::AJAX_NONCE_ACTION ),
        ) );
    }

    /**
     * ساختار پیش‌فرض تنظیمات یک پیام‌رسان مشخص
     */
    private function get_platform_defaults() {
        return array(
            'enabled'        => 'no',
            'bot_token'      => '',
            'chat_id'        => '',
            'chat_title'     => '',
            'alt_enabled'    => 'no',
            'alt_chat_id'    => '',
            'alt_chat_title' => '',
        );
    }

    /**
     * ساختار پیش‌فرض تنظیمات اطلاع‌رسانی محصول برای یک پیام‌رسان مشخص (توکن ندارد؛
     * از همان توکن پیام‌رسانِ کارت اطلاع‌رسانی سفارش استفاده می‌شود)
     */
    private function get_broadcast_platform_defaults() {
        return array(
            'enabled'    => 'no',
            'chat_id'    => '',
            'chat_title' => '',
        );
    }

    /**
     * دریافت تنظیمات ذخیره‌شده به همراه مقادیر پیش‌فرض (شامل ادغام عمیق برای هر پیام‌رسان،
     * چون wp_parse_args به‌صورت پیش‌فرض فقط سطح اول آرایه را ادغام می‌کند)
     */
    private function get_settings() {
        $defaults = array(
            'enabled'               => 'no',
            'send_mode'             => 'cron', // 'cron' یا 'direct'
            'order_statuses'        => array( 'processing' ),
            'high_value_enabled'    => 'no',
            'high_value_threshold'  => '',
            'platforms'             => array(),
            'product_broadcast'     => array(
                'trigger_mode' => 'first_publish',
                'header_text'  => '',
                'footer_text'  => '',
                // پیش‌فرض: بخش‌هایی که قبلاً همیشه در پیام بودند (عنوان/چکیده/قیمت/تصویر) روشن
                // باقی می‌مانند تا رفتار برای کسانی که از قبل این ویژگی را فعال کرده‌اند عوض نشود؛
                // بخش‌های تازه‌اضافه‌شده (ویژگی‌ها/دسته/برچسب) پیش‌فرض خاموش‌اند و اختیاری‌اند.
                'include'      => array(
                    'title'      => 'yes',
                    'excerpt'    => 'yes',
                    'price'      => 'yes',
                    'image'      => 'yes',
                    'attributes' => 'no',
                    'category'   => 'no',
                    'tags'       => 'no',
                ),
                'platforms'    => array(),
            ),
        );

        foreach ( array_keys( ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms() ) as $platform_id ) {
            $defaults['platforms'][ $platform_id ] = $this->get_platform_defaults();
            $defaults['product_broadcast']['platforms'][ $platform_id ] = $this->get_broadcast_platform_defaults();
        }

        $saved    = get_option( self::OPTION_KEY, array() );
        $saved    = is_array( $saved ) ? $saved : array();
        $settings = wp_parse_args( $saved, $defaults );

        foreach ( $defaults['platforms'] as $platform_id => $platform_defaults ) {
            $saved_platform = isset( $saved['platforms'][ $platform_id ] ) && is_array( $saved['platforms'][ $platform_id ] )
                ? $saved['platforms'][ $platform_id ]
                : array();
            $settings['platforms'][ $platform_id ] = wp_parse_args( $saved_platform, $platform_defaults );
        }

        $saved_broadcast = isset( $saved['product_broadcast'] ) && is_array( $saved['product_broadcast'] ) ? $saved['product_broadcast'] : array();
        $settings['product_broadcast'] = wp_parse_args( $saved_broadcast, $defaults['product_broadcast'] );

        $saved_include = isset( $saved_broadcast['include'] ) && is_array( $saved_broadcast['include'] ) ? $saved_broadcast['include'] : array();
        $settings['product_broadcast']['include'] = wp_parse_args( $saved_include, $defaults['product_broadcast']['include'] );

        foreach ( $defaults['product_broadcast']['platforms'] as $platform_id => $bp_defaults ) {
            $saved_bp = isset( $saved_broadcast['platforms'][ $platform_id ] ) && is_array( $saved_broadcast['platforms'][ $platform_id ] )
                ? $saved_broadcast['platforms'][ $platform_id ]
                : array();
            $settings['product_broadcast']['platforms'][ $platform_id ] = wp_parse_args( $saved_bp, $bp_defaults );
        }

        return $settings;
    }

    /**
     * پردازش ذخیره فرم تنظیمات
     *
     * چون تنظیمات حالا بین دو صفحه تقسیم شده، ابتدا کل تنظیمات فعلی خوانده می‌شود و
     * فقط کلیدهای مربوط به همان صفحه‌ای که فرمش ارسال شده بازنویسی می‌شوند؛ به این
     * ترتیب ذخیره در یک صفحه هرگز تنظیمات صفحه دیگر را پاک نمی‌کند.
     */
    public function handle_form_submit() {
        if ( ! isset( $_POST['armma_save_settings'] ) ) {
            return;
        }

        if ( ! isset( $_POST['armma_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['armma_nonce'] ) ), self::NONCE_ACTION ) ) {
            wp_die( 'خطای امنیتی، لطفاً صفحه را دوباره بارگذاری کرده و امتحان کنید.' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'شما دسترسی لازم برای این عملیات را ندارید.' );
        }

        $section = isset( $_POST['armma_section'] ) ? sanitize_text_field( wp_unslash( $_POST['armma_section'] ) ) : 'connection';
        if ( ! in_array( $section, array( 'connection', 'channel' ), true ) ) {
            $section = 'connection';
        }

        $settings = $this->get_settings();
        $previous = $settings;
        $errors   = array();

        if ( 'connection' === $section ) {
            $settings['enabled'] = isset( $_POST['armma_enabled'] ) ? 'yes' : 'no';

            $send_mode = isset( $_POST['armma_send_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['armma_send_mode'] ) ) : 'cron';
            if ( ! in_array( $send_mode, array( 'cron', 'direct' ), true ) ) {
                $send_mode = 'cron';
            }
            $settings['send_mode'] = $send_mode;

            // وضعیت «پیش‌نویس» (checkout-draft) از لیست انتخاب حذف می‌شود؛ ارسال پیام برای
            // این وضعیت منطقی نیست چون به سفارش نهایی‌شده تعلق ندارد.
            $allowed_statuses = array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) );
            $posted_statuses  = isset( $_POST['armma_order_statuses'] ) ? (array) wp_unslash( $_POST['armma_order_statuses'] ) : array();
            $order_statuses   = array();
            foreach ( $posted_statuses as $status ) {
                $status = sanitize_text_field( $status );
                if ( in_array( 'wc-' . $status, $allowed_statuses, true ) ) {
                    $order_statuses[] = $status;
                }
            }
            if ( empty( $order_statuses ) ) {
                $order_statuses = array( 'processing' );
            }
            $settings['order_statuses'] = $order_statuses;

            $high_value_threshold = isset( $_POST['armma_high_value_threshold'] ) ? wp_unslash( $_POST['armma_high_value_threshold'] ) : '';
            $settings['high_value_enabled']   = isset( $_POST['armma_high_value_enabled'] ) ? 'yes' : 'no';
            $settings['high_value_threshold'] = is_numeric( $high_value_threshold ) ? (string) max( 0, (float) $high_value_threshold ) : '';

            foreach ( array_keys( ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms() ) as $platform_id ) {
                $settings['platforms'][ $platform_id ] = array(
                    'enabled'        => isset( $_POST[ "armma_{$platform_id}_enabled" ] ) ? 'yes' : 'no',
                    'bot_token'      => isset( $_POST[ "armma_{$platform_id}_bot_token" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "armma_{$platform_id}_bot_token" ] ) ) : '',
                    'chat_id'        => isset( $_POST[ "armma_{$platform_id}_chat_id" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "armma_{$platform_id}_chat_id" ] ) ) : '',
                    'chat_title'     => isset( $_POST[ "armma_{$platform_id}_chat_title" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "armma_{$platform_id}_chat_title" ] ) ) : '',
                    'alt_enabled'    => isset( $_POST[ "armma_{$platform_id}_alt_enabled" ] ) ? 'yes' : 'no',
                    'alt_chat_id'    => isset( $_POST[ "armma_{$platform_id}_alt_chat_id" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "armma_{$platform_id}_alt_chat_id" ] ) ) : '',
                    'alt_chat_title' => isset( $_POST[ "armma_{$platform_id}_alt_chat_title" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "armma_{$platform_id}_alt_chat_title" ] ) ) : '',
                );
            }
        } else {
            $broadcast_trigger = isset( $_POST['armma_broadcast_trigger'] ) ? sanitize_text_field( wp_unslash( $_POST['armma_broadcast_trigger'] ) ) : 'first_publish';
            $allowed_triggers  = array( 'first_publish', 'first_publish_and_edit', 'first_publish_and_price_change' );
            if ( ! in_array( $broadcast_trigger, $allowed_triggers, true ) ) {
                $broadcast_trigger = 'first_publish';
            }

            $broadcast_platforms = array();
            foreach ( array_keys( ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms() ) as $platform_id ) {
                $broadcast_platforms[ $platform_id ] = array(
                    'enabled'    => isset( $_POST[ "armma_{$platform_id}_broadcast_enabled" ] ) ? 'yes' : 'no',
                    'chat_id'    => isset( $_POST[ "armma_{$platform_id}_broadcast_chat_id" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "armma_{$platform_id}_broadcast_chat_id" ] ) ) : '',
                    'chat_title' => isset( $_POST[ "armma_{$platform_id}_broadcast_chat_title" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "armma_{$platform_id}_broadcast_chat_title" ] ) ) : '',
                );
            }

            $settings['product_broadcast'] = array(
                'trigger_mode' => $broadcast_trigger,
                'header_text'  => isset( $_POST['armma_broadcast_header_text'] ) ? sanitize_text_field( wp_unslash( $_POST['armma_broadcast_header_text'] ) ) : '',
                'footer_text'  => isset( $_POST['armma_broadcast_footer_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['armma_broadcast_footer_text'] ) ) : '',
                'include'      => array(
                    'title'      => isset( $_POST['armma_broadcast_include_title'] ) ? 'yes' : 'no',
                    'excerpt'    => isset( $_POST['armma_broadcast_include_excerpt'] ) ? 'yes' : 'no',
                    'price'      => isset( $_POST['armma_broadcast_include_price'] ) ? 'yes' : 'no',
                    'image'      => isset( $_POST['armma_broadcast_include_image'] ) ? 'yes' : 'no',
                    'attributes' => isset( $_POST['armma_broadcast_include_attributes'] ) ? 'yes' : 'no',
                    'category'   => isset( $_POST['armma_broadcast_include_category'] ) ? 'yes' : 'no',
                    'tags'       => isset( $_POST['armma_broadcast_include_tags'] ) ? 'yes' : 'no',
                ),
                'platforms'    => $broadcast_platforms,
            );
        }

        $settings = $this->reject_duplicate_chat_ids( $settings, $previous, $section, $errors );

        update_option( self::OPTION_KEY, $settings );

        if ( ! empty( $errors ) ) {
            add_action( 'admin_notices', function () use ( $errors ) {
                ?>
                <div class="notice notice-error">
                    <p><b>بخشی از تنظیمات ذخیره نشد:</b></p>
                    <ul style="margin-right:18px;list-style:disc;">
                        <?php foreach ( $errors as $error ) : ?>
                            <li><?php echo esc_html( $error ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php
            } );
            return;
        }

        add_action( 'admin_notices', function () {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>تنظیمات با موفقیت ذخیره شد.</p>
            </div>
            <?php
        } );
    }

    /**
     * جلوگیری از انتخاب یک مقصد یکسان هم برای پیام سفارش و هم برای کانال محصول.
     *
     * یک شناسه نمی‌تواند هم‌زمان «مقصد پیام سفارش» و «کانال فروشگاه» باشد؛ در غیر این
     * صورت هر دو نوع پیام در یک چت مخلوط می‌شوند و تشخیص اینکه کدام پیام مربوط به
     * سفارش است و کدام مربوط به معرفی محصول، برای ادمین دشوار می‌شود.
     *
     * فیلد متناقض ذخیره نمی‌شود و مقدار قبلی‌اش حفظ می‌شود؛ بقیه تنظیمات همان درخواست
     * عادی ذخیره می‌شوند.
     *
     * توسعه‌دهنده‌ای که عمداً می‌خواهد هر دو به یک مقصد بروند، می‌تواند این محافظ را با
     * فیلتر armma_woo_notifer_allow_duplicate_chat_id غیرفعال کند.
     *
     * @param array $settings تنظیمات جدید (پس از اعمال فرم)
     * @param array $previous تنظیمات پیش از این ذخیره
     * @param string $section بخشی که فرمش ارسال شده
     * @param array $errors   خروجی: پیام‌های خطا برای نمایش به ادمین
     * @return array تنظیمات اصلاح‌شده
     */
    private function reject_duplicate_chat_ids( $settings, $previous, $section, &$errors ) {
        if ( apply_filters( 'armma_woo_notifer_allow_duplicate_chat_id', false ) ) {
            return $settings;
        }

        foreach ( ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms() as $platform_id => $label ) {
            $order_chat     = trim( (string) $settings['platforms'][ $platform_id ]['chat_id'] );
            $order_alt_chat = trim( (string) $settings['platforms'][ $platform_id ]['alt_chat_id'] );
            $channel_chat   = trim( (string) $settings['product_broadcast']['platforms'][ $platform_id ]['chat_id'] );

            if ( '' === $channel_chat ) {
                continue;
            }

            $clashes_with_main = ( '' !== $order_chat && $order_chat === $channel_chat );
            $clashes_with_alt  = ( '' !== $order_alt_chat && $order_alt_chat === $channel_chat );

            if ( ! $clashes_with_main && ! $clashes_with_alt ) {
                continue;
            }

            $conflict_label = $clashes_with_main ? 'مقصد پیام سفارش' : 'مقصد لغو/مرجوعی';

            if ( 'channel' === $section ) {
                // کاربر در صفحه «کانال فروشگاه» شناسه‌ای را وارد کرده که قبلاً مقصد سفارش است
                $settings['product_broadcast']['platforms'][ $platform_id ]['chat_id']    = $previous['product_broadcast']['platforms'][ $platform_id ]['chat_id'];
                $settings['product_broadcast']['platforms'][ $platform_id ]['chat_title'] = $previous['product_broadcast']['platforms'][ $platform_id ]['chat_title'];
                $errors[] = sprintf(
                    'کانال فروشگاه %s ذخیره نشد: این شناسه هم‌اکنون به‌عنوان «%s» همین پیام‌رسان استفاده می‌شود. یک کانال یا گروه دیگر انتخاب کنید.',
                    $label,
                    $conflict_label
                );
                continue;
            }

            // صفحه «تنظیمات و اتصال»: شناسه‌ای وارد شده که قبلاً کانال فروشگاه است
            if ( $clashes_with_main ) {
                $settings['platforms'][ $platform_id ]['chat_id']    = $previous['platforms'][ $platform_id ]['chat_id'];
                $settings['platforms'][ $platform_id ]['chat_title'] = $previous['platforms'][ $platform_id ]['chat_title'];
                $errors[] = sprintf(
                    'مقصد پیام سفارش %s ذخیره نشد: این شناسه هم‌اکنون به‌عنوان «کانال فروشگاه» همین پیام‌رسان استفاده می‌شود. یک گروه دیگر انتخاب کنید.',
                    $label
                );
            }

            if ( $clashes_with_alt ) {
                $settings['platforms'][ $platform_id ]['alt_chat_id']    = $previous['platforms'][ $platform_id ]['alt_chat_id'];
                $settings['platforms'][ $platform_id ]['alt_chat_title'] = $previous['platforms'][ $platform_id ]['alt_chat_title'];
                $errors[] = sprintf(
                    'مقصد لغو/مرجوعی %s ذخیره نشد: این شناسه هم‌اکنون به‌عنوان «کانال فروشگاه» همین پیام‌رسان استفاده می‌شود. یک گروه دیگر انتخاب کنید.',
                    $label
                );
            }
        }

        return $settings;
    }

    /**
     * بررسی مجوز و نانس مشترک برای درخواست‌های Ajax
     */
    private function verify_ajax_request() {
        check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ) );
        }
    }

    /**
     * استخراج و اعتبارسنجی شناسه پیام‌رسان ارسالی در یک درخواست Ajax
     * @return string|null در صورت نامعتبر بودن، پاسخ خطا ارسال و null برگردانده می‌شود (فراخوان باید فوراً return کند)
     */
    private function get_platform_from_request() {
        $platform_id = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '';
        $supported   = array_keys( ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms() );
        if ( ! in_array( $platform_id, $supported, true ) ) {
            wp_send_json_error( array( 'message' => 'پیام‌رسان نامعتبر است.' ) );
            return null;
        }
        return $platform_id;
    }

    /**
     * Ajax: تست معتبر بودن توکن بات (دکمه «بررسی توکن»)
     */
    public function ajax_test_token() {
        $this->verify_ajax_request();

        $platform_id = $this->get_platform_from_request();
        $token       = isset( $_POST['bot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) : '';

        $api    = ARMMA_WOO_NOTIFER_Messenger_API::create( $platform_id, $token );
        $result = $api->test_bot_token();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => isset( $result['display_message'] ) ? $result['display_message'] : 'اتصال موفق.',
        ) );
    }

    /**
     * Ajax: دریافت خودکار Chat ID گروه (دکمه «دریافت خودکار شناسه گروه»)
     */
    public function ajax_fetch_chat_id() {
        $this->verify_ajax_request();

        $platform_id = $this->get_platform_from_request();
        $token       = isset( $_POST['bot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) : '';

        // در صفحه «کانال فروشگاه» فیلد توکن وجود ندارد (توکن فقط یک‌بار در صفحه
        // «تنظیمات و اتصال» وارد می‌شود)؛ در آن حالت توکن ذخیره‌شده همان پیام‌رسان
        // از تنظیمات خوانده می‌شود تا توکن لازم نباشد در سورس صفحه قرار بگیرد.
        if ( '' === $token ) {
            $settings = $this->get_settings();
            $token    = $settings['platforms'][ $platform_id ]['bot_token'];
        }

        if ( '' === $token ) {
            wp_send_json_error( array(
                'message' => 'ابتدا در صفحه «تنظیمات و اتصال» توکن این پیام‌رسان را وارد و ذخیره کنید.',
            ) );
        }

        $api   = ARMMA_WOO_NOTIFER_Messenger_API::create( $platform_id, $token );
        $chats = $api->get_recent_chats();

        if ( is_wp_error( $chats ) ) {
            wp_send_json_error( array( 'message' => $chats->get_error_message() ) );
        }

        wp_send_json_success( array( 'chats' => $chats ) );
    }

    /**
     * Ajax: ارسال پیام آزمایشی به گروه برای اطمینان از صحت تنظیمات
     */
    public function ajax_send_test_message() {
        $this->verify_ajax_request();

        $platform_id = $this->get_platform_from_request();
        $token       = isset( $_POST['bot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) : '';
        $chat_id     = isset( $_POST['chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_id'] ) ) : '';

        $test_text = "✅ پیام آزمایشی\nاین یک پیام آزمایشی از ربات پیام‌رسان آرما است. اتصال شما به‌درستی برقرار است.";

        $api    = ARMMA_WOO_NOTIFER_Messenger_API::create( $platform_id, $token );
        $result = $api->send_message( $chat_id, $test_text );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => 'پیام آزمایشی با موفقیت ارسال شد. لطفاً گروه را بررسی کنید.' ) );
    }

    /* ---------------------------------------------------------------------
     * بخش نمایش (UI)
     * ------------------------------------------------------------------- */

    /**
     * وضعیت خلاصه یک پیام‌رسان برای نمایش به‌صورت نشان رنگی در هدر صفحه
     */
    private function get_platform_status( $platform_settings ) {
        if ( 'yes' !== $platform_settings['enabled'] ) {
            return array( 'state' => 'off', 'label' => 'غیرفعال' );
        }
        if ( empty( $platform_settings['bot_token'] ) || empty( $platform_settings['chat_id'] ) ) {
            return array( 'state' => 'warn', 'label' => 'ناقص' );
        }
        return array( 'state' => 'on', 'label' => 'فعال' );
    }

    /**
     * هدر مشترک بالای همه صفحات افزونه: عنوان، نسخه، نشان‌های وضعیت و نوار تب‌ها
     */
    private function render_page_header( $current_slug, $settings ) {
        $pages     = $this->get_pages();
        $platforms = ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms();
        $master_on = ( 'yes' === $settings['enabled'] );
        ?>
        <div class="armma-header">
            <div class="armma-header-brand">
                <span class="armma-header-icon dashicons dashicons-format-chat"></span>
                <div>
                    <h1>ربات پیام‌رسان آرما</h1>
                    <p class="armma-header-sub">
                        نسخه <?php echo esc_html( ARMMA_WOO_NOTIFER_VERSION ); ?> ·
                        اطلاع‌رسانی سفارش و محصول در روبیکا و بله
                    </p>
                </div>
            </div>

            <div class="armma-header-status">
                <span class="armma-status-chip armma-status-<?php echo $master_on ? 'on' : 'off'; ?>">
                    <span class="armma-dot"></span>
                    ارسال خودکار: <?php echo $master_on ? 'روشن' : 'خاموش'; ?>
                </span>
                <?php foreach ( $platforms as $platform_id => $label ) :
                    $status = $this->get_platform_status( $settings['platforms'][ $platform_id ] );
                    ?>
                    <span class="armma-status-chip armma-status-<?php echo esc_attr( $status['state'] ); ?>">
                        <span class="armma-dot"></span>
                        <?php echo esc_html( $label . ': ' . $status['label'] ); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <nav class="nav-tab-wrapper armma-nav">
            <?php foreach ( $pages as $slug => $title ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
                   class="nav-tab <?php echo $slug === $current_slug ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $title ); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <hr class="wp-header-end">
        <?php
    }

    /**
     * نوار ذخیره چسبان پایین فرم
     */
    private function render_save_bar() {
        ?>
        <div class="armma-save-bar">
            <button type="submit" name="armma_save_settings" class="button button-primary">ذخیره تنظیمات</button>
            <span class="armma-save-hint">تغییرات تا وقتی ذخیره نکنید اعمال نمی‌شوند.</span>
        </div>
        <?php
    }

    /**
     * یک ردیف فیلد (جایگزین form-table وردپرس؛ ساختار ساده‌تر و خواناتر در حالت RTL)
     */
    private function open_field( $label, $for = '', $class = '' ) {
        ?>
        <div class="armma-field <?php echo esc_attr( $class ); ?>">
            <div class="armma-field-label">
                <?php if ( $for ) : ?>
                    <label for="<?php echo esc_attr( $for ); ?>"><?php echo esc_html( $label ); ?></label>
                <?php else : ?>
                    <span class="armma-label-text"><?php echo esc_html( $label ); ?></span>
                <?php endif; ?>
            </div>
            <div class="armma-field-control">
        <?php
    }

    private function close_field() {
        echo '</div></div>';
    }

    /**
     * رندر کارت تنظیمات یک پیام‌رسان (روبیکا یا بله) در صفحه «تنظیمات و اتصال»
     */
    private function render_platform_card( $platform_id, $label, $platform_settings ) {
        $status   = $this->get_platform_status( $platform_settings );
        $alt_id   = 'armma_' . $platform_id . '_alt_fields';
        ?>
        <div class="armma-card armma-platform-card" data-platform="<?php echo esc_attr( $platform_id ); ?>">

            <div class="armma-card-head">
                <h2><?php echo esc_html( 'اتصال به ' . $label ); ?></h2>
                <div class="armma-card-head-actions">
                    <span class="armma-status-chip armma-status-<?php echo esc_attr( $status['state'] ); ?>">
                        <span class="armma-dot"></span><?php echo esc_html( $status['label'] ); ?>
                    </span>
                    <label class="armma-switch">
                        <input type="checkbox" name="armma_<?php echo esc_attr( $platform_id ); ?>_enabled" value="1"
                            <?php checked( $platform_settings['enabled'], 'yes' ); ?> />
                        <span class="armma-switch-label">فعال</span>
                    </label>
                </div>
            </div>

            <div class="armma-card-body">

                <?php $this->open_field( 'توکن بات', 'armma_' . $platform_id . '_bot_token' ); ?>
                    <input type="text" id="armma_<?php echo esc_attr( $platform_id ); ?>_bot_token" name="armma_<?php echo esc_attr( $platform_id ); ?>_bot_token"
                           value="<?php echo esc_attr( $platform_settings['bot_token'] ); ?>"
                           class="armma-input armma-input-wide armma-token-input" dir="ltr" autocomplete="off"
                           placeholder="توکن دریافتی از BotFather@" />
                    <p class="armma-hint">توکن را از بات <b>BotFather@</b> در <?php echo esc_html( $label ); ?> بگیرید.</p>
                    <details class="armma-more">
                        <summary>توضیح بیشتر</summary>
                        <div class="armma-more-body">
                            این توکن مثل رمز عبور بات شماست؛ آن را با کسی به اشتراک نگذارید. بعد از
                            وارد کردن، با دکمه «بررسی اتصال» مطمئن شوید توکن معتبر است و سرور سایت
                            به <?php echo esc_html( $label ); ?> دسترسی دارد.
                        </div>
                    </details>
                    <p class="armma-actions">
                        <button type="button" class="button armma-test-token-btn" data-platform="<?php echo esc_attr( $platform_id ); ?>">بررسی اتصال</button>
                        <span id="armma_<?php echo esc_attr( $platform_id ); ?>_test_token_result" class="armma-result"></span>
                    </p>
                <?php $this->close_field(); ?>

                <?php $this->open_field( 'مقصد پیام سفارش', 'armma_' . $platform_id . '_chat_id' ); ?>
                    <div class="armma-input-row">
                        <input type="text" id="armma_<?php echo esc_attr( $platform_id ); ?>_chat_id" name="armma_<?php echo esc_attr( $platform_id ); ?>_chat_id"
                               value="<?php echo esc_attr( $platform_settings['chat_id'] ); ?>"
                               class="armma-input" dir="ltr" placeholder="شناسه شخص یا گروه (Chat ID)" />
                        <input type="hidden" id="armma_<?php echo esc_attr( $platform_id ); ?>_chat_title" name="armma_<?php echo esc_attr( $platform_id ); ?>_chat_title" value="<?php echo esc_attr( $platform_settings['chat_title'] ); ?>" />
                        <?php if ( ! empty( $platform_settings['chat_title'] ) ) : ?>
                            <span class="armma-chat-title-badge">مقصد: <?php echo esc_html( $platform_settings['chat_title'] ); ?></span>
                        <?php endif; ?>
                        <button type="button" class="button armma-fetch-chatid-btn"
                                data-platform="<?php echo esc_attr( $platform_id ); ?>"
                                data-target="armma_<?php echo esc_attr( $platform_id ); ?>_chat_id"
                                data-target-title="armma_<?php echo esc_attr( $platform_id ); ?>_chat_title">
                            دریافت خودکار شناسه
                        </button>
                    </div>
                    <p class="armma-hint">می‌تواند یک گروه باشد یا یک شخص (چت خصوصی با بات).</p>
                    <span class="armma-fetch-result armma-result"></span>
                    <details class="armma-more">
                        <summary>توضیح بیشتر</summary>
                        <div class="armma-more-body">
                            <b>برای گروه:</b> بات را به گروه اضافه و <b>ادمین</b> کنید، سپس یک پیام ساده
                            (مثلاً <code dir="ltr">/start</code>) در گروه بفرستید.<br>
                            <b>برای یک شخص:</b> همان شخص باید یک پیام ساده مستقیماً به بات بدهد.<br>
                            بعد روی «دریافت خودکار شناسه» بزنید و مقصد را از لیست انتخاب کنید.
                        </div>
                    </details>
                    <div class="armma-chat-list"></div>
                <?php $this->close_field(); ?>

                <?php $this->open_field( 'تست ارسال' ); ?>
                    <p class="armma-actions">
                        <button type="button" class="button armma-send-test-btn" data-platform="<?php echo esc_attr( $platform_id ); ?>">ارسال پیام آزمایشی</button>
                        <span id="armma_<?php echo esc_attr( $platform_id ); ?>_send_test_result" class="armma-result"></span>
                    </p>
                    <p class="armma-hint">یک پیام نمونه به مقصد بالا فرستاده می‌شود تا از درستی تنظیمات مطمئن شوید.</p>
                <?php $this->close_field(); ?>

                <?php $this->open_field( 'مقصد جداگانه برای لغو/مرجوعی' ); ?>
                    <label class="armma-switch">
                        <input type="checkbox" name="armma_<?php echo esc_attr( $platform_id ); ?>_alt_enabled" value="1"
                               data-armma-toggle="<?php echo esc_attr( $alt_id ); ?>"
                            <?php checked( $platform_settings['alt_enabled'], 'yes' ); ?> />
                        <span class="armma-switch-label">ارسال این وضعیت‌ها به مقصد دیگر</span>
                    </label>
                    <p class="armma-hint">مناسب برای اطلاع‌رسانی جدا به تیم پشتیبانی.</p>

                    <div class="armma-dependent" id="<?php echo esc_attr( $alt_id ); ?>">
                        <details class="armma-more">
                            <summary>توضیح بیشتر</summary>
                            <div class="armma-more-body">
                                پیام سفارش‌هایی که به وضعیت «لغوشده»، «مرجوعی» یا «ناموفق» می‌روند — به‌شرطی که
                                همان وضعیت‌ها در کارت «قوانین ارسال سفارش» هم تیک خورده باشند — به مقصد زیر
                                می‌رود، نه مقصد اصلی.
                            </div>
                        </details>
                        <div class="armma-input-row">
                            <input type="text" id="armma_<?php echo esc_attr( $platform_id ); ?>_alt_chat_id" name="armma_<?php echo esc_attr( $platform_id ); ?>_alt_chat_id"
                                   value="<?php echo esc_attr( $platform_settings['alt_chat_id'] ); ?>"
                                   class="armma-input" dir="ltr" placeholder="شناسه جایگزین شخص یا گروه" />
                            <input type="hidden" id="armma_<?php echo esc_attr( $platform_id ); ?>_alt_chat_title" name="armma_<?php echo esc_attr( $platform_id ); ?>_alt_chat_title" value="<?php echo esc_attr( $platform_settings['alt_chat_title'] ); ?>" />
                            <?php if ( ! empty( $platform_settings['alt_chat_title'] ) ) : ?>
                                <span class="armma-chat-title-badge">مقصد: <?php echo esc_html( $platform_settings['alt_chat_title'] ); ?></span>
                            <?php endif; ?>
                            <button type="button" class="button armma-fetch-chatid-btn"
                                    data-platform="<?php echo esc_attr( $platform_id ); ?>"
                                    data-target="armma_<?php echo esc_attr( $platform_id ); ?>_alt_chat_id"
                                    data-target-title="armma_<?php echo esc_attr( $platform_id ); ?>_alt_chat_title">
                                دریافت خودکار شناسه
                            </button>
                        </div>
                        <span class="armma-fetch-result armma-result"></span>
                        <div class="armma-chat-list"></div>
                    </div>
                <?php $this->close_field(); ?>

            </div>
        </div>
        <?php
    }

    /**
     * صفحه ۱ — تنظیمات و اتصال
     */
    public function render_settings_page() {
        $settings     = $this->get_settings();
        $all_statuses = wc_get_order_statuses(); // مثل 'wc-processing' => 'در حال پردازش'
        $platforms    = ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms();
        ?>
        <div class="wrap armma-wrap">
            <?php $this->render_page_header( self::MENU_SLUG, $settings ); ?>

            <form method="post" action="">
                <?php wp_nonce_field( self::NONCE_ACTION, 'armma_nonce' ); ?>
                <input type="hidden" name="armma_section" value="connection" />

                <div class="armma-card armma-card-master">
                    <div class="armma-card-head">
                        <h2>فعال‌سازی ارسال خودکار</h2>
                        <label class="armma-switch">
                            <input type="checkbox" name="armma_enabled" id="armma_enabled" value="1"
                                <?php checked( $settings['enabled'], 'yes' ); ?> />
                            <span class="armma-switch-label">روشن</span>
                        </label>
                    </div>
                    <div class="armma-card-body">
                        <p class="armma-hint armma-hint-lead">
                            کلید اصلی افزونه. تا این کلید روشن نباشد، حتی اگر پیام‌رسان‌های پایین فعال باشند
                            هیچ پیام سفارشی ارسال نمی‌شود.
                        </p>
                    </div>
                </div>

                <?php
                foreach ( $platforms as $platform_id => $label ) {
                    $this->render_platform_card( $platform_id, $label, $settings['platforms'][ $platform_id ] );
                }
                ?>

                <div class="armma-card">
                    <div class="armma-card-head">
                        <h2>قوانین ارسال سفارش</h2>
                    </div>
                    <div class="armma-card-body">

                        <?php $this->open_field( 'وضعیت‌های سفارش' ); ?>
                            <p class="armma-hint">برای کدام وضعیت‌ها پیام فرستاده شود؟ معمولاً «در حال پردازش» یا «تکمیل‌شده».</p>
                            <div class="armma-choice-group">
                                <?php foreach ( $all_statuses as $status_key => $status_label ) :
                                    // وضعیت «پیش‌نویس» در این لیست منطقی نیست (سفارش هنوز نهایی نشده)
                                    if ( 'wc-checkout-draft' === $status_key ) {
                                        continue;
                                    }
                                    $clean_key = str_replace( 'wc-', '', $status_key );
                                    ?>
                                    <label class="armma-choice">
                                        <input type="checkbox" name="armma_order_statuses[]" value="<?php echo esc_attr( $clean_key ); ?>"
                                            <?php checked( in_array( $clean_key, $settings['order_statuses'], true ) ); ?> />
                                        <span><?php echo esc_html( $status_label ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php $this->close_field(); ?>

                        <?php $this->open_field( 'سفارش‌های پرمبلغ' ); ?>
                            <label class="armma-switch">
                                <input type="checkbox" name="armma_high_value_enabled" id="armma_high_value_enabled" value="1"
                                       data-armma-toggle="armma_high_value_fields"
                                    <?php checked( $settings['high_value_enabled'], 'yes' ); ?> />
                                <span class="armma-switch-label">علامت‌گذاری با ⭐️ در پیام</span>
                            </label>
                            <div class="armma-dependent" id="armma_high_value_fields">
                                <label class="armma-inline-label" for="armma_high_value_threshold">حداقل مبلغ (به واحد پول فروشگاه):</label>
                                <input type="number" min="0" step="1" id="armma_high_value_threshold" name="armma_high_value_threshold"
                                       value="<?php echo esc_attr( $settings['high_value_threshold'] ); ?>" class="armma-input armma-amount-input" dir="ltr" />
                            </div>
                        <?php $this->close_field(); ?>

                        <?php $this->open_field( 'نحوه ارسال' ); ?>
                            <div class="armma-choice-group armma-choice-block">
                                <label class="armma-choice">
                                    <input type="radio" name="armma_send_mode" value="cron"
                                        <?php checked( $settings['send_mode'], 'cron' ); ?> />
                                    <span>از طریق WP-Cron <span class="armma-choice-note">(پیش‌فرض)</span></span>
                                </label>
                                <label class="armma-choice">
                                    <input type="radio" name="armma_send_mode" value="direct"
                                        <?php checked( $settings['send_mode'], 'direct' ); ?> />
                                    <span>مستقیم و بلافاصله</span>
                                </label>
                            </div>
                            <p class="armma-hint">اگر پیام‌ها دیر می‌رسند یا اصلاً نمی‌رسند، حالت «مستقیم» را امتحان کنید.</p>
                            <details class="armma-more">
                                <summary>توضیح بیشتر</summary>
                                <div class="armma-more-body">
                                    <b>WP-Cron:</b> ارسال در یک درخواست پس‌زمینه جدا انجام می‌شود تا سرعت ثبت سفارش
                                    و ذخیره محصول کند نشود؛ اما به درست کار کردن Cron وردپرس روی هاست شما وابسته است.<br>
                                    <b>مستقیم و بلافاصله:</b> پیام همان لحظه ارسال می‌شود و هیچ وابستگی به Cron ندارد،
                                    اما ثبت سفارش یا ذخیره محصول تا پایان تماس با سرور پیام‌رسان منتظر می‌ماند.
                                </div>
                            </details>
                        <?php $this->close_field(); ?>

                    </div>
                </div>

                <?php $this->render_save_bar(); ?>
            </form>

            <?php $this->render_footer_credit(); ?>
        </div>
        <?php
    }

    /**
     * صفحه ۲ — کانال فروشگاه (اطلاع‌رسانی محصول جدید)
     */
    public function render_channel_page() {
        $settings  = $this->get_settings();
        $broadcast = $settings['product_broadcast'];
        $include   = $broadcast['include'];
        $platforms = ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms();
        ?>
        <div class="wrap armma-wrap">
            <?php $this->render_page_header( self::CHANNEL_SLUG, $settings ); ?>

            <form method="post" action="">
                <?php wp_nonce_field( self::NONCE_ACTION, 'armma_nonce' ); ?>
                <input type="hidden" name="armma_section" value="channel" />

                <div class="armma-card">
                    <div class="armma-card-head">
                        <h2>مقصد ارسال محصول</h2>
                    </div>
                    <div class="armma-card-body">
                        <p class="armma-hint armma-hint-lead">
                            با انتشار هر محصول، معرفی آن به کانال یا گروه فروش شما ارسال می‌شود. توکن جدا لازم نیست —
                            از همان توکن صفحه «تنظیمات و اتصال» استفاده می‌شود؛ فقط مقصد را اینجا مشخص کنید.
                        </p>

                        <?php foreach ( $platforms as $platform_id => $label ) :
                            $p = $broadcast['platforms'][ $platform_id ];
                            ?>
                            <?php $this->open_field( $label, '', 'armma-field-broadcast' ); ?>
                                <label class="armma-switch">
                                    <input type="checkbox" name="armma_<?php echo esc_attr( $platform_id ); ?>_broadcast_enabled" value="1"
                                        <?php checked( $p['enabled'], 'yes' ); ?> />
                                    <span class="armma-switch-label">ارسال به <?php echo esc_html( $label ); ?></span>
                                </label>
                                <div class="armma-input-row">
                                    <input type="text" id="armma_<?php echo esc_attr( $platform_id ); ?>_broadcast_chat_id" name="armma_<?php echo esc_attr( $platform_id ); ?>_broadcast_chat_id"
                                           value="<?php echo esc_attr( $p['chat_id'] ); ?>"
                                           class="armma-input" dir="ltr" placeholder="شناسه کانال یا گروه فروش" />
                                    <input type="hidden" id="armma_<?php echo esc_attr( $platform_id ); ?>_broadcast_chat_title" name="armma_<?php echo esc_attr( $platform_id ); ?>_broadcast_chat_title" value="<?php echo esc_attr( $p['chat_title'] ); ?>" />
                                    <?php if ( ! empty( $p['chat_title'] ) ) : ?>
                                        <span class="armma-chat-title-badge">مقصد: <?php echo esc_html( $p['chat_title'] ); ?></span>
                                    <?php endif; ?>
                                    <button type="button" class="button armma-fetch-chatid-btn"
                                            data-platform="<?php echo esc_attr( $platform_id ); ?>"
                                            data-target="armma_<?php echo esc_attr( $platform_id ); ?>_broadcast_chat_id"
                                            data-target-title="armma_<?php echo esc_attr( $platform_id ); ?>_broadcast_chat_title">
                                        دریافت خودکار شناسه
                                    </button>
                                </div>
                                <span class="armma-fetch-result armma-result"></span>
                                <p class="armma-hint">کانال فروش باید با گروه سفارش‌ها متفاوت باشد؛ یک شناسه نمی‌تواند هم‌زمان مقصد سفارش و کانال محصول باشد.</p>
                                <details class="armma-more">
                                    <summary>توضیح بیشتر</summary>
                                    <div class="armma-more-body">
                                        ابتدا در صفحه «تنظیمات و اتصال» توکن <?php echo esc_html( $label ); ?> را وارد کرده باشید.
                                        سپس بات را به کانال/گروه فروش اضافه و در صورت نیاز ادمین کنید و روی دکمه بالا بزنید.
                                    </div>
                                </details>
                                <div class="armma-chat-list"></div>
                            <?php $this->close_field(); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="armma-card">
                    <div class="armma-card-head">
                        <h2>زمان ارسال</h2>
                    </div>
                    <div class="armma-card-body">
                        <?php $this->open_field( 'پیام چه زمانی برود؟' ); ?>
                            <div class="armma-choice-group armma-choice-block">
                                <label class="armma-choice">
                                    <input type="radio" name="armma_broadcast_trigger" value="first_publish"
                                        <?php checked( $broadcast['trigger_mode'], 'first_publish' ); ?> />
                                    <span>فقط بار اول انتشار محصول</span>
                                </label>
                                <label class="armma-choice">
                                    <input type="radio" name="armma_broadcast_trigger" value="first_publish_and_edit"
                                        <?php checked( $broadcast['trigger_mode'], 'first_publish_and_edit' ); ?> />
                                    <span>بار اول + هر بار ویرایش و ذخیره</span>
                                </label>
                                <label class="armma-choice">
                                    <input type="radio" name="armma_broadcast_trigger" value="first_publish_and_price_change"
                                        <?php checked( $broadcast['trigger_mode'], 'first_publish_and_price_change' ); ?> />
                                    <span>بار اول + فقط هنگام تغییر قیمت</span>
                                </label>
                            </div>
                            <p class="armma-hint">
                                برای غیرفعال کردن ارسال یک محصول خاص، در صفحه ویرایش همان محصول تیک
                                «ارسال اطلاعات محصول به گروه» را بردارید.
                            </p>
                        <?php $this->close_field(); ?>
                    </div>
                </div>

                <div class="armma-card">
                    <div class="armma-card-head">
                        <h2>محتوای پیام</h2>
                    </div>
                    <div class="armma-card-body">

                        <?php $this->open_field( 'چه چیزهایی در پیام باشد؟' ); ?>
                            <div class="armma-choice-group">
                                <label class="armma-choice">
                                    <input type="checkbox" name="armma_broadcast_include_title" value="1" <?php checked( $include['title'], 'yes' ); ?> />
                                    <span>عنوان محصول</span>
                                </label>
                                <label class="armma-choice">
                                    <input type="checkbox" name="armma_broadcast_include_excerpt" value="1" <?php checked( $include['excerpt'], 'yes' ); ?> />
                                    <span>چکیده محصول</span>
                                </label>
                                <label class="armma-choice">
                                    <input type="checkbox" name="armma_broadcast_include_price" value="1" <?php checked( $include['price'], 'yes' ); ?> />
                                    <span>قیمت</span>
                                </label>
                                <label class="armma-choice">
                                    <input type="checkbox" name="armma_broadcast_include_image" value="1" <?php checked( $include['image'], 'yes' ); ?> />
                                    <span>تصویر محصول</span>
                                </label>
                                <label class="armma-choice">
                                    <input type="checkbox" name="armma_broadcast_include_attributes" value="1" <?php checked( $include['attributes'], 'yes' ); ?> />
                                    <span>ویژگی‌ها (رنگ، جنس، برند)</span>
                                </label>
                                <label class="armma-choice">
                                    <input type="checkbox" name="armma_broadcast_include_category" value="1" <?php checked( $include['category'], 'yes' ); ?> />
                                    <span>دسته‌بندی</span>
                                </label>
                                <label class="armma-choice">
                                    <input type="checkbox" name="armma_broadcast_include_tags" value="1" <?php checked( $include['tags'], 'yes' ); ?> />
                                    <span>برچسب</span>
                                </label>
                            </div>
                        <?php $this->close_field(); ?>

                        <?php $this->open_field( 'متن ابتدای پیام', 'armma_broadcast_header_text' ); ?>
                            <input type="text" id="armma_broadcast_header_text" name="armma_broadcast_header_text"
                                   value="<?php echo esc_attr( $broadcast['header_text'] ); ?>" class="armma-input armma-input-wide"
                                   placeholder="مثلاً: 🔥 فروش ویژه" />
                            <p class="armma-hint">اختیاری. خالی بگذارید تا پیام مستقیم از عنوان محصول شروع شود.</p>
                        <?php $this->close_field(); ?>

                        <?php $this->open_field( 'متن انتهای پیام', 'armma_broadcast_footer_text' ); ?>
                            <textarea id="armma_broadcast_footer_text" name="armma_broadcast_footer_text" class="armma-input armma-input-wide" rows="2"
                                      placeholder="مثلاً: برای مشاوره رایگان تماس بگیرید: ۰۹۱۲۳۴۵۶۷۸۹"><?php echo esc_textarea( $broadcast['footer_text'] ); ?></textarea>
                            <p class="armma-hint">اختیاری. بعد از لینک محصول، در انتهای پیام درج می‌شود.</p>
                        <?php $this->close_field(); ?>

                    </div>
                </div>

                <?php $this->render_save_bar(); ?>
            </form>

            <?php $this->render_footer_credit(); ?>
        </div>
        <?php
    }

    /**
     * صفحه ۳ — راهنما (بدون فرم و بدون دکمه ذخیره)
     */
    public function render_help_page() {
        $settings = $this->get_settings();
        $items    = $this->get_help_items();
        ?>
        <div class="wrap armma-wrap">
            <?php $this->render_page_header( self::HELP_SLUG, $settings ); ?>

            <div class="armma-card">
                <div class="armma-card-head">
                    <h2>راهنمای راه‌اندازی</h2>
                </div>
                <div class="armma-card-body">
                    <p class="armma-hint armma-hint-lead">روی هر بخش بزنید تا توضیحات آن باز شود.</p>
                    <div class="armma-accordion">
                        <?php foreach ( $items as $item ) : ?>
                            <details class="armma-accordion-item">
                                <summary class="armma-accordion-header"><?php echo esc_html( $item['title'] ); ?></summary>
                                <div class="armma-accordion-body"><?php echo wp_kses_post( $item['body'] ); ?></div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php $this->render_footer_credit(); ?>
        </div>
        <?php
    }

    /**
     * محتوای بخش‌های راهنما (بدون تغییر نسبت به نسخه قبل، فقط از HTML درون‌خطی به آرایه منتقل شده)
     */
    private function get_help_items() {
        return array(
            array(
                'title' => '۱. ساخت بات در روبیکا یا بله',
                'body'  => '<ol>
                    <li>در اپلیکیشن روبیکا یا بله، به بات <b>BotFather@</b> پیام دهید.</li>
                    <li>دستور ساخت بات جدید را بزنید و یک نام برای بات انتخاب کنید.</li>
                    <li>توکن دریافتی را کپی کرده و در کارت همان پیام‌رسان در صفحه «تنظیمات و اتصال» وارد کنید.</li>
                    <li>روی «بررسی اتصال» بزنید تا مطمئن شوید توکن درست است.</li>
                </ol>',
            ),
            array(
                'title' => '۲. اتصال بات به گروه فروشگاه یا یک شخص مشخص',
                'body'  => '<p>مقصد پیام می‌تواند یک گروه (مثلاً گروه فروش) باشد یا یک شخص مشخص (چت خصوصی با بات).</p>
                <p><b>روش اول — گروه:</b></p>
                <ol>
                    <li>یک گروه بسازید یا از گروه موجود فروشگاه استفاده کنید.</li>
                    <li>بات ساخته‌شده را جستجو کرده و به گروه اضافه کنید.</li>
                    <li>از لیست اعضای گروه، بات را پیدا کرده و آن را <b>ادمین</b> کنید (بدون این مرحله بات نمی‌تواند پیام بفرستد).</li>
                    <li>یک پیام ساده مثل <code dir="ltr">/start</code> در گروه بفرستید.</li>
                </ol>
                <p><b>روش دوم — یک شخص مشخص:</b></p>
                <ol>
                    <li>همان شخص باید بات را پیدا کرده و یک پیام ساده مثل <code dir="ltr">/start</code> مستقیماً به بات بدهد.</li>
                </ol>
                <p>در هر دو حالت، در صفحه «تنظیمات و اتصال» روی دکمه «دریافت خودکار شناسه» بزنید و مقصد مورد نظر را از لیست انتخاب کنید.</p>',
            ),
            array(
                'title' => '۳. فعال‌سازی نهایی',
                'body'  => '<ol>
                    <li>در کارت «فعال‌سازی ارسال خودکار» (بالای صفحه تنظیمات) کلید اصلی را روشن کنید.</li>
                    <li>سوییچ بالای کارت هر پیام‌رسانی که می‌خواهید استفاده کنید را هم روشن کنید (می‌توانید هم‌زمان چند پیام‌رسان فعال کنید).</li>
                    <li>در کارت «قوانین ارسال سفارش»، وضعیت(های) موردنظر را تیک بزنید (مثلاً «در حال پردازش»).</li>
                    <li>روی «ذخیره تنظیمات» بزنید.</li>
                    <li>با ثبت یک سفارش آزمایشی یا تغییر دستی وضعیت یک سفارش، مطمئن شوید پیام دریافت می‌شود.</li>
                </ol>',
            ),
            array(
                'title' => '۴. اتصال به کانال فروش (اطلاع‌رسانی محصول جدید)',
                'body'  => '<p>این بخش کاملاً جدا از اطلاع‌رسانی سفارش است و تنظیماتش در صفحه «کانال فروشگاه» قرار دارد.</p>
                <ol>
                    <li>توکن بات جداگانه لازم نیست؛ از همان توکنی استفاده می‌شود که در صفحه «تنظیمات و اتصال» وارد کرده‌اید. پس ابتدا مورد ۱ و ۲ همین راهنما را برای همان پیام‌رسان انجام دهید.</li>
                    <li>بات را به کانال یا گروه فروش (که می‌تواند با گروه سفارش‌ها متفاوت باشد) اضافه و در صورت نیاز <b>ادمین</b> کنید.</li>
                    <li>در صفحه «کانال فروشگاه»، سوییچ همان پیام‌رسان را روشن کنید.</li>
                    <li>روی «دریافت خودکار شناسه» بزنید و کانال/گروه فروش را از لیست انتخاب کنید.</li>
                    <li>مشخص کنید هر پیام شامل چه بخش‌هایی باشد و در صورت تمایل متن ابتدا/انتهای پیام را وارد کنید.</li>
                    <li>مشخص کنید پیام چه زمانی ارسال شود: فقط بار اول انتشار، بار اول + هر ویرایش، یا بار اول + فقط تغییر قیمت.</li>
                    <li>روی «ذخیره تنظیمات» بزنید. برای غیرفعال کردن اطلاع‌رسانی یک محصول خاص، در صفحه ویرایش همان محصول (تب «عمومی») تیک «ارسال اطلاعات محصول به گروه» را بردارید.</li>
                </ol>',
            ),
            array(
                'title' => '۵. دسترسی سرور به روبیکا/بله (مخصوص هاست‌های محدودشده)',
                'body'  => '<p>برخی هاست‌ها به‌دلایل امنیتی، دسترسی سایت به آدرس‌های خارجی را در فایل <code dir="ltr">wp-config.php</code> محدود می‌کنند. اگر خط زیر را در آن فایل دیدید:</p>
                <p><code dir="ltr">define( \'WP_HTTP_BLOCK_EXTERNAL\', true );</code></p>
                <p>یعنی سایت شما فقط اجازه دارد به آدرس‌های مشخص‌شده در <code dir="ltr">WP_ACCESSIBLE_HOSTS</code> وصل شود؛ در این حالت باید آدرس‌های موردنیاز این افزونه را به همان لیست اضافه کنید، وگرنه ارسال پیام با خطای اتصال مواجه می‌شود. آدرس‌های موردنیاز:</p>
                <ul>
                    <li><code dir="ltr">botapi.rubika.ir</code> — ارسال و دریافت پیام روبیکا</li>
                    <li><code dir="ltr">messengerg2f1.rubika.ir</code> — آپلود تصویر در روبیکا</li>
                    <li><code dir="ltr">tapi.bale.ai</code> — ارسال و دریافت پیام و تصویر بله</li>
                </ul>
                <p>در فایل <code dir="ltr">wp-config.php</code> مقدار زیر را اضافه کنید:</p>
                <p><code dir="ltr">define( \'WP_ACCESSIBLE_HOSTS\', \'botapi.rubika.ir,messengerg2f1.rubika.ir,tapi.bale.ai\' );</code></p>
                <p>اگر سایت شما چنین محدودیتی ندارد، نیازی به این مرحله نیست.</p>',
            ),
            array(
                'title' => '۶. پیام سفارش نمی‌رسد؟ اینجا را بررسی کنید',
                'body'  => '<p>اگر «ارسال پیام آزمایشی» موفق است ولی با ثبت یا تغییر وضعیت سفارش پیامی نمی‌رسد، این موارد را به ترتیب بررسی کنید:</p>
                <ol>
                    <li>کلید «فعال‌سازی ارسال خودکار» و سوییچ بالای کارت پیام‌رسان، هر دو روشن و ذخیره شده باشند.</li>
                    <li>وضعیتی که سفارش به آن تغییر می‌کند، دقیقاً همان وضعیتی باشد که در «قوانین ارسال سفارش» تیک زده‌اید.</li>
                    <li>شناسه شخص یا گروه (Chat ID) پر شده باشد؛ اگر خالی است، دوباره از دکمه «دریافت خودکار شناسه» استفاده کنید.</li>
                    <li>اگر مقصد یک گروه است، بات هنوز عضو و ادمین همان گروه باشد.</li>
                    <li>هر سفارش فقط یک‌بار برای هر وضعیت پیام می‌گیرد؛ در صفحه ویرایش همان سفارش، بخش «ربات پیام‌رسان آرما» را باز کنید و از دکمه «ارسال مجدد» استفاده کنید.</li>
                    <li>در همان بخش (و همچنین «یادداشت‌های سفارش»)، اگر ارسال با خطا مواجه شده باشد، متن خطا ثبت می‌شود.</li>
                    <li>اگر «نحوه ارسال» روی WP-Cron است و همچنان پیامی نمی‌رسد، احتمالاً Cron وردپرس روی هاست شما درست کار نمی‌کند؛ آن را روی «مستقیم و بلافاصله» بگذارید.</li>
                    <li>اگر خطا مربوط به عدم اتصال یا timeout است، مورد ۵ همین راهنما را بررسی کنید.</li>
                </ol>',
            ),
        );
    }

    private function render_footer_credit() {
        ?>
        <p class="armma-footer-credit">
            افزونه «ربات پیام‌رسان آرما» نسخه <?php echo esc_html( ARMMA_WOO_NOTIFER_VERSION ); ?> —
            طراحی و توسعه: <a href="https://alirahimpoor.ir" target="_blank" rel="noopener">علی رحیم پور</a>
        </p>
        <?php
    }
}
