# config/README.md
# bintelx.cifrid.com — Backend deployment

## 1. Clone

```bash
cd /var/www
git clone https://github.com/QuijoteShin/bintelx bintelx.cifrid.com
```

## 2. Directorios y permisos

```bash
mkdir -p /var/www/bintelx.cifrid.com/{storage,secrets,log}

chown -R quijote:www-data /var/www/bintelx.cifrid.com
find /var/www/bintelx.cifrid.com -type f -exec chmod 640 {} \;
find /var/www/bintelx.cifrid.com -type d -exec chmod 750 {} \;
chmod -R 770 /var/www/bintelx.cifrid.com/{storage,log}
```

## 3. Configuración (.env)

```bash
cp /var/www/bintelx.cifrid.com/.env.example /var/www/bintelx.cifrid.com/.env
# Editar con valores de producción (DB, JWT secrets, etc.)
```

### Secrets (recomendado)

```bash
echo -n 'DB_PASSWORD_HERE' > /var/www/bintelx.cifrid.com/secrets/db_password.secret
echo -n 'JWT_SECRET_HERE' > /var/www/bintelx.cifrid.com/secrets/jwt_secret.secret
echo -n 'JWT_XOR_KEY_HERE' > /var/www/bintelx.cifrid.com/secrets/jwt_xor_key.secret
openssl rand -hex 32 > /var/www/bintelx.cifrid.com/secrets/system_secret.secret
chmod 640 /var/www/bintelx.cifrid.com/secrets/*
```

En `.env` referenciar con:

```
SECRET_PLAIN_DB_PASSWORD=secrets/db_password.secret
SECRET_PLAIN_JWT_SECRET=secrets/jwt_secret.secret
SECRET_PLAIN_JWT_XOR_KEY=secrets/jwt_xor_key.secret
SECRET_PLAIN_SYSTEM_SECRET=secrets/system_secret.secret
```

| Secret | Uso |
|--------|-----|
| `db_password.secret` | Contraseña de base de datos |
| `jwt_secret.secret` | Firma de tokens JWT |
| `jwt_xor_key.secret` | Ofuscación adicional de JWT |
| `system_secret.secret` | Auth server-to-server (`ROUTER_SCOPE_SYSTEM` — endpoints `_internal`) |

## 4. Extensiones PHP requeridas

```bash
sudo apt install php8.4-bcmath php8.4-mysql php8.4-xml php8.4-mbstring php8.4-curl \
                 php8.4-igbinary
sudo systemctl reload php8.4-fpm
```

| Extensión | Usado por |
|-----------|-----------|
| `bcmath` | Math.php, PricingEngine, FeeEngine |
| `pdo_mysql` | CONN.php (PDO driver) |
| `xml/dom` | XMLGenerator.php |
| `mbstring` | Manejo de strings multibyte |
| `curl` | Dependencia común de librerías |
| `igbinary` | Cache (SwooleTableBackend) — serialización binaria 2x más rápida y 30% más compacta que JSON |

> `json` y `openssl` vienen built-in en PHP 8.4

## 5. SSL — *.cifrid.com wildcard

```bash
# Wildcard requiere DNS challenge
sudo certbot certonly --manual --preferred-challenges dns -d "*.cifrid.com" -d "cifrid.com"
# Crear registro TXT _acme-challenge.cifrid.com con el valor que entrega certbot
```

## 6. Symlinks

```bash
# nginx
sudo ln -s /var/www/bintelx.cifrid.com/config/nginx.conf /etc/nginx/sites-enabled/bintelx.cifrid.com.conf

# PHP-FPM pool
sudo ln -s /var/www/bintelx.cifrid.com/config/fpm-pool.conf /etc/php/8.4/fpm/pool.d/bintelx.conf
```

## 7. Channel Server (Swoole)

El Channel Server es un proceso Swoole persistente que provee WebSocket (tiempo real) y cache compartido (SwooleTable) para FPM.

### Qué hace

- **WebSocket**: conexiones persistentes para notificaciones en tiempo real
- **Cache compartido**: SwooleTable (64k entries, shared memory entre workers) — FPM accede via HTTP local
- **Endpoints `_internal`**: métricas, flush de cache, status — protegidos por `ROUTER_SCOPE_SYSTEM`

### Iniciar manualmente (desarrollo)

```bash
php app/channel.server.php
```

### Iniciar como servicio (producción)

```bash
sudo ln -s /var/www/bintelx.cifrid.com/config/channel.service \
        /etc/systemd/system/bintelx-channel-cifrid.service

sudo systemctl daemon-reload
sudo systemctl enable bintelx-channel-cifrid
sudo systemctl start bintelx-channel-cifrid
```

