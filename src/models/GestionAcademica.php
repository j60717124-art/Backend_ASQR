<?php
class GestionAcademica
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $result = $this->db->query(
      "SELECT idgestion_academica, nombre, fecha_inicio, fecha_fin
          FROM gestion_academica ORDER BY fecha_inicio DESC"
    );
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT idgestion_academica, nombre, fecha_inicio, fecha_fin
        FROM gestion_academica WHERE idgestion_academica = ? LIMIT 1"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idgestion_academica FROM gestion_academica WHERE idgestion_academica = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $nombre = validarTextoObligatorio($data['nombre'] ?? '', 'El nombre de la gestión', 3, 60);
    $inicio = validarFecha($data['fecha_inicio'] ?? '', 'La fecha de inicio');
    $fin = validarFecha($data['fecha_fin'] ?? '', 'La fecha de fin');
    validarRangoFechas($inicio, $fin);

    $stmt = $this->db->prepare(
      "INSERT INTO gestion_academica (nombre, fecha_inicio, fecha_fin) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("sss", $nombre, $inicio, $fin);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    $actual = $this->getById($id);
    if (!$actual) {
      throw new Exception("La gestión académica no existe.");
    }

    $nombre = isset($data['nombre']) ? validarTextoObligatorio($data['nombre'], 'El nombre de la gestión', 3, 60) : $actual['nombre'];
    $inicio = isset($data['fecha_inicio']) ? validarFecha($data['fecha_inicio'], 'La fecha de inicio') : $actual['fecha_inicio'];
    $fin = isset($data['fecha_fin']) ? validarFecha($data['fecha_fin'], 'La fecha de fin') : $actual['fecha_fin'];
    validarRangoFechas($inicio, $fin);

    $stmt = $this->db->prepare(
      "UPDATE gestion_academica SET nombre = ?, fecha_inicio = ?, fecha_fin = ? WHERE idgestion_academica = ?"
    );
    $stmt->bind_param("sssi", $nombre, $inicio, $fin, $id);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return true;
  }

  public function delete(int $id): bool
  {
    $stmt = $this->db->prepare("DELETE FROM gestion_academica WHERE idgestion_academica = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: hay asignaciones ligadas a esta gestión académica.");
    }
    return true;
  }
}