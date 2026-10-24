<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 *
 * OKX API v5 deposit history and X Layer JSON-RPC mock for local end-to-end tests and demos. Never point a live
 * store at it.
 *
 * It checks OKX request signatures the way OKX does, keeps internal transfers and token transfers, and makes a
 * chain that grows by one block per second, so both payment methods run without an OKX account or real funds:
 *
 *   MOCK_API_KEY=mock-key MOCK_API_SECRET=mock-secret MOCK_API_PASSPHRASE=mock-pass PHP_CLI_SERVER_WORKERS=4 \
 *       php -S 127.0.0.1:8703 Test/Mock/okx-mock.php
 *
 * Then set the OKX App Transfer API URL to http://127.0.0.1:8703 with the same key, secret and passphrase, and
 * the OKX Wallet RPC URL to http://127.0.0.1:8703/rpc.
 *
 * Environment:
 *   MOCK_API_KEY, MOCK_API_SECRET, MOCK_API_PASSPHRASE  OKX credentials (defaults: mock-key, mock-secret, mock-pass)
 *   MOCK_STATE_DIR      where the state is kept (default: system temp dir)
 *   MOCK_START_BLOCK    block number right after a reset (default 40000000)
 *
 * Test helpers (form fields or query string):
 *   GET  /mock/state      deposits, transfers and the current block as JSON
 *   POST /mock/reset      forget everything and restart the chain at MOCK_START_BLOCK
 *   POST /mock/deposit    ccy, amt, from, state (default 2): an internal transfer arrives in the OKX account
 *   POST /mock/transfer   token (USDT0, USDT, USDG, USDC or an address), to, amount, from, status (0x1),
 *                         block (default: the current one): a token transfer on X Layer; returns its hash
 *   POST /mock/fail       target (okx or rpc), count: the next calls fail, as during an outage
 */
declare(strict_types=1);

const OKM_CHAIN_ID = 196;
const OKM_MAX_LOG_RANGE = 100;
const OKM_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
const OKM_TOKENS = [
    'USDT0' => '0x779ded0c9e1022225f8e0630b35a9b54be713736',
    'USDT' => '0x1e4a5963abfd975d8c9021ce480b42188849d41d',
    'USDG' => '0x4ae46a509f6b1d9056937ba4500cb143933d2dc8',
    'USDC' => '0x74b7f16337b8972027f6196a17a631ac6de26d22',
];

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Run with: php -S 127.0.0.1:8703 " . basename(__FILE__) . "\n");
    exit(1);
}

okm_route(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    (string)file_get_contents('php://input')
);

/**
 * Dispatch one HTTP request.
 */
function okm_route(string $method, string $path, string $body): void
{
    if ($path === '/api/v5/asset/deposit-history' && $method === 'GET') {
        okm_deposit_history($body);
    } elseif ($path === '/rpc' && $method === 'POST') {
        okm_rpc($body);
    } elseif ($path === '/mock/state') {
        okm_json(200, okm_with_state(static function (array &$state): array {
            return ['block' => okm_block($state)] + $state;
        }));
    } elseif ($path === '/mock/reset' && $method === 'POST') {
        okm_with_state(static function (array &$state): void {
            $state = okm_empty_state();
        });
        okm_json(200, ['reset' => true]);
    } elseif ($path === '/mock/deposit' && $method === 'POST') {
        okm_json(200, okm_add_deposit($_REQUEST));
    } elseif ($path === '/mock/transfer' && $method === 'POST') {
        $transfer = okm_add_transfer($_REQUEST);
        okm_json($transfer === null ? 400 : 200, $transfer ?? ['error' => 'unknown token or bad address']);
    } elseif ($path === '/mock/fail' && $method === 'POST') {
        okm_with_state(static function (array &$state): void {
            $state['fail'][(string)($_REQUEST['target'] ?? 'okx')] = max(0, (int)($_REQUEST['count'] ?? 1));
        });
        okm_json(200, ['ok' => true]);
    } else {
        okm_json(404, ['error' => 'not found']);
    }
}

/**
 * GET /api/v5/asset/deposit-history
 */
