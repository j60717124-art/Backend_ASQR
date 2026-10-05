<?php

require_once __DIR__ . '/libs/PHPMailer/Exception.php';
require_once __DIR__ . '/libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/libs/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// EXCEPCIONES PROPIAS

// Se lanza cuando el token de sesión falta, es inválido o expiró.
class SesionInvalidaException extends Exception {}

// Se lanza cuando el usuario autenticado no tiene permiso para la acción.
class AccesoDenegadoException extends Exception {}

// RESPUESTAS Y PETICIONES HTTP

// ENVIAR RESPUESTA JSON
function jsonResponse(mixed $data, int $status = 200): void
{
  http_response_code($status);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit();
}

// OBTENER CUERPO DE LA PETICIÓN (JSON o form-urlencoded)
function getRequestBody(): array
{
  $contentType = $_SERVER["CONTENT_TYPE"] ?? "";

  if (stripos($contentType, "application/json") !== false) {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  }
  return $_POST;
}

// FECHAS Y HORAS (zona horaria de Bolivia)

// OBTENER FECHA Y HORA DE BOLIVIA
function getFechaHoraBolivia(): string
{
  $timezone = new DateTimeZone("America/La_Paz");
  $fecha = new DateTime("now", $timezone);
  return $fecha->format("Y-m-d H:i:s");
}

// OBTENER SOLO LA FECHA (Y-m-d) DE BOLIVIA
function getFechaBolivia(): string
{
  $timezone = new DateTimeZone("America/La_Paz");
  $fecha = new DateTime("now", $timezone);
  return $fecha->format("Y-m-d");
}

// OBTENER SOLO LA HORA (H:i:s) DE BOLIVIA
function getHoraBolivia(): string
{
  $timezone = new DateTimeZone("America/La_Paz");
  $fecha = new DateTime("now", $timezone);
  return $fecha->format("H:i:s");
}

// VALIDACIONES REUTILIZABLES — TEXTO Y DATOS PERSONALES

// LIMPIAR TEXTO
function limpiarTexto(mixed $valor): string
{
  if ($valor === null) {
    return "";
  }
  return trim((string) $valor);
}

// VALIDAR TELÉFONO BOLIVIA (acepta celular [6-7] y fijo [2-4], 7 u 8 dígitos)
function validarTelefono(mixed $telefono, bool $obligatorio = true): ?string
{
  $telefono = limpiarTexto($telefono);

  if ($telefono === "") {
    if ($obligatorio) {
      throw new Exception("El campo de número telefónico es obligatorio.");
    }
    return null;
  }

  $telefono = preg_replace('/[\s\-\(\)]/', '', $telefono);
  $telefono = preg_replace('/^\+?591/', '', $telefono);

  if (!preg_match('/^[2-7]\d{6,7}$/', $telefono)) {
    throw new Exception("El teléfono no es válido. Debe tener 7 u 8 dígitos (celular: empieza en 6 o 7; fijo: empieza en 2, 3 o 4).");
  }

  return $telefono;
}

// VALIDAR CI (carnet de identidad)
function validarCI(mixed $ci, bool $obligatorio = true): ?string
{
  $ci = strtoupper(limpiarTexto($ci));

  if ($ci === "") {
    if ($obligatorio) {
      throw new Exception("El CI (carnet de identidad) es obligatorio.");
    }
    return null;
  }

  if (!preg_match('/^\d{5,10}(-?[A-Z]{2})?$/', $ci)) {
    throw new Exception("El CI no tiene un formato válido. Ejemplo: 1234567 o 1234567-SC.");
  }

  return $ci;
}

// VALIDAR NOMBRE DE PERSONA (nombres o apellidos)
function validarNombrePersona(mixed $nombre, string $campo = "El nombre completo", bool $obligatorio = true): ?string
{
  $nombre = limpiarTexto($nombre);

  if ($nombre === "") {
    if ($obligatorio) {
      throw new Exception("$campo es obligatorio.");
    }
    return null;
  }

  if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 70) {
    throw new Exception("$campo debe tener entre 2 y 70 caracteres.");
  }

  if (!preg_match('/^[\p{L}\s\.\'\-]+$/u', $nombre)) {
    throw new Exception("$campo solo puede contener letras y espacios.");
  }

  return $nombre;
}

