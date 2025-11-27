<?php
/**
 * Hedging Endpoint (metals_numia)
 */

header('Content-Type: application/json');
set_time_limit(30);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 0);

const KG_TO_OUNCE = 32.1507466; // kg -> troy ounce
const MIN_QUOTE_DATE = '2022-08-17 07:59:41'; // najstarszy dostępny kurs metalu w bazie
const MAX_HISTORY_DAYS = 7;
const LOG_FILE = __DIR__ . '/hedging-endpoint.log';
const TOKENS_FILE_PATH = __DIR__ . '/../data/config/tokens.php';
const TOKEN_COMMENT_KEY = 'hedging endpoint';
const DB_CONFIG_PATH = __DIR__ . '/../data/config/hedging-db.php';

function loadDbConfig()
{
    $envHost = getenv('HEDGING_DB_HOST');
    $envName = getenv('HEDGING_DB_NAME');
    $envUser = getenv('HEDGING_DB_USER');
    $envPassword = getenv('HEDGING_DB_PASSWORD');
    $envCharset = getenv('HEDGING_DB_CHARSET');

    if ($envHost && $envName && $envUser && $envPassword) {
        return [
            'host' => $envHost,
            'dbname' => $envName,
            'username' => $envUser,
            'password' => $envPassword,
            'charset' => $envCharset ? $envCharset : 'utf8mb4',
        ];
    }

    if (file_exists(DB_CONFIG_PATH)) {
        $fileConfig = include DB_CONFIG_PATH;
        if (is_array($fileConfig)) {
            $fileConfig['charset'] = isset($fileConfig['charset']) && $fileConfig['charset'] ? $fileConfig['charset'] : 'utf8mb4';

            if (!empty($fileConfig['host']) && !empty($fileConfig['dbname']) && !empty($fileConfig['username']) && !empty($fileConfig['password'])) {
                return $fileConfig;
            }
        }
    }

    sendError('Server misconfiguration: database credentials not set', 500);
}

function loadApiToken()
{
    $envToken = getenv('HEDGING_ENDPOINT_TOKEN');
    if (!empty($envToken)) {
        return $envToken;
    }

    if (file_exists(TOKENS_FILE_PATH)) {
        $tokens = include TOKENS_FILE_PATH;
        if (is_array($tokens)) {
            foreach ($tokens as $token => $meta) {
                $comment = isset($meta['comment']) ? strtolower(trim($meta['comment'])) : '';
                $isActive = isset($meta['active']) ? (bool)$meta['active'] : false;
                if ($isActive && $comment === strtolower(TOKEN_COMMENT_KEY)) {
                    return $token;
                }
            }
        }
    }

    return 'CHANGE_ME_TO_SECURE_TOKEN';
}

$dbConfig = loadDbConfig();
define('API_TOKEN', loadApiToken());

/**
 * Append entry to the local log file.
 *
 * @param string $message
 * @return void
 */
