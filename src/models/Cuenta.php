<?php

class Cuenta
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  // CRUD BÁSICO

  public function getAll(): array
  {
    $sql = "
      SELECT c.idcuenta, c.n_usuario, c.correo, c.estado, c.fecha_de_agregacion,
              c.rol_idrol, r.nombre_rol AS rol_nombre
      FROM cuenta c
      INNER JOIN rol r ON r.idrol = c.rol_idrol
      ORDER BY c.idcuenta DESC
    ";
    $result = $this->db->query($sql);
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $sql = "
      SELECT c.idcuenta, c.n_usuario, c.correo, c.estado, c.fecha_de_agregacion,
              c.rol_idrol, r.nombre_rol AS rol_nombre
      FROM cuenta c
      INNER JOIN rol r ON r.idrol = c.rol_idrol
      WHERE c.idcuenta = ?
      LIMIT 1
    ";
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idcuenta FROM cuenta WHERE idcuenta = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  private function usuarioExiste(string $usuario, ?int $idExcluir = null): bool
  {
    if ($idExcluir === null) {
      $stmt = $this->db->prepare("SELECT idcuenta FROM cuenta WHERE n_usuario = ? LIMIT 1");
      $stmt->bind_param("s", $usuario);
    } else {
      $stmt = $this->db->prepare("SELECT idcuenta FROM cuenta WHERE n_usuario = ? AND idcuenta != ? LIMIT 1");
      $stmt->bind_param("si", $usuario, $idExcluir);
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  private function correoExiste(string $correo, ?int $idExcluir = null): bool
  {
    if ($idExcluir === null) {
      $stmt = $this->db->prepare("SELECT idcuenta FROM cuenta WHERE correo = ? LIMIT 1");
      $stmt->bind_param("s", $correo);
    } else {
      $stmt = $this->db->prepare("SELECT idcuenta FROM cuenta WHERE correo = ? AND idcuenta != ? LIMIT 1");
      $stmt->bind_param("si", $correo, $idExcluir);
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  // Crea una nueva cuenta
  public function create(array $data): int
  {
    $usuario = validarNombreUsuario($data['n_usuario'] ?? '');
    $correo = validarCorreo($data['correo'] ?? '', true);
    $clave = validarClave($data['clave'] ?? '');
    $estado = validarEnum($data['estado'] ?? 'Activo', ['Activo', 'Inactivo'], "El estado de la cuenta");
    $rolId = validarIdPositivo($data['rol_idrol'] ?? null, "El rol");

    if ($this->usuarioExiste($usuario)) {
      throw new Exception("El nombre de usuario ya existe.");
    }
    if ($this->correoExiste($correo)) {
      throw new Exception("Ya existe una cuenta registrada con ese correo.");
    }
    if (!(new Rol())->existeId($rolId)) {
      throw new Exception("El rol seleccionado no existe.");
    }

    // Nunca se guarda la contraseña en texto plano
    $claveHash = password_hash($clave, PASSWORD_DEFAULT);

    $stmt = $this->db->prepare(
      "INSERT INTO cuenta (n_usuario, correo, clave, estado, rol_idrol) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssssi", $usuario, $correo, $claveHash, $estado, $rolId);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    if (!$this->existeId($id)) {
      throw new Exception("La cuenta no existe.");
    }

    $campos = [];
    $tipos = "";
    $valores = [];

    if (isset($data["n_usuario"])) {
      $usuario = validarNombreUsuario($data["n_usuario"]);
      if ($this->usuarioExiste($usuario, $id)) {
        throw new Exception("El nombre de usuario ya existe.");
      }
      $campos[] = "n_usuario = ?";
      $tipos .= "s";
      $valores[] = $usuario;
    }

    if (isset($data["correo"])) {
      $correo = validarCorreo($data["correo"], true);
      if ($this->correoExiste($correo, $id)) {
        throw new Exception("Ya existe una cuenta registrada con ese correo.");
      }
      $campos[] = "correo = ?";
      $tipos .= "s";
      $valores[] = $correo;
    }

    if (isset($data["estado"])) {
      $campos[] = "estado = ?";
      $tipos .= "s";
      $valores[] = validarEnum($data["estado"], ['Activo', 'Inactivo'], "El estado de la cuenta");
    }

    if (isset($data["rol_idrol"])) {
      $rolId = validarIdPositivo($data["rol_idrol"], "El rol");
      if (!(new Rol())->existeId($rolId)) {
          throw new Exception("El rol seleccionado no existe.");
      }
      $campos[] = "rol_idrol = ?";
      $tipos .= "i";
      $valores[] = $rolId;
    }

    if (isset($data["clave"]) && trim((string) $data["clave"]) !== "") {
      $clave = validarClave($data["clave"]);
      $campos[] = "clave = ?";
      $tipos .= "s";
      $valores[] = password_hash($clave, PASSWORD_DEFAULT);
    }

    if (empty($campos)) {
      throw new Exception("No hay datos para actualizar.");
    }

    $sql = "UPDATE cuenta SET " . implode(", ", $campos) . " WHERE idcuenta = ?";
    $tipos .= "i";
    $valores[] = $id;
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param($tipos, ...$valores);
    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }
    return true;
  }

  public function delete(int $id): bool
  {
    $stmt = $this->db->prepare("DELETE FROM cuenta WHERE idcuenta = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: la cuenta tiene un docente asociado u otros registros.");
    }
    return true;
  }

  // LOGIN Y SEGURIDAD

  /**
   * Verifica credenciales de inicio de sesión.
   * Aplica bloqueo temporal tras varios intentos fallidos (LOGIN_INTENTOS_MAXIMOS / LOGIN_BLOQUEO_MINUTOS).
   * Devuelve los datos de la cuenta si son correctos.
   * Lanza Exception con un mensaje apto para mostrar al usuario en cualquier otro caso (credenciales inválidas, cuenta inactiva o bloqueada).
   */
  public function iniciarSesion(string $usuarioOCorreo, string $clave): array
  {
    $usuarioOCorreo = limpiarTexto($usuarioOCorreo);

    $sql = "
      SELECT c.idcuenta, c.n_usuario, c.correo, c.clave, c.estado, c.rol_idrol,
              c.intentos_fallidos, c.bloqueado_hasta, r.nombre_rol
      FROM cuenta c
      INNER JOIN rol r ON r.idrol = c.rol_idrol
      WHERE (c.n_usuario = ? OR c.correo = ?)
      LIMIT 1
    ";
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param("ss", $usuarioOCorreo, $usuarioOCorreo);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();

    if (!$fila) {
      // Mensaje genérico: no revelamos si el usuario existe o no
      throw new Exception("Usuario/correo o contraseña incorrectos.");
    }

    if ($fila['estado'] !== 'Activo') {
      throw new Exception("Esta cuenta está inactiva. Contacta a Dirección.");
    }

    // ¿La cuenta está bloqueada todavía?
    if (!empty($fila['bloqueado_hasta']) && strtotime($fila['bloqueado_hasta']) > time()) {
      $minutosRestantes = (int) ceil((strtotime($fila['bloqueado_hasta']) - time()) / 60);
      throw new Exception("Cuenta bloqueada temporalmente por varios intentos fallidos. Intenta de nuevo en $minutosRestantes minuto(s).");
    }

    $claveValida = password_verify($clave, $fila['clave']);

    if (!$claveValida) {
      $this->registrarIntentoFallido((int) $fila['idcuenta'], (int) $fila['intentos_fallidos']);
      throw new Exception("Usuario/correo o contraseña incorrectos.");
    }

    // Login correcto: reiniciar contador de intentos fallidos
    $this->reiniciarIntentosFallidos((int) $fila['idcuenta']);

    unset($fila['clave'], $fila['intentos_fallidos'], $fila['bloqueado_hasta']);
    return $fila;
  }

  private function registrarIntentoFallido(int $idcuenta, int $intentosActuales): void
  {
    $intentos = $intentosActuales + 1;

    if ($intentos >= LOGIN_INTENTOS_MAXIMOS) {
      $bloqueadoHasta = date('Y-m-d H:i:s', strtotime('+' . LOGIN_BLOQUEO_MINUTOS . ' minutes'));
      $stmt = $this->db->prepare("UPDATE cuenta SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE idcuenta = ?");
      $stmt->bind_param("isi", $intentos, $bloqueadoHasta, $idcuenta);
    } else {
      $stmt = $this->db->prepare("UPDATE cuenta SET intentos_fallidos = ? WHERE idcuenta = ?");
      $stmt->bind_param("ii", $intentos, $idcuenta);
    }
    $stmt->execute();
  }

  private function reiniciarIntentosFallidos(int $idcuenta): void
  {
      $stmt = $this->db->prepare("UPDATE cuenta SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE idcuenta = ?");
      $stmt->bind_param("i", $idcuenta);
      $stmt->execute();
  }

  // RECUPERACIÓN DE CONTRASEÑA (PIN por correo, expira en 5 minutos)

  /**
   * Genera un PIN de 6 dígitos, lo guarda con expiración de PIN_RECUPERACION_MINUTOS (5 min) y lo envía por correo. Por seguridad, siempre responde con éxito aunque el correo no exista.
   */
  public function solicitarPinRecuperacion(string $correo): void
  {
    $correo = validarCorreo($correo, true);

    $stmt = $this->db->prepare("SELECT idcuenta FROM cuenta WHERE correo = ? AND estado = 'Activo' LIMIT 1");
    $stmt->bind_param("s", $correo);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();

    if (!$fila) {
      // No revelamos si el correo existe o no: simplemente no se envía nada.
      return;
    }

    $pin = generarPin(6);
    $expiracion = date('Y-m-d H:i:s', strtotime('+' . PIN_RECUPERACION_MINUTOS . ' minutes'));

    $upd = $this->db->prepare("UPDATE cuenta SET pin_recuperacion = ?, pin_expiracion = ? WHERE idcuenta = ?");
    $upd->bind_param("ssi", $pin, $expiracion, $fila['idcuenta']);
    if (!$upd->execute()) {
      throw new Exception($upd->error);
    }

    $enviado = enviarCorreoPinRecuperacion($correo, $pin, PIN_RECUPERACION_MINUTOS);
    if (!$enviado) {
      // El PIN ya quedó guardado; informamos igual para no bloquear al usuario,
      // pero dejamos constancia en el log del servidor.
      error_log("⚠️ No se pudo enviar el correo de recuperación a $correo");
    }
  }

  /**
   * Verifica el PIN (vigente dentro de los 5 minutos) y cambia la contraseña.
   */
  public function verificarPinYCambiarClave(string $correo, string $pin, string $nuevaClave): void
  {
    $correo = validarCorreo($correo, true);
    $pin = validarPin($pin);
    $nuevaClave = validarClave($nuevaClave);

    $ahora = date('Y-m-d H:i:s');

    $sql = "
      SELECT idcuenta FROM cuenta
      WHERE correo = ? AND pin_recuperacion = ? AND pin_expiracion IS NOT NULL AND pin_expiracion > ?
      LIMIT 1
    ";
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param("sss", $correo, $pin, $ahora);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();

    if (!$fila) {
      throw new Exception("El código de verificación es incorrecto o ya expiró. Solicita uno nuevo.");
    }

    $idcuenta = (int) $fila['idcuenta'];
    $hash = password_hash($nuevaClave, PASSWORD_DEFAULT);

    // Cambia la clave, limpia el PIN (no se puede reusar) y el bloqueo/intentos
    $upd = $this->db->prepare("
      UPDATE cuenta
      SET clave = ?, pin_recuperacion = NULL, pin_expiracion = NULL,
          intentos_fallidos = 0, bloqueado_hasta = NULL
      WHERE idcuenta = ?
    ");
    $upd->bind_param("si", $hash, $idcuenta);
    if (!$upd->execute()) {
      throw new Exception($upd->error);
    }
  }
}