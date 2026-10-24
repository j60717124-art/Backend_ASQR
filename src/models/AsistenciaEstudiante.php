<?php
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

  // Registro por escaneo del carnet (QR fijo del estudiante) en la clase que el docente tiene seleccionada.
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

    return $this->registrar($idHorario, (int) $qr['estudiante_idestudiante']);
  }

  // Registro manual (el estudiante olvidó el carnet, falla la cámara, etc.). Exige un motivo y queda en el log.
  public function registrarManual(int $idHorario, int $idEstudiante, string $motivo): array
  {
    $motivo = validarTextoObligatorio($motivo, 'El motivo del registro manual', 3, 150);

    $resultado = $this->registrar($idHorario, $idEstudiante);
    error_log("ℹ️ Registro manual de asistencia (motivo: $motivo) - estudiante #$idEstudiante, horario #$idHorario");

    $resultado['motivo'] = $motivo;
    return $resultado;
  }

  // Lógica común de ambos registros: valida, calcula Presente/Tardanza e inserta sin duplicar.
  private function registrar(int $idHorario, int $idEstudiante): array
  {
    $horario = (new Horario())->getById($idHorario);
    if (!$horario) {
      throw new Exception("El horario/clase seleccionado no existe.");
    }

    $estudiante = (new Estudiante())->getById($idEstudiante);
    if (!$estudiante) {
      throw new Exception("El estudiante no existe.");
    }
    if (($estudiante['estado_estudiante'] ?? 'Activo') !== 'Activo') {
      throw new Exception("El estudiante está inactivo y no puede registrar asistencia.");
    }

    $inscripcion = (new HorarioEstudiante())->getPorHorarioYEstudiante($idHorario, $idEstudiante);
    if (!$inscripcion) {
      throw new Exception("Este estudiante no pertenece a la materia/horario seleccionado.");
    }

    $fecha = getFechaBolivia();
    $hora = getHoraBolivia();

    if (ASISTENCIA_VALIDAR_HORARIO) {
      $this->validarDentroDeLaClase($horario, $hora);
    }

    $estado = $this->calcularEstado($fecha, $horario['hora_inicio'], $hora);
    $idInscripcion = (int) $inscripcion['idhorario_estudiante'];

    $this->db->begin_transaction();
    try {
      $stmtCheck = $this->db->prepare(
        "SELECT idasistencia_estudiante FROM asistencia_estudiante
          WHERE horario_estudiante_idhorario_estudiante = ? AND fecha = ? LIMIT 1"
      );
      $stmtCheck->bind_param("is", $idInscripcion, $fecha);
      $stmtCheck->execute();
      if ($stmtCheck->get_result()->num_rows > 0) {
        throw new Exception("Este estudiante ya tiene registrada su asistencia hoy para esta clase.");
      }

      $stmt = $this->db->prepare(
        "INSERT INTO asistencia_estudiante (fecha, hora, estado, horario_estudiante_idhorario_estudiante)
          VALUES (?, ?, ?, ?)"
      );
      $stmt->bind_param("sssi", $fecha, $hora, $estado, $idInscripcion);
      $stmt->execute();
      $idAsistencia = $this->db->insert_id;

      $stmtMateria = $this->db->prepare("SELECT nombre_materia FROM materia WHERE idmateria = ? LIMIT 1");
      $stmtMateria->bind_param("i", $horario['materia_idmateria']);
      $stmtMateria->execute();
      $materia = $stmtMateria->get_result()->fetch_assoc();

      $this->db->commit();
    } catch (mysqli_sql_exception $e) {
      $this->db->rollback();
      if ((int) $e->getCode() === 1062) {
        throw new Exception("Este estudiante ya tiene registrada su asistencia hoy para esta clase.");
      }
      throw $e;
    } catch (Throwable $e) {
      $this->db->rollback();
      throw $e;
    }

    return [
      'idasistencia_estudiante' => $idAsistencia,
      'estudiante_idestudiante' => $idEstudiante,
      'estudiante_nombre' => trim($estudiante['nombres'] . ' ' . $estudiante['apellidos']),
      'materia_nombre' => $materia['nombre_materia'] ?? 'Materia',
      'fecha' => $fecha,
      'hora' => $hora,
      'estado' => $estado,
    ];
  }

  // La asistencia solo puede registrarse el día de la clase y antes de que termine.
  private function validarDentroDeLaClase(array $horario, string $horaActual): void
  {
    if (strtoupper($horario['dia']) !== getDiaSemanaBolivia()) {
      throw new Exception("Esta clase corresponde al día " . $horario['dia'] . ", no a hoy.");
    }
    if (strtotime($horaActual) > strtotime($horario['hora_fin'])) {
      throw new Exception("La clase ya terminó (" . substr($horario['hora_fin'], 0, 5) . "). No se puede registrar asistencia.");
    }
  }

  // Presente hasta ASISTENCIA_TOLERANCIA_MINUTOS después del inicio; después, Tardanza.
  private function calcularEstado(string $fecha, string $horaInicio, string $horaRegistro): string
  {
    $inicio = (new DateTime($fecha . ' ' . $horaInicio))->getTimestamp();
    $registro = (new DateTime($fecha . ' ' . $horaRegistro))->getTimestamp();
    $minutos = ($registro - $inicio) / 60;

    return ($minutos > ASISTENCIA_TOLERANCIA_MINUTOS) ? 'Tardanza' : 'Presente';
  }
}