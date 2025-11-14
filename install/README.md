# Sistema de Instalación de Bintelx

Sistema modular y automatizado para instalar y configurar Bintelx Framework.

## 🚀 Instalación Rápida

```bash
cd /var/www/bintelx/install
sudo bash install.sh
```

El instalador te guiará paso a paso por todo el proceso.

---

## 📋 Arquitectura Modular

El sistema de instalación está dividido en módulos independientes:

```
install/
├── install.sh                  # Orquestador principal
└── modules/
    ├── check-deps.sh           # Verifica dependencias
    ├── wizard-env.sh           # Crea .env interactivamente
    ├── setup-ssl.sh            # Configura certificados SSL
    ├── setup-nginx.sh          # Configura Nginx con symlinks
    ├── setup-db.sh             # Importa esquemas de BD
    └── verify.sh               # Verifica la instalación
```

Cada módulo puede ejecutarse individualmente o saltarse con flags.

---

## 🎯 Opciones de Instalación

### Instalación Completa (Recomendado)
```bash
sudo bash install.sh
```

### Instalación Parcial
Omitir pasos específicos:

```bash
# Solo configurar Nginx y SSL (asume que .env ya existe)
sudo bash install.sh --skip-deps --skip-env --skip-db

# Solo base de datos
sudo bash install.sh --skip-deps --skip-env --skip-ssl --skip-nginx

# Instalar sin verificación final
sudo bash install.sh --skip-verify
```

### Modo Desatendido
Para scripts automatizados (usa valores por defecto):

```bash
sudo bash install.sh --unattended
```

### Ayuda
```bash
bash install.sh --help
```

---

## 📚 Módulos en Detalle

### 1. check-deps.sh - Verificar Dependencias

**¿Qué hace?**
- Detecta PHP, MySQL, Nginx instalados
- **No reinstala** si ya existen
- Sugiere actualizaciones si la versión es antigua
- Ofrece instalar dependencias faltantes

**Ejecutar manualmente:**
```bash
bash modules/check-deps.sh
```

**Dependencias verificadas:**
- PHP 8.1+ con extensiones (pdo_mysql, mbstring, json, curl, xml)
- PHP-FPM
- MySQL/MariaDB
- Nginx
- OpenSSL
- Certbot (opcional)

---

### 2. wizard-env.sh - Asistente de Configuración

**¿Qué hace?**
- Guía paso a paso para crear `.env`
- Valida credenciales de base de datos en tiempo real
- Genera claves JWT seguras automáticamente
- Configura CORS, timezone, rutas

**Ejecutar manualmente:**
```bash
bash modules/wizard-env.sh
```

**Variables configuradas:**
- `APP_ENV`, `APP_DEBUG`, `APP_URL`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `JWT_SECRET`, `JWT_XOR_KEY`, `JWT_EXPIRATION`
- `CORS_ALLOWED_ORIGINS`, `CORS_ALLOWED_METHODS`
- `DEFAULT_TIMEZONE`
- `LOG_PATH`, `UPLOAD_PATH`

**Salida:**
- Archivo `.env` con permisos 600
- Directorios `log/` y `uploads/` creados

---

### 3. setup-ssl.sh - Certificados SSL

**¿Qué hace?**
- Ofrece 3 opciones: auto-firmado, Let's Encrypt, o existentes
- **Usa enlaces simbólicos** para Let's Encrypt (auto-renovación)
- Genera `dhparam.pem` para seguridad
- Genera `quic_host_key_file` para HTTP/3

**Ejecutar manualmente:**
```bash
bash modules/setup-ssl.sh
```

**Opciones disponibles:**

#### Opción 1: Auto-firmado (Desarrollo)
```bash
# Seleccionar opción 1 en el menú
# Genera certificado válido por 365 días
```

Resultado:
```
ssl/
├── cert.pem
├── key.pem
├── dhparam.pem
└── quic_host_key_file
```

#### Opción 2: Let's Encrypt (Producción)
```bash
# Seleccionar opción 2 en el menú
# Requiere dominio público válido
```

**Arquitectura con Symlinks:**
```
ssl/
├── cert.pem -> /etc/letsencrypt/live/domain.com/fullchain.pem
├── key.pem -> /etc/letsencrypt/live/domain.com/privkey.pem
├── dhparam.pem
└── quic_host_key_file
```

**Ventajas:**
- Auto-renovación funciona sin tocar el proyecto
- Certbot renueva en `/etc/letsencrypt/`
- Nginx automáticamente usa el certificado renovado

#### Opción 3: Certificados Existentes
```bash
# Seleccionar opción 3 en el menú
# Ingresar rutas de certificados existentes
# Elegir copiar o symlink
```

---

### 4. setup-nginx.sh - Configuración de Nginx

**¿Qué hace?**
- Detecta nginx existente y versión
- Detecta soporte HTTP/3 (QUIC)
- Genera configuración desde plantilla
- **Usa enlaces simbólicos** para mantener aislamiento
- Configura PHP-FPM socket automáticamente

**Ejecutar manualmente:**
```bash
bash modules/setup-nginx.sh
```

**Arquitectura de Symlinks:**
```
/var/www/bintelx/
└── bintelx/config/server/
    └── nginx.bintelx.conf       # Configuración generada

/etc/nginx/
├── sites-available/
│   └── bintelx -> /var/www/bintelx/bintelx/config/server/nginx.bintelx.conf
└── sites-enabled/
    └── bintelx -> /etc/nginx/sites-available/bintelx
```

**Ventajas:**
- Editas en el proyecto, no en `/etc/nginx/`
- Git puede versionar la configuración
- Fácil rollback
- Certificados SSL aislados en `ssl/` del proyecto