### Operaciones

```bash
# Hot reload workers (código de endpoints/package, NO kernel)
kill -USR1 $(pgrep -f 'bintelx-channel-master')        # dev (proceso directo)
sudo systemctl reload bintelx-channel-cifrid            # prod (systemd)

# Restart completo (necesario si cambia código en kernel/ o Cache)
kill $(pgrep -f 'bintelx-channel-master') && php app/channel.server.php   # dev
sudo systemctl restart bintelx-channel-cifrid                              # prod

# Verificar estado
curl -s http://127.0.0.1:8000/api/_internal/metrics \
  -H "X-System-Key: $(cat secrets/system_secret.secret)"

# Test cache
curl -s -X POST http://127.0.0.1:8000/api/_internal/cache/get \
  -H "X-System-Key: $(cat secrets/system_secret.secret)" \
  -H "Content-Type: application/json" \
  -d '{"key":"geo:country:CL"}'
```

### USR1 vs restart completo

| Cambio en... | USR1 (hot reload) | Restart completo |
|---|---|---|
| `package/` endpoints | OK | OK |
| custom modules | OK | OK |
| `bintelx/kernel/` | NO — cargado por master antes del fork | **Necesario** |
| `Cache.php`, `SwooleTableBackend.php` | NO | **Necesario** |

> USR1 preserva SwooleTable y conexiones WS. Restart completo vacía el cache (se repuebla en el primer request).

### Cache bridge FPM → Channel

FPM usa `ChannelCacheBackend` que conecta al Channel via HTTP local (`127.0.0.1:8000`). Incluye circuit breaker (5s) — si el Channel está caído, FPM va directo a DB sin error.

Serialización interna: **igbinary** (`compact_strings=On`). Transporte HTTP: JSON estándar.

## 8. Channel hot-reload (desarrollo)

```bash
# Requiere inotify-tools
sudo apt install inotify-tools

# Auto-reload workers cuando cambian .php en kernel/package/custom
bash config/channel-watch.sh

# Con paths custom adicionales
CUSTOM_PATH=/var/www/erp_labtronic/bintelx bash config/channel-watch.sh

# Reload manual (sin watcher)
kill -USR1 $(pgrep -f 'bintelx-channel-master')
```

El watcher usa `inotifywait` (kernel inotify, zero CPU). Vigila `bintelx/kernel/`, `package/`, y opcionalmente `CUSTOM_PATH`. Envía `SIGUSR1` al master — los workers se recargan sin perder conexiones WS ni Swoole\Tables.

## 9. Verificar y activar

```bash
sudo nginx -t && sudo php-fpm8.4 -t
sudo systemctl reload nginx && sudo systemctl reload php8.4-fpm
```

## Archivos en este directorio

| Archivo | Destino | Servicio |
|---------|---------|----------|
| `nginx.conf` | `ln -s` → `/etc/nginx/sites-enabled/` | nginx |
| `fpm-pool.conf` | `ln -s` → `/etc/php/8.4/fpm/pool.d/` | PHP-FPM |
| `channel.service` | `ln -s` → `/etc/systemd/system/` | systemd (Swoole) |
| `upstream.conf` | incluido por nginx.conf | nginx |
| `ssl.conf` | incluido por nginx.conf | nginx |
| `channel-watch.sh` | `bash config/channel-watch.sh` | desarrollo (inotify) |

## Estructura en producción

```
/var/www/bintelx.cifrid.com/
├── config/                ← deployment (este directorio)
│   ├── nginx.conf
│   ├── upstream.conf
│   ├── ssl.conf
│   ├── fpm-pool.conf
│   ├── channel.service
│   └── README.md
├── bintelx/
│   ├── config/server/
│   │   ├── snippets/      ← compartidos (los incluyen los proyectos)
│   │   │   ├── proxy.conf
│   │   │   ├── location-rules.conf
│   │   │   ├── security.conf
│   │   │   └── upstreams-realtime.conf
│   │   └── dhparam.pem
│   ├── kernel/            ← código PHP compartido
│   └── WarmUp.php
├── app/
│   ├── api.php            ← entry point (FPM)
│   └── channel.server.php ← entry point (Swoole — WebSocket + Cache)
├── package/               ← módulos base
├── .env                   ← config + secrets (no en repo)
├── secrets/               ← archivos de secrets
├── storage/               ← uploads (shared)
└── log/                   ← logs kernel
```
