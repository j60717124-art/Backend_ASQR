<?php
// Registro real de asistencia de un estudiante a una clase (fecha + hora + estado).
class AsistenciaEstudiante
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  public function getPorHorario(int $idHorario, string $fecha): array
  {
    $stmt = $this->db->prepare(
      "SELECT ae.idasistencia_estudiante, ae.fecha, ae.hora, ae.estado,
              e.idestudiante, e.nombres, e.apellidos, e.ci
        FROM asistencia_estudiante ae
        INNER JOIN horario_estudiante he ON he.idhorario_estudiante = ae.horario_estudiante_idhorario_estudiante
        INNER JOIN estudiante e ON e.idestudiante = he.estudiante_idestudiante
        WHERE he.horario_idhorario = ? AND ae.fecha = ?
        ORDER BY ae.hora"
    );
    $stmt->bind_param("is", $idHorario, $fecha);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  public function getPorEstudiante(int $idEstudiante): array
  {
    $stmt = $this->db->prepare(
      "SELECT ae.idasistencia_estudiante, ae.fecha, ae.hora, ae.estado,
              h.idhorario, h.dia, m.nombre_materia
        FROM asistencia_estudiante ae
        INNER JOIN horario_estudiante he ON he.idhorario_estudiante = ae.horario_estudiante_idhorario_estudiante
        INNER JOIN horario h ON h.idhorario = he.horario_idhorario
        INNER JOIN materia m ON m.idmateria = h.materia_idmateria
        WHERE he.estudiante_idestudiante = ?
        ORDER BY ae.fecha DESC, ae.hora DESC"
    );
    $stmt->bind_param("i", $idEstudiante);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }

    /**
     * Registra la asistencia de un estudiante a partir del código QR de su
     * carnet, para el horario (clase) que el docente tiene seleccionado.
     *
     * Validaciones aplicadas:
     *  - El código QR debe existir y estar vigente.
     *  - El estudiante debe estar inscrito en ese horario .
     *  - No se permite doble registro el mismo día para la misma clase.
     *  - Se marca "Tardanza" si pasó el margen de tolerancia configurado.
     */
    public function registrarPorQr(string $codigoQr, int $idHorario): array
    {
      $codigoQr = limpiarTexto($codigoQr);
      if ($codigoQr === '') {
        throw new Exception("El código QR escaneado está vacío.");
      }

      $qr = (new QrEstudiante())->getPorCodigo($codigoQr);
      if (!$qr || (int) $qr['vigente'] !== 1) {
        throw new Exception("El carnet escaneado no es válido o fue dado de baja.");
      }

      $horario = (new Horario())->getById($idHorario);
      if (!$horario) {
        throw new Exception("El horario/clase seleccionado no existe.");
      }

      $inscripcion = (new HorarioEstudiante())->getPorHorarioYEstudiante($idHorario, (int) $qr['estudiante_idestudiante']);
      if (!$inscripcion) {
        throw new Exception("Este estudiante no pertenece a la materia/horario seleccionado.");
      }

      $fecha = getFechaBolivia();
      $hora = getHoraBolivia();

      // RN-01: no se puede registrar dos veces la misma clase el mismo día
      $stmtCheck = $this->db->prepare(
        "SELECT idasistencia_estudiante FROM asistencia_estudiante
          WHERE horario_estudiante_idhorario_estudiante = ? AND fecha = ? LIMIT 1"
      );
      $stmtCheck->bind_param("is", $inscripcion['idhorario_estudiante'], $fecha);
      $stmtCheck->execute();
      if ($stmtCheck->get_result()->num_rows > 0) {
        throw new Exception("Este estudiante ya tiene registrada su asistencia hoy para esta clase.");
      }

      // ¿Presente o Tardanza? según la tolerancia configurada desde el inicio de la clase
      $minutosTranscurridos = (strtotime($hora) - strtotime($horario['hora_inicio'])) / 60;
      $estado = ($minutosTranscurridos > ASISTENCIA_TOLERANCIA_MINUTOS) ? 'Tardanza' : 'Presente';

      $stmt = $this->db->prepare(
        "INSERT INTO asistencia_estudiante (fecha, hora, estado, horario_estudiante_idhorario_estudiante)
          VALUES (?, ?, ?, ?)"
      );
      $stmt->bind_param("sssi", $fecha, $hora, $estado, $inscripcion['idhorario_estudiante']);
      if (!$stmt->execute()) {
        throw new Exception($stmt->error);
      }

      return [
        'idasistencia_estudiante' => $this->db->insert_id,
        'estudiante_idestudiante' => $qr['estudiante_idestudiante'],
        'fecha' => $fecha,
        'hora' => $hora,
        'estado' => $estado,
      ];
    }

  /**
   * Registro manual (de respaldo) por si el carnet está dañado o ilegible.
   * Requiere indicar directamente el estudiante en vez de escanear el QR.
   */
  public function registrarManual(int $idHorario, int $idEstudiante, string $motivo): array
  {
    $motivo = validarTextoObligatorio($motivo, 'El motivo del registro manual', 3, 150);

    $inscripcion = (new HorarioEstudiante())->getPorHorarioYEstudiante($idHorario, $idEstudiante);
    if (!$inscripcion) {
      throw new Exception("Este estudiante no pertenece a la materia/horario seleccionado.");
    }

    $horario = (new Horario())->getById($idHorario);
    $fecha = getFechaBolivia();
    $hora = getHoraBolivia();

    $stmtCheck = $this->db->prepare(
      "SELECT idasistencia_estudiante FROM asistencia_estudiante
        WHERE horario_estudiante_idhorario_estudiante = ? AND fecha = ? LIMIT 1"
    );
    $stmtCheck->bind_param("is", $inscripcion['idhorario_estudiante'], $fecha);
    $stmtCheck->execute();
    if ($stmtCheck->get_result()->num_rows > 0) {
      throw new Exception("Este estudiante ya tiene registrada su asistencia hoy para esta clase.");
    }

    $minutosTranscurridos = (strtotime($hora) - strtotime($horario['hora_inicio'])) / 60;
    $estado = ($minutosTranscurridos > ASISTENCIA_TOLERANCIA_MINUTOS) ? 'Tardanza' : 'Presente';

    $stmt = $this->db->prepare(
      "INSERT INTO asistencia_estudiante (fecha, hora, estado, horario_estudiante_idhorario_estudiante)
        VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("sssi", $fecha, $hora, $estado, $inscripcion['idhorario_estudiante']);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }

    error_log("ℹ️ Registro manual de asistencia (motivo: $motivo) - estudiante #$idEstudiante, horario #$idHorario");

    return [
      'idasistencia_estudiante' => $this->db->insert_id,
      'fecha' => $fecha,
      'hora' => $hora,
      'estado' => $estado,
      'motivo' => $motivo,
    ];
  }
}