#!/usr/bin/env python3
# Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
"""End-to-end checkout tests for JoePham_OkxPay against the OKX and X Layer mock (Test/Mock/okx-mock.php).

Scenarios:
  app           OKX app transfer of the exact amount: order invoiced, success page
  wrong_amount  a transfer of the plain order total is not taken for the payment; the exact amount is
  cancel        the shopper cancels on the payment page: order cancelled, cart restored
  late          the payment window ends (cron cancels the order), then the money arrives: flagged as late
  wallet        OKX Wallet on X Layer, paid in one tap from an injected test wallet (USDG)
  wallet_scan   a transfer from any wallet is found by the log scan; one mined before the order is ignored
  admin         the merchant sees the OKX payment on the order (needs --admin-user, --admin-password)

The store must have the module enabled and pointed at the mock (API URL, key, secret, passphrase, RPC URL), and
Playwright for Python installed. Example:

  python3 Test/E2E/okx_pay_e2e.py --base https://shop.test --magento-root /var/www/magento \\
      --product joust-duffle-bag.html --admin-user demo --admin-password secret

--video DIR records each scenario; --demo slows down and adds captions, for the README animations.
"""
import argparse
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse
import urllib.request

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
TOKENS = {
    '0x779ded0c9e1022225f8e0630b35a9b54be713736': 'USD₮0',
    '0x1e4a5963abfd975d8c9021ce480b42188849d41d': 'USDT',
    '0x4ae46a509f6b1d9056937ba4500cb143933d2dc8': 'USDG',
    '0x74b7f16337b8972027f6196a17a631ac6de26d22': 'USDC',
}
SHOPPER_ADDRESS = '0x52908400098527886e0f7030069857d2e4169ee7'

# A stand-in for OKX Wallet's injected provider. It answers the calls the payment page makes and shows a small
# panel labelled as a test wallet while it "asks" the shopper to approve.
FAKE_WALLET = """
(() => {
    const demo = %(demo)s;
    const tokens = %(tokens)s;
    let chainKnown = false;
    const short = (a) => a.slice(0, 6) + '...' + a.slice(-4);
    const approve = (tx) => new Promise((resolve) => {
        const units = BigInt('0x' + tx.data.slice(74));
        const amount = (Number(units) / 1e6).toFixed(4);
        const panel = document.createElement('div');
        panel.style.cssText = 'position:fixed;top:16px;right:16px;z-index:2147483646;width:300px;background:#fff;'
            + 'border:1px solid #d4d4d8;border-radius:16px;box-shadow:0 12px 32px rgba(0,0,0,.18);'
            + 'font:14px/1.45 system-ui,sans-serif;color:#111;overflow:hidden';
        panel.innerHTML = '<div style="background:#111;color:#fff;padding:10px 14px;font-weight:600">Test wallet'
            + ' <span style="opacity:.6;font-weight:400">(simulated)</span></div>'
            + '<div style="padding:14px"><div style="color:#666">Confirm transfer on X Layer</div>'
            + '<div style="font-size:22px;font-weight:700;margin:6px 0">' + amount + ' ' + (tokens[tx.to] || 'token') + '</div>'
            + '<div style="color:#666">To ' + short('0x' + tx.data.slice(34, 74)) + '</div>'
            + '<div style="margin-top:12px;background:#111;color:#fff;text-align:center;border-radius:10px;padding:9px;'
            + 'font-weight:600">Confirm</div></div>';
        document.body.appendChild(panel);
        setTimeout(() => {
            window.e2eWalletSend(JSON.stringify(tx)).then((hash) => {
                panel.remove();
                resolve(hash);
            });
        }, demo ? 2200 : 300);
    });
    window.okxwallet = {
        isOkxWallet: true,
        request: async ({method, params}) => {
            if (method === 'eth_requestAccounts' || method === 'eth_accounts') {
                return ['%(shopper)s'];
            }
            if (method === 'eth_chainId') {
                return chainKnown ? '0xc4' : '0x1';
            }
            if (method === 'wallet_switchEthereumChain') {
                if (!chainKnown) {
                    throw Object.assign(new Error('Unrecognized chain ID "0xc4".'), {code: 4902});
                }
                return null;
            }
            if (method === 'wallet_addEthereumChain') {
                chainKnown = params[0].chainId === '0xc4';
                return null;
            }
            if (method === 'eth_sendTransaction') {
                return approve(params[0]);
            }
            throw Object.assign(new Error('Unsupported method ' + method), {code: 4200});
        }
    };
})();
"""


