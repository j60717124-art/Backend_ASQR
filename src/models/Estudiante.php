<?php
class Estudiante
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $result = $this->db->query(
        "SELECT idestudiante, nombres, apellidos, ci, fecha_agregacion, estado_estudiante
          FROM estudiante ORDER BY apellidos, nombres"
    );
    if (!$result) {
        throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT idestudiante, nombres, apellidos, ci, fecha_agregacion, estado_estudiante
        FROM estudiante WHERE idestudiante = ? LIMIT 1"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idestudiante FROM estudiante WHERE idestudiante = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  private function ciExiste(string $ci, ?int $idExcluir = null): bool
  {
    if ($idExcluir === null) {
        $stmt = $this->db->prepare("SELECT idestudiante FROM estudiante WHERE ci = ? LIMIT 1");
        $stmt->bind_param("s", $ci);
    } else {
        $stmt = $this->db->prepare("SELECT idestudiante FROM estudiante WHERE ci = ? AND idestudiante != ? LIMIT 1");
        $stmt->bind_param("si", $ci, $idExcluir);
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

   // Registra un nuevo estudiante y le genera automáticamente su QR fijo

  public function create(array $data): array
  {
    $nombres = validarNombrePersona($data['nombres'] ?? '', 'Los nombres');
    $apellidos = validarNombrePersona($data['apellidos'] ?? '', 'Los apellidos');
    $ci = validarCI($data['ci'] ?? '');
    $estado = validarEnum($data['estado_estudiante'] ?? 'Activo', ['Activo', 'Inactivo'], 'El estado del estudiante');

    if ($this->ciExiste($ci)) {
      throw new Exception("Ya existe un estudiante registrado con ese CI.");
    }

    $this->db->begin_transaction();
    try {
      $stmt = $this->db->prepare(
        "INSERT INTO estudiante (nombres, apellidos, ci, estado_estudiante) VALUES (?, ?, ?, ?)"
      );
      $stmt->bind_param("ssss", $nombres, $apellidos, $ci, $estado);
      if (!$stmt->execute()) {
        throw new Exception($stmt->error);
      }
      $idEstudiante = $this->db->insert_id;
      $qr = (new QrEstudiante())->generarNuevo($idEstudiante);
      $this->db->commit();

      return [
        'idestudiante' => $idEstudiante,
        'qr_codigo' => $qr['codigo'],
      ];
    } catch (Exception $e) {
      $this->db->rollback();
      throw $e;
    }
  }

  public function update(int $id, array $data): bool
  {
    if (!$this->existeId($id)) {
      throw new Exception("El estudiante no existe.");
    }

    $campos = [];
    $tipos = "";
    $valores = [];

    if (isset($data['nombres'])) {
      $campos[] = "nombres = ?";
      $tipos .= "s";
      $valores[] = validarNombrePersona($data['nombres'], 'Los nombres');
    }
    if (isset($data['apellidos'])) {
      $campos[] = "apellidos = ?";
      $tipos .= "s";
      $valores[] = validarNombrePersona($data['apellidos'], 'Los apellidos');
    }
    if (isset($data['ci'])) {
      $ci = validarCI($data['ci']);
      if ($this->ciExiste($ci, $id)) {
        throw new Exception("Ya existe un estudiante registrado con ese CI.");
      }
      $campos[] = "ci = ?";
      $tipos .= "s";
      $valores[] = $ci;
    }
    if (isset($data['estado_estudiante'])) {
      $campos[] = "estado_estudiante = ?";
      $tipos .= "s";
      $valores[] = validarEnum($data['estado_estudiante'], ['Activo', 'Inactivo'], 'El estado del estudiante');
    }

    if (empty($campos)) {
      throw new Exception("No hay datos para actualizar.");
    }

    $sql = "UPDATE estudiante SET " . implode(", ", $campos) . " WHERE idestudiante = ?";
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
    $stmt = $this->db->prepare("DELETE FROM estudiante WHERE idestudiante = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: el estudiante tiene inscripciones, asistencias o QR asociados.");
    }
    return true;
  }
}