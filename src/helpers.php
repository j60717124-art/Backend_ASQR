<?php
// Funciones auxiliares del backend: respuestas HTTP, validaciones, sesión, QR y correo.

require_once __DIR__ . '/libs/PHPMailer/Exception.php';
require_once __DIR__ . '/libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/libs/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// EXCEPCIONES PROPIAS
// Token de sesión ausente, inválido o expirado (HTTP 401).
class SesionInvalidaException extends Exception {}

// El usuario autenticado no tiene permiso para la acción (HTTP 403).
class AccesoDenegadoException extends Exception {}

// Petición mal formada (HTTP 400).
class PeticionInvalidaException extends Exception {}

// RESPUESTAS Y PETICIONES HTTP

function jsonResponse(mixed $data, int $status = 200): void
{
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit();
}

// Cuerpo de la petición como array. Acepta JSON (aunque el Content-Type venga mal) o form-data.
// Si el JSON enviado es inválido responde 400 en lugar de procesar un cuerpo vacío en silencio.
function getRequestBody(): array
{
  $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
  $raw = file_get_contents('php://input');

  if ($raw !== false && $raw !== '' && (stripos($contentType, 'application/json') !== false || $raw[0] === '{' || $raw[0] === '[')) {
    $data = json_decode($raw, true);
    if (!is_array($data)) {
      throw new PeticionInvalidaException('El cuerpo de la petición no es un JSON válido.');
    }
    return $data;
  }

  return $_POST;
}

// FECHA Y HORA DE BOLIVIA

function getFechaHoraBolivia(): string
{
  return (new DateTime('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d H:i:s');
}

function getFechaBolivia(): string
{
  return (new DateTime('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
}

function getHoraBolivia(): string
{
  return (new DateTime('now', new DateTimeZone('America/La_Paz')))->format('H:i:s');
}

// Día de la semana actual en Bolivia
function getDiaSemanaBolivia(): string
{
  $dias = [1 => 'LUNES', 2 => 'MARTES', 3 => 'MIERCOLES', 4 => 'JUEVES', 5 => 'VIERNES', 6 => 'SABADO', 7 => 'DOMINGO'];
  $n = (int) (new DateTime('now', new DateTimeZone('America/La_Paz')))->format('N');
  return $dias[$n];
}

// VALIDACIONES REUTILIZABLES — TEXTO Y PERSONAS

function limpiarTexto(mixed $valor): string
{
  if ($valor === null) {
    return '';
  }
  if (is_array($valor) || is_object($valor)) {
    throw new Exception('Se recibió un dato con formato no válido.');
  }
  return trim((string) $valor);
}

function validarTelefono(mixed $telefono, bool $obligatorio = true): ?string
{
  $telefono = limpiarTexto($telefono);

  if ($telefono === '') {
    if ($obligatorio) {
      throw new Exception('El campo de número telefónico es obligatorio.');
    }
    return null;
  }

  $telefono = preg_replace('/[\s\-\(\)]/', '', $telefono);
  $telefono = preg_replace('/^\+?591/', '', $telefono);

  if (!preg_match('/^[67]\d{7}$/', $telefono)) {
    throw new Exception('El teléfono no es válido. Debe tener 8 dígitos y empezar con 6 o 7.');
  }

  return $telefono;
}

function validarCI(mixed $ci, bool $obligatorio = true): ?string
{
  $ci = strtoupper(limpiarTexto($ci));

  if ($ci === '') {
    if ($obligatorio) {
      throw new Exception('El CI (carné de identidad) es obligatorio.');
    }
    return null;
  }

  if (!preg_match('/^\d{5,10}(-?[A-Z]{2})?$/', $ci)) {
    throw new Exception('El CI no tiene un formato válido. Ejemplo: 1234567 o 1234567-SC.');
  }

  return $ci;
}

function validarNombrePersona(mixed $nombre, string $campo = 'El nombre completo', bool $obligatorio = true): ?string
{
  $nombre = limpiarTexto($nombre);

  if ($nombre === '') {
    if ($obligatorio) {
      throw new Exception("$campo es obligatorio.");
    }
    return null;
  }

  if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 150) {
    throw new Exception("$campo debe tener entre 2 y 150 caracteres.");
  }

  if (!preg_match('/^[\p{L}\s\.\'\-]+$/u', $nombre)) {
    throw new Exception("$campo solo puede contener letras y espacios.");
  }

  return $nombre;
}

function validarTextoObligatorio(mixed $valor, string $campo, int $min = 2, int $max = 150): string
{
  $valor = limpiarTexto($valor);

  if ($valor === '') {
    throw new Exception("$campo es obligatorio.");
  }

  if (mb_strlen($valor) < $min || mb_strlen($valor) > $max) {
    throw new Exception("$campo debe tener entre $min y $max caracteres.");
  }

  return $valor;
}

function validarNombreUsuario(mixed $usuario): string
{
  $usuario = limpiarTexto($usuario);

  if ($usuario === '') {
    throw new Exception('El nombre de usuario es obligatorio.');
  }

  if (mb_strlen($usuario) < 4 || mb_strlen($usuario) > 50) {
    throw new Exception('El nombre de usuario debe tener entre 4 y 50 caracteres.');
  }

  if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $usuario)) {
    throw new Exception('El nombre de usuario solo puede contener letras, números, puntos, guiones y guion bajo, sin espacios.');
  }

  return $usuario;
}

