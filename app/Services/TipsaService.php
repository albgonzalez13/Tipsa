<?php

namespace App\Services;

use Exception;

class TipsaService
{
    const URL_PRODUCCION = 'http://webservices.tipsa-dinapaq.com:8099/SOAP?service=';
    const URL_TEST       = 'https://wsval.tipsa-dinapaq.com/SOAP?service=';

    private string $url;
    private string $agencia;
    private string $cliente;
    private string $password;
    private ?string $session_id = null;
    private string $language = "ES";

    /**
     * Constructor del servicio TIPSA.
     *
     * @param string $agencia Código de la agencia.
     * @param string $cliente Código del cliente.
     * @param string $password Contraseña del cliente.
     * @param string $language Idioma del servicio (por defecto 'ES').
     * @param string|null $url
     */
    public function __construct(
        string $agencia,
        string $cliente,
        string $password,
        string $language = "ES",
        ?string $url = null
    ) {
        $this->agencia = $agencia;
        $this->cliente = $cliente;
        $this->password = $password;
        $this->language = $language;
        $this->url = $url ?? self::URL_TEST;
    }

    /**
     * Recupera todos los envíos registrados en una fecha determinada.
     *
     * @param string $fecha Fecha en formato 'YYYY/MM/DD'.
     * @return array Lista de envíos encontrados.
     * @throws Exception
     */
    public function getEnviosByDate(string $fecha)
    {
        $result = $this->call(
            "WebServService",
            "InfEnvios",
            [
                "dtFecha" => $fecha,
            ],
            "INF_ENVIOS"
        );

        return $this->parseEmbeddedXml(
            $result["strInfEnvios"] ?? null,
            "INF_ENVIOS"
        );
    }

    /**
     * Devuelve los estados de un envío a partir de su referencia.
     *
     * @param string $ref Referencia del envío.
     * @return array Lista ordenada de estados del envío.
     * @throws Exception
     */
    public function getEstadosByReference(string $ref)
    {
        $result = $this->call(
            "WebServService",
            "ConsEnvEstadosRef",
            [
                "strRef" => $ref,
            ],
            "ENV_ESTADOS_REF"
        );

        $data = $this->parseEmbeddedXml(
            $result["strEnvEstadosRef"] ?? null,
            "ENV_ESTADOS_REF"
        );
        if (!$data) {
            return [];
        }
        return $this->parseEnvios($data);
    }

    /**
     * Recupera el último estado de un envío mediante su albarán.
     *
     * @param string $ref Número de albarán.
     * @return array Datos del estado más reciente.
     * @throws Exception
     */

    public function getLastEstadoByAlbaran(string $ref)
    {
        $result = $this->call(
            "WebServService",
            "ConsUltimoEstadoEnvio",
            [
                "strAlbaran" => $ref,
            ],
            "CONS_ULTIMO_ESTADO_ENVIO"
        );
        dd($result);
        $data = $this->parseEmbeddedXml(
            $result["strUltimoEstadoEnvio"] ?? null,
            "CONS_ULTIMO_ESTADO_ENVIO"
        );
        if (!$data) {
            return [];
        }
        return $this->parseEnvios($data);
    }

    /**
     * Muestra el albarán del envío en formato PDF directamente en el navegador.
     *
     * @param string $ref Número de albarán.
     * @return \Illuminate\Http\Response
     * @throws Exception
     */

    public function showAlabaran(string $ref)
    {
        $result = $this->call("WebServService", "ConsAlbaranEnvio", [
            "strAlbaran" => $ref,
        ]);

        $albaran = $result["strAlbEnt"] ?? null;
        if (!$albaran) {
            abort(404, "Etiqueta no encontrada");
        }
        return response(base64_decode($albaran))
            ->header("Content-Type", "application/pdf")
            ->header(
                "Content-Disposition",
                'inline; filename="etiqueta_tipsa.pdf"'
            );
    }

