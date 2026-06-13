<?php
// podio/PodioFileUploader.php

class PodioFileUploader
{
    private PodioClient $client;

    public function __construct(PodioClient $client)
    {
        $this->client = $client;
    }

    /**
     * Carica un file remoto da URL e lo invia a Podio
     *
     * @param string $url
     * @param string $filename
     * @return int file_id
     * @throws Exception
     */
    public function uploadFromUrl(string $url, string $filename): int
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'podio_img_');
        $imgData = @file_get_contents($url);

        if (!$imgData) {
            throw new Exception("❌ Errore download immagine da URL: $url");
        }

        file_put_contents($tmpFile, $imgData);
        $fileId = $this->uploadFromPath($tmpFile, $filename);
        unlink($tmpFile);

        return $fileId;
    }

    /**
     * Carica un file da path locale e lo invia a Podio
     *
     * @param string $filePath
     * @param string $filename
     * @return int file_id
     * @throws Exception
     */
    public function uploadFromPath(string $filePath, string $filename): int
    {
        if (!file_exists($filePath)) {
            throw new Exception("❌ File non trovato: $filePath");
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $curlFile = new CURLFile($filePath, $mimeType, $filename);

        $ch = curl_init("https://api.podio.com/file/");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'source' => $curlFile,
                'filename' => $filename
            ],
            CURLOPT_HTTPHEADER => [
                "Authorization: OAuth2 " . $this->getAccessToken()
            ]
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if (!isset($data['file_id'])) {
            throw new Exception("❌ Upload fallito (status $status): " . json_encode($data));
        }

        return $data['file_id'];
    }

    /**
     * Recupera l'accessToken anche se la proprietà è protetta
     *
     * @return string
     * @throws Exception
     */
    private function getAccessToken(): string
    {
        $ref = new ReflectionClass($this->client);
        if (!$ref->hasProperty('accessToken')) {
            throw new Exception("❌ Impossibile accedere a accessToken del client Podio.");
        }

        $prop = $ref->getProperty('accessToken');
        $prop->setAccessible(true);
        return $prop->getValue($this->client);
    }
}
