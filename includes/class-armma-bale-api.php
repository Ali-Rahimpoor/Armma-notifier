<?php
/**
 * کلاس ارتباط با API بازوی (بات) پیام‌رسان بله
 * آدرس پایه: https://tapi.bale.ai/bot{token}/{METHOD_NAME}
 * ساختار API بله بر پایه API بات تلگرام است: پاسخ همیشه یک فیلد boolean با نام «ok» دارد؛
 * در صورت موفقیت نتیجه در «result» و در صورت خطا شرح آن در «description» قرار می‌گیرد.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ARMMA_WOO_NOTIFER_Bale_API extends ARMMA_WOO_NOTIFER_Messenger_API {

    public function get_platform_id() {
        return 'bale';
    }

    private function get_api_base_url() {
        return 'https://tapi.bale.ai/bot' . $this->bot_token . '/';
    }

    /**
     * استخراج یکسان پیام خطا از پاسخ ناموفق بله (فیلد description، در صورت وجود)
     */
    private function extract_error_message( $body, $fallback ) {
        if ( is_array( $body ) && ! empty( $body['description'] ) ) {
            return $fallback . ' — ' . $body['description'];
        }
        return $fallback;
    }

    public function test_bot_token() {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'armma_no_token', 'توکن بازو وارد نشده است.' );
        }

        $body = $this->do_request( $this->get_api_base_url() . 'getMe', array(), 'json' );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        if ( empty( $body['ok'] ) || empty( $body['result'] ) || ! is_array( $body['result'] ) ) {
            return new WP_Error(
                'armma_invalid_token',
                $this->extract_error_message( $body, 'توکن بازوی بله نامعتبر است یا پاسخ نامعتبری دریافت شد.' )
            );
        }

        $bot_data = $body['result'];
        $username   = isset( $bot_data['username'] ) ? $bot_data['username'] : '';
        $first_name = isset( $bot_data['first_name'] ) ? $bot_data['first_name'] : '';

        return array(
            'display_message' => $username
                ? sprintf( 'اتصال موفق! بات شما: @%s (%s)', $username, $first_name )
                : 'اتصال موفق! توکن معتبر است.',
        );
    }

    public function get_recent_chats() {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'armma_no_token', 'ابتدا توکن بازو را وارد و ذخیره کنید.' );
        }

        $body = $this->do_request( $this->get_api_base_url() . 'getUpdates', array( 'limit' => 100 ), 'json' );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        if ( empty( $body['ok'] ) ) {
            return new WP_Error( 'armma_updates_failed', $this->extract_error_message( $body, 'دریافت آپدیت‌ها از بله ناموفق بود.' ) );
        }

        $updates = isset( $body['result'] ) && is_array( $body['result'] ) ? $body['result'] : array();

        if ( empty( $updates ) ) {
            return new WP_Error(
                'armma_no_updates',
                'هیچ پیامی یافت نشد. لطفاً ابتدا بازو را به گروه موردنظر اضافه و ادمین کنید (یا شخص موردنظر مستقیماً به بازو پیام بدهد)، سپس /start ارسال کنید و دوباره روی این دکمه بزنید.'
            );
        }

        // هم گفتگوهای گروهی/کانالی و هم چت خصوصی با یک شخص مدنظر است، چون این ربات
        // می‌تواند هم به یک گروه و هم مستقیماً به یک شخص مشخص پیام ارسال کند.
        $chats = array();
        foreach ( $updates as $update ) {
            $chat = null;
            if ( ! empty( $update['message']['chat'] ) ) {
                $chat = $update['message']['chat'];
            } elseif ( ! empty( $update['edited_message']['chat'] ) ) {
                $chat = $update['edited_message']['chat'];
            }

            if ( empty( $chat ) || empty( $chat['id'] ) ) {
                continue;
            }

            $type = isset( $chat['type'] ) ? $chat['type'] : '';

            if ( 'private' === $type ) {
                // برای چت خصوصی، عنوانی متشکل از نام (و در صورت وجود نام کاربری) شخص می‌سازیم
                $person_name = trim( ( isset( $chat['first_name'] ) ? $chat['first_name'] : '' ) . ' ' . ( isset( $chat['last_name'] ) ? $chat['last_name'] : '' ) );
                if ( '' === $person_name ) {
                    $person_name = ! empty( $chat['username'] ) ? '@' . $chat['username'] : ( 'کاربر ' . $chat['id'] );
                }
                $title = '👤 ' . $person_name;
            } elseif ( in_array( $type, array( 'group', 'channel' ), true ) ) {
                $title = ! empty( $chat['title'] ) ? $chat['title'] : ( 'گروه ' . $chat['id'] );
            } else {
                continue;
            }

            $chats[ $chat['id'] ] = array(
                'id'    => $chat['id'],
                'title' => $title,
                'type'  => $type,
            );
        }

        if ( empty( $chats ) ) {
            return new WP_Error(
                'armma_no_group_found',
                'شخص یا گروهی یافت نشد. مطمئن شوید بازو را به گروه اضافه کرده‌اید یا شخص موردنظر مستقیماً به بازو پیام داده باشد (مثلاً /start).'
            );
        }

        return array_values( $chats );
    }

    public function send_message( $chat_id, $text ) {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'armma_no_token', 'توکن بازو تنظیم نشده است.' );
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

        if ( empty( $body['ok'] ) ) {
            return new WP_Error( 'armma_send_failed', $this->extract_error_message( $body, 'ارسال پیام با خطا مواجه شد.' ) );
        }

        return true;
    }

    /**
     * ارسال تصویر به بله.
     *
     * روش قدیمی (قرار دادن یک URL عمومی در فیلد photo) وابسته به این بود که سرورِ بله
     * بتواند تصویر را مستقیماً از سایت دانلود کند؛ روی بسیاری از هاست‌های واقعی
     * (به‌دلیل فایروال/WAF، مسدودسازی IPهای خارجی یا کندی سرور) این دانلود یا
     * ناموفق است یا چند ده ثانیه طول می‌کشد — نتیجه‌اش رسیدن پیام با تأخیر زیاد و
     * در نهایت ارسال نسخه بدون عکس بود.
     *
     * راه‌حل: آپلود مستقیم بایت‌های فایل با multipart/form-data (مشابه روبیکا)؛
     * در این حالت هیچ وابستگی‌ای به دسترس‌پذیر بودن سایت از بیرون وجود ندارد.
     * ترتیب تلاش‌ها:
     *   ۱) خواندن فایل از مسیر محلی روی همین سرور (سریع‌ترین و مطمئن‌ترین راه)
     *   ۲) دانلود بایت‌ها از URL تصویر (loopback) و آپلود آن‌ها
     *   ۳) به‌عنوان آخرین چاره، همان روش قدیمی ارسال URL مستقیم
     */
    public function send_photo( $chat_id, $image_url, $caption, $local_path = '' ) {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'armma_no_token', 'توکن بازو تنظیم نشده است.' );
        }
        if ( empty( $chat_id ) ) {
            return new WP_Error( 'armma_no_chat_id', 'شناسه گروه (Chat ID) تنظیم نشده است.' );
        }
        if ( empty( $image_url ) && empty( $local_path ) ) {
            return new WP_Error( 'armma_no_image', 'آدرس تصویر مشخص نشده است.' );
        }

        // زیرنویس (caption) در بله حداکثر ۱۰۲۴ کاراکتر است؛ برای اطمینان کمی محافظه‌کارانه‌تر می‌بریم.
        // از mb_substr فقط در صورت وجود افزونه mbstring روی هاست استفاده می‌شود، وگرنه substr معمولی
        // (که ممکن است یک نویسه چندبایتی را در وسط ببرد، ولی خطای Fatal ایجاد نمی‌کند).
        $caption = (string) $caption;
        if ( function_exists( 'mb_substr' ) ) {
            $caption = mb_substr( $caption, 0, 1000 );
        } elseif ( strlen( $caption ) > 1000 ) {
            $caption = substr( $caption, 0, 1000 );
        }

        $last_error = null;

        // گام ۱: آپلود مستقیم از فایل محلی
        if ( ! empty( $local_path ) && file_exists( $local_path ) && is_readable( $local_path ) ) {
            $image_bytes = file_get_contents( $local_path );
            if ( false !== $image_bytes && '' !== $image_bytes ) {
                $result = $this->send_photo_bytes( $chat_id, $image_bytes, $caption, basename( $local_path ) );
                if ( ! is_wp_error( $result ) ) {
                    return true;
                }
                $last_error = $result;
            }
        }

        // گام ۲: دانلود بایت‌ها از URL تصویر توسط خود سایت و آپلود آن‌ها
        if ( ! empty( $image_url ) ) {
            $image_response = wp_remote_get( $image_url, array( 'timeout' => 25 ) );
            if ( ! is_wp_error( $image_response ) ) {
                $image_bytes = wp_remote_retrieve_body( $image_response );
                if ( ! empty( $image_bytes ) ) {
                    $filename = basename( parse_url( $image_url, PHP_URL_PATH ) );
                    if ( '' === $filename || false === $filename ) {
                        $filename = 'product-image.jpg';
                    }
                    $result = $this->send_photo_bytes( $chat_id, $image_bytes, $caption, $filename );
                    if ( ! is_wp_error( $result ) ) {
                        return true;
                    }
                    $last_error = $result;
                }
            }
        }

        // گام ۳ (آخرین چاره): ارسال مستقیم URL به بله؛ اگر سرور بله بتواند فایل را
        // دانلود کند ارسال می‌شود، وگرنه خطای مرحله قبل (یا همین مرحله) برگردانده می‌شود.
        if ( ! empty( $image_url ) ) {
            $payload = array(
                'chat_id' => $chat_id,
                'photo'   => $image_url,
                'caption' => $caption,
            );

            $body = $this->do_request( $this->get_api_base_url() . 'sendPhoto', $payload, 'json' );

            if ( ! is_wp_error( $body ) && ! empty( $body['ok'] ) ) {
                return true;
            }

            if ( is_wp_error( $body ) ) {
                $last_error = $body;
            } else {
                $last_error = new WP_Error( 'armma_send_failed', $this->extract_error_message( $body, 'ارسال عکس با خطا مواجه شد.' ) );
            }
        }

        if ( null === $last_error ) {
            $last_error = new WP_Error( 'armma_no_image', 'فایل تصویر در دسترس نبود و ارسال عکس انجام نشد.' );
        }

        return $last_error;
    }

    /**
     * آپلود دستی بایت‌های تصویر به متد sendPhoto بله با ساخت دستی بدنه
     * multipart/form-data (چون wp_remote_post برای آرایه‌های حاوی باینری خام
     * خودش این کار را انجام نمی‌دهد؛ رشته‌ی باینری را به‌عنوان مقدار پارامتر
     * encode می‌کند و فایل خراب می‌شود).
     *
     * @param string $chat_id
     * @param string $image_bytes محتوای باینری کامل فایل تصویر
     * @param string $caption
     * @param string $filename نام نمایشی فایل
     * @return true|WP_Error
     */
    private function send_photo_bytes( $chat_id, $image_bytes, $caption, $filename ) {
        $boundary = 'ARMMAWOONOTIFER' . wp_generate_password( 16, false, false );
        if ( '' === $filename ) {
            $filename = 'product-image.jpg';
        }

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"chat_id\"\r\n\r\n";
        $body .= $chat_id . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"caption\"\r\n\r\n";
        $body .= $caption . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"photo\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $image_bytes . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post( $this->get_api_base_url() . 'sendPhoto', array(
            'timeout' => 40,
            'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
            'body'    => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'armma_send_failed', 'ارسال تصویر به بله ناموفق بود: ' . $response->get_error_message() );
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( null === $decoded ) {
            return new WP_Error( 'armma_bad_response', 'پاسخ نامعتبر از بله هنگام ارسال تصویر دریافت شد.' );
        }

        if ( empty( $decoded['ok'] ) ) {
            return new WP_Error( 'armma_send_failed', $this->extract_error_message( $decoded, 'ارسال عکس با خطا مواجه شد.' ) );
        }

        return true;
    }
}
