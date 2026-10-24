<?php
class Docente
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $sql = "
      SELECT d.iddocente, d.nombres, d.apellidos, d.ci, d.telefono, d.fecha_agregacion,
            d.cuenta_idcuenta, c.n_usuario, c.correo, c.estado
      FROM docente d
      INNER JOIN cuenta c ON c.idcuenta = d.cuenta_idcuenta
      ORDER BY d.apellidos, d.nombres
    ";
    $result = $this->db->query($sql);
    if (!$result) throw new Exception($this->db->error);
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $sql = "
      SELECT d.iddocente, d.nombres, d.apellidos, d.ci, d.telefono, d.fecha_agregacion,
            d.cuenta_idcuenta, c.n_usuario, c.correo, c.estado
      FROM docente d
      INNER JOIN cuenta c ON c.idcuenta = d.cuenta_idcuenta
      WHERE d.iddocente = ?
      LIMIT 1
    ";
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT iddocente FROM docente WHERE iddocente = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function getPorCuenta(int $idCuenta): ?array
  {
    $stmt = $this->db->prepare(
        "SELECT iddocente, nombres, apellidos, ci, telefono, cuenta_idcuenta
          FROM docente WHERE cuenta_idcuenta = ? LIMIT 1"
    );
    $stmt->bind_param("i", $idCuenta);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  private function ciExiste(string $ci, ?int $idExcluir = null): bool
  {
    if ($idExcluir === null) {
      $stmt = $this->db->prepare("SELECT iddocente FROM docente WHERE ci = ? LIMIT 1");
      $stmt->bind_param("s", $ci);
    } else {
      $stmt = $this->db->prepare("SELECT iddocente FROM docente WHERE ci = ? AND iddocente != ? LIMIT 1");
      $stmt->bind_param("si", $ci, $idExcluir);
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $nombres = validarNombrePersona($data['nombres'] ?? '', 'Los nombres');
    $apellidos = validarNombrePersona($data['apellidos'] ?? '', 'Los apellidos');
    $ci = validarCI($data['ci'] ?? '');
    $telefono = validarTelefono($data['telefono'] ?? null, false);

    if ($this->ciExiste($ci)) {
      throw new Exception("Ya existe un docente registrado con ese CI.");
    }

    $this->db->begin_transaction();
    try {
      $rolDocenteId = $this->obtenerIdRolDocente();
      $clave = trim((string) ($data['clave'] ?? ''));
      if ($clave === '') {
          $clave = bin2hex(random_bytes(8)) . 'A1!';
      }

      $cuenta = new Cuenta();
      $idcuenta = $cuenta->create([
        'n_usuario' => $data['n_usuario'] ?? '',
        'correo'    => $data['correo'] ?? '',
        'clave'     => $clave,
        'estado'    => $data['estado'] ?? 'Activo',
        'rol_idrol' => $rolDocenteId,
      ]);

      $stmt = $this->db->prepare(
        "INSERT INTO docente (nombres, apellidos, ci, telefono, cuenta_idcuenta) VALUES (?, ?, ?, ?, ?)"
      );
      $telefonoStr = $telefono ?? '';
      $stmt->bind_param("ssssi", $nombres, $apellidos, $ci, $telefonoStr, $idcuenta);
      if (!$stmt->execute()) {
        throw new Exception($stmt->error);
      }
      $iddocente = $this->db->insert_id;

      $this->db->commit();
      return $iddocente;
    } catch (Exception $e) {
      $this->db->rollback();
      throw $e;
    }
  }

  private function obtenerIdRolDocente(): int
  {
    $result = $this->db->query("SELECT idrol FROM rol WHERE nombre_rol = 'Docente' LIMIT 1");
    $fila = $result ? $result->fetch_assoc() : null;
    if (!$fila) {
      throw new Exception("No se encontró el rol 'Docente'. Verifica la tabla de roles.");
    }
    return (int) $fila['idrol'];
  }

  public function update(int $id, array $data): bool
  {
    $actual = $this->getById($id);
    if (!$actual) throw new Exception("El docente no existe.");

    $campos = [];
    $tipos = "";
    $valores = [];

    if (isset($data['nombres'])) {
      $campos[] = "nombres = ?"; $tipos .= "s"; $valores[] = validarNombrePersona($data['nombres'], 'Los nombres');
    }
    if (isset($data['apellidos'])) {
      $campos[] = "apellidos = ?"; $tipos .= "s"; $valores[] = validarNombrePersona($data['apellidos'], 'Los apellidos');
    }
    if (isset($data['ci'])) {
      $ci = validarCI($data['ci']);
      if ($this->ciExiste($ci, $id)) throw new Exception("Ya existe un docente registrado con ese CI.");
      $campos[] = "ci = ?"; $tipos .= "s"; $valores[] = $ci;
    }
    if (array_key_exists('telefono', $data)) {
      $campos[] = "telefono = ?"; $tipos .= "s"; $valores[] = validarTelefono($data['telefono'], false) ?? '';
    }

    // Docente y cuenta se actualizan juntos o no se actualiza nada.
    $this->db->begin_transaction();
    try {
      if (!empty($campos)) {
        $sql = "UPDATE docente SET " . implode(", ", $campos) . " WHERE iddocente = ?";
        $tipos .= "i"; $valores[] = $id;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($tipos, ...$valores);
        if (!$stmt->execute()) throw new Exception($stmt->error);
      }

      $datosCuenta = array_intersect_key($data, array_flip(['n_usuario', 'correo', 'clave', 'estado']));
      if (!empty($datosCuenta)) {
        (new Cuenta())->update((int) $actual['cuenta_idcuenta'], $datosCuenta);
      }

      $this->db->commit();
    } catch (Throwable $e) {
      $this->db->rollback();
      throw $e;
    }

    return true;
  }

  public function delete(int $id): bool
  {
    $actual = $this->getById($id);
    if (!$actual) throw new Exception("El docente no existe.");

    // Se borran docente y cuenta en una sola transacción
    $this->db->begin_transaction();
    try {
      $stmt = $this->db->prepare("DELETE FROM docente WHERE iddocente = ?");
      $stmt->bind_param("i", $id);
      $stmt->execute();

      (new Cuenta())->delete((int) $actual['cuenta_idcuenta']);
      $this->db->commit();
    } catch (mysqli_sql_exception $e) {
      $this->db->rollback();
      if ((int) $e->getCode() === 1451) {
        throw new Exception("No se puede eliminar: el docente tiene horarios o asistencias asociadas.");
      }
      throw $e;
    } catch (Throwable $e) {
      $this->db->rollback();
      throw $e;
    }
    return true;
  }
}