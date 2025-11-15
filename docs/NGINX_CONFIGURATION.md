# Configuración de Nginx para Bintelx

**Fecha**: 2025-11-14
**Versión Nginx**: Custom build con QUIC/HTTP3 support
**Arquitectura**: Backend API + Frontend SPA + Streams

---

## 📋 Índice

1. [Visión General](#visión-general)
2. [Estructura de Archivos](#estructura-de-archivos)
3. [Configuración Principal](#configuración-principal)
4. [Server Blocks](#server-blocks)
5. [Upstreams](#upstreams)
6. [Snippets](#snippets)
7. [Protocolos Soportados](#protocolos-soportados)
8. [Seguridad](#seguridad)
9. [Ejemplos de Uso](#ejemplos-de-uso)
10. [Troubleshooting](#troubleshooting)

---

## 🎯 Visión General

### Arquitectura del Sistema

```
┌─────────────────────────────────────────────────────────┐
│                     NGINX (dev.local)                   │
│                   Puerto 443 (HTTPS)                    │
│             HTTP/1.1 + HTTP/2 + HTTP/3 (QUIC)          │
└─────────────────────────────────────────────────────────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
        ▼                   ▼                   ▼
┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│   Frontend   │    │   API/PHP    │    │   Streams    │
│  (bintelx_   │    │  (FastCGI)   │    │   (Python/   │
│   front)     │    │   Unix       │    │   Swoole/    │
│              │    │   Socket     │    │   WebRTC)    │
│ :8080 (dev)  │    │   php8.4-fpm │    │ :8000/:9000  │
│ :8081 (prod)│    │              │    │   :9501      │
└──────────────┘    └──────────────┘    └──────────────┘
```

### Puertos y Servicios

| Puerto | Protocolo | Servicio | Descripción |
|--------|-----------|----------|-------------|
| 80 | HTTP | Redirect | Redirecciona a HTTPS |
| 443 | HTTPS/HTTP2/HTTP3 | Main | Servidor principal con QUIC |
| 8080 | HTTP | Frontend Dev | Servidor de desarrollo (Vite/npm) |
| 8081 | HTTP | Frontend Prod | Build estático de producción |
| Unix Socket | FastCGI | PHP-FPM | Backend API PHP 8.4 |
| 8000 | HTTP | Stream Backend | Python ASGI (SSE/WebSocket) |
| 9000 | WebSocket | WebRTC Signaling | Servidor de señalización WebRTC |
| 9501 | HTTP | Swoole | Swoole cluster para SSE |

---

## 📁 Estructura de Archivos

```
bintelx/config/server/
├── nginx.conf                              # Configuración principal global
├── nginx.bintelx.dev.localhost.conf        # Virtual host de dev.local
├── php.pool.conf                           # Configuración de PHP-FPM
├── dev.local.crt                           # Certificado SSL
├── dev.local.key                           # Llave privada SSL
├── dhparam.pem                             # Diffie-Hellman parameters
├── quic_host_key_file                      # QUIC host key (opcional)
│
└── snippets/                               # Fragmentos reutilizables
    ├── upstreams.conf                      # Definición de upstreams
    ├── http3.conf                          # Configuración QUIC/HTTP3
    ├── ssl.conf                            # Configuración SSL/TLS
    ├── security.conf                       # Headers de seguridad
    ├── proxy.conf                          # Headers de proxy
    ├── location-rules.conf                 # Reglas de ubicación
    ├── vars.conf                           # Variables personalizadas
    └── common-rules.conf                   # Reglas comunes
```

---

## ⚙️ Configuración Principal

**Archivo**: `nginx.conf`

### Características Principales

```nginx
user www-data www-data;
worker_processes auto;                      # Un worker por CPU
pid /run/nginx.pid;

events {
    worker_connections 1024;                # Conexiones por worker
    multi_accept on;                        # Aceptar múltiples conexiones
}

http {
    # --- Protocolos SSL/TLS ---
    ssl_protocols TLSv1.2 TLSv1.3;         # Solo protocolos seguros
    ssl_prefer_server_ciphers off;          # Cliente elige cipher (TLS 1.3)

    # --- Compresión Gzip ---
    gzip on;
    gzip_vary on;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/javascript;

    # --- Logs ---
    access_log /var/log/nginx/access.log combined;
    error_log /var/log/nginx/error.log debug;

    # --- Includes ---
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
```

### Variables Importantes

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `$base_domain` | Dominio base extraído del host | `dev.local` |
| `$host` | Hostname del request | `dev.local` |
| `$remote_addr` | IP del cliente | `192.168.1.100` |
| `$scheme` | Protocolo (http/https) | `https` |
| `$http3` | Indicador HTTP/3 | `h3` si está activo |

---

## 🖥️ Server Blocks

**Archivo**: `nginx.bintelx.dev.localhost.conf`

### Server 1: Main (HTTPS + HTTP2 + HTTP3)

```nginx
server {
    server_name dev.local;
    root /var/www/bintelx/app;

    # --- Protocolos ---
    listen 443 ssl;                         # HTTP/1.1 + SSL
    http2 on;                               # HTTP/2
    include snippets/http3.conf;            # HTTP/3 (QUIC)

    # --- SSL ---
    include snippets/ssl.conf;

    # --- Locations ---
    location / { ... }                      # Frontend
    location /api/ { ... }                  # Backend API
    location /ws/ { ... }                   # WebSockets
    location /stream/ { ... }               # SSE
    location /sse/ { ... }                  # Swoole SSE
    location /wrtc/ { ... }                 # WebRTC
    location ~ \.php$ { ... }               # PHP processor
}
```

---

### Location: `/` (Frontend)

**Propósito**: Servir la aplicación frontend (SPA)

```nginx
location / {
    proxy_pass http://bintelx_front;        # Upstream definido en upstreams.conf
    include snippets/proxy.conf;

    # WebSocket support
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";

    # Timeouts cortos para dev server
    proxy_connect_timeout 3s;
    proxy_send_timeout 3s;
    proxy_read_timeout 3s;
}
```

**Upstream**: `bintelx_front`
- **Dev**: `dev.local:8080` (servidor Vite/npm run dev)
- **Backup**: `127.0.0.1:8081` (build estático)

**Flujo**:
```
Cliente → NGINX:443 → bintelx_front:8080 → Vite Dev Server
                   └─→ (si falla) → :8081 → Static Build
```

---

### Location: `/api/` (Backend API)

**Propósito**: Procesar requests de API con PHP-FPM

```nginx
location /api/ {
    try_files $uri $uri/ /api.php$is_args$args;  # Reescribe a api.php

    # Timeouts
    proxy_connect_timeout 5s;
    proxy_send_timeout 5s;
    proxy_read_timeout 60s;                       # 1 min para API

    include snippets/proxy.conf;
}
```

**Procesamiento PHP**:
```nginx
location ~ \.php$ {
    try_files $uri =404;
    fastcgi_pass api_backend;                     # Unix socket PHP-FPM
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;

    fastcgi_read_timeout 60s;
    fastcgi_next_upstream error timeout invalid_header http_500 http_503;
}
```

**Flujo**:
```
Cliente → NGINX:443/api/login
       → try_files → /api.php?/login
       → fastcgi_pass → unix:/run/php/php8.4-fpm.sock
       → PHP ejecuta /var/www/bintelx/app/api.php
       → Router procesa /login
       → Response → Cliente
```

---

### Location: `/ws/` (WebSockets)

**Propósito**: Conexiones WebSocket bidireccionales

```nginx
location /ws/ {
    proxy_pass http://stream_backend;             # Python ASGI

    # Headers para WebSocket
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";

    include snippets/proxy.conf;
    proxy_read_timeout 86400;                     # 24 horas
}
```

**Características**:
- Mantiene conexión persistente (24h)
- Soporta upgrade de HTTP a WebSocket
- Backend: Python ASGI (Hypercorn/Uvicorn)

---

### Location: `/stream/` (Server-Sent Events)

**Propósito**: Streaming de eventos del servidor al cliente

```nginx
location /stream/ {
    proxy_pass http://stream_backend;
    include snippets/proxy.conf;
    proxy_read_timeout 86400;                     # 24 horas
}
```

**Uso**:
```javascript
// Cliente JavaScript
const eventSource = new EventSource('https://dev.local/stream/events');
eventSource.onmessage = (event) => {
    console.log('Evento recibido:', event.data);
};
```

---

### Location: `/sse/` (Swoole SSE)

**Propósito**: SSE usando Swoole cluster

```nginx
location = /sse/ {
    proxy_pass http://swoole_cluster$query_string;

    # Configuración específica para SSE
    proxy_buffering off;                          # No bufferear
    proxy_cache off;                              # No cachear
    proxy_read_timeout 1d;                        # 1 día
    proxy_http_version 1.1;                       # Keep-alive
    proxy_set_header Connection '';               # Limpiar header
    proxy_set_header Cache-Control no-cache;
}
```

**Diferencias con `/stream/`**:
- `/stream/` → Python ASGI backend
- `/sse/` → Swoole PHP backend
- Ambos soportan SSE, diferentes tecnologías

---

### Location: `/wrtc/` (WebRTC Signaling)

**Propósito**: Servidor de señalización para WebRTC

```nginx
location /wrtc/ {
    proxy_pass http://webrtc_signaling_server;    # Rust/Go server

    # WebSocket upgrade
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";

    include snippets/proxy.conf;
    proxy_read_timeout 86400;                     # 24 horas
}
```

**Flujo WebRTC**:
```
Peer A ←→ NGINX:443/wrtc/ ←→ Signaling Server (:9000) ←→ NGINX ←→ Peer B
                                      ↓
                            Intercambian SDP/ICE
                                      ↓
                            Peer A ←───────────────────────→ Peer B
                            (Conexión P2P directa, sin NGINX)
```

---

## 🔄 Upstreams

**Archivo**: `snippets/upstreams.conf`

### 1. bintelx_front (Frontend)

```nginx
upstream bintelx_front {
    zone bintelx_front_shm 64k;                   # Memoria compartida 64KB

    server dev.local:8080 resolve max_fails=3 fail_timeout=10s;
    server 127.0.0.1:8081 backup;                 # Backup estático
}
```

**Características**:
- `resolve`: Resolver DNS dinámicamente
- `max_fails=3`: Marcar como down después de 3 fallos
- `fail_timeout=10s`: Reintentar después de 10 segundos
- `backup`: Solo se usa si el primario falla

---

### 2. api_backend (PHP-FPM)

```nginx
upstream api_backend {
    server unix:/run/php/php8.4-fpm.sock;         # Unix socket
}
```

**Ventajas de Unix Socket vs TCP**:
- ✅ Más rápido (sin overhead de red)
- ✅ Más seguro (no expuesto en red)
- ✅ Mejor para servidor local

---

### 3. stream_backend (SSE/WebSocket)

```nginx
upstream stream_backend {
    zone stream_front_shm 64k;
    server 127.0.0.1:8000;                        # Python ASGI
}
```

**Tecnologías soportadas**:
- Hypercorn
- FastAPI
- Uvicorn
- Starlette

---

### 4. webrtc_signaling_server

```nginx
upstream webrtc_signaling_server {
    zone webrtc_front_shm 64k;
    server 127.0.0.1:9000;                        # Rust/Go server
}
```

---

### 5. swoole_cluster (Swoole SSE)

```nginx
upstream swoole_cluster {
    # ip_hash;                                     # Opcional: sticky sessions
    server 127.0.0.1:9501;
    # server 127.0.0.1:9502;                       # Segundo nodo
}
```

**Load Balancing**:
- Sin `ip_hash`: Round-robin (default)
- Con `ip_hash`: Mismo cliente → mismo servidor

---

### Resolver DNS

```nginx
resolver 127.0.0.1 172.24.168.52 valid=30s;      # DNS resolvers
```

**Uso**:
- Resolver `dev.local` dinámicamente
- Cache de 30 segundos
- Múltiples resolvers para redundancia

---

## 🔐 Seguridad

**Archivo**: `snippets/security.conf`

### Headers de Seguridad

```nginx
# HSTS - Forzar HTTPS
add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;

# Clickjacking protection
add_header X-Frame-Options "SAMEORIGIN" always;

# MIME-type sniffing protection
add_header X-Content-Type-Options "nosniff" always;

# XSS protection
add_header X-XSS-Protection "1; mode=block" always;

# Referrer policy
add_header Referrer-Policy "no-referrer-when-downgrade" always;
```

---

### Content Security Policy (CSP)

```nginx
# Variables CSP
set $csp_common "'self' *.$base_domain";
set $csp_default "default-src $csp_common ";
set $csp_scripts "script-src $csp_common 'unsafe-inline' 'unsafe-eval'";
set $csp_media   "media-src $csp_common data:";
set $csp_styles  "style-src $csp_common 'unsafe-inline' data:";
set $csp_connect "connect-src $csp_common ws: wss:";

# Construir CSP final
set $csp "";
set $csp "${csp}$csp_default; ";
set $csp "${csp}$csp_scripts; ";
set $csp "${csp}$csp_media; ";
set $csp "${csp}$csp_styles; ";
set $csp "${csp}$csp_connect; ";

add_header Content-Security-Policy $csp always;
```

**CSP Resultante**:
```
default-src 'self' *.dev.local;
script-src 'self' *.dev.local 'unsafe-inline' 'unsafe-eval';
media-src 'self' *.dev.local data:;
style-src 'self' *.dev.local 'unsafe-inline' data:;
connect-src 'self' *.dev.local ws: wss:;
```

**Permite**:
- Scripts y estilos inline (desarrollo)
- WebSockets (ws:, wss:)
- Data URIs para media
- Subdominios de dev.local

---

### Reglas de Ubicación

**Archivo**: `snippets/location-rules.conf`

```nginx
# Cache para assets estáticos
location ~* \.(?:ico|svg|webp|woff|woff2|pdf)$ {
    expires 3d;
    add_header Cache-Control "public, no-transform";
    access_log off;
    log_not_found off;
}

# Denegar acceso a archivos ocultos
location ~ /\. {
    deny all;
}

# Proteger directorios sensibles
location ~ ^/log/ { deny all; }
location ~ ^/bintelx/ { deny all; }                  # Core PHP
location ~ ^/custom/ { deny all; }                   # Custom code
location = /robots.txt { access_log off; log_not_found off; }
location = /favicon.ico { access_log off; log_not_found off; }
```

**Protección**:
- ❌ `.git`, `.env`, `.htaccess` → Bloqueados
- ❌ `/bintelx/` → Core PHP no accesible directamente
- ❌ `/custom/` → Código custom no accesible directamente
- ❌ `/log/` → Logs no accesibles
- ✅ Solo `/api/` es el punto de entrada público

---

## 🌐 Protocolos Soportados

### HTTP/1.1

**Puerto**: 443
**Configuración**: `listen 443 ssl;`

**Uso**: Clientes legacy, requests simples

---

### HTTP/2

**Puerto**: 443
**Configuración**: `http2 on;`

**Ventajas**:
- Multiplexing (múltiples requests en una conexión)
- Server Push
- Header compression (HPACK)
- Binary protocol (más eficiente)

**Uso automático**: Navegadores modernos usan HTTP/2 si está disponible

---

### HTTP/3 (QUIC)

**Puerto**: 443 UDP
**Archivo**: `snippets/http3.conf`

```nginx
listen 443 quic reuseport;                           # QUIC/HTTP3

# Mitigación de ataques
quic_retry on;                                        # Address validation
quic_gso on;                                          # Generic Segmentation Offload

# Anunciar soporte HTTP/3
add_header Alt-Svc 'h3=":443"; ma=86400' always;

# 0-RTT (opcional, requiere QuicTLS)
# ssl_early_data on;
```

**Características**:
- Basado en UDP (no TCP)
- 0-RTT connection (más rápido)
- Mejor manejo de pérdida de paquetes
- Multiplexing nativo
- Encriptación obligatoria

**Detección**:
```bash
curl -I https://dev.local --http3
# Alt-Svc: h3=":443"; ma=86400
```

**Uso en navegador**:
- Chrome: `chrome://flags/#enable-quic`
- Firefox: `network.http.http3.enabled`

---

## 🔒 SSL/TLS

**Archivo**: `snippets/ssl.conf`

### Certificados

```nginx
ssl_certificate /var/www/bintelx/bintelx/config/server/dev.local.crt;
ssl_certificate_key /var/www/bintelx/bintelx/config/server/dev.local.key;
ssl_trusted_certificate /var/www/bintelx/bintelx/config/server/dev.local.crt;
ssl_dhparam /var/www/bintelx/bintelx/config/server/dhparam.pem;
```

**Generar certificado auto-firmado** (desarrollo):
```bash
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout dev.local.key \
  -out dev.local.crt \
  -subj "/CN=dev.local"
```

**Generar DH params**:
```bash
openssl dhparam -out dhparam.pem 2048
```

---

### Protocolos y Ciphers

```nginx
ssl_protocols TLSv1.2 TLSv1.3;                       # Solo versiones seguras
ssl_prefer_server_ciphers on;                        # Servidor elige cipher
ssl_ciphers 'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:ECDHE-RSA-AES256-GCM-SHA384';
```

**Ciphers soportados** (orden de preferencia):
1. `TLS_AES_128_GCM_SHA256` - TLS 1.3, AES 128-bit
2. `TLS_AES_256_GCM_SHA384` - TLS 1.3, AES 256-bit
3. `TLS_CHACHA20_POLY1305_SHA256` - TLS 1.3, ChaCha20
4. `ECDHE-RSA-AES256-GCM-SHA384` - TLS 1.2, ECDHE

---

### Session Cache

```nginx
ssl_session_cache shared:SSL:10m;                    # 10MB compartido
ssl_session_timeout 1d;                              # 1 día
ssl_session_tickets off;                             # Deshabilitar tickets
```

**Ventajas**:
- Reutilizar sesiones SSL (más rápido)
- Cache compartido entre workers
- 10MB ≈ 40,000 sesiones

---

### OCSP Stapling

```nginx
ssl_stapling on;                                     # Activar OCSP stapling
ssl_stapling_verify on;                              # Verificar respuesta
```

**Beneficio**: Validación de certificado más rápida (servidor cachea respuesta OCSP)

---

## 🔄 Proxy Configuration

**Archivo**: `snippets/proxy.conf`

```nginx
proxy_http_version 1.1;                              # Keep-alive
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
```

### Headers Explicados

| Header | Descripción | Valor Ejemplo |
|--------|-------------|---------------|
| `Host` | Hostname original | `dev.local` |
| `X-Real-IP` | IP real del cliente | `192.168.1.100` |
| `X-Forwarded-For` | Cadena de IPs (proxies) | `192.168.1.100, 10.0.0.1` |
| `X-Forwarded-Proto` | Protocolo original | `https` |

**Uso en PHP**:
```php
$clientIP = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'];
$protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http';
```

---

## 📊 Ejemplos de Uso

### Ejemplo 1: Request de API

```bash
curl -X POST https://dev.local/api/_demo/login \
  -H "Content-Type: application/json" \
  -d '{"username":"test","password":"test123"}'
```

**Flujo**:
```
1. Cliente → NGINX:443/api/_demo/login (HTTPS)
2. NGINX → try_files → /api.php?/_demo/login
3. NGINX → fastcgi_pass → unix:/run/php/php8.4-fpm.sock
4. PHP-FPM ejecuta /var/www/bintelx/app/api.php
5. Router::dispatch() procesa /_demo/login
6. AuthHandler::login() ejecuta
7. Response JSON → PHP → NGINX → Cliente
```

---

### Ejemplo 2: Conexión WebSocket

```javascript
// Cliente JavaScript
const ws = new WebSocket('wss://dev.local/ws/chat');

ws.onopen = () => {
    console.log('Conectado');
    ws.send(JSON.stringify({ type: 'join', room: 'general' }));
};

ws.onmessage = (event) => {
    console.log('Mensaje:', event.data);
};
```

**Flujo**:
```
1. Cliente → wss://dev.local/ws/chat
2. NGINX → Upgrade to WebSocket
3. NGINX → proxy_pass → http://stream_backend (:8000)
4. Python ASGI mantiene conexión
5. Mensajes bidireccionales cliente ↔ ASGI
```

---

### Ejemplo 3: Server-Sent Events

```javascript
// Cliente JavaScript
const eventSource = new EventSource('https://dev.local/stream/notifications');

eventSource.addEventListener('message', (event) => {
    console.log('Notificación:', event.data);
});
```

**Flujo**:
```
1. Cliente → https://dev.local/stream/notifications
2. NGINX → proxy_pass → stream_backend (:8000)
3. Python ASGI envía eventos:
   data: {"type":"notification","message":"New order"}

4. NGINX → Cliente (streaming)
```

---

## 🐛 Troubleshooting

### Problema 1: Frontend no carga (502 Bad Gateway)

**Causa**: Servidor de desarrollo no está corriendo

**Diagnóstico**:
```bash
curl -I http://localhost:8080
# curl: (7) Failed to connect to localhost port 8080
```

**Solución**:
```bash
cd /var/www/bintelx_front
npm run dev
```

**Verificar**:
```bash
curl -I http://localhost:8080
# HTTP/1.1 200 OK
```

---

### Problema 2: API retorna 404

**Causa**: `try_files` no encuentra api.php

**Diagnóstico**:
```bash
ls -la /var/www/bintelx/app/api.php
# -rw-r--r-- 1 www-data www-data ... api.php
```

**Verificar nginx error log**:
```bash
tail -f /var/log/nginx/error.log
# FastCGI sent in stderr: "Primary script unknown"
```

**Solución**: Verificar `root` en server block
```nginx
server {
    root /var/www/bintelx/app;  # Debe apuntar a directorio correcto
}
```

---

### Problema 3: WebSocket se desconecta

**Causa**: Timeout muy corto

**Diagnóstico**:
```bash
# Error en navegador después de 60 segundos
```

**Solución**: Aumentar `proxy_read_timeout`
```nginx
location /ws/ {
    proxy_read_timeout 86400;  # 24 horas
}
```

---

### Problema 4: QUIC/HTTP3 no funciona

**Diagnóstico**:
```bash
curl -I https://dev.local --http3
# curl: (7) Couldn't connect to server
```

**Verificar**:
```bash
# 1. Nginx compilado con QUIC
nginx -V 2>&1 | grep quic
# --with-http_v3_module

# 2. Puerto UDP abierto
sudo netstat -ulnp | grep :443
# udp ... 0.0.0.0:443 ... nginx

# 3. Firewall permite UDP 443
sudo ufw status | grep 443
# 443/udp ALLOW Anywhere
```

**Solución**: Recompilar nginx con soporte QUIC o abrir firewall

---

### Problema 5: SSL certificate error

**Diagnóstico**:
```bash
curl https://dev.local
# SSL certificate problem: self signed certificate
```

**Solución desarrollo**:
```bash
# Ignorar verificación SSL
curl -k https://dev.local

# O agregar certificado a trust store
sudo cp dev.local.crt /usr/local/share/ca-certificates/
sudo update-ca-certificates
```

---

## 📚 Referencias Oficiales

### Documentación Nginx

- **Nginx Core**: https://nginx.org/en/docs/
- **HTTP Core Module**: https://nginx.org/en/docs/http/ngx_http_core_module.html
- **Proxy Module**: https://nginx.org/en/docs/http/ngx_http_proxy_module.html
- **Upstream Module**: https://nginx.org/en/docs/http/ngx_http_upstream_module.html
- **SSL Module**: https://nginx.org/en/docs/http/ngx_http_ssl_module.html
- **FastCGI Module**: https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html

### HTTP/3 y QUIC

- **QUIC Module**: https://nginx.org/en/docs/http/ngx_http_v3_module.html
- **QUIC and HTTP/3 Guide**: https://www.nginx.com/blog/our-roadmap-quic-http-3-support-nginx/

### Seguridad

- **Security Headers**: https://observatory.mozilla.org/
- **CSP Guide**: https://content-security-policy.com/
- **SSL Configuration**: https://ssl-config.mozilla.org/

### Performance

- **Tuning Nginx**: https://www.nginx.com/blog/tuning-nginx/
- **Load Balancing**: https://nginx.org/en/docs/http/load_balancing.html

---

## ✅ Checklist de Configuración

### Producción

- [ ] Cambiar `error_log` de `debug` a `warn` o `error`
- [ ] Deshabilitar `access_log` para assets estáticos
- [ ] Usar certificados SSL válidos (Let's Encrypt)
- [ ] Configurar `ssl_stapling` con resolver válido
- [ ] Ajustar `worker_processes` según CPUs
- [ ] Habilitar `gzip_static` para assets pre-comprimidos
- [ ] Configurar `proxy_cache` para API (opcional)
- [ ] Revisar CSP y eliminar `unsafe-inline`/`unsafe-eval`
- [ ] Habilitar rate limiting (`limit_req`)
- [ ] Configurar backups de certificados SSL

### Seguridad

- [ ] Verificar permisos de archivos (644 para .conf, 600 para .key)
- [ ] Denegar acceso a directorios sensibles
- [ ] Configurar fail2ban para NGINX
- [ ] Actualizar nginx regularmente
- [ ] Monitorear logs de errores

---

**Estado**: ✅ DOCUMENTADO
**Fecha**: 2025-11-14
**Versión**: 1.0 - Bintelx Nginx Configuration
