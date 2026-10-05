<?php
class Materia
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $result = $this->db->query("SELECT idmateria, nombre_materia, descripcion FROM materia ORDER BY nombre_materia");
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare("SELECT idmateria, nombre_materia, descripcion FROM materia WHERE idmateria = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idmateria FROM materia WHERE idmateria = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $nombre = validarTextoObligatorio($data['nombre_materia'] ?? '', 'El nombre de la materia', 2, 150);
    $descripcion = validarTextoOpcional($data['descripcion'] ?? null, 'La descripción', 255);
    $stmt = $this->db->prepare("INSERT INTO materia (nombre_materia, descripcion) VALUES (?, ?)");
    $stmt->bind_param("ss", $nombre, $descripcion);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    if (!$this->existeId($id)) {
      throw new Exception("La materia no existe.");
    }

    $campos = [];
    $tipos = "";
    $valores = [];

    if (isset($data['nombre_materia'])) {
      $campos[] = "nombre_materia = ?";
      $tipos .= "s";
      $valores[] = validarTextoObligatorio($data['nombre_materia'], 'El nombre de la materia', 2, 150);
    }
    if (array_key_exists('descripcion', $data)) {
      $campos[] = "descripcion = ?";
      $tipos .= "s";
      $valores[] = validarTextoOpcional($data['descripcion'], 'La descripción', 255);
    }

    if (empty($campos)) {
      throw new Exception("No hay datos para actualizar.");
    }

    $sql = "UPDATE materia SET " . implode(", ", $campos) . " WHERE idmateria = ?";
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
    $stmt = $this->db->prepare("DELETE FROM materia WHERE idmateria = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: hay horarios asociados a esta materia.");
    }
    return true;
  }
}