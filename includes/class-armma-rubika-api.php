<?php
/**
 * کلاس ارتباط با API بات روبیکا (Rubika)
 * آدرس پایه: https://botapi.rubika.ir/v3/{token}/{method}
 * تمام درخواست‌ها با متد POST و بدنه JSON ارسال می‌شوند.
 *
 * نسخه ساده‌شده (بدون دکمه شیشه‌ای/اینلاین و بدون وب‌هوک): طبق مستندات رسمی روبیکا،
 * inline_keypad فقط در چت خصوصی با کاربر معتبر است و در گروه باعث خطای INVALID_INPUT
 * می‌شود؛ به همین دلیل این قابلیت و هر چیز وابسته به آن (وب‌هوک دریافت کلیک دکمه) از
 * افزونه حذف شده تا پیام سفارش با کمترین پیش‌نیاز و بدون وابستگی به HTTPS/وب‌هوک ارسال شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ARMMA_WOO_NOTIFER_Rubika_API extends ARMMA_WOO_NOTIFER_Messenger_API {

    public function get_platform_id() {
        return 'rubika';
    }

    private function get_api_base_url() {
        return 'https://botapi.rubika.ir/v3/' . $this->bot_token . '/';
    }

    /**
     * بررسی توکن روبیکا به‌صورت ساده: فقط خالی/پر بودن توکن سنجیده می‌شود، بدون
     * فراخوانی واقعی getMe. طبق تصمیم صاحب افزونه این تغییر عمدی است: کاربری که
     * توکن را وارد و ارسال می‌کند، از قبل مطمئن است توکن درست است؛ نمایش پیام خام
     * خطای API (که پیش‌تر این‌جا اتفاق می‌افتاد) هم گیج‌کننده بود و هم غیرحرفه‌ای.
     * @return array|WP_Error
     */
    public function test_bot_token() {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'armma_no_token', 'توکن خالی است.' );
        }

        return array(
            'display_message' => 'توکن مورد نظر دریافت شد.',
        );
    }

    public function get_recent_chats() {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'armma_no_token', 'ابتدا توکن بات را وارد و ذخیره کنید.' );
        }

        $body = $this->do_request( $this->get_api_base_url() . 'getUpdates', array( 'limit' => 50 ), 'json' );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        if ( empty( $body['data']['updates'] ) ) {
            return new WP_Error(
                'armma_no_updates',
                'هیچ پیامی یافت نشد. لطفاً ابتدا بات را به گروه موردنظر اضافه و ادمین کنید (یا شخص موردنظر مستقیماً به بات پیام بدهد)، سپس /start ارسال کنید و دوباره روی این دکمه بزنید.'
            );
        }

        // در روبیکا هر Update مستقیماً شامل chat_id است (بدون شیء chat جداگانه)؛ این
        // chat_id می‌تواند مربوط به یک گروه یا یک چت خصوصی با یک شخص باشد.
        $chats = array();
        foreach ( $body['data']['updates'] as $update ) {
            if ( ! empty( $update['chat_id'] ) ) {
                $chat_id = $update['chat_id'];
                // عنوان دقیق را نداریم مگر با فراخوانی جداگانه getChat؛ فعلاً از شناسه به‌عنوان عنوان موقت استفاده می‌کنیم
                $chats[ $chat_id ] = array(
                    'id'    => $chat_id,
                    'title' => 'گفتگوی ' . $chat_id,
                    'type'  => 'unknown',
                );
            }
        }

        if ( empty( $chats ) ) {
            return new WP_Error(
                'armma_no_group_found',
                'شخص یا گروهی یافت نشد. مطمئن شوید بات را به گروه اضافه و ادمین کرده‌اید یا شخص موردنظر مستقیماً به بات پیام داده باشد (مثلاً /start).'
            );
        }

        // برای هر چت یافت‌شده، تلاش می‌کنیم عنوان واقعی را از طریق getChat دریافت کنیم.
        // این کار یک فراخوانی جداگانه به ازای هر چت است (N+1)؛ چون این تابع در یک
        // درخواست AJAX همزمان با کلیک ادمین اجرا می‌شود، برای جلوگیری از طولانی شدن
        // بیش از حد درخواست (و timeout احتمالی)، فقط برای حداکثر ۱۵ چت اول عنوان واقعی
        // را می‌گیریم؛ بقیه با عنوان موقت «گروه {id}» نمایش داده می‌شوند که همچنان
        // برای انتخاب دستی گروه کاربردی است.
        $max_titles_to_resolve = 15;
        $resolved = 0;
        foreach ( $chats as $id => $chat ) {
            if ( $resolved >= $max_titles_to_resolve ) {
                break;
            }
            $chat_info = $this->do_request( $this->get_api_base_url() . 'getChat', array( 'chat_id' => $id ), 'json' );
            if ( ! is_wp_error( $chat_info ) && ! empty( $chat_info['data']['chat']['title'] ) ) {
                $chats[ $id ]['title'] = $chat_info['data']['chat']['title'];
                $chats[ $id ]['type']  = 'group';
            } elseif ( ! is_wp_error( $chat_info ) && ! empty( $chat_info['data']['chat']['first_name'] ) ) {
                $chats[ $id ]['title'] = '👤 ' . $chat_info['data']['chat']['first_name'];
                $chats[ $id ]['type']  = 'private';
            }
            $resolved++;
        }

        return array_values( $chats );
    }

    /**
     * ارسال پیام متنی ساده (بدون دکمه/کیبورد؛ چون inline_keypad فقط در چت خصوصی معتبر
     * است و در گروه باعث رد کل درخواست توسط روبیکا می‌شود - INVALID_INPUT)
     * @param string $chat_id
     * @param string $text
     * @return true|WP_Error
     */
    public function send_message( $chat_id, $text ) {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'armma_no_token', 'توکن بات تنظیم نشده است.' );
        }
        if ( empty( $chat_id ) ) {
            return new WP_Error( 'armma_no_chat_id', 'شناسه گروه (Chat ID) تنظیم نشده است.' );
        }

        $payload = array(
            'chat_id' => $chat_id,
            'text'    => $text,
        );

        $body = $this->do_request( $this->get_api_base_url() . 'sendMessage', $payload, 'json' );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        if ( empty( $body['data']['message_id'] ) ) {
            $desc = isset( $body['status'] ) ? 'ارسال پیام ناموفق بود (وضعیت: ' . $body['status'] . ')' : 'ارسال پیام با خطا مواجه شد.';
            if ( isset( $body['dev_message'] ) ) {
                $desc .= ' — ' . $body['dev_message'];
            }
            return new WP_Error( 'armma_send_failed', $desc );
        }

        return true;
    }

    /**
     * ارسال تصویر در روبیکا، برخلاف بله، یک URL عمومی را مستقیم قبول نمی‌کند و طبق
     * مستندات رسمی (rubika.ir/botapi/methods) نیازمند سه مرحله است:
     * ۱) requestSendFile — گرفتن یک آدرس آپلود موقت از روبیکا
     * ۲) آپلود واقعی بایت‌های فایل (multipart/form-data) به همان آدرس
     * ۳) sendFile — ارسال فایل آپلودشده (با file_id بازگشتی) همراه با متن پیام
     */
    public function send_photo( $chat_id, $image_url, $caption, $local_path = '' ) {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'armma_no_token', 'توکن بات تنظیم نشده است.' );
        }
        if ( empty( $chat_id ) ) {
            return new WP_Error( 'armma_no_chat_id', 'شناسه گروه (Chat ID) تنظیم نشده است.' );
        }
        if ( empty( $image_url ) && empty( $local_path ) ) {
            return new WP_Error( 'armma_no_image', 'آدرس تصویر مشخص نشده است.' );
        }

        // مرحله صفر: دریافت بایت‌های واقعی فایل تصویر. اگر مسیر محلی فایل روی همین
        // سرور داده شده باشد، مستقیماً از دیسک خوانده می‌شود (بدون هیچ تماس شبکه‌ای)؛
        // در غیر این صورت فایل از URL دانلود می‌شود. (روبیکا فقط file_id آپلودشده
        // روی سرور خودش را می‌پذیرد، نه یک URL خارجی)
        $image_bytes = false;
        if ( ! empty( $local_path ) && file_exists( $local_path ) && is_readable( $local_path ) ) {
            $image_bytes = file_get_contents( $local_path );
        }

        if ( false === $image_bytes || '' === $image_bytes ) {
            $image_response = wp_remote_get( $image_url, array( 'timeout' => 25 ) );
            if ( is_wp_error( $image_response ) ) {
                return new WP_Error( 'armma_image_fetch_failed', 'دریافت فایل تصویر از سایت ناموفق بود: ' . $image_response->get_error_message() );
            }
            $image_bytes = wp_remote_retrieve_body( $image_response );
        }

        if ( empty( $image_bytes ) ) {
            return new WP_Error( 'armma_image_fetch_failed', 'فایل تصویر خالی بود یا در دسترس نبود.' );
        }

        // مرحله ۱: درخواست آدرس آپلود موقت از روبیکا
        $upload_request = $this->do_request( $this->get_api_base_url() . 'requestSendFile', array( 'type' => 'Image' ), 'json' );
        if ( is_wp_error( $upload_request ) ) {
            return $upload_request;
        }
        $upload_url = isset( $upload_request['data']['upload_url'] ) ? $upload_request['data']['upload_url'] : '';
        if ( empty( $upload_url ) ) {
            $desc = isset( $upload_request['status'] ) ? 'دریافت آدرس آپلود ناموفق بود (وضعیت: ' . $upload_request['status'] . ')' : 'دریافت آدرس آپلود از روبیکا ناموفق بود.';
            return new WP_Error( 'armma_upload_url_failed', $desc );
        }

        // مرحله ۲: آپلود واقعی بایت‌های فایل به آدرس دریافت‌شده
        $file_id = $this->upload_file_bytes( $upload_url, $image_bytes );
        if ( is_wp_error( $file_id ) ) {
            return $file_id;
        }

        // مرحله ۳: ارسال فایل آپلودشده به همراه متن پیام
        $send_payload = array(
            'chat_id' => $chat_id,
            'file_id' => $file_id,
            'text'    => $caption,
        );
        $body = $this->do_request( $this->get_api_base_url() . 'sendFile', $send_payload, 'json' );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        if ( empty( $body['data']['message_id'] ) ) {
            $desc = isset( $body['status'] ) ? 'ارسال فایل ناموفق بود (وضعیت: ' . $body['status'] . ')' : 'ارسال فایل با خطا مواجه شد.';
            return new WP_Error( 'armma_send_failed', $desc );
        }

        return true;
    }

    /**
     * آپلود دستی بایت‌های یک فایل به آدرس upload_url که requestSendFile برگردانده،
     * با ساخت دستی بدنه multipart/form-data (چون wp_remote_post برای آرایه‌های حاوی
     * محتوای باینری خام، خودش این کار را نمی‌کند).
     *
     * @return string|WP_Error شناسه فایل (file_id) یا خطا
     */
    private function upload_file_bytes( $upload_url, $file_bytes ) {
        $boundary = 'ARMMAWOONOTIFER' . wp_generate_password( 16, false, false );
        $filename = 'product-image.jpg';

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $file_bytes . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post( $upload_url, array(
            'timeout' => 40,
            'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
            'body'    => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'armma_upload_failed', 'آپلود تصویر به روبیکا ناموفق بود: ' . $response->get_error_message() );
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        $file_id = isset( $decoded['data']['file_id'] ) ? $decoded['data']['file_id'] : '';
        if ( empty( $file_id ) ) {
            return new WP_Error( 'armma_upload_failed', 'دریافت شناسه فایل از روبیکا پس از آپلود ناموفق بود.' );
        }

        return $file_id;
    }
}