// Contraseña: mínimo 6 caracteres, con al menos una letra y un número. Máximo 72 (límite real de bcrypt).
function validarClave(mixed $clave, int $minimo = 6): string
{
  if (is_array($clave) || is_object($clave)) {
    throw new Exception('La contraseña no es válida.');
  }
  $clave = (string) $clave;

  if (trim($clave) === '') {
    throw new Exception('La contraseña es obligatoria.');
  }

  if (strlen($clave) < $minimo) {
    throw new Exception("La contraseña debe tener al menos $minimo caracteres.");
  }

  if (strlen($clave) > 72) {
    throw new Exception('La contraseña no puede superar los 72 caracteres.');
  }

  if (!preg_match('/[A-Za-z]/', $clave) || !preg_match('/\d/', $clave)) {
    throw new Exception('La contraseña debe incluir al menos una letra y un número.');
  }

  return $clave;
}

function validarCorreo(mixed $correo, bool $obligatorio = true): ?string
{
  $correo = strtolower(limpiarTexto($correo));

  if ($correo === '') {
    if ($obligatorio) {
      throw new Exception('El correo electrónico es obligatorio.');
    }
    return null;
  }

  if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    throw new Exception('El correo electrónico no tiene un formato válido.');
  }

  if (mb_strlen($correo) > 80) {
    throw new Exception('El correo electrónico es demasiado largo (máximo 80 caracteres).');
  }

  return $correo;
}

// PIN de recuperación (6 dígitos)
function validarPin(mixed $pin): string
{
  $pin = limpiarTexto($pin);

  if (!preg_match('/^\d{6}$/', $pin)) {
    throw new Exception('El código de verificación debe tener 6 dígitos.');
  }

  return $pin;
}

// VALIDACIONES REUTILIZABLES — NÚMEROS, FECHAS Y ENUMERADOS

function validarIdPositivo(mixed $valor, string $campo = 'El identificador'): int
{
  if (is_array($valor) || !is_numeric($valor) || (int) $valor != $valor || (int) $valor <= 0) {
    throw new Exception("$campo no es válido.");
  }
  return (int) $valor;
}

function validarEnteroRango(mixed $valor, string $campo, int $min, int $max): int
{
  if (is_array($valor) || !is_numeric($valor) || (int) $valor != $valor) {
    throw new Exception("$campo debe ser un número entero.");
  }
  $valor = (int) $valor;
  if ($valor < $min || $valor > $max) {
    throw new Exception("$campo debe estar entre $min y $max.");
  }
  return $valor;
}

// El valor debe estar en la lista permitida (insensible a mayúsculas); devuelve el valor canónico.
function validarEnum(mixed $valor, array $permitidos, string $campo): string
{
  $valor = limpiarTexto($valor);

  if ($valor === '') {
    throw new Exception("$campo es obligatorio.");
  }

  foreach ($permitidos as $opcion) {
    if (mb_strtolower($opcion) === mb_strtolower($valor)) {
      return $opcion;
    }
  }

  throw new Exception("$campo debe ser uno de los siguientes valores: " . implode(', ', $permitidos) . '.');
}

