<?php
// Funciones auxiliares del backend.

// ENVIAR RESPUESTA JSON
function jsonResponse(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// OBTENER CUERPO DE LA PETICIÓN
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

// VALIDACIONES REUTILIZABLES

// VALIDAR TELÉFONO BOLIVIA
function validarTelefono(mixed $telefono, bool $obligatorio = true): ?string
{
  $telefono = limpiarTexto($telefono);

  if ($telefono === "") {
    if ($obligatorio) {
      throw new Exception("El campo de numero teléfonico es obligatorio.");
    }
    return null;
  }

  $telefono = preg_replace('/[\s\-\(\)]/', '', $telefono);
  $telefono = preg_replace('/^\+?591/', '', $telefono);

  if (!preg_match('/^[67]\d{7}$/', $telefono)) {
    throw new Exception(
      "El teléfono no es válido. Debe tener 8 dígitos y empezar con 6 o 7."
    );
  }

  return $telefono;
}

// VALIDAR CI
function validarCI(mixed $ci, bool $obligatorio = true): ?string
{
  $ci = strtoupper(limpiarTexto($ci));

  if ($ci === "") {
    if ($obligatorio) {
      throw new Exception("El CI(carne de identidad) es obligatorio.");
    }
    return null;
  }

  if (!preg_match('/^\d{5,10}(-?[A-Z]{2})?$/', $ci)) {
    throw new Exception("El CI no tiene un formato válido. Ejemplo: 1234567 o 1234567-SC.");
  }

  return $ci;
}

// VALIDAR NOMBRE DE PERSONA
function validarNombrePersona(mixed $nombre, string $campo = "El nombre completo", bool $obligatorio = true): ?string
{
  $nombre = limpiarTexto($nombre);

  if ($nombre === "") {
    if ($obligatorio) {
      throw new Exception("$campo es obligatorio.");
    }
    return null;
  }

  if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 150) {
    throw new Exception("$campo debe tener entre 2 y 30 caracteres.");
  }

  if (!preg_match('/^[\p{L}\s\.\'\-]+$/u', $nombre)) {
    throw new Exception("$campo solo puede contener letras y espacios.");
  }

  return $nombre;
}

// VALIDAR TEXTO OBLIGATORIO GENÉRICO
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

// VALIDAR NOMBRE DE USUARIO
function validarNombreUsuario(mixed $usuario): string
{
  $usuario = limpiarTexto($usuario);

  if ($usuario === "") {
    throw new Exception("El nombre de usuario es obligatorio.");
  }

  if (mb_strlen($usuario) < 4 || mb_strlen($usuario) > 50) {
    throw new Exception("El nombre de usuario debe tener entre 4 y 50 caracteres.");
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

// VALIDAR FECHA
function validarFecha(mixed $fecha, string $campo = "La fecha"): string
{
  $fecha = limpiarTexto($fecha);

  if ($fecha === "" || strtotime($fecha) === false) {
    throw new Exception("$campo no es válida.");
  }

  return $fecha;
}

// LIMPIAR TEXTO
function limpiarTexto(mixed $valor): string
{
  if ($valor === null) {
    return "";
  }

  return trim((string) $valor);
}

// CORREO ELECTRÓNICO
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

  if (mb_strlen($correo) > 150) {
    throw new Exception("El correo electrónico es demasiado largo.");
  }

  return $correo;
}