// VALIDAR TEXTO OBLIGATORIO GENÉRICO (nombres de materia, aula, colegio, curso, etc.)
function validarTextoObligatorio(mixed $valor, string $campo, int $min = 2, int $max = 150): string
{
  $valor = limpiarTexto($valor);

  if ($valor === "") {
    throw new Exception("$campo es obligatorio.");
  }

  if (mb_strlen($valor) < $min || mb_strlen($valor) > $max) {
    throw new Exception("$campo debe tener entre $min y $max caracteres.");
  }

  return $valor;
}

// VALIDAR TEXTO OPCIONAL (descripciones). Devuelve null si viene vacío.
function validarTextoOpcional(mixed $valor, string $campo, int $max = 255): ?string
{
  $valor = limpiarTexto($valor);

  if ($valor === "") {
    return null;
  }

  if (mb_strlen($valor) > $max) {
    throw new Exception("$campo no puede superar los $max caracteres.");
  }

  return $valor;
}

// VALIDAR NOMBRE DE USUARIO (campo n_usuario de la tabla cuenta)
function validarNombreUsuario(mixed $usuario): string
{
  $usuario = limpiarTexto($usuario);

  if ($usuario === "") {
    throw new Exception("El nombre de usuario es obligatorio.");
  }

  if (mb_strlen($usuario) < 4 || mb_strlen($usuario) > 60) {
    throw new Exception("El nombre de usuario debe tener entre 4 y 60 caracteres.");
  }

  if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $usuario)) {
    throw new Exception("El nombre de usuario solo puede contener letras, números, puntos, guiones y guion bajo, sin espacios.");
  }

  return $usuario;
}

// VALIDAR CONTRASEÑA
function validarClave(mixed $clave, int $minimo = 6): string
{
  $clave = (string) $clave;

  if (trim($clave) === "") {
    throw new Exception("La contraseña es obligatoria.");
  }

  if (strlen($clave) < $minimo) {
    throw new Exception("La contraseña debe tener al menos $minimo caracteres.");
  }

  return $clave;
}

// VALIDAR CORREO ELECTRÓNICO
function validarCorreo(mixed $correo, bool $obligatorio = true): ?string
{
  $correo = strtolower(limpiarTexto($correo));

  if ($correo === "") {
    if ($obligatorio) {
      throw new Exception("El correo electrónico es obligatorio.");
    }
    return null;
  }

  if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    throw new Exception("El correo electrónico no tiene un formato válido.");
  }

  if (mb_strlen($correo) > 80) {
    throw new Exception("El correo electrónico es demasiado largo (máximo 80 caracteres).");
  }

  return $correo;
}

// VALIDAR PIN DE RECUPERACIÓN (6 dígitos)
function validarPin(mixed $pin): string
{
  $pin = limpiarTexto($pin);

  if ($pin === "" || !preg_match('/^\d{6}$/', $pin)) {
    throw new Exception("El código de verificación debe tener 6 dígitos.");
  }

  return $pin;
}

// VALIDACIONES REUTILIZABLES — NÚMEROS, FECHAS Y ENUMERADOS

// VALIDAR ENTERO POSITIVO (ids de relaciones foráneas, cantidades, etc.)
function validarIdPositivo(mixed $valor, string $campo = "El identificador"): int
{
  if (!is_numeric($valor) || (int) $valor <= 0) {
    throw new Exception("$campo no es válido.");
  }
  return (int) $valor;
}

// VALIDAR ENTERO DENTRO DE UN RANGO (radio en metros, segundos de rotación, etc.)
function validarEnteroRango(mixed $valor, string $campo, int $min, int $max): int
{
  if (!is_numeric($valor) || (int) $valor != $valor) {
    throw new Exception("$campo debe ser un número entero.");
  }
  $valor = (int) $valor;
  if ($valor < $min || $valor > $max) {
    throw new Exception("$campo debe estar entre $min y $max.");
  }
  return $valor;
}

// VALIDAR QUE UN VALOR ESTÉ DENTRO DE UNA LISTA PERMITIDA (estados, roles, enums en general)
function validarEnum(mixed $valor, array $permitidos, string $campo): string
{
  $valor = limpiarTexto($valor);

  if ($valor === "") {
    throw new Exception("$campo es obligatorio.");
  }

  // Comparación insensible a mayúsculas/minúsculas, pero se devuelve el valor "canónico"
  foreach ($permitidos as $opcion) {
    if (mb_strtolower($opcion) === mb_strtolower($valor)) {
      return $opcion;
    }
  }

  throw new Exception("$campo debe ser uno de los siguientes valores: " . implode(', ', $permitidos) . ".");
}