// Fecha en formato Y-m-d (estricto: rechaza fechas imposibles como 2026-02-31).
function validarFecha(mixed $fecha, string $campo = 'La fecha'): string
{
  $fecha = limpiarTexto($fecha);

  $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
  $errores = DateTime::getLastErrors();
  if ($dt === false || ($errores && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
    throw new Exception("$campo no es válida. Usa el formato AAAA-MM-DD.");
  }

  return $dt->format('Y-m-d');
}

function validarRangoFechas(string $fechaInicio, string $fechaFin, string $campoInicio = 'La fecha de inicio', string $campoFin = 'La fecha de fin'): void
{
  if (strtotime($fechaFin) < strtotime($fechaInicio)) {
    throw new Exception("$campoFin no puede ser anterior a $campoInicio.");
  }
}

// Hora "H:i" o "H:i:s" normalizada a "H:i:s".
function validarHora(mixed $hora, string $campo = 'La hora'): string
{
  $hora = limpiarTexto($hora);

  if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $hora, $m)) {
    throw new Exception("$campo no tiene un formato válido. Usa HH:MM (ejemplo: 08:00).");
  }

  return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[4] ?? 0));
}

function validarRangoHoras(string $horaInicio, string $horaFin): void
{
  if (strtotime($horaFin) <= strtotime($horaInicio)) {
    throw new Exception('La hora de fin debe ser posterior a la hora de inicio.');
  }
}

