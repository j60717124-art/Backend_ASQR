<?php
class Paralelo
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $sql = "
      SELECT p.idparalelo, p.nombre, p.curso_idcurso, c.nombre AS curso_nombre
      FROM paralelo p
      INNER JOIN curso c ON c.idcurso = p.curso_idcurso
      ORDER BY c.nombre, p.nombre
    ";
    $result = $this->db->query($sql);
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare("SELECT idparalelo, nombre, curso_idcurso FROM paralelo WHERE idparalelo = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idparalelo FROM paralelo WHERE idparalelo = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  private function combinacionExiste(int $cursoId, string $nombre, ?int $idExcluir = null): bool
  {
    if ($idExcluir === null) {
      $stmt = $this->db->prepare("SELECT idparalelo FROM paralelo WHERE curso_idcurso = ? AND nombre = ? LIMIT 1");
      $stmt->bind_param("is", $cursoId, $nombre);
    } else {
      $stmt = $this->db->prepare("SELECT idparalelo FROM paralelo WHERE curso_idcurso = ? AND nombre = ? AND idparalelo != ? LIMIT 1");
      $stmt->bind_param("isi", $cursoId, $nombre, $idExcluir);
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $nombre = validarNombreParalelo($data['nombre'] ?? '');
    $cursoId = validarIdPositivo($data['curso_idcurso'] ?? null, 'El curso');

    if (!(new Curso())->existeId($cursoId)) {
      throw new Exception("El curso seleccionado no existe.");
    }
    if ($this->combinacionExiste($cursoId, $nombre)) {
      throw new Exception("Ese curso ya tiene un paralelo con ese nombre.");
    }

    $stmt = $this->db->prepare("INSERT INTO paralelo (nombre, curso_idcurso) VALUES (?, ?)");
    $stmt->bind_param("si", $nombre, $cursoId);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    $actual = $this->getById($id);
    if (!$actual) {
      throw new Exception("El paralelo no existe.");
    }

    $cursoId = $actual['curso_idcurso'];
    $nombre = $actual['nombre'];

    if (isset($data['curso_idcurso'])) {
      $cursoId = validarIdPositivo($data['curso_idcurso'], 'El curso');
      if (!(new Curso())->existeId($cursoId)) {
        throw new Exception("El curso seleccionado no existe.");
      }
    }
    if (isset($data['nombre'])) {
      $nombre = validarNombreParalelo($data['nombre']);
    }

    if ($this->combinacionExiste($cursoId, $nombre, $id)) {
      throw new Exception("Ese curso ya tiene un paralelo con ese nombre.");
    }

    $stmt = $this->db->prepare("UPDATE paralelo SET nombre = ?, curso_idcurso = ? WHERE idparalelo = ?");
    $stmt->bind_param("sii", $nombre, $cursoId, $id);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return true;
  }

  public function delete(int $id): bool
  {
    $stmt = $this->db->prepare("DELETE FROM paralelo WHERE idparalelo = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: hay asignaciones asociadas a este paralelo.");
    }
    return true;
  }
}