// VALIDAR FECHA (formato Y-m-d o cualquiera que entienda strtotime)
function validarFecha(mixed $fecha, string $campo = "La fecha"): string
{
  $fecha = limpiarTexto($fecha);

  if ($fecha === "" || strtotime($fecha) === false) {
    throw new Exception("$campo no es válida.");
  }

  return date('Y-m-d', strtotime($fecha));
}

// VALIDAR QUE UNA FECHA FINAL SEA POSTERIOR (o igual) A UNA FECHA INICIAL
function validarRangoFechas(string $fechaInicio, string $fechaFin, string $campoInicio = "La fecha de inicio", string $campoFin = "La fecha de fin"): void
{
  if (strtotime($fechaFin) < strtotime($fechaInicio)) {
    throw new Exception("$campoFin no puede ser anterior a $campoInicio.");
  }
}

// VALIDAR HORA (acepta "H:i" o "H:i:s") y la normaliza a "H:i:s"
function validarHora(mixed $hora, string $campo = "La hora"): string
{
  $hora = limpiarTexto($hora);

  if ($hora === "" || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $hora, $m)) {
    throw new Exception("$campo no tiene un formato válido. Usa HH:MM (ejemplo: 08:00).");
  }

  $segundos = $m[4] ?? '00';
  return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) $segundos);
}

// VALIDAR QUE LA HORA DE FIN SEA POSTERIOR A LA HORA DE INICIO
function validarRangoHoras(string $horaInicio, string $horaFin): void
{
  if (strtotime($horaFin) <= strtotime($horaInicio)) {
    throw new Exception("La hora de fin debe ser posterior a la hora de inicio.");
  }
}

// VALIDAR DÍA DE LA SEMANA (para horarios de clase)
function validarDiaSemana(mixed $dia): string
{
  return validarEnum($dia, ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO'], 'El día');
}

// VALIDAR NOMBRE DE PARALELO (una sola letra o dígito: A, B, 1, 2...)
function validarNombreParalelo(mixed $valor): string
{
    $valor = strtoupper(limpiarTexto($valor));

    if (!preg_match('/^[A-Z0-9]$/', $valor)) {
        throw new Exception("El nombre del paralelo debe ser un único carácter (ejemplo: A, B, 1).");
    }

    return $valor;
}

// VALIDACIONES REUTILIZABLES — GEOLOCALIZACIÓN

// VALIDAR LATITUD (-90 a 90)
function validarLatitud(mixed $valor, string $campo = "La latitud"): float
{
  if (!is_numeric($valor)) {
    throw new Exception("$campo debe ser un número.");
  }
  $valor = (float) $valor;
  if ($valor < -90 || $valor > 90) {
    throw new Exception("$campo debe estar entre -90 y 90.");
  }
  return $valor;
}

// VALIDAR LONGITUD (-180 a 180)
function validarLongitud(mixed $valor, string $campo = "La longitud"): float
{
  if (!is_numeric($valor)) {
    throw new Exception("$campo debe ser un número.");
  }
  $valor = (float) $valor;
  if ($valor < -180 || $valor > 180) {
    throw new Exception("$campo debe estar entre -180 y 180.");
  }
  return $valor;
}

// CALCULAR LA DISTANCIA EN METROS ENTRE DOS COORDENADAS (fórmula de Haversine)
// Se usa para validar que el docente esté dentro del radio permitido (geocerca).
function distanciaMetros(float $lat1, float $lon1, float $lat2, float $lon2): float
{
  $radioTierra = 6371000; // metros
  $dLat = deg2rad($lat2 - $lat1);
  $dLon = deg2rad($lon2 - $lon1);
  $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
  $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

  return $radioTierra * $c;
}

// AUTENTICACIÓN POR TOKEN (sesión sin estado, firmada con HMAC)

// Construye y firma un token de sesión que incluye idcuenta, rol y expiración.
function crearSesionToken(string $rol, int $idcuenta): string
{
  $payload = [
    'idcuenta' => $idcuenta,
    'rol'      => $rol,
    'exp'      => time() + SESION_DURACION_SEGUNDOS,
  ];

  $payloadJson = json_encode($payload);
  $payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
  $firma = hash_hmac('sha256', $payloadB64, APP_SECRET);

  return $payloadB64 . '.' . $firma;
}

// Extrae el token de sesión de los headers de la petición (X-API-Key o Authorization: Bearer).
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

  if (!empty($_GET['token'])) {
    return trim($_GET['token']);
  }

  return null;
}