function okm_deposit_history(string $body): void
{
    $error = okm_check_signature($body);
    if ($error !== null) {
        okm_json($error[0] === '50113' || $error[0] === '50111' ? 401 : 400, [
            'code' => $error[0],
            'msg' => $error[1],
            'data' => [],
        ]);
        return;
    }
    if (okm_take_failure('okx')) {
        okm_json(503, ['code' => '50001', 'msg' => 'Service temporarily unavailable. Please try again later.', 'data' => []]);
        return;
    }

    $ccy = strtoupper((string)($_GET['ccy'] ?? ''));
    $type = (string)($_GET['type'] ?? '');
    $after = isset($_GET['after']) ? (int)$_GET['after'] : null;
    $before = isset($_GET['before']) ? (int)$_GET['before'] : null;
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 100)));

    $deposits = okm_with_state(static fn (array &$state): array => $state['deposits']);
    $rows = array_values(array_filter($deposits, static function (array $deposit) use ($ccy, $type, $after, $before) {
        return ($ccy === '' || $deposit['ccy'] === $ccy)
            && ($type === '' || $deposit['type'] === $type)
            && ($after === null || (int)$deposit['ts'] < $after)
            && ($before === null || (int)$deposit['ts'] > $before);
    }));
    usort($rows, static fn (array $a, array $b): int => (int)$b['ts'] <=> (int)$a['ts']);
    $rows = array_map(static function (array $deposit): array {
        unset($deposit['type']);
        return $deposit;
    }, array_slice($rows, 0, $limit));

    okm_json(200, ['code' => '0', 'msg' => '', 'data' => $rows]);
}

/**
 * POST /rpc: the X Layer JSON-RPC calls the module makes.
 */
function okm_rpc(string $body): void
{
    $request = json_decode($body, true);
    if (!is_array($request) || !isset($request['method'])) {
        okm_json(200, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'parse error']]);
        return;
    }
    $id = $request['id'] ?? null;
    $params = (array)($request['params'] ?? []);
    if (okm_take_failure('rpc')) {
        okm_json(503, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'upstream unavailable']]);
        return;
    }

    $state = okm_with_state(static fn (array &$state): array => $state);
    $latest = okm_block($state);
    switch ($request['method']) {
        case 'eth_chainId':
            $result = '0x' . dechex(OKM_CHAIN_ID);
            break;
        case 'eth_blockNumber':
            $result = '0x' . dechex($latest);
            break;
        case 'eth_getTransactionReceipt':
            $hash = strtolower((string)($params[0] ?? ''));
            $result = isset($state['transfers'][$hash]) && $state['transfers'][$hash]['block'] <= $latest
                ? okm_receipt($state['transfers'][$hash])
                : null;
            break;
        case 'eth_getLogs':
            $filter = (array)($params[0] ?? []);
            $from = okm_block_param($filter['fromBlock'] ?? 'latest', $latest);
            $to = min(okm_block_param($filter['toBlock'] ?? 'latest', $latest), $latest);
            if ($to - $from + 1 > OKM_MAX_LOG_RANGE) {
                okm_json(200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                    'code' => -32602,
                    'message' => 'block range greater than ' . OKM_MAX_LOG_RANGE . ' max',
                ]]);
                return;
            }
            $result = okm_logs($state, $from, $to, $filter);
            break;
        default:
            okm_json(200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                'code' => -32601,
                'message' => 'the method ' . $request['method'] . ' does not exist/is not available',
            ]]);
            return;
    }

    okm_json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

/**
 * Logs of the transfers in a block range that match an eth_getLogs filter.
 */
function okm_logs(array $state, int $from, int $to, array $filter): array
{
    $addresses = array_map('strtolower', (array)($filter['address'] ?? []));
    $topics = (array)($filter['topics'] ?? []);
    $logs = [];
    foreach ($state['transfers'] as $transfer) {
        if ($transfer['block'] < $from || $transfer['block'] > $to || $transfer['status'] !== '0x1') {
            continue;
        }
        $log = okm_log($transfer);
        if ($addresses !== [] && !in_array($log['address'], $addresses, true)) {
            continue;
        }
        foreach ($topics as $position => $wanted) {
            if ($wanted === null) {
                continue;
            }
            $options = array_map('strtolower', (array)$wanted);
            if (!in_array(strtolower($log['topics'][$position] ?? ''), $options, true)) {
                continue 2;
            }
        }
        $logs[] = $log;
    }
    usort($logs, static fn (array $a, array $b): int => hexdec($a['blockNumber']) <=> hexdec($b['blockNumber']));

    return $logs;
}

