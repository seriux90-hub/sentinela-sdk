<?php

namespace Sentinela\LaravelClient\Support;

class PayloadSigner
{
    /**
     * Cabeceras de firma para un cuerpo JSON ya serializado: timestamp +
     * nonce (evitan repetición del mismo envío) y una firma HMAC-SHA256
     * calculada sobre "{timestamp}.{nonce}.{body}" con el secreto del
     * proyecto. El servidor recalcula la misma firma para verificarla.
     *
     * Importante (ver README, sección "Seguridad"): esto da INTEGRIDAD del
     * mensaje y protección anti-replay, no autorización — el cliente es
     * untrusted y conoce el secreto, así que la decisión de aceptar o no
     * el evento la toma siempre el servidor (suscripción, cuota, etc.).
     *
     * @return array{'X-Sentinela-Timestamp': string, 'X-Sentinela-Nonce': string, 'X-Sentinela-Signature': string}
     */
    public function headersFor(string $jsonBody, string $secret): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-Sentinela-Timestamp' => $timestamp,
            'X-Sentinela-Nonce' => $nonce,
            'X-Sentinela-Signature' => $this->sign($jsonBody, $timestamp, $nonce, $secret),
        ];
    }

    public function sign(string $jsonBody, string $timestamp, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$nonce}.{$jsonBody}", $secret);
    }
}
