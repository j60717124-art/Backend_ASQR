<?php
class Aula
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $sql = "
      SELECT a.idaula, a.nombre, a.ubicacion, a.colegio_idcolegio, c.nombre AS colegio_nombre
      FROM aula a
      INNER JOIN colegio c ON c.idcolegio = a.colegio_idcolegio
      ORDER BY c.nombre, a.nombre
    ";
    $result = $this->db->query($sql);
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare("SELECT idaula, nombre, ubicacion, colegio_idcolegio FROM aula WHERE idaula = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idaula FROM aula WHERE idaula = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $nombre = validarTextoObligatorio($data['nombre'] ?? '', 'El nombre del aula', 1, 100);
    $ubicacion = validarTextoOpcional($data['ubicacion'] ?? null, 'La ubicación', 255);
    $colegioId = validarIdPositivo($data['colegio_idcolegio'] ?? null, 'El colegio');

    if (!(new Colegio())->existeId($colegioId)) {
      throw new Exception("El colegio seleccionado no existe.");
    }

    $stmt = $this->db->prepare("INSERT INTO aula (nombre, ubicacion, colegio_idcolegio) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $nombre, $ubicacion, $colegioId);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    if (!$this->existeId($id)) {
      throw new Exception("El aula no existe.");
    }

    $campos = [];
    $tipos = "";
    $valores = [];

    if (isset($data['nombre'])) {
      $campos[] = "nombre = ?";
      $tipos .= "s";
      $valores[] = validarTextoObligatorio($data['nombre'], 'El nombre del aula', 1, 100);
    }
    if (array_key_exists('ubicacion', $data)) {
      $campos[] = "ubicacion = ?";
      $tipos .= "s";
      $valores[] = validarTextoOpcional($data['ubicacion'], 'La ubicación', 255);
    }
    if (isset($data['colegio_idcolegio'])) {
      $colegioId = validarIdPositivo($data['colegio_idcolegio'], 'El colegio');
      if (!(new Colegio())->existeId($colegioId)) {
        throw new Exception("El colegio seleccionado no existe.");
      }
      $campos[] = "colegio_idcolegio = ?";
      $tipos .= "i";
      $valores[] = $colegioId;
    }

    if (empty($campos)) {
      throw new Exception("No hay datos para actualizar.");
    }

    $sql = "UPDATE aula SET " . implode(", ", $campos) . " WHERE idaula = ?";
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
    $stmt = $this->db->prepare("DELETE FROM aula WHERE idaula = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: hay horarios asociados a esta aula.");
    }
    return true;
  }
}