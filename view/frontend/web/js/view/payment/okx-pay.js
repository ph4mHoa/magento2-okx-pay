/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push(
        {
            type: 'joepham_okx_app',
            component: 'JoePham_OkxPay/js/view/payment/method-renderer/okx-pay'
        },
        {
            type: 'joepham_okx_wallet',
            component: 'JoePham_OkxPay/js/view/payment/method-renderer/okx-pay'
        }
    );

    return Component.extend({});
});