    /**
     * Devuelve las incidencias registradas en una fecha específica.
     *
     * @param string $fecha Fecha en formato 'YYYY/MM/DD'.
     * @return array Lista de incidencias.
     * @throws Exception
     */
    public function getIncidenciasByDate(string $fecha)
    {
        $result = $this->call(
            "WebServService",
            "ConsEnvIncidenciasFecha",
            [
                "dtFecha" => $fecha,
            ],
            "ENV_INCIDENCIAS"
        );

        return $this->parseEmbeddedXml(
            $result["strEnvIncidencias"] ?? null,
            "ENV_INCIDENCIAS"
        );
    }

    private function parseEmbeddedXml(?string $xmlString, string $node): array
    {
        if (!$xmlString) {
            return [];
        }

        $xml = simplexml_load_string(
            $xmlString,
            "SimpleXMLElement",
            LIBXML_NOCDATA
        );
        if (!$xml) {
            return [];
        }

        $array = json_decode(json_encode($xml), true);
        return $array[$node] ?? [];
    }

    /**
     * Crea un nuevo envío en el sistema TIPSA.
     *
     * @param array $data Datos del envío.
     * @return array Respuesta de la API de TIPSA.
     * @throws Exception
     */
    public function createEnvio(array $data)
    {
        $params = array_merge(
            [
                "boInsert" => true,
            ],
            $data
        );

        return $this->call("WebServService", "GrabaEnvio24", $params, null);
    }

    /**
     * Ejecuta una llamada SOAP a un método del servicio TIPSA.
     *
     * @param string $service Nombre del servicio SOAP.
     * @param string $method Método del servicio a invocar.
     * @param array $parameters Parámetros de la llamada.
     * @param string|null $responseKey Clave esperada dentro del XML (opcional).
     * @return array|string Respuesta parseada o cruda.
     * @throws Exception En caso de fallo en la respuesta SOAP.
     */

    private function call(
        string $service,
        string $method,
        array $parameters,
        string $responseKey = null
    ) {
        if ($service === "WebServService" && !$this->session_id) {
            $this->login();
        }

        $requestXml = $this->buildRequest($service, $method, $parameters);
        $soapXml = $this->wrapEnvelope($requestXml, true);
        $url = $this->url . $service;
        $response = $this->request($url, $soapXml);

        // Detectar y lanzar errores SOAP
        if (
            strpos($response, "<SOAP-ENV:Fault>") !== false ||
            strpos($response, "<soap:Fault>") !== false
        ) {
            if (
                preg_match(
                    "/<faultstring>(.*?)<\/faultstring>/",
                    $response,
                    $match
                )
            ) {
                throw new Exception(
                    "TIPSA API Error: " . html_entity_decode($match[1])
                );
            }
            throw new Exception("TIPSA API Error: SOAP Fault");
        }

        if (
            preg_match(
                "/<v1:" .
                    $service .
                    "___" .
                    $method .
                    "Response>(.*?)<\/v1:" .
                    $service .
                    "___" .
                    $method .
                    "Response>/s",
                $response,
                $match
            )
        ) {
            $cleanXml = preg_replace("/(<\\/?)v1:/", '$1', $match[1]);
            $wrappedXml =
                '<?xml version="1.0" encoding="utf-8"?><root>' .
                $cleanXml .
                "</root>";

            $xml = simplexml_load_string(
                $wrappedXml,
                "SimpleXMLElement",
                LIBXML_NOCDATA
            );
            if (!$xml) {
                throw new Exception("Error al parsear XML limpio de TIPSA.");
            }

            return json_decode(json_encode($xml), true);
        }

        return $response;
    }
    /**
     * Ejecuta la petición HTTP CURL con el XML generado.
     *
     * @param string $url URL completa del servicio SOAP.
     * @param string $xml Contenido XML del cuerpo de la petición.
     * @return string Respuesta cruda devuelta por el servidor SOAP.
     */