class Run:
    def __init__(self, args, context, page):
        self.args = args
        self.context = context
        self.page = page

    # ---------------------------------------------------------------- helpers

    def caption(self, text, pause=1.6):
        """Show a caption over the page in demo mode; its progress bar keeps the frames moving while it shows."""
        if not self.args.demo:
            return
        self.page.evaluate(
            """([text, ms]) => {
                let el = document.getElementById('e2e-caption');
                if (!el) {
                    el = document.createElement('div');
                    el.id = 'e2e-caption';
                    el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2147483647;'
                        + 'background:rgba(17,24,39,.92);color:#fff;font:600 20px/1.4 system-ui,sans-serif;overflow:hidden;'
                        + 'padding:12px 22px 16px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.25);max-width:90%;text-align:center';
                    document.body.appendChild(el);
                }
                el.innerHTML = '<span></span><i style="position:absolute;left:0;bottom:0;height:4px;width:0;background:#d4d4d8"></i>';
                el.firstChild.textContent = text;
                el.lastChild.animate([{width: '0%'}, {width: '100%'}], {duration: ms, fill: 'forwards'});
            }""",
            [text, int(pause * 1000)],
        )
        self.page.wait_for_timeout(int(pause * 1000))

    def pause(self, seconds=1.0):
        if self.args.demo:
            self.page.wait_for_timeout(int(seconds * 1000))

    def order_state(self, increment_id):
        out = subprocess.run(
            ['php', os.path.join(HERE, 'order-state.php'), self.args.magento_root, increment_id],
            capture_output=True, text=True,
        )
        return json.loads(out.stdout or '{}')

    def wait_for_state(self, increment_id, check, timeout=60):
        end = time.time() + timeout
        while True:
            state = self.order_state(increment_id)
            if check(state):
                return state
            if time.time() > end:
                raise AssertionError(f'order {increment_id} did not reach the expected state: {json.dumps(state)[:800]}')
            time.sleep(1)

    def run_cron(self, expire_increment_id=None, background=False):
        """Run the module's cron job; with background=True, return the process for cron_done()."""
        command = ['php', os.path.join(HERE, 'run-cron.php'), self.args.magento_root]
        if expire_increment_id:
            command.append(expire_increment_id)
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        return process if background else self.cron_done(process)

    @staticmethod
    def cron_done(process):
        stdout, stderr = process.communicate()
        assert stdout.strip().endswith('ok'), stdout + stderr

    def mock(self, path, form=None):
        data = urllib.parse.urlencode(form).encode() if form is not None else None
        request = urllib.request.Request(self.args.mock + path, data=data, method='POST' if form is not None else 'GET')
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.loads(response.read())

    def wallet_send(self, tx_json):
        """Called by the test wallet: put the transfer it was asked to send on the mock chain."""
        tx = json.loads(tx_json)
        data = tx['data']
        assert data.startswith('0xa9059cbb') and len(data) == 138, data
        units = int(data[74:], 16)
        transfer = self.mock('/mock/transfer', {
            'token': tx['to'],
            'to': '0x' + data[34:74],
            'from': tx['from'],
            'amount': f'{units / 1e6:.6f}',
        })
        return transfer['hash']

    # ---------------------------------------------------------------- shopper

    def checkout(self, method):
        """Add a product and check out as a guest; returns (increment id, payment page token)."""
        page, base = self.page, self.args.base
        page.goto(f'{base}/{self.args.product}')
        self.caption('A shopper adds a product to the cart')
        page.click('#product-addtocart-button')
        page.wait_for_selector('.message-success', timeout=90000)
        page.goto(f'{base}/checkout/')
        page.wait_for_selector('#customer-email', state='visible', timeout=180000)
        form = '#shipping-new-address-form'
        page.fill('#customer-email', f'e2e+{int(time.time() * 1000)}@example.com')
        page.fill(f'{form} input[name="firstname"]', 'Alex')
        page.fill(f'{form} input[name="lastname"]', 'Nguyen')
        page.fill(f'{form} input[name="street[0]"]', '100 Market Street')
        page.select_option(f'{form} select[name="country_id"]', 'US')
        page.select_option(f'{form} select[name="region_id"]', label='California')
        page.fill(f'{form} input[name="city"]', 'Los Angeles')
        page.fill(f'{form} input[name="postcode"]', '90001')
        page.fill(f'{form} input[name="telephone"]', '5555550100')
        page.wait_for_selector('input[value="flatrate_flatrate"]', timeout=90000)
        page.check('input[value="flatrate_flatrate"]')
        self.pause(0.6)
        page.click('#shipping-method-buttons-container button.continue')
        page.wait_for_selector(f'#{method}', state='visible', timeout=90000)
        page.check(f'#{method}')
        page.wait_for_selector('.payment-method._active .joepham-okxpay-note', state='visible')
        self.caption('At checkout the shopper picks ' + ('OKX Wallet' if method.endswith('wallet') else 'OKX app transfer'))
        page.click('.payment-method._active button.checkout')
        page.wait_for_url(re.compile(r'/joepham_okxpay/payment/view/token/[0-9a-f]{32}'), timeout=90000)
        page.wait_for_selector('#okxpay[data-status="pending"]')
        if self.args.demo:
            page.evaluate("document.getElementById('okxpay').scrollIntoView({block: 'start'})")
        token = re.search(r'/token/([0-9a-f]{32})', page.url).group(1)
        increment_id = re.search(r'#(\d+)', page.inner_text('.okxpay__title')).group(1)
        return increment_id, token

    def payment_page(self):
        page = self.page
        return {
            'amount': page.inner_text('[data-role="amount"]').strip(),
            'coin': page.inner_text('[data-role="coin"]').strip(),
        }

    def wait_paid_and_success(self, increment_id, timeout=90):
        page = self.page
        page.wait_for_selector('#okxpay[data-status="paid"]', timeout=timeout * 1000)
        self.caption('Payment received: the page confirms it by itself', 1.4)
        page.wait_for_url(re.compile(r'/checkout/onepage/success'), timeout=30000)
        page.wait_for_selector('.checkout-success')
        assert increment_id in page.inner_text('.checkout-success'), 'order number not on the success page'

    def assert_paid(self, increment_id, coin, reference_pattern):
        state = self.wait_for_state(increment_id, lambda s: s.get('state') == 'processing')
        assert len(state['invoices']) == 1 and state['invoices'][0]['state'] == 2, state['invoices']
        assert state['email_sent'], 'order email not sent'
        okx = state['okx']
        assert okx['status'] == 'paid' and okx['coin'] == coin, okx
        assert re.fullmatch(reference_pattern, okx['reference']), okx['reference']
        assert state['payment']['info'].get('okx_received', '').endswith(' ' + coin), state['payment']['info']
        return state

    # ---------------------------------------------------------------- scenarios

    def scenario_app(self):
        increment_id, _ = self.checkout('joepham_okx_app')
        shown = self.payment_page()
        assert re.fullmatch(r'\d+\.\d{4}', shown['amount']) and shown['coin'] == 'USDT', shown
        assert 'payments@example.com' in self.page.inner_text('[data-role="recipient"]')
        self.caption('The payment page shows the exact amount and the OKX account to send it to', 2.6)
        self.caption('The shopper sends it from the OKX app (simulated by the test mock)', 2.0)
        deposit = self.mock('/mock/deposit', {'ccy': 'USDT', 'amt': shown['amount'], 'from': 'ale***@example.com'})
        # In a recording, find the transfer now instead of at the page's next search, while the page keeps polling.
        cron = self.run_cron(background=True) if self.args.demo else None
        self.wait_paid_and_success(increment_id)
        if cron:
            self.cron_done(cron)
        self.caption('Paid: the order is invoiced and the confirmation email is sent', 2.2)
        state = self.assert_paid(increment_id, 'USDT', re.escape(deposit['depId']))
        assert state['payment']['info']['okx_payer'] == 'ale***@example.com', state['payment']['info']
        return increment_id

    def scenario_wrong_amount(self):
        increment_id, _ = self.checkout('joepham_okx_app')
        shown = self.payment_page()
        total = self.order_state(increment_id)['grand_total']
        plain = f'{float(total):.2f}'
        assert plain != shown['amount']
        self.mock('/mock/deposit', {'ccy': 'USDT', 'amt': plain})
        self.run_cron()
        state = self.order_state(increment_id)
        assert state['state'] == 'pending_payment' and state['okx']['status'] == 'pending', state['okx']
        self.mock('/mock/deposit', {'ccy': 'USDC', 'amt': shown['amount']})  # coin not accepted
        self.run_cron()
        assert self.order_state(increment_id)['okx']['status'] == 'pending'
        deposit = self.mock('/mock/deposit', {'ccy': 'USDT', 'amt': shown['amount']})
        self.wait_paid_and_success(increment_id)
        self.assert_paid(increment_id, 'USDT', re.escape(deposit['depId']))
        return increment_id

    def scenario_cancel(self):
        page = self.page
        increment_id, _ = self.checkout('joepham_okx_app')
        self.caption('The shopper changes their mind and cancels')
        page.once('dialog', lambda dialog: dialog.accept())
        page.click('.okxpay__cancel')
        page.wait_for_url(re.compile(r'/checkout/cart'), timeout=90000)
        page.wait_for_selector('.message-notice, .message-success')
        assert page.locator('#shopping-cart-table .cart.item').count() >= 1, 'cart not restored'
        self.caption('The order is cancelled and the cart is back', 2.0)
        state = self.wait_for_state(increment_id, lambda s: s.get('state') == 'canceled')
        assert state['okx']['status'] == 'canceled', state['okx']
        return increment_id

    def scenario_late(self):
        page = self.page
        increment_id, _ = self.checkout('joepham_okx_app')
        shown = self.payment_page()
        self.run_cron(expire_increment_id=increment_id)
        state = self.wait_for_state(increment_id, lambda s: s.get('state') == 'canceled')
        assert state['okx']['status'] == 'expired', state['okx']
        page.wait_for_selector('#okxpay[data-status="expired"]', timeout=30000)

        deposit = self.mock('/mock/deposit', {'ccy': 'USDT', 'amt': shown['amount']})
        self.run_cron()
        state = self.wait_for_state(increment_id, lambda s: (s.get('okx') or {}).get('status') == 'late')
        assert state['state'] == 'canceled' and not state['invoices'], state
        assert state['okx']['reference'] == deposit['depId'], state['okx']
        assert any('arrived after the order stopped waiting' in c for c in state['comments']), state['comments']
        return increment_id

    def scenario_wallet(self):
        page = self.page
        increment_id, _ = self.checkout('joepham_okx_wallet')
        page.wait_for_selector('[data-role="wallet-pay"]:not([hidden])')
        self.caption('OKX Wallet: the page shows the amount and the receiving address on X Layer', 2.4)
        page.click('.okxpay__token[data-token-index="1"]')
        shown = self.payment_page()
        assert shown['coin'] == 'USDG', shown
        self.caption('The shopper picks USDG and pays in one tap', 1.8)
        page.click('[data-role="wallet-pay"]')
        page.wait_for_function(
            "document.querySelector('[data-role=\"status-text\"]').textContent.includes('Transaction sent')",
            timeout=30000,
        )
        self.caption('Sent on X Layer; the module waits for 3 confirmations', 2.0)
        self.wait_paid_and_success(increment_id)
        self.caption('Paid: the order is invoiced and the confirmation email is sent', 2.2)
        state = self.assert_paid(increment_id, 'USDG', r'0x[0-9a-f]{64}:0')
        assert state['okx']['tx_hash'] == state['okx']['reference'].split(':')[0], state['okx']
        assert state['payment']['info']['okx_payer'] == SHOPPER_ADDRESS, state['payment']['info']
        return increment_id

    def scenario_wallet_scan(self):
        page = self.page
        self.context.add_init_script('delete window.okxwallet; delete window.ethereum;')
        increment_id, _ = self.checkout('joepham_okx_wallet')
        page.wait_for_selector('[data-role="wallet-hint"]:not([hidden])')
        shown = self.payment_page()
        address = page.inner_text('[data-role="address"]').strip()
        okx = self.order_state(increment_id)['okx']
        start_block = int(okx['start_block'])

        # Same amount, mined before the order existed: must not pay it.
        self.mock('/mock/transfer', {'token': 'USDT0', 'to': address, 'amount': shown['amount'], 'block': start_block - 5})
        # Wrong token contract: ignored.
        self.mock('/mock/transfer', {'token': '0x' + '12' * 20, 'to': address, 'amount': shown['amount']})
        time.sleep(4)
        self.run_cron()
        assert self.order_state(increment_id)['okx']['status'] == 'pending'

        transfer = self.mock('/mock/transfer', {'token': 'USDT0', 'to': address, 'amount': shown['amount']})
        self.wait_paid_and_success(increment_id)
        state = self.assert_paid(increment_id, 'USDT0', re.escape(transfer['hash']) + ':0')
        assert not state['okx']['tx_hash'], 'no wallet transaction was submitted in this scenario'
        return increment_id

    def scenario_admin(self, increment_id=None):
        page, base = self.page, self.args.base
        increment_id = increment_id or self.args.order or self.scenario_wallet()
        page.goto(f'{base}/{self.args.admin_path}')
        if page.locator('#username').count():
            page.fill('#username', self.args.admin_user)
            page.fill('#login', self.args.admin_password)
            page.click('.action-login')
        page.wait_for_selector('#menu-magento-sales-sales', state='attached', timeout=90000)
        orders_url = page.get_attribute('#menu-magento-sales-sales a[href*="/sales/order/"]', 'href')
        page.goto(orders_url)
        page.wait_for_selector('#fulltext', timeout=90000)
        page.fill('#fulltext', increment_id)
        page.press('#fulltext', 'Enter')
        row = page.locator('tr.data-row', has_text=increment_id).first
        row.wait_for(timeout=60000)
        self.caption('The merchant opens the order in the admin')
        row.locator('a.action-menu-item', has_text='View').click()
        page.wait_for_selector('.order-payment-method', timeout=90000)
        page.locator('.order-payment-method').scroll_into_view_if_needed()
        text = page.inner_text('.order-payment-method')
        assert 'Amount Received' in text and ('X Layer Transaction' in text or 'OKX Deposit ID' in text), text
        self.caption('Amount received, transaction and sender are on the order', 2.6)
        page.locator('#order_history_block').scroll_into_view_if_needed()
        assert 'OKX payment received' in page.inner_text('#order_history_block')
        self.caption('The order is invoiced automatically', 2.0)
        return increment_id


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--base', required=True, help='store URL, e.g. https://shop.test')
    parser.add_argument('--magento-root', required=True)
    parser.add_argument('--product', required=True, help='URL key of a simple product, e.g. joust-duffle-bag.html')
    parser.add_argument('--mock', default='http://127.0.0.1:8703')
    parser.add_argument('--scenario', default='app,wrong_amount,cancel,late,wallet,wallet_scan,admin')
    parser.add_argument('--admin-path', default='admin')
    parser.add_argument('--admin-user', default=os.environ.get('E2E_ADMIN_USER', ''))
    parser.add_argument('--admin-password', default=os.environ.get('E2E_ADMIN_PASSWORD', ''))
    parser.add_argument('--order', help='show this paid order in the admin scenario instead of placing a new one')
    parser.add_argument('--video', help='record each scenario into this directory')
    parser.add_argument('--demo', action='store_true', help='slow down and caption the steps')
    parser.add_argument('--headed', action='store_true')
    args = parser.parse_args()
    args.base = args.base.rstrip('/')
    args.mock = args.mock.rstrip('/')

    failures = 0
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=not args.headed)
        for name in [s.strip() for s in args.scenario.split(',') if s.strip()]:
            options = {'ignore_https_errors': True, 'viewport': {'width': 1280, 'height': 800}}
            if args.video:
                options['record_video_dir'] = os.path.join(args.video, name)
                options['record_video_size'] = {'width': 1280, 'height': 800}
            context = browser.new_context(**options)
            context.set_default_timeout(60000)
            if args.demo:
                # Keep store-specific admin notices and third-party menus out of the recordings.
                context.add_init_script(
                    "document.addEventListener('DOMContentLoaded', () => {"
                    " const style = document.createElement('style');"
                    " style.textContent = '#system_messages, .message-system,"
                    " #nav > li[id^=\"menu-\"]:not([id^=\"menu-magento-\"]) { display: none !important; }';"
                    " document.head.appendChild(style); });"
                )
            page = context.new_page()
            run = Run(args, context, page)
            context.expose_function('e2eWalletSend', run.wallet_send)
            context.add_init_script(FAKE_WALLET % {
                'demo': 'true' if args.demo else 'false',
                'tokens': json.dumps(TOKENS, ensure_ascii=False),
                'shopper': SHOPPER_ADDRESS,
            })
            started = time.time()
            try:
                increment_id = getattr(run, f'scenario_{name}')()
                print(f'PASS {name} order {increment_id} ({time.time() - started:.0f}s)', flush=True)
            except Exception as error:  # report every scenario, then fail
                failures += 1
                shot = os.path.join(args.video or '.', f'failure-{name}.png')
                try:
                    page.screenshot(path=shot, full_page=True)
                except Exception:
                    shot = '(no screenshot)'
                print(f'FAIL {name}: {error} [{page.url}] {shot}', flush=True)
            finally:
                context.close()
        browser.close()
    sys.exit(1 if failures else 0)


if __name__ == '__main__':
    main()
