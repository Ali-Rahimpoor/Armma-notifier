<?php
/**
 * نمایش وضعیت آخرین ارسال‌های ربات و دکمه «ارسال مجدد» در خود صفحه ویرایش سفارش
 * (هم حالت کلاسیک و هم HPOS ووکامرس). این کلاس صفحه/منوی جداگانه‌ای در پیشخوان ندارد؛
 * فقط یک باکس کوچک را داخل صفحه سفارش تزریق می‌کند.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ARMMA_WOO_NOTIFER_Order_Box {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_armma_woo_notifer_resend_notification', array( $this, 'ajax_resend' ) );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_box' ) );
    }

    /**
     * بارگذاری اسکریپت/استایل در صفحه ویرایش سفارش (کلاسیک و HPOS)، چون دکمه
     * «ارسال مجدد» فقط همان‌جا رندر می‌شود.
     */
    public function enqueue_assets( $hook ) {
        $is_classic_order_edit = ( 'post.php' === $hook ) && isset( $_GET['post'] ) && 'shop_order' === get_post_type( absint( $_GET['post'] ) );
        $is_hpos_order_screen  = 'woocommerce_page_wc-orders' === $hook;

        if ( ! $is_classic_order_edit && ! $is_hpos_order_screen ) {
            return;
        }

        wp_enqueue_style( 'armma-admin-style', ARMMA_WOO_NOTIFER_PLUGIN_URL . 'assets/admin.css', array(), ARMMA_WOO_NOTIFER_ASSETS_VERSION );
        wp_enqueue_script( 'armma-admin-script', ARMMA_WOO_NOTIFER_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), ARMMA_WOO_NOTIFER_ASSETS_VERSION, true );

        wp_localize_script( 'armma-admin-script', 'armmaAjax', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( ARMMA_WOO_NOTIFER_Settings::AJAX_NONCE_ACTION ),
        ) );
    }

    /**
     * Ajax: ارسال مجدد دستی پیام یک سفارش به یک پیام‌رسان مشخص
     */
    public function ajax_resend() {
        check_ajax_referer( ARMMA_WOO_NOTIFER_Settings::AJAX_NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ) );
        }

        $order_id    = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $platform_id = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '';
        $supported   = array_keys( ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms() );

        if ( ! $order_id || ! in_array( $platform_id, $supported, true ) ) {
            wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ) );
        }

        $notifier = ARMMA_WOO_NOTIFER_Order_Notifier::get_instance();
        if ( ! $notifier ) {
            wp_send_json_error( array( 'message' => 'افزونه به‌درستی راه‌اندازی نشده است.' ) );
        }

        $result = $notifier->manual_resend( $order_id, $platform_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => sprintf( 'پیام سفارش #%d با موفقیت به %s ارسال مجدد شد.', $order_id, ARMMA_WOO_NOTIFER_Messenger_API::get_platform_label( $platform_id ) ),
        ) );
    }

    /**
     * نمایش باکس کوچک وضعیت آرما در صفحه ویرایش سفارش (بلافاصله بعد از جزئیات سفارش،
     * چه در حالت کلاسیک و چه در HPOS — این هوک در هر دو حالت به یک شکل فایر می‌شود)
     */
    public function render_order_box( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $settings  = get_option( ARMMA_WOO_NOTIFER_Settings::OPTION_KEY, array() );
        $platforms = isset( $settings['platforms'] ) && is_array( $settings['platforms'] ) ? $settings['platforms'] : array();

        $enabled_platforms = array();
        foreach ( array_keys( ARMMA_WOO_NOTIFER_Messenger_API::get_supported_platforms() ) as $platform_id ) {
            if ( ! empty( $platforms[ $platform_id ]['enabled'] ) && 'yes' === $platforms[ $platform_id ]['enabled'] ) {
                $enabled_platforms[] = $platform_id;
            }
        }

        if ( empty( $enabled_platforms ) ) {
            return;
        }

        $logs = ARMMA_WOO_NOTIFER_Log::get_last_for_order( $order->get_id(), 5 );
        ?>
        <div class="armma-order-box">
            <h4>🤖 ربات پیام‌رسان آرما</h4>
            <?php if ( empty( $logs ) ) : ?>
                <p class="description">هنوز پیامی برای این سفارش ثبت نشده است.</p>
            <?php else : ?>
                <ul class="armma-order-log-list">
                    <?php foreach ( $logs as $log ) :
                        $platform_label = ARMMA_WOO_NOTIFER_Messenger_API::get_platform_label( $log->platform );
                        ?>
                        <li>
                            <?php echo esc_html( $platform_label ); ?> —
                            <?php if ( $log->success ) : ?>
                                <span class="armma-badge armma-badge-success">موفق</span>
                            <?php else : ?>
                                <span class="armma-badge armma-badge-error">ناموفق</span>
                            <?php endif; ?>
                            <span class="armma-log-date"><?php echo esc_html( $log->created_at ); ?></span>
                            <?php if ( ! $log->success && $log->error_message ) : ?>
                                <div class="armma-log-error"><?php echo esc_html( $log->error_message ); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="armma-resend-buttons">
                <?php foreach ( $enabled_platforms as $platform_id ) : ?>
                    <button type="button" class="button button-small armma-resend-btn"
                            data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                            data-platform="<?php echo esc_attr( $platform_id ); ?>">
                        ارسال مجدد به <?php echo esc_html( ARMMA_WOO_NOTIFER_Messenger_API::get_platform_label( $platform_id ) ); ?>
                    </button>
                <?php endforeach; ?>
                <span class="armma-result armma-resend-result"></span>
            </p>
        </div>
        <?php
    }
}
