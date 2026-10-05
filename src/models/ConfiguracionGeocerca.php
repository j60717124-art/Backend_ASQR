<?php

class ConfiguracionGeocerca
{
  private mysqli $db;

  public function __construct()
  {
    $this->db = getDB();
  }

  // Devuelve la configuración activa. Si por alguna razón no existe ninguna fila todavía, crea una con valores por defecto razonables.
  public function obtener(): array
  {
    $result = $this->db->query(
      "SELECT idconfiguracion_geocerca, latitud_referencia, longitud_referencia,
              radio_metros, intervalo_rotacion_segundos, actualizado_en
        FROM configuracion_geocerca ORDER BY idconfiguracion_geocerca DESC LIMIT 1"
    );
    $fila = $result ? $result->fetch_assoc() : null;

    if ($fila) {
      return $fila;
    }

    // No había configuración: se crea una por defecto para que el sistema no falle.
    $stmt = $this->db->prepare(
      "INSERT INTO configuracion_geocerca (latitud_referencia, longitud_referencia, radio_metros, intervalo_rotacion_segundos)
        VALUES (0, 0, 50, 30)"
    );
    $stmt->execute();

    return $this->obtener();
  }

  // Actualiza la configuración (Dirección). Todos los campos son opcionales; solo se cambian los que vienen en $data.

  public function actualizar(array $data): array
  {
    $actual = $this->obtener();
    $latitud = isset($data['latitud_referencia'])
      ? validarLatitud($data['latitud_referencia'], 'La latitud de referencia')
      : $actual['latitud_referencia'];

    $longitud = isset($data['longitud_referencia'])
      ? validarLongitud($data['longitud_referencia'], 'La longitud de referencia')
      : $actual['longitud_referencia'];

    $radio = isset($data['radio_metros'])
      ? validarEnteroRango($data['radio_metros'], 'El radio en metros', 5, 2000)
      : $actual['radio_metros'];

    $intervalo = isset($data['intervalo_rotacion_segundos'])
      ? validarEnteroRango($data['intervalo_rotacion_segundos'], 'El intervalo de rotación', 10, 3600)
      : $actual['intervalo_rotacion_segundos'];

    $stmt = $this->db->prepare(
      "UPDATE configuracion_geocerca
        SET latitud_referencia = ?, longitud_referencia = ?, radio_metros = ?, intervalo_rotacion_segundos = ?
        WHERE idconfiguracion_geocerca = ?"
    );
    $id = (int) $actual['idconfiguracion_geocerca'];
    $stmt->bind_param("ddiii", $latitud, $longitud, $radio, $intervalo, $id);
    if (!$stmt->execute()) {
      throw new Exception($stmt->error);
    }

    return $this->obtener();
  }
}