/**
 * Transfer event of a token transfer, shaped like eth_getLogs returns it.
 */
function okm_log(array $transfer): array
{
    return [
        'address' => $transfer['token'],
        'topics' => [OKM_TRANSFER_TOPIC, okm_topic($transfer['from']), okm_topic($transfer['to'])],
        'data' => '0x' . str_pad(okm_dec_to_hex($transfer['units']), 64, '0', STR_PAD_LEFT),
        'blockNumber' => '0x' . dechex($transfer['block']),
        'blockHash' => '0x' . hash('sha256', 'block' . $transfer['block']),
        'transactionHash' => $transfer['hash'],
        'transactionIndex' => '0x0',
        'logIndex' => '0x' . dechex($transfer['logIndex']),
        'removed' => false,
    ];
}

/**
 * Receipt of a token transfer.
 */
function okm_receipt(array $transfer): array
{
    return [
        'transactionHash' => $transfer['hash'],
        'transactionIndex' => '0x0',
        'blockNumber' => '0x' . dechex($transfer['block']),
        'blockHash' => '0x' . hash('sha256', 'block' . $transfer['block']),
        'from' => $transfer['from'],
        'to' => $transfer['token'],
        'status' => $transfer['status'],
        'gasUsed' => '0xb411',
        'logs' => $transfer['status'] === '0x1' ? [okm_log($transfer)] : [],
    ];
}

/**
 * Record an internal transfer to the OKX account.
 */
function okm_add_deposit(array $input): array
{
    return okm_with_state(static function (array &$state) use ($input): array {
        $state['seq']++;
        $deposit = [
            'actualDepBlkConfirm' => '',
            'amt' => (string)($input['amt'] ?? '0'),
            'areaCodeFrom' => '',
            'ccy' => strtoupper((string)($input['ccy'] ?? 'USDT')),
            'chain' => '',
            'depId' => (string)(171000000 + $state['seq']),
            'from' => (string)($input['from'] ?? 'sho***@example.com'),
            'fromWdId' => (string)(9100000 + $state['seq']),
            'state' => (string)($input['state'] ?? '2'),
            'to' => '',
            'ts' => (string)(int)round(microtime(true) * 1000),
            'txId' => '',
            'type' => (string)($input['type'] ?? '3'),
        ];
        $state['deposits'][] = $deposit;

        return $deposit;
    });
}

/**
 * Record a token transfer on X Layer.
 */
function okm_add_transfer(array $input): ?array
{
    $token = (string)($input['token'] ?? 'USDT0');
    $token = strtolower(OKM_TOKENS[strtoupper($token)] ?? $token);
    $to = strtolower((string)($input['to'] ?? ''));
    $from = strtolower((string)($input['from'] ?? '0x' . bin2hex(random_bytes(20))));
    if (!preg_match('/^0x[0-9a-f]{40}$/', $token) || !preg_match('/^0x[0-9a-f]{40}$/', $to)
        || !preg_match('/^0x[0-9a-f]{40}$/', $from)) {
        return null;
    }
    $units = okm_to_units((string)($input['amount'] ?? '0'));

    return okm_with_state(static function (array &$state) use ($input, $token, $to, $from, $units): array {
        $block = isset($input['block']) ? (int)$input['block'] : okm_block($state);
        $logIndex = count(array_filter($state['transfers'], static fn (array $t): bool => $t['block'] === $block));
        $hash = '0x' . bin2hex(random_bytes(32));
        $state['transfers'][$hash] = [
            'hash' => $hash,
            'token' => $token,
            'from' => $from,
            'to' => $to,
            'units' => $units,
            'block' => $block,
            'logIndex' => $logIndex,
            'status' => (string)($input['status'] ?? '0x1'),
        ];

        return $state['transfers'][$hash];
    });
}

/**
 * OKX signature check: base64 HMAC-SHA256 of timestamp + method + request path with query + body.
 *
 * @return array{0: string, 1: string}|null error code and message
 */
