# Changelog

Todos los cambios notables de este paquete se documentan aquí. El formato
sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/) y el
versionado, [SemVer](https://semver.org/lang/es/).

## [0.1.0] - 2026-08-19

Primera versión.

### Añadido
- `SentinelaClient`: captura y envío de eventos a `POST /api/logs`, fail-safe
  (nunca lanza), con reintentos ante fallos de red y timeout configurable.
- Canal de logging de Laravel (`SentinelaLogChannelFactory` + Monolog handler).
- Captura automática de excepciones no controladas vía el evento
  `MessageLogged`, y método explícito `Sentinela::reportException()`.
- Firma HMAC-SHA256 del payload con timestamp + nonce anti-replay
  (`X-Sentinela-Signature`, `X-Sentinela-Timestamp`, `X-Sentinela-Nonce`).
- PII scrubbing configurable (claves + patrones de valor), sampling,
  modo debug y dry-run.
- Comando `sentinela:test`.
- Config publicable (`sentinela-config`), no-op si falta configuración.
