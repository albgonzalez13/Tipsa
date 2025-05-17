
> ⚠ Aunque esta clase fue diseñada para Laravel, puede utilizarse en cualquier proyecto PHP ya que no depende de ningún componente específico del framework.

# Tipsa
Servicio PHP para integrar con la API SOAP de TIPSA. Compatible con Laravel y otros proyectos PHP

Este paquete proporciona una clase de servicio `TipsaService` que permite integrar aplicaciones Laravel con los Web Services SOAP del transportista TIPSA.

Permite realizar operaciones como:

- 📦 Crear nuevos envíos
- 🔄 Consultar el estado actual y el histórico de los envíos
- 🧾 Descargar etiquetas en PDF, ZPL o TXT
- 🛠 Consultar datos detallados de un envío
- 📅 Listar envíos e incidencias por fecha

Requisitos
----------

- Laravel 8.x o superior
- PHP 8.0 o superior
- Extensión `SOAP` habilitada en PHP

Instalación
-----------

Copia el archivo `TipsaService.php` en `app/Services` de tu proyecto Laravel.

Puedes luego inyectarlo directamente en tus controladores o servicios.

Uso básico
----------

```php
use App\Services\TipsaService;

$tipsa = new TipsaService(
    agencia: '000000',
    cliente: '123456',
    password: 'miClaveSecreta'
);

// Crear un envío
$response = $tipsa->createEnvio([
    'strNomDes' => 'Juan Pérez',
    'strDirDes' => 'Calle Falsa 123',
    'strPobDes' => 'Madrid',
    'strCPDes' => '28080',
    'strTlfDes' => '600123456',
    'intPaq' => 1,
    'strContenido' => 'Zapatos',
    'strRef' => 'PED12345',
]);
```

Métodos disponibles
-------------------

| Método                        | Descripción                                                       |
|------------------------------|-------------------------------------------------------------------|
| `createEnvio()`              | Registra un nuevo envío                                           |
| `getEnviosByDate()`          | Lista de envíos por fecha                                         |
| `getIncidenciasByDate()`     | Lista de incidencias por fecha                                    |
| `getEstadosByReference()`    | Estados de un envío por referencia                                |
| `getLastEstadoByAlbaran()`   | Último estado de un envío                                         |
| `getEstadoEnvio()`           | Estados completos de un envío                                     |
| `getEnvio()`                 | Información general de un envío                                   |
| `showAlabaran()`             | Muestra la etiqueta del albarán como PDF                          |
| `requeryEtiqueta()`          | Reconsulta la etiqueta del envío en distintos formatos            |

Autenticación
-------------

TIPSA utiliza autenticación basada en sesión. Este servicio realiza automáticamente el login inicial (`LoginCli2`) cuando se invoca cualquier método que lo requiera.

Licencia
--------

Este código se publica bajo la licencia MIT.

Aportaciones
------------

Pull requests y sugerencias son bienvenidas. Puedes contribuir con mejoras, nuevas funciones o documentación.