**Configuración generada:**
- Redirección HTTP → HTTPS
- HTTP/2 habilitado
- HTTP/3 (si nginx lo soporta)
- Security headers
- FastCGI para `/api/`
- Protección de archivos sensibles (.env, .git, etc.)
- Health check endpoint

---

### 5. setup-db.sh - Base de Datos

**¿Qué hace?**
- Verifica conexión a MySQL
- Crea base de datos si no existe
- Detecta e importa esquemas SQL
- Carga datos de zona horaria

**Ejecutar manualmente:**
```bash
bash modules/setup-db.sh
```

**Esquemas detectados e importados:**
1. `bintelx/config/server/schema.sql` - Core (snapshot, entity, order)
2. `bintelx/doc/DataCaptureService.sql` - Sistema EAV versionado
3. `custom/cdc/cdc.sql` - Módulo CDC (si existe)

**Seguridad:**
- Pregunta antes de sobrescribir si ya hay tablas
- Muestra errores de importación
- Permite continuar si falla un esquema

---

### 6. verify.sh - Verificación

**¿Qué hace?**
- Ejecuta +30 tests automáticos
- Verifica archivos, permisos, servicios
- Prueba conexiones (DB, HTTP)
- Genera reporte detallado

**Ejecutar manualmente:**
```bash
bash modules/verify.sh
```

**Tests realizados:**
- ✓ Archivos de configuración (.env, WarmUp.php, Config.php)
- ✓ Certificados SSL y validez
- ✓ PHP y extensiones requeridas
- ✓ PHP-FPM corriendo
- ✓ Conexión a base de datos
- ✓ Tablas creadas
- ✓ Nginx configuración válida
- ✓ Sitio habilitado
- ✓ Permisos de directorios
- ✓ Clase Config funciona
- ✓ Conexión PHP → MySQL
- ✓ Endpoints HTTP responden

**Resultado:**
```
✓ Passed:  28/30
⚠ Warnings: 2/30
✗ Failed:  0/30

╔═══════════════════════════════════════╗
║  ✓ INSTALLATION VERIFIED SUCCESSFULLY ║
╚═══════════════════════════════════════╝
```

---

## 🔧 Ejecución Individual de Módulos

Si ya completaste algunos pasos, puedes ejecutar solo lo que necesitas:

### Re-generar .env
```bash
cd install
bash modules/wizard-env.sh
```

### Solo SSL
```bash
cd install
bash modules/setup-ssl.sh
```

### Solo Nginx
```bash
cd install
bash modules/setup-nginx.sh
```

### Solo Base de Datos
```bash
cd install
bash modules/setup-db.sh
```

### Solo Verificar
```bash
cd install
bash modules/verify.sh
```

---

## 🎨 Características del Sistema

### ✅ Inteligente
- Detecta servicios existentes
- No reinstala innecesariamente
- Sugiere mejoras (ej: HTTP/3)

### ✅ Flexible
- Cada módulo independiente
- Múltiples opciones (SSL, etc.)
- Flags para saltar pasos

### ✅ Seguro
- Valida entradas
- Permisos correctos (600 para .env)
- Backups automáticos

### ✅ Aislado
- Symlinks mantienen proyecto separado
- Certificados en `ssl/` del proyecto
- Config nginx en el proyecto

### ✅ Informativo
- Output colorido
- Mensajes claros
- Ayuda contextual

---

## 🐛 Solución de Problemas

### Error: "nginx -t failed"
```bash
# Ver detalles del error
sudo nginx -t

# Editar configuración
nano /var/www/bintelx/bintelx/config/server/nginx.bintelx.conf

# Volver a probar
sudo nginx -t
sudo systemctl reload nginx
```

### Error: "Database connection failed"
```bash
# Verificar MySQL corriendo
sudo systemctl status mysql

# Verificar credenciales en .env
cat .env | grep DB_

# Probar conexión manual
mysql -h127.0.0.1 -uUSER -pPASS database_name
```

### Error: "PHP extension missing"
```bash
# Ver extensiones instaladas
php -m

# Instalar extensiones faltantes
sudo apt install php8.4-mysql php8.4-mbstring php8.4-curl
sudo systemctl restart php8.4-fpm
```

### Advertencia: "HTTP/3 not supported"
Nginx estándar no incluye HTTP/3. Para compilar con soporte:

```bash
# Ver documentación de compilación custom
cat /var/www/bintelx/bintelx/config/server/install.sh
```

---

## 📖 Documentación Adicional

- **Migración a .env:** `/var/www/bintelx/MIGRATION_TO_ENV.md`
- **Arquitectura Bintelx:** `/var/www/bintelx/bintelx/doc/CoreRelations.md`
- **DataCaptureService:** `/var/www/bintelx/bintelx/doc/DataCaptureService.md`
- **Router:** `/var/www/bintelx/bintelx/doc/Router.md`

---

## 🆘 Soporte

Si encuentras problemas:

1. Ejecuta el verificador: `bash modules/verify.sh`
2. Revisa logs: `tail -f /var/www/bintelx/log/bintelx.log`
3. Revisa logs de nginx: `sudo tail -f /var/log/nginx/error.log`
4. Revisa logs de PHP-FPM: `sudo tail -f /var/log/php8.4-fpm.log`

---

## 📝 Notas

- **Requiere sudo:** La mayoría de operaciones necesitan privilegios root
- **Backup automático:** Si re-ejecutas, se hace backup de .env
- **Symlinks:** Se usan para mantener aislamiento y facilitar updates
- **Let's Encrypt:** Renovación automática funciona sin intervención

---

**Versión:** 1.0.0
**Última actualización:** 2025-11-12