function logEvent($message)
{
    $line = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

function sanitizePayloadEncoding(array &$payload)
{
    array_walk_recursive($payload, static function (&$value) {
        if (!is_string($value)) {
            return;
        }

        if (function_exists('mb_convert_encoding')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            return;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    });
}

/**
 * Emit JSON error response and stop execution.
 *
 * @param string $message
 * @param int    $code
 * @return void
 */
function sendError($message, $code = 400)
{
    logEvent("ERROR code=$code message=" . $message);
    http_response_code($code);
    echo json_encode([
        'status' => 'error',
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

/**
 * Validate Authorization: Bearer header against API token.
 *
 * @return void
 */
function requireBearerToken()
{
    if (empty(API_TOKEN) || API_TOKEN === 'CHANGE_ME_TO_SECURE_TOKEN') {
        sendError('Server misconfiguration: API token not set', 500);
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = '';
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $authHeader = $value;
            break;
        }
    }

    if (!$authHeader && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!$authHeader && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (stripos($authHeader, 'Bearer ') !== 0) {
        sendError('Unauthorized', 401);
    }

    $providedToken = trim(substr($authHeader, 7));

    if (!hash_equals(API_TOKEN, $providedToken)) {
        sendError('Unauthorized', 401);
    }
}

try {
    requireBearerToken();
    $requestStarted = microtime(true);
    $lastImportedId = isset($_GET['last_imported_id']) ? (int)$_GET['last_imported_id'] : 0;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $limit = min(max($limit, 1), 200);

    $cutoff = (new DateTimeImmutable(sprintf('-%d days', MAX_HISTORY_DAYS)))->format('Y-m-d H:i:s');
    $effectiveStartDate = strtotime($cutoff) > strtotime(MIN_QUOTE_DATE) ? $cutoff : MIN_QUOTE_DATE;

    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Zamówienia
    $ordersStmt = $pdo->prepare("
        SELECT id_order, id_customer, id_currency, date_add, conversion_rate
        FROM km_orders
        WHERE id_order > :last_imported_id
          AND date_add >= :min_quote_date
        ORDER BY id_order ASC
        LIMIT :limit
    ");
    $ordersStmt->bindValue(':last_imported_id', $lastImportedId, PDO::PARAM_INT);
    $ordersStmt->bindValue(':min_quote_date', $effectiveStartDate);
    $ordersStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $ordersStmt->execute();
    $orders = $ordersStmt->fetchAll();

    if (!$orders) {
        echo json_encode([
            'status' => 'success',
            'data' => [],
            'meta' => [
                'total' => 0,
                'last_id' => $lastImportedId,
                'date_from' => $effectiveStartDate,
                'has_more' => false,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ], JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
        exit;
    }

    $orderIds = array_map('intval', array_column($orders, 'id_order'));
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    // Produkty + ostatnia wycena metalu
    $detailsSql = "
        SELECT
            od.id_order,
            od.product_id,
            od.product_name,
            od.product_quantity,
            od.product_weight,
            ROUND(od.product_weight * od.product_quantity, 4) AS total_weight_kg,
            od.product_weight * od.product_quantity * " . KG_TO_OUNCE . " AS total_weight_oz_precise,
            ROUND(od.product_weight * od.product_quantity * " . KG_TO_OUNCE . ", 0) AS total_weight_oz,
            p.symbol,
            (
                SELECT qv.quote
                FROM km_priceupdater_quote_value qv
                INNER JOIN km_priceupdater_quote q ON q.id_quote = qv.id_quote
                WHERE qv.symbol = p.symbol
                  AND q.date_add <= o.date_add
                  AND q.date_add >= DATE_SUB(o.date_add, INTERVAL 1 DAY)
                ORDER BY q.date_add DESC
                LIMIT 1
            ) AS metal_rate
        FROM km_order_detail od
        INNER JOIN km_orders o ON o.id_order = od.id_order
        INNER JOIN km_priceupdater_product p ON p.id_product = od.product_id AND p.active = 1
        WHERE od.id_order IN ($placeholders)
        ORDER BY od.id_order, od.id_order_detail
    ";

    $detailsStmt = $pdo->prepare($detailsSql);
    $detailsStmt->execute($orderIds);
    $details = $detailsStmt->fetchAll();

    $detailsByOrder = [];
    foreach ($details as $detail) {
        $orderId = (int)$detail['id_order'];
        $detailsByOrder[$orderId][] = [
            'product_id' => (int)$detail['product_id'],
            'product_name' => $detail['product_name'],
            'product_quantity' => (int)$detail['product_quantity'],
            'product_weight_kg' => (float)$detail['product_weight'],
            'total_weight_kg' => (float)$detail['total_weight_kg'],
            'total_weight_oz_precise' => (float)$detail['total_weight_oz_precise'],
            'total_weight_oz' => (float)$detail['total_weight_oz'],
            'symbol' => $detail['symbol'],
            'metal_rate' => $detail['metal_rate'] !== null ? (float)$detail['metal_rate'] : null
        ];
    }

    foreach ($orders as &$order) {
        $orderId = (int)$order['id_order'];
        $order['products'] = isset($detailsByOrder[$orderId]) ? $detailsByOrder[$orderId] : [];
    }
    unset($order);

    $lastId = end($orderIds);

    $durationMs = (microtime(true) - $requestStarted) * 1000;
    logEvent(sprintf('OK last_id=%d limit=%d returned=%d duration_ms=%.2f', $lastImportedId, $limit, count($orders), $durationMs));

    $payload = [
        'status' => 'success',
        'data' => $orders,
        'meta' => [
            'total' => count($orders),
            'last_id' => $lastId,
            'date_from' => $effectiveStartDate,
            'has_more' => (count($orders) === $limit),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];

    sanitizePayloadEncoding($payload);

    $jsonOptions = JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonOptions |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($payload, $jsonOptions);
    if ($json === false) {
        logEvent('ENCODE_ERROR code=' . json_last_error() . ' msg=' . json_last_error_msg());
        sendError('JSON encode failed', 500);
    }

    echo $json;

} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}
?>
