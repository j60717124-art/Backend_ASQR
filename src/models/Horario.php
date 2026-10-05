<?php
class Horario
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getAll(): array
  {
    $sql = "
      SELECT h.idhorario, h.dia, h.hora_inicio, h.hora_fin,
              h.docente_iddocente, h.materia_idmateria, h.aula_idaula,
              CONCAT(d.nombres, ' ', d.apellidos) AS docente_nombre,
              m.nombre_materia, a.nombre AS aula_nombre
      FROM horario h
      INNER JOIN docente d ON d.iddocente = h.docente_iddocente
      INNER JOIN materia m ON m.idmateria = h.materia_idmateria
      INNER JOIN aula a ON a.idaula = h.aula_idaula
      ORDER BY FIELD(h.dia,'LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO'), h.hora_inicio
    ";
    $result = $this->db->query($sql);
    if (!$result) {
      throw new Exception($this->db->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT idhorario, dia, hora_inicio, hora_fin, docente_iddocente, materia_idmateria, aula_idaula
        FROM horario WHERE idhorario = ? LIMIT 1"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idhorario FROM horario WHERE idhorario = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  // Devuelve los horarios vigentes de un docente para un día dado (o todos si $dia es null)
  public function getPorDocente(int $idDocente, ?string $dia = null): array
  {
    if ($dia !== null) {
      $stmt = $this->db->prepare(
        "SELECT h.idhorario, h.dia, h.hora_inicio, h.hora_fin, h.aula_idaula, h.materia_idmateria,
              m.nombre_materia, a.nombre AS aula_nombre
          FROM horario h
          INNER JOIN materia m ON m.idmateria = h.materia_idmateria
          INNER JOIN aula a ON a.idaula = h.aula_idaula
          WHERE h.docente_iddocente = ? AND h.dia = ?
          ORDER BY h.hora_inicio"
      );
      $stmt->bind_param("is", $idDocente, $dia);
    } else {
      $stmt = $this->db->prepare(
        "SELECT h.idhorario, h.dia, h.hora_inicio, h.hora_fin, h.aula_idaula, h.materia_idmateria,
              m.nombre_materia, a.nombre AS aula_nombre
          FROM horario h
          INNER JOIN materia m ON m.idmateria = h.materia_idmateria
          INNER JOIN aula a ON a.idaula = h.aula_idaula
          WHERE h.docente_iddocente = ?
          ORDER BY FIELD(h.dia,'LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO'), h.hora_inicio"
      );
      $stmt->bind_param("i", $idDocente);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  // Evita que un mismo docente o una misma aula tengan dos clases que se solapen el mismo día
  private function hayCruce(string $dia, string $horaInicio, string $horaFin, int $docenteId, int $aulaId, ?int $idExcluir = null): bool
  {
    $sql = "
      SELECT idhorario FROM horario
      WHERE dia = ?
        AND (docente_iddocente = ? OR aula_idaula = ?)
        AND hora_inicio < ?
        AND hora_fin > ?
    ";
    if ($idExcluir !== null) {
      $sql .= " AND idhorario != ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $this->db->prepare($sql);
    if ($idExcluir !== null) {
      $stmt->bind_param("siissi", $dia, $docenteId, $aulaId, $horaFin, $horaInicio, $idExcluir);
    } else {
      $stmt->bind_param("siiss", $dia, $docenteId, $aulaId, $horaFin, $horaInicio);
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  public function create(array $data): int
  {
    $dia = validarDiaSemana($data['dia'] ?? '');
    $horaInicio = validarHora($data['hora_inicio'] ?? '', 'La hora de inicio');
    $horaFin = validarHora($data['hora_fin'] ?? '', 'La hora de fin');
    validarRangoHoras($horaInicio, $horaFin);
    $docenteId = validarIdPositivo($data['docente_iddocente'] ?? null, 'El docente');
    $materiaId = validarIdPositivo($data['materia_idmateria'] ?? null, 'La materia');
    $aulaId = validarIdPositivo($data['aula_idaula'] ?? null, 'El aula');

    if (!(new Docente())->existeId($docenteId)) {
      throw new Exception("El docente seleccionado no existe.");
    }
    if (!(new Materia())->existeId($materiaId)) {
      throw new Exception("La materia seleccionada no existe.");
    }
    if (!(new Aula())->existeId($aulaId)) {
      throw new Exception("El aula seleccionada no existe.");
    }
    if ($this->hayCruce($dia, $horaInicio, $horaFin, $docenteId, $aulaId)) {
      throw new Exception("El docente o el aula ya tienen una clase que se cruza en ese horario.");
    }

    $stmt = $this->db->prepare(
      "INSERT INTO horario (dia, hora_inicio, hora_fin, docente_iddocente, materia_idmateria, aula_idaula)
        VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssiii", $dia, $horaInicio, $horaFin, $docenteId, $materiaId, $aulaId);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function update(int $id, array $data): bool
  {
    $actual = $this->getById($id);
    if (!$actual) {
      throw new Exception("El horario no existe.");
    }

    $dia = isset($data['dia']) ? validarDiaSemana($data['dia']) : $actual['dia'];
    $horaInicio = isset($data['hora_inicio']) ? validarHora($data['hora_inicio'], 'La hora de inicio') : $actual['hora_inicio'];
    $horaFin = isset($data['hora_fin']) ? validarHora($data['hora_fin'], 'La hora de fin') : $actual['hora_fin'];
    validarRangoHoras($horaInicio, $horaFin);
    $docenteId = $actual['docente_iddocente'];
    $materiaId = $actual['materia_idmateria'];
    $aulaId = $actual['aula_idaula'];

    if (isset($data['docente_iddocente'])) {
      $docenteId = validarIdPositivo($data['docente_iddocente'], 'El docente');
      if (!(new Docente())->existeId($docenteId)) {
        throw new Exception("El docente seleccionado no existe.");
      }
    }
    if (isset($data['materia_idmateria'])) {
      $materiaId = validarIdPositivo($data['materia_idmateria'], 'La materia');
      if (!(new Materia())->existeId($materiaId)) {
        throw new Exception("La materia seleccionada no existe.");
      }
    }
    if (isset($data['aula_idaula'])) {
      $aulaId = validarIdPositivo($data['aula_idaula'], 'El aula');
      if (!(new Aula())->existeId($aulaId)) {
        throw new Exception("El aula seleccionada no existe.");
      }
    }

    if ($this->hayCruce($dia, $horaInicio, $horaFin, (int) $docenteId, (int) $aulaId, $id)) {
      throw new Exception("El docente o el aula ya tienen una clase que se cruza en ese horario.");
    }

    $stmt = $this->db->prepare(
      "UPDATE horario SET dia = ?, hora_inicio = ?, hora_fin = ?, docente_iddocente = ?, materia_idmateria = ?, aula_idaula = ?
        WHERE idhorario = ?"
    );
    $stmt->bind_param("sssiiii", $dia, $horaInicio, $horaFin, $docenteId, $materiaId, $aulaId, $id);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return true;
  }

  public function delete(int $id): bool
  {
    $stmt = $this->db->prepare("DELETE FROM horario WHERE idhorario = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede eliminar: hay estudiantes inscritos o asignaciones ligadas a este horario.");
    }
    return true;
  }
}