// Valida el token de sesión actual y devuelve su contenido (idcuenta, rol, exp). Lanza SesionInvalidaException si no hay token, está mal formado, la firma nocoincide o ya expiró.
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

  $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'));
  $payload = json_decode($payloadJson, true);

  if (!is_array($payload) || empty($payload['exp']) || empty($payload['idcuenta'])) {
    throw new SesionInvalidaException('El token de sesión no es válido.');
  }

  if (time() > (int) $payload['exp']) {
    throw new SesionInvalidaException('Tu sesión expiró. Vuelve a iniciar sesión.');
  }

  return $payload;
}

// Exige que exista una sesión válida y, opcionalmente, que el rol pertenezca a la lista de roles permitidos.
function requireAuth(string ...$rolesPermitidos): array
{
  $sesion = obtenerSesionActual();

  if (!empty($rolesPermitidos)) {
    $rolActual = $sesion['rol'] ?? '';
    $permitido = false;
    foreach ($rolesPermitidos as $rol) {
      if (mb_strtolower($rol) === mb_strtolower($rolActual)) {
        $permitido = true;
        break;
      }
    }
    if (!$permitido) {
      throw new AccesoDenegadoException('No tienes permiso para realizar esta acción.');
    }
  }

  return $sesion;
}

// CÓDIGOS QR (institucional dinámico y del carnet del estudiante)

// Genera un identificador aleatorio único para usar como "codigo" de un QR.
function generarCodigoQrUnico(): string
{
  return bin2hex(random_bytes(16));
}

// Genera un PIN numérico de N dígitos (por defecto 6) para recuperación de contraseña.
function generarPin(int $digitos = 6): string
{
  $max = (10 ** $digitos) - 1;
  return str_pad((string) random_int(0, $max), $digitos, '0', STR_PAD_LEFT);
}

// Firma un código QR institucional junto con su fecha de expiración, para que no pueda ser falsificado ni reutilizado fuera de su ventana de validez.
function generarFirmaQR(string $codigo, string $fechaExpiracion): string
{
  return hash_hmac('sha256', $codigo . '|' . $fechaExpiracion, APP_SECRET);
}

// Verifica que la firma de un QR institucional sea auténtica.
function verificarFirmaQR(string $codigo, string $fechaExpiracion, string $firma): bool
{
  $firmaEsperada = generarFirmaQR($codigo, $fechaExpiracion);
  return hash_equals($firmaEsperada, $firma);
}

// ENVÍO DE CORREO ELECTRÓNICO

// Envía un correo usando SMTP (PHPMailer).
function enviarCorreo(string $destinatario, string $asunto, string $cuerpoHtml, string $cuerpoTexto = ''): bool
{
  if ($cuerpoTexto === '') {
      $cuerpoTexto = trim(strip_tags($cuerpoHtml));
  }

  if (defined('SMTP_HABILITADO') && SMTP_HABILITADO) {
    try {
      $mail = new PHPMailer(true);
      $mail->isSMTP();
      $mail->Host = SMTP_HOST;
      $mail->SMTPAuth = true;
      $mail->Username = SMTP_USER;
      $mail->Password = SMTP_PASS;
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port = SMTP_PORT;
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
        // Continúa hacia el respaldo con mail()
    }
  }

  // Respaldo: función nativa mail() (requiere un MTA configurado en el servidor)
  $headers = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type: text/html; charset=UTF-8\r\n";
  $headers .= "From: " . SMTP_FROM_NOMBRE . " <" . SMTP_FROM_EMAIL . ">\r\n";

  return @mail($destinatario, $asunto, $cuerpoHtml, $headers);
}

// Arma y envía el correo con el PIN de recuperación de contraseña.
function enviarCorreoPinRecuperacion(string $destinatario, string $pin, int $minutosExpiracion): bool
{
  $asunto = 'Código de recuperación de contraseña - Sistema de Asistencia QR';
  $cuerpoHtml = "
    <div style='font-family: Arial, sans-serif; max-width: 480px; margin: auto;'>
      <h2 style='color:#1749ff;'>Recuperación de contraseña</h2>
      <p>Recibimos una solicitud para restablecer tu contraseña.</p>
      <p>Tu código de verificación es:</p>
      <p style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color:#07122b;'>$pin</p>
      <p>Este código expira en <strong>$minutosExpiracion minutos</strong>.</p>
      <p>Si tú no solicitaste este cambio, puedes ignorar este correo.</p>
      <hr>
      <p style='font-size:12px;color:#667f9e;'>Sistema de Asistencia QR</p>
    </div>
  ";
  return enviarCorreo($destinatario, $asunto, $cuerpoHtml);
}