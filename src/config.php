<?php
// CONFIGURACIÓN GENERAL DEL BACKEND

// Carga mínima de .env (sin dependencias). Las variables ya definidas en el entorno tienen prioridad.
(function (): void {
    $ruta = __DIR__ . '/../.env';
    if (!is_readable($ruta)) {
        return;
    }
    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) {
            continue;
        }
        [$clave, $valor] = explode('=', $linea, 2);
        $clave = trim($clave);
        $valor = trim($valor);
        if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && substr($valor, -1) === $valor[0]) {
            $valor = substr($valor, 1, -1);
        }
        if ($clave !== '' && getenv($clave) === false) {
            putenv("$clave=$valor");
            $_ENV[$clave] = $valor;
        }
    }
})();

function env(string $clave, mixed $porDefecto = null): mixed
{
    $valor = getenv($clave);
    return ($valor === false || $valor === '') ? $porDefecto : $valor;
}

function envBool(string $clave, bool $porDefecto = false): bool
{
    $valor = env($clave);
    if ($valor === null) {
        return $porDefecto;
    }
    return in_array(strtolower((string) $valor), ['1', 'true', 'yes', 'si', 'on'], true);
}

// BASE DE DATOS (TiDB Cloud)
define('DB_HOST', (string) env('DB_HOST', 'localhost'));
define('DB_PORT', (string) env('DB_PORT', '4000'));
define('DB_NAME', (string) env('DB_NAME', 'asqr'));
define('DB_USER', (string) env('DB_USER', 'sPykEGDX5ufBpNk.root'));
define('DB_PASS', (string) env('DB_PASS', 'ILTyrcU780YPa3FK'));
define('DB_SSL_CA', (string) env('DB_SSL_CA', __DIR__ . '/../certs/isrgrootx1.pem'));

// DEPURACIÓN: en producción debe ser false (no filtra detalles internos al cliente).
define('APP_DEBUG', envBool('APP_DEBUG', false));

// SEGURIDAD
// Clave para firmar tokens de sesión y los QR institucionales (HMAC). Mínimo 32 caracteres.
// Generar una con:  php -r "echo bin2hex(random_bytes(32));"
define('APP_SECRET', (string) env('APP_SECRET', 'ad305e36898e233fe4a117170a064debd9f27f993b0fcfb331aee456d91f04cc'));

// Orígenes del frontend permitidos por CORS (separados por coma). Ej: https://frontend-asqr.onrender.com
define('FRONTEND_URL', (string) env('FRONTEND_URL', 'https://frontend-asqr.onrender.com'));

define('SESION_DURACION_SEGUNDOS', (int) env('SESION_DURACION_SEGUNDOS', 8 * 60 * 60));
define('PIN_RECUPERACION_MINUTOS', (int) env('PIN_RECUPERACION_MINUTOS', 5));
define('LOGIN_INTENTOS_MAXIMOS', (int) env('LOGIN_INTENTOS_MAXIMOS', 3));
define('LOGIN_BLOQUEO_MINUTOS', (int) env('LOGIN_BLOQUEO_MINUTOS', 15));

// Minutos de tolerancia desde el inicio de la clase antes de marcar "Tardanza".
define('ASISTENCIA_TOLERANCIA_MINUTOS', (int) env('ASISTENCIA_TOLERANCIA_MINUTOS', 10));
// Si es true, solo se registra asistencia de estudiantes el día y dentro de la franja de la clase.
// Ponlo en false mientras pruebas fuera del horario real.
define('ASISTENCIA_VALIDAR_HORARIO', envBool('ASISTENCIA_VALIDAR_HORARIO', true));

// CORREO (SMTP)
define('SMTP_HOST', (string) env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int) env('SMTP_PORT', 587));
define('SMTP_USER', (string) env('SMTP_USER', 'j60717124@gmail.com'));
define('SMTP_PASS', (string) env('SMTP_PASS', 'vtdz fsrt wgwo rngb'));
define('SMTP_FROM_EMAIL', (string) env('SMTP_FROM_EMAIL', (string) env('SMTP_USER', 'no-reply@localhost')));
define('SMTP_FROM_NOMBRE', (string) env('SMTP_FROM_NOMBRE', 'Sistema de Asistencia QR'));
define('SMTP_HABILITADO', envBool('SMTP_HABILITADO', SMTP_USER !== '' && SMTP_PASS !== ''));