    private function request(string $url, string $xml): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => utf8_encode($xml),
            CURLOPT_HTTPHEADER => ["Content-Type: text/xml"],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    /**
     * Realiza la autenticación con el servicio TIPSA y obtiene un session_id.
     *
     * @throws Exception Si la autenticación falla.
     */

    private function login(): void
    {
        $xml = $this->wrapEnvelope(
            $this->buildRequest("LoginWSService", "LoginCli2", [
                "strCodAge" => $this->agencia,
                "strCod" => $this->cliente,
                "strPass" => $this->password,
                "strIdioma" => $this->language,
            ]),
            false
        );

        $response = $this->request($this->url . "LoginWSService", $xml);

        preg_match_all("@<v1:(\w+)>([^<]+)<@ms", $response, $matches);

        $parsed = array_combine($matches[1], $matches[2]);

        if (!isset($parsed["strSesion"])) {
            throw new Exception(
                "Login error: " . ($parsed["strError"] ?? "Unknown")
            );
        }

        $this->session_id = $parsed["strSesion"];
    }

    /**
     * Envuelve un cuerpo XML dentro de la estructura SOAP estándar.
     *
     * @param string $body Cuerpo XML de la petición.
     * @param bool $withHeader Si debe incluir la cabecera de autenticación.
     * @return string XML SOAP completo listo para ser enviado.
     */

    private function wrapEnvelope(string $body, bool $withHeader): string
    {
        $header = "";
        if ($withHeader && $this->session_id) {
            $header = "<soapenv:Header>
                <tem:ROClientIDHeader>
                    <tem:ID>{$this->session_id}</tem:ID>
                </tem:ROClientIDHeader>
            </soapenv:Header>";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:tem=\"http://tempuri.org/\">
{$header}
<soapenv:Body>
{$body}
</soapenv:Body>
</soapenv:Envelope>";
    }
    /**
     * Construye el cuerpo del XML con los parámetros de la llamada SOAP.
     *
     * @param string $service Nombre del servicio SOAP.
     * @param string $method Nombre del método dentro del servicio.
     * @param array $params Parámetros de la petición.
     * @return string XML listo para ser insertado en el body del envelope.
     */

    private function buildRequest(
        string $service,
        string $method,
        array $params
    ): string {
        $body = "<tem:{$service}___{$method}>";
        foreach ($params as $key => $value) {
            $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, "UTF-8");
            $body .= "<tem:{$key}>{$escaped}</tem:{$key}>";
        }
        $body .= "</tem:{$service}___{$method}>";
        return $body;
    }

    /**
     * Parsea los datos de estados de envío en una estructura ordenada.
     *
     * @param array $results Datos brutos obtenidos del XML.
     * @return array Lista de estados formateados con claves útiles.
     */

    private function parseEnvios($results)
    {
        $sends = [];
        foreach ($results as $k => $result) {
            if ($k == "@attributes") {
                $sends[] = [
                    "service" => empty($result["V_SERVICIO"])
                        ? ""
                        : $result["V_SERVICIO"],
                    "last" => empty($result["B_ULT"])
                        ? false
                        : $result["B_ULT"],
                    "date" => empty($result["D_FEC_HORA_ALTA"])
                        ? ""
                        : $result["D_FEC_HORA_ALTA"],
                    "code_type" =>
                        $result["V_COD_TIPO_EST"] == ""
                            ? ""
                            : $result["V_COD_TIPO_EST"],
                    "code" => $this->getCode($result["V_COD_TIPO_EST"]),
                ];
            } else {
                $sends[] = [
                    "service" => empty($result["@attributes"]["V_SERVICIO"])
                        ? ""
                        : $result["@attributes"]["V_SERVICIO"],
                    "last" => empty($result["@attributes"]["B_ULT"])
                        ? false
                        : (bool) $result["@attributes"]["B_ULT"],
                    "date" => empty($result["@attributes"]["D_FEC_HORA_ALTA"])
                        ? ""
                        : $result["@attributes"]["D_FEC_HORA_ALTA"],
                    "code_type" =>
                        $result["@attributes"]["V_COD_TIPO_EST"] == ""
                            ? ""
                            : $result["@attributes"]["V_COD_TIPO_EST"],
                    "code" => $this->getCode(
                        $result["@attributes"]["V_COD_TIPO_EST"]
                    ),
                ];
            }
        }

        usort($sends, function ($a, $b) {
            return $a["date"] >= $b["date"];
        });

        return $sends;
    }

