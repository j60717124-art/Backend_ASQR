<?php
// Conexión única (por petición) a la base de datos.

function getDB(): mysqli
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $db = mysqli_init();
        $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

        $flags = 0;
        if (is_readable(DB_SSL_CA)) {
            // TiDB Cloud exige TLS: se valida el certificado del servidor con la CA indicada.
            $db->ssl_set(null, null, DB_SSL_CA, null, null);
            $flags = MYSQLI_CLIENT_SSL;
        } elseif (!APP_DEBUG) {
            error_log('⚠️ DB_SSL_CA no encontrado: la conexión a la base de datos podría no estar cifrada.');
        }

        $db->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT, null, $flags);
        $db->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
        error_log('❌ Error de conexión a la base de datos: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Servicio no disponible. Inténtalo de nuevo en unos minutos.']);
        exit;
    }

    return $db;
}