/**
 * اسکریپت صفحات مدیریت «ربات پیام‌رسان آرما»
 * تب‌ها حالا صفحه‌های مستقل وردپرس هستند و آکاردئون راهنما با <details> بومی کار می‌کند،
 * پس این فایل فقط شامل عملیات Ajax و نمایش/پنهان‌سازی فیلدهای وابسته است.
 */
jQuery(function ($) {
    'use strict';

    function setResult($el, message, type) {
        $el.removeClass('success error loading').addClass(type).text(message);
    }

    /* ---------- بررسی اتصال توکن ---------- */
    $(document).on('click', '.armma-test-token-btn', function () {
        var $btn = $(this);
        var platform = $btn.data('platform');
        var $result = $('#armma_' + platform + '_test_token_result');
        var token = $('#armma_' + platform + '_bot_token').val().trim();

        if (!token) {
            setResult($result, 'ابتدا توکن بات را وارد کنید.', 'error');
            return;
        }

        $btn.prop('disabled', true);
        setResult($result, 'در حال بررسی...', 'loading');

        $.post(armmaAjax.ajaxUrl, {
            action: 'armma_woo_notifer_test_token',
            nonce: armmaAjax.nonce,
            platform: platform,
            bot_token: token
        }).done(function (res) {
            setResult($result, res.data.message, res.success ? 'success' : 'error');
        }).fail(function () {
            setResult($result, 'خطا در ارتباط با سرور.', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ---------- دریافت خودکار شناسه شخص/گروه/کانال ---------- */
    $(document).on('click', '.armma-fetch-chatid-btn', function () {
        var $btn = $(this);
        var platform = $btn.data('platform');
        var targetId = $btn.data('target');
        var targetTitleId = $btn.data('target-title');
        var $scope = $btn.closest('.armma-field-control');
        var $result = $scope.find('.armma-fetch-result').first();
        var $list = $scope.find('.armma-chat-list').first();
        var $tokenField = $('#armma_' + platform + '_bot_token');
        var token = $tokenField.length ? $tokenField.val().trim() : '';

        // اگر فیلد توکن در این صفحه وجود دارد ولی خالی است، همان‌جا هشدار بده.
        // اگر اصلاً وجود ندارد (صفحه «کانال فروشگاه»)، توکن ذخیره‌شده سمت سرور استفاده می‌شود.
        if ($tokenField.length && !token) {
            setResult($result, 'ابتدا توکن بات را وارد کنید.', 'error');
            return;
        }

        $btn.prop('disabled', true);
        $list.empty();
        setResult($result, 'در حال جستجو...', 'loading');

        $.post(armmaAjax.ajaxUrl, {
            action: 'armma_woo_notifer_fetch_chat_id',
            nonce: armmaAjax.nonce,
            platform: platform,
            bot_token: token
        }).done(function (res) {
            if (!res.success) {
                setResult($result, res.data.message, 'error');
                return;
            }

            setResult($result, 'مقصد(های) زیر یافت شد. یکی را انتخاب کنید:', 'success');

            res.data.chats.forEach(function (chat) {
                var $item = $('<div class="armma-chat-item"></div>');
                $item.append($('<span class="armma-chat-title"></span>').text(chat.title));
                $item.append($('<span class="armma-chat-id"></span>').text(chat.id));

                var $useBtn = $('<button type="button" class="button button-small">انتخاب</button>');
                $useBtn.on('click', function () {
                    $('#' + targetId).val(chat.id);

                    var $titleHidden = $('#' + targetTitleId);
                    $titleHidden.val(chat.title);

                    var $badge = $titleHidden.next('.armma-chat-title-badge');
                    var badgeText = 'مقصد: ' + chat.title;
                    if ($badge.length) {
                        $badge.text(badgeText);
                    } else {
                        $titleHidden.after($('<span class="armma-chat-title-badge"></span>').text(badgeText));
                    }

                    setResult($result, 'مقصد «' + chat.title + '» انتخاب شد. برای ثبت، تنظیمات را ذخیره کنید.', 'success');
                    $list.empty();
                });

                $item.append($useBtn);
                $list.append($item);
            });
        }).fail(function () {
            setResult($result, 'خطا در ارتباط با سرور.', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ---------- ارسال پیام آزمایشی (همیشه روی مقصد اصلی) ---------- */
    $(document).on('click', '.armma-send-test-btn', function () {
        var $btn = $(this);
        var platform = $btn.data('platform');
        var $result = $('#armma_' + platform + '_send_test_result');
        var token = $('#armma_' + platform + '_bot_token').val().trim();
        var chatId = $('#armma_' + platform + '_chat_id').val().trim();

        if (!token || !chatId) {
            setResult($result, 'ابتدا توکن بات و شناسه شخص یا گروه را وارد کنید.', 'error');
            return;
        }

        $btn.prop('disabled', true);
        setResult($result, 'در حال ارسال...', 'loading');

        $.post(armmaAjax.ajaxUrl, {
            action: 'armma_woo_notifer_send_test_message',
            nonce: armmaAjax.nonce,
            platform: platform,
            bot_token: token,
            chat_id: chatId
        }).done(function (res) {
            setResult($result, res.data.message, res.success ? 'success' : 'error');
        }).fail(function () {
            setResult($result, 'خطا در ارتباط با سرور.', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ---------- ارسال مجدد دستی (باکس آرما در صفحه ویرایش سفارش) ---------- */
    $(document).on('click', '.armma-resend-btn', function () {
        var $btn = $(this);
        var $result = $btn.closest('p, td').find('.armma-resend-result');

        $btn.prop('disabled', true);
        setResult($result, 'در حال ارسال مجدد...', 'loading');

        $.post(armmaAjax.ajaxUrl, {
            action: 'armma_woo_notifer_resend_notification',
            nonce: armmaAjax.nonce,
            order_id: $btn.data('order-id'),
            platform: $btn.data('platform')
        }).done(function (res) {
            setResult($result, res.data.message, res.success ? 'success' : 'error');
        }).fail(function () {
            setResult($result, 'خطا در ارتباط با سرور.', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ---------- فیلدهای وابسته به یک سوییچ ---------- */
    function armmaSyncDependent($checkbox) {
        var targetId = $checkbox.data('armma-toggle');
        if (!targetId) return;
        $('#' + targetId).toggleClass('is-visible', $checkbox.is(':checked'));
    }

    $('[data-armma-toggle]').each(function () {
        armmaSyncDependent($(this));
    });

    $(document).on('change', '[data-armma-toggle]', function () {
        armmaSyncDependent($(this));
    });
});
