<?php
/**
 * ارسال معرفی محصول به کانال/گروه بله و/یا روبیکا هنگام انتشار محصول در ووکامرس
 * (و به دلخواه ادمین، در ویرایش‌های بعدی هم). این ویژگی کاملاً مستقل از اطلاع‌رسانی
 * سفارش است؛ مقصد و فعال/غیرفعال بودنش هم جدا تنظیم می‌شود، اما توکن بات همان
 * توکنی است که در کارت پیام‌رسان مربوطه (بخش اطلاع‌رسانی سفارش) وارد شده.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ARMMA_WOO_NOTIFER_Product_Broadcaster {

    /**
     * نام هوک cron که ارسال واقعی پیام محصول (تماس‌های شبکه‌ای نسبتاً کند با
     * پیام‌رسان‌ها) را در یک درخواست پس‌زمینه جدا انجام می‌دهد، تا ذخیره محصول در
     * پیشخوان معطل پاسخ API پیام‌رسان نماند.
     */
    const CRON_HOOK = 'armma_woo_notifer_send_product_broadcast';

    /**
     * زمان «مبنا»: از این لحظه به بعد، محصول تازه‌منتشرشده به‌حساب می‌آید. محصولاتی که
     * پیش از این زمان روی سایت بوده‌اند، قبلاً معرفی‌شده در نظر گرفته می‌شوند.
     */
    const BASELINE_OPTION = 'armma_woo_notifer_broadcast_baseline';

    /**
     * یک‌بار برای همیشه: زمان مبنا را ثبت می‌کند و برای همه محصولات منتشرشده موجود،
     * پرچم «قبلاً معرفی شده» را می‌نویسد.
     *
     * بدون این کار، هر محصول قدیمی اولین باری که به هر دلیلی ذخیره می‌شد (از جمله
     * کاهش موجودی هنگام خرید مشتری) به‌عنوان «محصول جدید» به کانال معرفی می‌شد.
     * چون فقط یک‌بار اجرا می‌شود، روی فروشگاه‌های بزرگ هم هزینه‌ای ندارد.
     */
    public static function maybe_set_broadcast_baseline() {
        if ( get_option( self::BASELINE_OPTION, 0 ) ) {
            return;
        }

        global $wpdb;

        $wpdb->query(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
             SELECT p.ID, '_armma_first_broadcast_done', '1'
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID AND pm.meta_key = '_armma_first_broadcast_done'
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status = 'publish'
               AND pm.post_id IS NULL"
        );

        add_option( self::BASELINE_OPTION, time(), '', false );
    }

    /**
     * وضعیت‌هایی که در همین درخواست برای یک محصول مشخص، پیام ارسال شده تا از ارسال
     * تکراری هنگامی که چند هوک مختلف (مثلاً transition_post_status و
     * woocommerce_update_product) در یک درخواست ذخیره برای همان محصول فایر می‌شوند،
     * جلوگیری شود.
     * @var array<int,bool>
     */
    private static $sent_in_this_request = array();

    /**
     * فیلدهایی (prop) که در جریان ذخیره هر محصول واقعاً تغییر کرده‌اند، به تفکیک محصول.
     * برای تشخیص «ذخیره‌های صرفاً انباری» استفاده می‌شود؛ رجوع کنید به is_stock_only_save().
     * @var array<int,array<int,string>>
     */
    private static $updated_props = array();

    /**
     * فیلدهایی که تغییرشان به‌تنهایی به‌معنای ویرایش محتوایی محصول نیست و نباید باعث
     * ارسال پیام معرفی شود. کاهش موجودی هنگام خرید مشتری دقیقاً در همین دسته است.
     * @var array<int,string>
     */
    private static $stock_only_props = array(
        'stock_quantity',
        'stock_status',
        'manage_stock',
        'backorders',
        'low_stock_amount',
        'total_sales',
        'date_modified',
        'rating_counts',
        'average_rating',
        'review_count',
    );

    private $trigger_mode = 'first_publish';

    public function __construct() {
        $settings = get_option( ARMMA_WOO_NOTIFER_Settings::OPTION_KEY, array() );
        $this->trigger_mode = isset( $settings['product_broadcast']['trigger_mode'] )
            ? $settings['product_broadcast']['trigger_mode']
            : 'first_publish';

        // هوک‌های woocommerce_new_product و woocommerce_update_product عمداً به‌جای
        // transition_post_status استفاده می‌شوند: transition_post_status از داخل
        // wp_insert_post() فایر می‌شود که در جریان «انتشار محصول از پیشخوان»، ZOODتر
        // از ذخیره‌سازی فیلدهای اختصاصی ووکامرس (قیمت، موجودی و ...) اتفاق می‌افتد؛
        // یعنی در لحظه فایر شدنش، قیمت هنوز در دیتابیس نوشته نشده و get_price() مقدار
        // خالی/صفر برمی‌گرداند. این دو هوک ووکامرس اما از انتهای متد $product->save()
        // فایر می‌شوند، یعنی همه‌چیز (شامل قیمت) از قبل ذخیره شده است.
        add_action( 'woocommerce_new_product', array( $this, 'handle_product_saved' ), 20, 1 );
        add_action( 'woocommerce_update_product', array( $this, 'handle_product_saved' ), 20, 1 );

        // ثبت فیلدهای تغییرکرده در هر ذخیره (همیشه، مستقل از حالت ارسال). این هوک
        // پیش از woocommerce_update_product فایر می‌شود، بنابراین در لحظه تصمیم‌گیری
        // در handle_product_saved اطلاعاتش در دسترس است.
        add_action( 'woocommerce_product_object_updated_props', array( $this, 'record_updated_props' ), 5, 2 );

        // هندلر واقعی ارسال؛ توسط رویداد cron در یک درخواست پس‌زمینه جدا اجرا می‌شود
        // (یا در حالت «مستقیم» تنظیمات، بلافاصله در همین درخواست).
        add_action( self::CRON_HOOK, array( $this, 'send_broadcast_now' ), 10, 1 );

        if ( 'first_publish_and_price_change' === $this->trigger_mode ) {
            // ارسال مجدد فقط وقتی قیمت محصول واقعاً تغییر کرده باشد
            add_action( 'woocommerce_product_object_updated_props', array( $this, 'handle_price_change' ), 10, 2 );
        }

        // چک‌باکس «ارسال اطلاعات محصول به گروه» در تب General صفحه ویرایش محصول
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_broadcast_toggle_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_broadcast_toggle_field' ) );
    }

    /**
     * رندر چک‌باکس «ارسال اطلاعات محصول به گروه» در تب «عمومی» صفحه ویرایش محصول.
     * پیش‌فرض روشن است (چون هیچ مقداری هنوز ذخیره نشده و طبق منطق broadcast()، نبودِ
     * مقدار هم یعنی فعال). اگر ادمین این تیک را برای یک محصول خاص بردارد، آن محصول
     * مشخص هرگز به کانال ارسال نمی‌شود، فارغ از تنظیمات کلی.
     */
    public function render_broadcast_toggle_field() {
        global $post;
        $enabled = 'no' !== get_post_meta( $post->ID, '_armma_broadcast_enabled', true );

        woocommerce_wp_checkbox( array(
            'id'          => '_armma_broadcast_enabled',
            'label'       => 'ارسال اطلاعات محصول به گروه',
            'description' => 'اگر این تیک برداشته شود، این محصول مشخص در کانال/گروه پیام‌رسان اطلاع‌رسانی نمی‌شود (فارغ از تنظیمات کلی افزونه).',
            'value'       => $enabled ? 'yes' : 'no',
        ) );
    }

    /**
     * ذخیره چک‌باکس «ارسال اطلاعات محصول به گروه» هنگام ذخیره محصول
     */
    public function save_broadcast_toggle_field( $post_id ) {
        $enabled = isset( $_POST['_armma_broadcast_enabled'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_armma_broadcast_enabled', $enabled );
    }

    /**
     * بررسی فعال بودن اطلاع‌رسانی برای یک محصول مشخص. اولویت با مقدار واقعی
     * ارسال‌شده در همین درخواست است (اگر این دقیقاً همان ذخیره‌ای باشد که از فرم
     * کلاسیک ویرایش محصول ارسال شده)، تا نتیجه مستقل از ترتیب اجرای هوک‌های مختلف
     * ووکامرس (که ممکن است save_broadcast_toggle_field را زودتر یا دیرتر از هوک
     * ارسال پیام فایر کنند) همیشه درست باشد. در غیر این صورت (مثلاً ذخیره از طریق
     * REST API یا ابزار وارد کردن محصول)، به مقدار ذخیره‌شده در دیتابیس رجوع می‌شود؛
     * هر مقداری غیر از رشته دقیق 'no' به معنای فعال است، تا محصولاتی که هنوز هیچ
     * مقداری برایشان ذخیره نشده (مثلاً محصولات قدیمی‌تر از این ویژگی) هم پیش‌فرض
     * فعال باشند، بدون نیاز به نوشتن مقدار 'yes' برای همه محصولات موجود.
     */
    private function is_broadcast_enabled_for_product( $product_id ) {
        if (
            isset( $_POST['woocommerce_meta_nonce'] )
            && isset( $_POST['post_ID'] )
            && (int) $_POST['post_ID'] === (int) $product_id
        ) {
            return isset( $_POST['_armma_broadcast_enabled'] );
        }

        return 'no' !== get_post_meta( $product_id, '_armma_broadcast_enabled', true );
    }

    /**
     * ثبت فیلدهای تغییرکرده در جریان ذخیره یک محصول، برای استفاده در is_stock_only_save()
     *
     * @param WC_Product $product
     * @param array      $updated_props
     */
    public function record_updated_props( $product, $updated_props ) {
        $product_id = $product->get_id();
        if ( ! isset( self::$updated_props[ $product_id ] ) ) {
            self::$updated_props[ $product_id ] = array();
        }
        self::$updated_props[ $product_id ] = array_merge( self::$updated_props[ $product_id ], (array) $updated_props );
    }

    /**
     * آیا این ذخیره فقط یک تغییر انباری/آماری بوده (نه ویرایش محتوایی محصول)؟
     *
     * مهم‌ترین موردی که این متد جلویش را می‌گیرد: با هر خرید مشتری، ووکامرس موجودی
     * محصول را کم می‌کند و برای محصولات متغیر، محصول والد را هم همگام‌سازی و ذخیره
     * می‌کند. این ذخیره‌ها هوک woocommerce_update_product را فایر می‌کنند و پیش از این
     * دقیقاً مثل «ویرایش محصول توسط ادمین» تفسیر می‌شدند.
     *
     * اگر هیچ اطلاعاتی از فیلدهای تغییرکرده در دست نباشد، عمداً false برگردانده می‌شود
     * تا رفتار عادی (ارسال) حفظ شود و این محافظ باعث از دست رفتن پیام نشود.
     */
    private function is_stock_only_save( $product_id ) {
        if ( empty( self::$updated_props[ $product_id ] ) ) {
            return false;
        }
        return ! array_diff( self::$updated_props[ $product_id ], self::$stock_only_props );
    }

    /**
     * آیا این ذخیره از یک مسیر «ویرایشی» انجام شده (پیشخوان، REST، WP-CLI، درون‌ریزی)
     * یا از یک مسیر سمت مشتری (ثبت سفارش در فروشگاه)؟
     *
     * مشتری فروشگاه هرگز دسترسی edit_products ندارد، بنابراین همین یک شرط، تمام
     * ذخیره‌های ناشی از خرید را — شامل همگام‌سازی‌های تعویق‌افتاده‌ای که در انتهای
     * همان درخواست اجرا می‌شوند — کنار می‌گذارد.
     */
    private function is_editorial_save() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }
        if ( wp_doing_cron() ) {
            // درخواست‌های cron کاربر ندارند؛ ارسال از این مسیر توسط خود افزونه
            // زمان‌بندی می‌شود و از handle_product_saved عبور نمی‌کند.
            return false;
        }
        return current_user_can( 'edit_products' );
    }

    /**
     * آیا این محصول از قبلِ نصب افزونه وجود داشته و باید «قبلاً معرفی‌شده» در نظر گرفته شود؟
     *
     * پیش از این، نبودِ متای _armma_first_broadcast_done به‌معنای «هنوز منتشر نشده»
     * تفسیر می‌شد؛ یعنی هر محصول قدیمی، اولین باری که به هر دلیلی ذخیره می‌شد،
     * به‌عنوان «محصول جدید» به کانال معرفی می‌شد. این متد به‌همراه backfill هنگام
     * فعال‌سازی، آن رفتار را اصلاح می‌کند.
     */
    private function is_pre_existing_product( $product ) {
        $baseline = (int) get_option( self::BASELINE_OPTION, 0 );
        if ( ! $baseline ) {
            return false;
        }
        $created = $product->get_date_created();
        if ( ! $created ) {
            return false;
        }
        return $created->getTimestamp() < $baseline;
    }

    /**
     * تشخیص اولین انتشار محصول + مدیریت حالت «انتشار اول + هر ویرایش». این هندلر با
     * هر بار ذخیره یک محصول (چه محصول کاملاً جدید، چه ویرایش یکی از قبل موجود) فایر
     * می‌شود، اما تا این لحظه ووکامرس قیمت و بقیه فیلدها را قطعاً ذخیره کرده است.
     */
    public function handle_product_saved( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'publish' !== $product->get_status() ) {
            return; // فقط محصولات منتشرشده؛ پیش‌نویس/در انتظار بررسی و... نادیده گرفته می‌شوند
        }

        // محافظ ۱: ذخیره‌هایی که صرفاً موجودی/فروش را تغییر داده‌اند (مثل کاهش موجودی
        // هنگام خرید مشتری) ویرایش محصول نیستند.
        if ( $this->is_stock_only_save( $product_id ) ) {
            return;
        }

        // محافظ ۲: ذخیره‌های سمت مشتری (ثبت سفارش) هرگز نباید پیام معرفی محصول بفرستند.
        if ( ! $this->is_editorial_save() ) {
            return;
        }

        if ( ! empty( self::$sent_in_this_request[ $product_id ] ) ) {
            // در همین درخواست (مثلاً توسط handle_price_change که زودتر از این هوک فایر
            // می‌شود) قبلاً ارسال شده؛ اگر این اولین انتشار بوده، فقط پرچم دائمی را ثبت کن
            if ( ! get_post_meta( $product_id, '_armma_first_broadcast_done', true ) ) {
                update_post_meta( $product_id, '_armma_first_broadcast_done', '1' );
            }
            return;
        }

        $first_done = get_post_meta( $product_id, '_armma_first_broadcast_done', true );

        if ( ! $first_done ) {
            // محافظ ۳: محصولی که از قبلِ نصب افزونه روی سایت بوده، «محصول تازه‌منتشرشده»
            // نیست؛ فقط پرچمش ثبت می‌شود تا از این به بعد درست رفتار شود.
            if ( $this->is_pre_existing_product( $product ) ) {
                update_post_meta( $product_id, '_armma_first_broadcast_done', '1' );
                return;
            }

            // قفل دائمی روی خود محصول، تا حتی اگر بعداً به‌طور موقت پیش‌نویس و دوباره
            // منتشر شود، به‌عنوان «اولین» انتشار دوباره پیام نفرستد
            update_post_meta( $product_id, '_armma_first_broadcast_done', '1' );
            $this->queue_broadcast( $product_id );
            return;
        }

        if ( 'first_publish_and_edit' === $this->trigger_mode ) {
            $this->queue_broadcast( $product_id );
        }
        // برای حالت first_publish_and_price_change، ارسالِ غیر از بار اول کاملاً به عهده
        // handle_price_change است، نه اینجا.
    }

    /**
     * ارسال مجدد فقط در صورت تغییر واقعی قیمت (حالت «انتشار اول + تغییر قیمت»)
     */
    public function handle_price_change( $product, $updated_props ) {
        $product_id = $product->get_id();
        if ( 'publish' !== $product->get_status() ) {
            return;
        }
        if ( ! empty( self::$sent_in_this_request[ $product_id ] ) ) {
            return;
        }
        // همان محافظ‌های handle_product_saved: تغییر قیمت ناشی از یک مسیر سمت مشتری
        // (مثلاً افزونه‌های قیمت پویا در جریان ثبت سفارش) نباید پیام معرفی بفرستد.
        if ( ! $this->is_editorial_save() ) {
            return;
        }
        $price_related_props = array( 'price', 'regular_price', 'sale_price' );
        if ( ! array_intersect( $price_related_props, (array) $updated_props ) ) {
            return;
        }
        // محصولی که هنوز به‌عنوان «معرفی‌شده» ثبت نشده و از قبلِ نصب افزونه وجود داشته،
        // نباید با اولین تغییر قیمت به‌عنوان محصول جدید معرفی شود.
        if ( ! get_post_meta( $product_id, '_armma_first_broadcast_done', true ) && $this->is_pre_existing_product( $product ) ) {
            update_post_meta( $product_id, '_armma_first_broadcast_done', '1' );
            return;
        }
        $this->queue_broadcast( $product_id );
    }

    /**
     * صف‌بندی ارسال پیام محصول بر اساس حالت ارسال انتخاب‌شده در تنظیمات (همان
     * تنظیم سراسری «نحوه ارسال پیام» که برای سفارش‌ها هم استفاده می‌شود):
     * - 'cron' (پیش‌فرض): یک رویداد cron برای ارسال در پس‌زمینه زمان‌بندی می‌شود تا
     *   ذخیره محصول در پیشخوان معطل تماس‌های شبکه‌ای با پیام‌رسان نماند. تماس‌های
     *   API روی هاست‌های واقعی ممکن است چند ده ثانیه طول بکشند (مثلاً هنگام آپلود
     *   تصویر)؛ همین بود که قبلاً باعث کندی شدید ذخیره محصول می‌شد.
     * - 'direct': ارسال بلافاصله در همین درخواست؛ بدون وابستگی به WP-Cron.
     *
     * پرچم sent_in_this_request اینجا ست می‌شود (نه در پایان ارسال واقعی) تا در
     * صورت فایر شدن چند هوک برای یک محصول در یک درخواست، فقط یک رویداد صف شود.
     */
    private function queue_broadcast( $product_id ) {
        self::$sent_in_this_request[ $product_id ] = true;

        $settings  = get_option( ARMMA_WOO_NOTIFER_Settings::OPTION_KEY, array() );
        $send_mode = isset( $settings['send_mode'] ) && 'direct' === $settings['send_mode'] ? 'direct' : 'cron';

        if ( 'direct' === $send_mode ) {
            $this->send_broadcast_now( $product_id );
            return;
        }

        wp_schedule_single_event( time(), self::CRON_HOOK, array( $product_id ) );

        // مانند مسیر ارسال سفارش: برای اینکه رویداد بدون صبر برای بازدید بعدی فوراً
        // اجرا شود، صریحاً یک درخواست غیرمنتظر به wp-cron.php ارسال می‌شود.
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    /**
     * اجرای واقعی ارسال پیام معرفی محصول؛ توسط رویداد cron صف‌شده در
     * queue_broadcast اجرا می‌شود (یا بلافاصله در حالت «مستقیم»).
     */
    public function send_broadcast_now( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'publish' !== $product->get_status() ) {
            return;
        }
        $this->broadcast( $product_id );
    }

    /**
     * ارسال واقعی پیام معرفی محصول به تمام پیام‌رسان‌هایی که برای این ویژگی فعال شده‌اند
     */
    private function broadcast( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        // کلید ادمین برای غیرفعال‌کردن اطلاع‌رسانی این محصول خاص (چک‌باکس داخل خود
        // صفحه محصول).
        if ( ! $this->is_broadcast_enabled_for_product( $product_id ) ) {
            return;
        }

        $settings           = get_option( ARMMA_WOO_NOTIFER_Settings::OPTION_KEY, array() );
        $pb_settings        = isset( $settings['product_broadcast'] ) && is_array( $settings['product_broadcast'] ) ? $settings['product_broadcast'] : array();
        $broadcast_settings = isset( $pb_settings['platforms'] ) && is_array( $pb_settings['platforms'] ) ? $pb_settings['platforms'] : array();
        $order_platforms    = isset( $settings['platforms'] ) && is_array( $settings['platforms'] ) ? $settings['platforms'] : array();
        $include            = isset( $pb_settings['include'] ) && is_array( $pb_settings['include'] ) ? $pb_settings['include'] : array();
        $header_text        = isset( $pb_settings['header_text'] ) ? $pb_settings['header_text'] : '';
        $footer_text        = isset( $pb_settings['footer_text'] ) ? $pb_settings['footer_text'] : '';

        $message = $this->build_message( $product, $include, $header_text, $footer_text );

        // مسیر محلی فایل تصویر روی همین سرور: با داشتن آن، بایت‌های فایل مستقیماً به
        // پیام‌رسان آپلود می‌شوند و سرور پیام‌رسان لازم نیست تصویر را از سایت دانلود
        // کند (دانلود سمتِ سرورِ پیام‌رسان روی هاست‌هایی که فایروال/WAF دارند
        // ناموفق یا بسیار کند است و باعث می‌شد عکس ارسال نشود و پیام هم با تأخیر
        // چند ده ثانیه‌ای برسد).
        $image_url  = '';
        $image_path = '';
        if ( ! empty( $include['image'] ) && 'yes' === $include['image'] ) {
            $image_id = $product->get_image_id();
            if ( $image_id ) {
                $image_url = wp_get_attachment_image_url( $image_id, 'large' );
                // اندازه large ممکن است برای تصاویر کوچک‌تر از ابعادش تولید نشده باشد؛
                // wp_get_attachment_image_url در این حالت خودش نزدیک‌ترین اندازه یا
                // اصل فایل را برمی‌گرداند. مسیر محلی همیشه از فایل اصلی خوانده می‌شود
                // چون آپلود مستقیم اندازه معقول (زیر حد ۱۰ مگابایت بله) دارد.
                $image_path = get_attached_file( $image_id );
            }
        }

        foreach ( array_keys( ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms() ) as $platform_id ) {
            $platform_broadcast = isset( $broadcast_settings[ $platform_id ] ) ? $broadcast_settings[ $platform_id ] : array();
            if ( empty( $platform_broadcast['enabled'] ) || 'yes' !== $platform_broadcast['enabled'] ) {
                continue;
            }

            $chat_id = isset( $platform_broadcast['chat_id'] ) ? $platform_broadcast['chat_id'] : '';
            $token   = isset( $order_platforms[ $platform_id ]['bot_token'] ) ? $order_platforms[ $platform_id ]['bot_token'] : '';
            if ( empty( $token ) || empty( $chat_id ) ) {
                continue;
            }

            $api = ARMMA_WOO_NOTIFER_Messenger_API::create( $platform_id, $token );
            if ( is_wp_error( $api ) ) {
                continue;
            }

            $sent = false;
            if ( $image_url || $image_path ) {
                $result = $api->send_photo( $chat_id, $image_url, $message, $image_path );
                $sent   = ! is_wp_error( $result );
                if ( ! $sent ) {
                    $this->log_broadcast_error( $platform_id, $product_id, $result );
                }
            }
            if ( ! $sent ) {
                // اگر عکس غیرفعال بود، محصول عکس نداشت، یا ارسال عکس (مثلاً به دلیل مشکل
                // شبکه) ناموفق بود، حداقل نسخه متنی پیام ارسال شود تا محصول کاملاً
                // بی‌اطلاع‌رسانی نماند
                $result = $api->send_message( $chat_id, $message );
                if ( is_wp_error( $result ) ) {
                    $this->log_broadcast_error( $platform_id, $product_id, $result );
                }
            }
        }
    }

    /**
     * ثبت خطای ارسال پیام محصول در error log وردپرس (در صورت فعال بودن WP_DEBUG_LOG).
     * برخلاف پیام سفارش که یادداشت سفارش و جدول لاگ دارد، برای محصولات جای نمایشی
     * وجود ندارد؛ این لاگ فقط برای عیب‌یابی توسط توسعه‌دهنده است.
     */
    private function log_broadcast_error( $platform_id, $product_id, $error ) {
        if ( ! is_wp_error( $error ) ) {
            return;
        }
        error_log( sprintf(
            'ARMMA Woo Notifer: ارسال پیام محصول #%d به %s ناموفق بود — [%s] %s',
            (int) $product_id,
            ARMMA_WOO_NOTIFER_Messenger_API::get_platform_label( $platform_id ),
            $error->get_error_code(),
            $error->get_error_message()
        ) );
    }

    /**
     * ساخت متن پیام معرفی محصول؛ هر بخش فقط در صورتی که ادمین از تنظیمات فعالش کرده
     * باشد درج می‌شود. اگر متن ابتدای پیام (header_text) خالی باشد، هیچ خط عنوان
     * عمومی درج نمی‌شود و پیام مستقیماً با عنوان محصول (در صورت فعال بودن) شروع می‌شود.
     */
    private function build_message( $product, $include, $header_text, $footer_text ) {
        $message = '';

        $header_text = trim( (string) $header_text );
        if ( '' !== $header_text ) {
            $message .= $this->clean_text( $header_text ) . "\n\n";
        }

        if ( ! empty( $include['title'] ) && 'yes' === $include['title'] ) {
            $message .= '🏷 ' . $this->clean_text( $product->get_name() ) . "\n\n";
        }

        if ( ! empty( $include['price'] ) && 'yes' === $include['price'] ) {
            $message .= $this->build_price_lines( $product ) . "\n";
        }

        if ( ! empty( $include['excerpt'] ) && 'yes' === $include['excerpt'] ) {
            $excerpt = $this->build_excerpt( $product );
            if ( '' !== $excerpt ) {
                $message .= "\n📝 {$excerpt}\n";
            }
        }

        if ( ! empty( $include['attributes'] ) && 'yes' === $include['attributes'] ) {
            $attributes_line = $this->build_attributes_line( $product );
            if ( '' !== $attributes_line ) {
                $message .= "\n🔧 مشخصات:\n{$attributes_line}\n";
            }
        }

        if ( ! empty( $include['category'] ) && 'yes' === $include['category'] ) {
            $category_line = $this->build_category_line( $product );
            if ( '' !== $category_line ) {
                $message .= "\n{$category_line}\n";
            }
        }

        if ( ! empty( $include['tags'] ) && 'yes' === $include['tags'] ) {
            $tags_line = $this->build_tags_line( $product );
            if ( '' !== $tags_line ) {
                $message .= "\n{$tags_line}\n";
            }
        }

        $message .= "\n🔗 مشاهده و خرید:\n" . $product->get_permalink();

        $footer_text = trim( (string) $footer_text );
        if ( '' !== $footer_text ) {
            $message .= "\n\n" . $this->clean_text( $footer_text );
        }

        return trim( $message );
    }

    /**
     * ویژگی‌های محصول (نه ویژگی مخصوص یک تنوع خاص؛ بلکه ویژگی‌های سطح خودِ محصول، مثل
     * رنگ، جنس یا برند که از تب «ویژگی‌ها» در ویرایش محصول تعریف می‌شوند)
     */
    private function build_attributes_line( $product ) {
        $attributes = $product->get_attributes();
        if ( empty( $attributes ) ) {
            return '';
        }

        $lines = array();
        foreach ( $attributes as $attribute ) {
            if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_visible' ) || ! $attribute->get_visible() ) {
                continue;
            }

            $label = wc_attribute_label( $attribute->get_name() );

            if ( $attribute->is_taxonomy() ) {
                $terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
                $value = implode( '، ', $terms );
            } else {
                $value = implode( '، ', $attribute->get_options() );
            }

            $value = $this->clean_text( $value );
            if ( '' !== $value ) {
                $lines[] = "• {$label}: {$value}";
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * دسته‌بندی محصول
     */
    private function build_category_line( $product ) {
        $categories = wc_get_product_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
        if ( empty( $categories ) ) {
            return '';
        }
        return '📂 دسته: ' . implode( '، ', array_map( array( $this, 'clean_text' ), $categories ) );
    }

    /**
     * برچسب‌های محصول
     */
    private function build_tags_line( $product ) {
        $tags = wc_get_product_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
        if ( empty( $tags ) ) {
            return '';
        }
        return '🏷 برچسب: ' . implode( '، ', array_map( array( $this, 'clean_text' ), $tags ) );
    }

    /**
     * قیمت محصول، شامل نمایش تخفیف در صورت وجود. طبق تصمیم صاحب افزونه، بدون هیچ
     * منطق خاصی برای تنوع‌ها؛ همان مقدار خام get_price()/get_regular_price() که
     * ووکامرس برمی‌گرداند استفاده می‌شود (برای محصول متغیر معمولاً پایین‌ترین قیمت
     * تنوع‌هاست، چون ووکامرس این مقدار را خودش همگام‌سازی می‌کند).
     */
    private function build_price_lines( $product ) {
        if ( $product->is_on_sale() && '' !== $product->get_sale_price() ) {
            $regular = $this->clean_text( wc_price( $product->get_regular_price() ) );
            $sale    = $this->clean_text( wc_price( $product->get_sale_price() ) );
            $percent = 0;
            $regular_num = (float) $product->get_regular_price();
            if ( $regular_num > 0 ) {
                $percent = (int) round( ( ( $regular_num - (float) $product->get_sale_price() ) / $regular_num ) * 100 );
            }
            $line = "💰 قیمت قبلی: {$regular}\n🔥 قیمت با تخفیف: {$sale}";
            if ( $percent > 0 ) {
                $line .= " (٪{$percent} تخفیف)";
            }
            return $line;
        }

        $price = $this->clean_text( wc_price( $product->get_price() ) );
        return "💰 قیمت: {$price}";
    }

    /**
     * چکیده محصول: اولویت با توضیح کوتاه محصول، در نبود آن، خلاصه‌ای از توضیح کامل.
     * برای مناسب بودن طول پیام کانال، حداکثر ۲۰۰ کاراکتر نمایش داده می‌شود.
     */
    private function build_excerpt( $product ) {
        $text = $product->get_short_description();
        if ( '' === trim( wp_strip_all_tags( (string) $text ) ) ) {
            $text = $product->get_description();
        }
        $text = $this->clean_text( $text );
        if ( '' === $text ) {
            return '';
        }
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text ) > 200 ) {
                $text = mb_substr( $text, 0, 200 ) . '…';
            }
        } elseif ( strlen( $text ) > 200 ) {
            $text = substr( $text, 0, 200 ) . '…';
        }
        return $text;
    }

    /**
     * تبدیل امن هر مقدار متنی به متن ساده و خوانا (مشابه کلاس اطلاع‌رسان سفارش)
     */
    private function clean_text( $value ) {
        if ( '' === $value || null === $value ) {
            return '';
        }
        $value = preg_replace( '#<br\s*/?>#i', "\n", $value );
        $value = wp_strip_all_tags( $value );
        $value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
        $value = str_replace( "\xc2\xa0", ' ', $value );
        $value = preg_replace( '/[ \t]+/', ' ', $value );
        return trim( $value );
    }
}
