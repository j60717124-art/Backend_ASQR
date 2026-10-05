<?php
class HorarioEstudiante
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getById(int $id): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT idhorario_estudiante, horario_idhorario, estudiante_idestudiante
        FROM horario_estudiante WHERE idhorario_estudiante = ? LIMIT 1"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function existeId(int $id): bool
  {
    $stmt = $this->db->prepare("SELECT idhorario_estudiante FROM horario_estudiante WHERE idhorario_estudiante = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
  }

  // Lista de estudiantes inscritos en un horario (para pasar lista / ver el curso)
  public function getPorHorario(int $idHorario): array
  {
    $stmt = $this->db->prepare(
      "SELECT he.idhorario_estudiante, he.estudiante_idestudiante,
              e.nombres, e.apellidos, e.ci
        FROM horario_estudiante he
        INNER JOIN estudiante e ON e.idestudiante = he.estudiante_idestudiante
        WHERE he.horario_idhorario = ?
        ORDER BY e.apellidos, e.nombres"
    );
    $stmt->bind_param("i", $idHorario);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  // Horarios en los que está inscrito un estudiante
  public function getPorEstudiante(int $idEstudiante): array
  {
    $stmt = $this->db->prepare(
      "SELECT he.idhorario_estudiante, he.horario_idhorario,
              h.dia, h.hora_inicio, h.hora_fin, m.nombre_materia
        FROM horario_estudiante he
        INNER JOIN horario h ON h.idhorario = he.horario_idhorario
        INNER JOIN materia m ON m.idmateria = h.materia_idmateria
        WHERE he.estudiante_idestudiante = ?
        ORDER BY FIELD(h.dia,'LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO'), h.hora_inicio"
    );
    $stmt->bind_param("i", $idEstudiante);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  public function getPorHorarioYEstudiante(int $idHorario, int $idEstudiante): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT idhorario_estudiante, horario_idhorario, estudiante_idestudiante
        FROM horario_estudiante WHERE horario_idhorario = ? AND estudiante_idestudiante = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $idHorario, $idEstudiante);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
  }

  public function inscribir(array $data): int
  {
    $horarioId = validarIdPositivo($data['horario_idhorario'] ?? null, 'El horario');
    $estudianteId = validarIdPositivo($data['estudiante_idestudiante'] ?? null, 'El estudiante');

    if (!(new Horario())->existeId($horarioId)) {
      throw new Exception("El horario seleccionado no existe.");
    }
    if (!(new Estudiante())->existeId($estudianteId)) {
      throw new Exception("El estudiante seleccionado no existe.");
    }
    if ($this->getPorHorarioYEstudiante($horarioId, $estudianteId)) {
      throw new Exception("El estudiante ya está inscrito en ese horario.");
    }

    $stmt = $this->db->prepare(
      "INSERT INTO horario_estudiante (horario_idhorario, estudiante_idestudiante) VALUES (?, ?)"
    );
    $stmt->bind_param("ii", $horarioId, $estudianteId);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }
    return $this->db->insert_id;
  }

  public function retirar(int $id): bool
  {
    $stmt = $this->db->prepare("DELETE FROM horario_estudiante WHERE idhorario_estudiante = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
      throw new Exception("No se puede retirar: el estudiante ya tiene asistencias registradas en ese horario.");
    }
    return true;
  }
}