function okm_check_signature(string $body): ?array
{
    $key = (string)($_SERVER['HTTP_OK_ACCESS_KEY'] ?? '');
    $sign = (string)($_SERVER['HTTP_OK_ACCESS_SIGN'] ?? '');
    $timestamp = (string)($_SERVER['HTTP_OK_ACCESS_TIMESTAMP'] ?? '');
    $passphrase = (string)($_SERVER['HTTP_OK_ACCESS_PASSPHRASE'] ?? '');
    if ($key === '') {
        return ['50103', 'Request header "OK-ACCESS-KEY" cannot be empty.'];
    }
    if ($passphrase === '') {
        return ['50104', 'Request header "OK-ACCESS-PASSPHRASE" cannot be empty.'];
    }
    if ($sign === '') {
        return ['50106', 'Request header "OK-ACCESS-SIGN" cannot be empty.'];
    }
    if ($timestamp === '') {
        return ['50107', 'Request header "OK-ACCESS-TIMESTAMP" cannot be empty.'];
    }
    if ($key !== (getenv('MOCK_API_KEY') ?: 'mock-key')) {
        return ['50111', 'Invalid OK-ACCESS-KEY.'];
    }
    if ($passphrase !== (getenv('MOCK_API_PASSPHRASE') ?: 'mock-pass')) {
        return ['50105', 'Request header "OK-ACCESS-PASSPHRASE" incorrect.'];
    }
    $time = strtotime($timestamp);
    if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $timestamp) || $time === false) {
        return ['50112', 'Invalid OK-ACCESS-TIMESTAMP.'];
    }
    if (abs($time - time()) > 30) {
        return ['50102', 'Timestamp request expired.'];
    }
    $expected = base64_encode(hash_hmac(
        'sha256',
        $timestamp . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ($_SERVER['REQUEST_URI'] ?? '') . $body,
        getenv('MOCK_API_SECRET') ?: 'mock-secret',
        true
    ));

    return hash_equals($expected, $sign) ? null : ['50113', 'Invalid Sign.'];
}

/**
 * Consume one simulated failure of a target.
 */
function okm_take_failure(string $target): bool
{
    return okm_with_state(static function (array &$state) use ($target): bool {
        if (($state['fail'][$target] ?? 0) > 0) {
            $state['fail'][$target]--;
            return true;
        }
        return false;
    });
}

/**
 * Current block: one block per second since the last reset.
 */
function okm_block(array $state): int
{
    return $state['start_block'] + max(0, time() - $state['started_at']);
}

function okm_block_param(mixed $value, int $latest): int
{
    $value = (string)$value;
    if (in_array($value, ['latest', 'pending', 'safe', 'finalized'], true)) {
        return $latest;
    }
    if ($value === 'earliest') {
        return 0;
    }

    return (int)hexdec(substr($value, 0, 2) === '0x' ? substr($value, 2) : $value);
}

function okm_topic(string $address): string
{
    return '0x' . str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
}

function okm_to_units(string $amount): string
{
    if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
        return '0';
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

    return ltrim($whole . str_pad(substr($fraction, 0, 6), 6, '0'), '0') ?: '0';
}

function okm_dec_to_hex(string $decimal): string
{
    $hex = '';
    while ($decimal !== '0' && $decimal !== '') {
        $remainder = 0;
        $quotient = '';
        foreach (str_split($decimal) as $digit) {
            $value = $remainder * 10 + (int)$digit;
            $quotient .= (string)intdiv($value, 16);
            $remainder = $value % 16;
        }
        $hex = dechex($remainder) . $hex;
        $decimal = ltrim($quotient, '0');
    }

    return $hex !== '' ? $hex : '0';
}

/**
 * Run $callback on the state under an exclusive file lock and save what it changed.
 */
function okm_with_state(callable $callback): mixed
{
    $handle = fopen(okm_state_dir() . '/state.json', 'c+');
    flock($handle, LOCK_EX);
    $raw = (string)stream_get_contents($handle);
    $state = json_decode($raw, true) ?: okm_empty_state();

    $result = $callback($state);

    $after = (string)json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($after !== $raw) {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $after);
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

function okm_empty_state(): array
{
    return [
        'start_block' => (int)(getenv('MOCK_START_BLOCK') ?: 40000000),
        'started_at' => time(),
        'seq' => 0,
        'deposits' => [],
        'transfers' => [],
        'fail' => [],
    ];
}

function okm_state_dir(): string
{
    $dir = getenv('MOCK_STATE_DIR') ?: sys_get_temp_dir() . '/okx-mock';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    return $dir;
}

function okm_json(int $httpCode, mixed $data): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}