    /**
     * Traduce el código de estado de TIPSA a una descripción legible.
     *
     * @param string|int $code Código numérico del estado.
     * @return string Descripción legible del estado.
     */

    private function getCode($code)
    {
        $messages_by_code = [
            "1" => "Tránsito",
            "2" => "Reparto",
            "3" => "Entregado",
            "4" => "Incidencia",
            "5" => "Devuelto",
            "6" => "Falta de expedición",
            "7" => "Recanalizado",
            "9" => "Falta de expedición administrativa",
            "10" => "Destruído",
            "14" => "Disponible",
            "15" => "Entrega parcial",
        ];
        return !empty($messages_by_code[$code])
            ? $messages_by_code[$code]
            : "Indeterminado";
    }

    /**
     * Reconsulta la etiqueta de un envío en formato base64 (PDF, ZPL o TXT).
     *
     * @param string $albaran Número de albarán del envío.
     * @param string $formato Formato deseado ('pdf', 'zpl', 'txt').
     * @param int $repDetId ID del informe (0 para el predeterminado).
     * @param int $desde Número de bulto desde.
     * @param int $hasta Número de bulto hasta.
     * @param int $posIni Posición inicial de la etiqueta.
     * @return string|null Contenido base64 de la etiqueta.
     * @throws Exception
     */
    public function requeryEtiqueta(
        string $albaran,
        string $formato = "txt",
        int $repDetId = 0,
        int $desde = 1,
        int $hasta = 1,
        int $posIni = 1
    ): ?string {
        $result = $this->call("WebServService", "ConsEtiquetaEnvio8", [
            "strCodAgeOri" => $this->agencia,
            "strAlbaran" => $albaran,
            "strNumBultoDesde" => $desde,
            "strNumBultoHasta" => $hasta,
            "intPosIni" => $posIni,
            "intIdRepDet" => $repDetId,
            "strFormato" => $formato,
        ]);
        return $result["strEtiqueta"] ?? null;
    }
    /**
     * Consulta los datos generales de un envío a partir de su albarán.
     *
     * @param string $albaran Número de albarán.
     * @return array Datos generales del envío.
     * @throws Exception
     */
    public function getEnvio(string $albaran): array
    {
        $result = $this->call(
            "WebServService",
            "ConsEnvio",
            [
                "strCodAgeCargo" => $this->agencia,
                "strCodAgeOri" => $this->agencia,
                "strAlbaran" => $albaran,
            ],
            "ENVIOS"
        );

        $data = $this->parseEmbeddedXml($result["strEnvio"] ?? null, "ENVIOS");
        if (!$data) {
            return [];
        }
        return $data["@attributes"] ?? [];
    }

    /**
     * Consulta los estados registrados de un envío a partir de su albarán.
     *
     * @param string $albaran Número de albarán.
     * @return array Lista de estados del envío.
     * @throws Exception
     */
    public function getEstadoEnvio(string $albaran): array
    {
        $result = $this->call("WebServService", "ConsEnvEstados", [
            "strCodAgeCargo" => $this->agencia,
            "strCodAgeOri" => $this->agencia,
            "strAlbaran" => $albaran,
        ]);

        $data = $this->parseEmbeddedXml(
            $result["strEnvEstados"] ?? null,
            "ENV_ESTADOS"
        );
        if (!$data) {
            return [];
        }
        return $data["@attributes"] ?? [];
    }
}