function validarDiaSemana(mixed $dia): string
{
  $dia = limpiarTexto($dia);
  // Acepta "Miércoles" / "sábado" con tildes
  $dia = strtr($dia, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
  return validarEnum($dia, ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO'], 'El día');
}

function validarNombreParalelo(mixed $valor): string
{
  $valor = strtoupper(limpiarTexto($valor));

  if (!preg_match('/^[A-Z0-9]$/', $valor)) {
    throw new Exception('El nombre del paralelo debe ser un único carácter (ejemplo: A, B, 1).');
  }

  return $valor;
}

// VALIDACIONES REUTILIZABLES — GEOLOCALIZACIÓN

function validarLatitud(mixed $valor, string $campo = 'La latitud'): float
{
  if (is_array($valor) || !is_numeric($valor)) {
    throw new Exception("$campo debe ser un número.");
  }
  $valor = (float) $valor;
  if ($valor < -90 || $valor > 90) {
    throw new Exception("$campo debe estar entre -90 y 90.");
  }
  return $valor;
}

function validarLongitud(mixed $valor, string $campo = 'La longitud'): float
{
  if (is_array($valor) || !is_numeric($valor)) {
    throw new Exception("$campo debe ser un número.");
  }
  $valor = (float) $valor;
  if ($valor < -180 || $valor > 180) {
    throw new Exception("$campo debe estar entre -180 y 180.");
  }
  return $valor;
}

// Distancia en metros entre dos coordenadas (Haversine). Se usa para la geocerca del docente.
function distanciaMetros(float $lat1, float $lon1, float $lat2, float $lon2): float
{
  $radioTierra = 6371000;
  $dLat = deg2rad($lat2 - $lat1);
  $dLon = deg2rad($lon2 - $lon1);
  $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
  return $radioTierra * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// AUTENTICACIÓN POR TOKEN (sin estado, firmado con HMAC-SHA256)

function crearSesionToken(string $rol, int $idcuenta): string
{
  $payload = [
    'idcuenta' => $idcuenta,
    'rol'      => $rol,
    'exp'      => time() + SESION_DURACION_SEGUNDOS,
  ];

  $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
  $firma = hash_hmac('sha256', $payloadB64, APP_SECRET);

  return $payloadB64 . '.' . $firma;
}

// El token solo se acepta por cabecera (X-API-Key o Authorization: Bearer). No por URL: las URL quedan en logs e historiales.
function obtenerTokenDesdeRequest(): ?string
{
  if (!empty($_SERVER['HTTP_X_API_KEY'])) {
    return trim($_SERVER['HTTP_X_API_KEY']);
  }

  if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $valor = trim($_SERVER['HTTP_AUTHORIZATION']);
    if (stripos($valor, 'Bearer ') === 0) {
      return trim(substr($valor, 7));
    }
    return $valor;
  }

  return null;
}

// Valida firma y expiración del token y que la cuenta siga activa.
function obtenerSesionActual(): array
{
  $token = obtenerTokenDesdeRequest();

  if (!$token) {
    throw new SesionInvalidaException('No se envió un token de sesión. Inicia sesión nuevamente.');
  }

  $partes = explode('.', $token);
  if (count($partes) !== 2) {
    throw new SesionInvalidaException('El token de sesión no es válido.');
  }

  [$payloadB64, $firmaRecibida] = $partes;
  $firmaEsperada = hash_hmac('sha256', $payloadB64, APP_SECRET);

  if (!hash_equals($firmaEsperada, $firmaRecibida)) {
    throw new SesionInvalidaException('El token de sesión no es válido.');
  }

  $payload = json_decode((string) base64_decode(strtr($payloadB64, '-_', '+/')), true);

  if (!is_array($payload) || empty($payload['exp']) || empty($payload['idcuenta'])) {
    throw new SesionInvalidaException('El token de sesión no es válido.');
  }

  if (time() > (int) $payload['exp']) {
    throw new SesionInvalidaException('Tu sesión expiró. Vuelve a iniciar sesión.');
  }

  $stmt = getDB()->prepare(
    'SELECT c.estado, r.nombre_rol FROM cuenta c INNER JOIN rol r ON r.idrol = c.rol_idrol WHERE c.idcuenta = ? LIMIT 1'
  );
  $idcuenta = (int) $payload['idcuenta'];
  $stmt->bind_param('i', $idcuenta);
  $stmt->execute();
  $cuenta = $stmt->get_result()->fetch_assoc();

  if (!$cuenta || $cuenta['estado'] !== 'Activo') {
    throw new SesionInvalidaException('Tu cuenta ya no está activa. Contacta a Dirección.');
  }

  return ['idcuenta' => $idcuenta, 'rol' => $cuenta['nombre_rol'], 'exp' => (int) $payload['exp']];
}

// Exige sesión válida y, opcionalmente, que el rol esté entre los permitidos.
function requireAuth(string ...$rolesPermitidos): array
{
  $sesion = obtenerSesionActual();

  if (!empty($rolesPermitidos)) {
    $rolActual = mb_strtolower($sesion['rol']);
    foreach ($rolesPermitidos as $rol) {
      if (mb_strtolower($rol) === $rolActual) {
        return $sesion;
      }
    }
    throw new AccesoDenegadoException('No tienes permiso para realizar esta acción.');
  }

  return $sesion;
}

// CÓDIGOS QR Y PIN

function generarCodigoQrUnico(): string
{
  return bin2hex(random_bytes(16));
}

function generarPin(int $digitos = 6): string
{
  return str_pad((string) random_int(0, (10 ** $digitos) - 1), $digitos, '0', STR_PAD_LEFT);
}

// La firma liga código y expiración: no se puede falsificar ni alargar la vigencia de un QR.
function generarFirmaQR(string $codigo, string $fechaExpiracion): string
{
  return hash_hmac('sha256', $codigo . '|' . $fechaExpiracion, APP_SECRET);
}

function verificarFirmaQR(string $codigo, string $fechaExpiracion, string $firma): bool
{
  return hash_equals(generarFirmaQR($codigo, $fechaExpiracion), $firma);
}

// ENVÍO DE CORREO ELECTRÓNICO

// Envía por SMTP (PHPMailer). Si SMTP no está habilitado o falla, intenta mail() como respaldo.
function enviarCorreo(string $destinatario, string $asunto, string $cuerpoHtml, string $cuerpoTexto = ''): bool
{
  if ($cuerpoTexto === '') {
    $cuerpoTexto = trim(strip_tags($cuerpoHtml));
  }

  if (SMTP_HABILITADO) {
    try {
      $mail = new PHPMailer(true);
      $mail->isSMTP();
      $mail->Host = SMTP_HOST;
      $mail->SMTPAuth = true;
      $mail->Username = SMTP_USER;
      $mail->Password = SMTP_PASS;
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port = SMTP_PORT;
      $mail->Timeout = 10; // no dejar la petición colgada si el servidor SMTP no responde
      $mail->CharSet = 'UTF-8';
      $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NOMBRE);
      $mail->addAddress($destinatario);
      $mail->isHTML(true);
      $mail->Subject = $asunto;
      $mail->Body = $cuerpoHtml;
      $mail->AltBody = $cuerpoTexto;
      $mail->send();
      return true;
    } catch (PHPMailerException $e) {
      error_log('⚠️ Error enviando correo por SMTP: ' . $e->getMessage());
    }
  }

  $headers = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type: text/html; charset=UTF-8\r\n";
  $headers .= 'From: ' . SMTP_FROM_NOMBRE . ' <' . SMTP_FROM_EMAIL . ">\r\n";

  return @mail($destinatario, $asunto, $cuerpoHtml, $headers);
}

// Arma y envía el correo con el PIN de recuperación de contraseña.
function enviarCorreoPinRecuperacion(string $destinatario, string $pin, int $minutosExpiracion): bool
{
  $asunto = 'Código de recuperación de contraseña - Sistema de Asistencia QR';
  $pinSeguro = htmlspecialchars($pin, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $minutosSeguros = max(1, (int) $minutosExpiracion);

  $cuerpoHtml = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title>Recuperación de contraseña</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f6fb; font-family:Arial, Helvetica, sans-serif; color:#17233c;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background-color:#f3f6fb;">
    <tr>
      <td align="center" style="padding:32px 12px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%; max-width:600px; background-color:#ffffff; border:1px solid #e0e8f2; border-radius:18px; overflow:hidden;">
          <tr>
            <td style="padding:28px 32px 22px; border-bottom:1px solid #e7edf5;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td width="64" valign="middle">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="56" height="56" style="width:56px; height:56px; background-color:#1768ed; border-radius:14px;">
                      <tr><td align="center" valign="middle" style="color:#ffffff; font-size:23px; font-weight:bold;">A</td></tr>
                    </table>
                  </td>
                  <td valign="middle" style="padding-left:12px;">
                    <div style="font-size:19px; line-height:26px; font-weight:bold; color:#17233c;">Sistema Académico</div>
                    <div style="font-size:14px; line-height:21px; color:#526581;">Sistema de Asistencia QR</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:30px 32px 34px;">
              <div style="display:inline-block; padding:8px 13px; background-color:#e1f5f8; border-radius:9px; color:#009bb8; font-size:11px; line-height:15px; font-weight:bold; letter-spacing:2px;">SEGURIDAD DE TU CUENTA</div>
              <h1 style="margin:20px 0 12px; color:#111d36; font-size:29px; line-height:36px; font-weight:700;">Recuperación de contraseña</h1>
              <p style="margin:0 0 14px; color:#405371; font-size:16px; line-height:25px;">Recibimos una solicitud para restablecer la contraseña de tu cuenta.</p>
              <p style="margin:0 0 20px; color:#405371; font-size:15px; line-height:24px;">Introduce este código de verificación en la pantalla de recuperación:</p>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; max-width:480px;">
                <tr>
                  <td align="center" style="padding:18px 8px; background-color:#f3f8ff; border:1px solid #bfd5ff; border-radius:12px; color:#1768ed; font-size:39px; line-height:48px; font-weight:bold; letter-spacing:9px;">$pinSeguro</td>
                </tr>
              </table>
              <p style="margin:17px 0 0; color:#405371; font-size:14px; line-height:22px;">Este código vence en <strong style="color:#17233c;">$minutosSeguros minutos</strong>.</p>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:26px;">
                <tr><td style="border-top:1px solid #e2eaf5; font-size:1px; line-height:1px;">&nbsp;</td></tr>
              </table>
              <p style="margin:20px 0 0; color:#405371; font-size:14px; line-height:23px;">Si no solicitaste este cambio, ignora este correo. Tu contraseña seguirá siendo la misma.</p>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:20px 24px 24px; background-color:#fbfcfe; border-top:1px solid #e7edf5;">
              <p style="margin:0 0 6px; color:#526581; font-size:13px; line-height:20px;">&copy; 2026 Sistema de Asistencia QR</p>
              <p style="margin:0; color:#71819a; font-size:12px; line-height:18px;">Mensaje automático. Por favor, no respondas a este correo.</p>
            </td>
          </tr>
        </table>
        <p style="margin:16px 8px 0; color:#8290a5; font-size:11px; line-height:17px; text-align:center;">Por seguridad, no compartas este código con otras personas.</p>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

  $cuerpoTexto = "SISTEMA DE ASISTENCIA QR\n\n" .
    "Recuperación de contraseña\n\n" .
    "Tu código de verificación es: {$pin}\n" .
    "Este código vence en {$minutosSeguros} minutos.\n\n" .
    "Si no solicitaste este cambio, ignora este correo.\n" .
    "Por seguridad, no compartas este código con otras personas.";

  return enviarCorreo($destinatario, $asunto, $cuerpoHtml, $cuerpoTexto);
}