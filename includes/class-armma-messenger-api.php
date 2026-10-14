<?php
/**
 * کلاس پایه انتزاعی برای ارتباط با API پیام‌رسان‌ها (روبیکا، بله و پیام‌رسان‌های آینده)
 * هر پیام‌رسان با ساخت یک کلاس فرزند از این کلاس و ثبت آن در متد create() اضافه می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class ARMMA_WOO_NOTIFER_Messenger_API {

    protected $bot_token;

    public function __construct( $bot_token = '' ) {
        $this->bot_token = $bot_token;
    }

    /**
     * لیست پیام‌رسان‌های پشتیبانی‌شده به همراه عنوان فارسی نمایشی آن‌ها
     * افزودن یک پیام‌رسان جدید فقط نیازمند اضافه‌کردن یک ردیف اینجا و یک case در create() است.
     * @return array<string,string> platform_id => label
     */
    public static function get_supported_platforms() {
        return array(
            'rubika' => 'روبیکا',
            'bale'   => 'بله',
        );
    }

    /**
     * عنوان فارسی یک پیام‌رسان بر اساس شناسه آن، بدون نیاز به ساخت شیء
     * @param string $platform_id
     * @return string
     */
    public static function get_platform_label( $platform_id ) {
        $platforms = self::get_supported_platforms();
        return isset( $platforms[ $platform_id ] ) ? $platforms[ $platform_id ] : $platform_id;
    }

    /**
     * کارخانه ساخت شیء API متناسب با پیام‌رسان درخواستی
     * @param string $platform_id 'rubika' یا 'bale'
     * @param string $bot_token
     * @return ARMMA_WOO_NOTIFER_Messenger_API|WP_Error
     */
    public static function create( $platform_id, $bot_token ) {
        switch ( $platform_id ) {
            case 'rubika':
                return new ARMMA_WOO_NOTIFER_Rubika_API( $bot_token );
            case 'bale':
                return new ARMMA_WOO_NOTIFER_Bale_API( $bot_token );
            default:
                return new WP_Error( 'armma_invalid_platform', 'پیام‌رسان نامعتبر: ' . $platform_id );
        }
    }

    /**
     * اجرای درخواست HTTP با استفاده از WP_Http (wp_remote_get / wp_remote_post)
     * به‌جای cURL خام؛ این‌طوری پروکسی تنظیم‌شده در wp-config.php (که در بسیاری از
     * هاست‌های ایرانی برای دسترسی به سرویس‌های خارجی لازم است)، فیلترهای وردپرس
     * (مثل http_request_args) و افزونه‌های امنیتی که روی wp_remote_* قلاب می‌شوند
     * به‌درستی اعمال می‌شوند. علاوه بر این، در صورت غیرفعال بودن cURL روی هاست،
     * WP_Http به‌صورت خودکار به transport جایگزین (streams/fopen) سوییچ می‌کند.
     *
     * @param string $url آدرس کامل endpoint
     * @param array|null $post_fields در صورت null، درخواست GET ارسال می‌شود
     * @param string $content_type نوع بدنه درخواست: 'json' یا 'form'
     * @return array|WP_Error
     */
    protected function do_request( $url, $post_fields = null, $content_type = 'json' ) {
        $args = array(
            'timeout'   => 25,
            'sslverify' => true,
        );

        if ( null !== $post_fields ) {
            if ( 'json' === $content_type ) {
                $args['headers'] = array( 'Content-Type' => 'application/json' );
                $args['body']    = wp_json_encode( $post_fields );
            } else {
                // wp_remote_post به‌طور پیش‌فرض بدنه آرایه‌ای را به‌صورت form-urlencoded ارسال می‌کند
                $args['body'] = $post_fields;
            }
            $response = wp_remote_post( $url, $args );
        } else {
            $response = wp_remote_get( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'armma_http_error', 'خطای اتصال: ' . $response->get_error_message() );
        }

        $body    = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );

        if ( null === $decoded ) {
            return new WP_Error( 'armma_bad_response', 'پاسخ نامعتبر از سرور دریافت شد. توکن ربات را بررسی کنید.' );
        }

        return $decoded;
    }

    /**
     * شناسه یکتای این پیام‌رسان (باید با کلید موجود در get_supported_platforms هماهنگ باشد)
     * @return string
     */
    abstract public function get_platform_id();

    /**
     * تست معتبر بودن توکن ربات
     * @return array|WP_Error در صورت موفقیت، آرایه‌ای شامل کلید 'display_message'
     *                        (متن آماده نمایش به کاربر) یا در صورت خطا WP_Error
     */
    abstract public function test_bot_token();

    /**
     * دریافت آخرین آپدیت‌ها برای پیدا کردن Chat ID گروه
     * @return array|WP_Error
     */
    abstract public function get_recent_chats();

    /**
     * ارسال پیام متنی ساده به یک چت مشخص (بدون دکمه/کیبورد)
     * @param string $chat_id
     * @param string $text
     * @return true|WP_Error
     */
    abstract public function send_message( $chat_id, $text );

    /**
     * ارسال یک تصویر (به همراه متن توضیح) به یک چت مشخص. مکانیزم دو پیام‌رسان کاملاً
     * متفاوت است: بله یک URL عمومی را مستقیم می‌پذیرد، ولی روبیکا نیازمند آپلود واقعی
     * بایت‌های فایل در چند مرحله است؛ این جزئیات داخل هر کلاس فرزند پیاده‌سازی می‌شود.
     *
     * @param string $chat_id
     * @param string $image_url آدرس عمومی و قابل‌دسترس تصویر (معمولاً روی همین سایت)
     * @param string $caption متن همراه تصویر
     * @param string $local_path مسیر محلی فایل تصویر روی همین سرور (در صورت دسترس‌بودن)؛
     *                           وقتی داده شود، بایت‌های فایل مستقیماً آپلود می‌شوند و
     *                           پیام‌رسان مجبور نمی‌شود خودش تصویر را از سایت دانلود کند
     *                           (دانلود سمت سرورِ پیام‌رسان روی خیلی از هاست‌ها به دلیل
     *                           فایروال/WAF یا کندی، ناموفق یا بسیار کند از آب درمی‌آید).
     * @return true|WP_Error
     */
    abstract public function send_photo( $chat_id, $image_url, $caption, $local_path = '' );
}
