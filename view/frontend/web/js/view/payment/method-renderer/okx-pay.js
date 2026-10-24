/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'JoePham_OkxPay/payment/okx-pay'
        },

        /** The amount to pay is set after the order is placed, by the start controller. */
        redirectAfterPlaceOrder: false,

        /**
         * @return {Boolean}
         */
        isWallet: function () {
            return this.getCode() === 'joepham_okx_wallet';
        },

        /**
         * Open the payment page.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('joepham_okxpay/payment/start'));
        }
    });
});
