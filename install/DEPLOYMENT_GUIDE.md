# 📖 Guía de Despliegue Bintelx

Esta guía explica cómo usar el sistema de instalación en diferentes escenarios.

---

## 🎯 ¿Qué Script Usar?

| Escenario | Script | Seguridad |
|-----------|--------|-----------|
| **Instalación nueva** (servidor limpio) | `install.sh` | ✅ Seguro |
| **Actualizar sistema existente** (producción) | `update.sh` | ✅ Seguro (hace backup) |
| **Instancia paralela** (múltiples sites) | `install.sh --instance` | ✅ Seguro |
| **Solo verificar** | `modules/verify.sh` | ✅ Solo lectura |

---

## 📦 ESCENARIO 1: Instalación Nueva (Servidor Limpio)

**Cuando usar:** Primera vez, servidor sin nginx/mysql o VM nueva

```bash
# Clonar proyecto
git clone <repo> /var/www/bintelx
cd /var/www/bintelx/install

# Instalación completa
sudo bash install.sh
```

**Lo que hace:**
1. ✓ Instala dependencias (PHP, MySQL, Nginx)
2. ✓ Crea .env interactivamente
3. ✓ Genera certificados SSL
4. ✓ Configura Nginx
5. ✓ Importa schemas SQL
6. ✓ Verifica instalación

**Tiempo estimado:** 10-15 minutos (incluye preguntas)

---

## 🔄 ESCENARIO 2: Actualizar Sistema Existente

**Cuando usar:** Ya tienes Bintelx funcionando y quieres actualizar

```bash
cd /var/www/bintelx/install

# Actualización SEGURA (hace backup automático)
sudo bash update.sh
```

**Lo que hace:**
1. ✓ **Backup automático completo**
2. ✓ Verifica dependencias
3. ✓ Valida .env (NO modifica)
4. ✓ Git pull (actualiza código)
5. ✓ Reinicia servicios (reload, no restart)
6. ✓ Verifica que todo funcione

**Diferencias con install.sh:**
- ❌ NO sobrescribe .env
- ❌ NO sobrescribe nginx
- ❌ NO importa schemas SQL
- ✅ Hace backup antes de cada cambio
- ✅ Puedes hacer rollback si algo falla

**Seguro para producción:** ✅ SÍ

**Ejemplo en producción:**
```bash
# 1. Backup + update
sudo bash update.sh

# 2. Si algo falla, rollback
sudo bash modules/rollback.sh

# 3. Verificar
bash modules/verify.sh
```

---

## 🏢 ESCENARIO 3: Múltiples Instancias (Multi-site)

**Cuando usar:** Ya tienes nginx/mysql y quieres otra instancia de Bintelx

**Arquitectura:**
```
/var/www/bintelx-production/  → php-fpm-production.sock → api.example.com
/var/www/bintelx-staging/     → php-fpm-staging.sock    → staging.example.com
/var/www/bintelx-dev/          → php-fpm-dev.sock        → dev.local
```

**Instalación:**

```bash
# 1. Clonar en directorio específico
git clone <repo> /var/www/bintelx-staging
cd /var/www/bintelx-staging

# 2. Crear .env para esta instancia
cp .env.example .env
nano .env  # Editar:
           # DB_DATABASE=bnx_staging
           # APP_URL=https://staging.example.com

# 3. Configurar como instancia
cd install
sudo bash modules/setup-instance.sh
# → Preguntará: Nombre de instancia: staging
# → Creará: php-fpm pool "bintelx-staging"
# → Creará: nginx site "bintelx-staging"

# 4. Setup SSL para esta instancia
bash modules/setup-ssl.sh

# 5. Importar schemas (base de datos propia)
bash modules/setup-db.sh

# 6. Verificar
bash modules/verify.sh
```

**Resultado:**
- ✓ Pool PHP-FPM independiente: `bintelx-staging`
- ✓ Socket independiente: `/run/php/php8.4-fpm-staging.sock`
- ✓ Sitio Nginx independiente: `/etc/nginx/sites-enabled/bintelx-staging`
- ✓ Base de datos independiente: `bnx_staging`
- ✓ Certificados SSL independientes: `/var/www/bintelx-staging/ssl/`
- ✓ Logs independientes: `/var/www/bintelx-staging/log/`

**Ventajas:**
- Sin conflictos entre instancias
- Actualizar una no afecta las otras
- Diferentes versiones de código
- Bases de datos separadas
- Pools PHP-FPM aislados

---

## 🛡️ ESCENARIO 4: Actualización con Backup Manual

**Cuando usar:** Quieres control total del backup

```bash
cd /var/www/bintelx/install

# 1. Hacer backup manualmente
sudo bash modules/backup.sh

# 2. Actualizar (sin backup automático)
sudo bash update.sh --skip-backup

# 3. Si algo falla, rollback
sudo bash modules/rollback.sh
# → Te mostrará lista de backups disponibles
```

---

## 🔍 ESCENARIO 5: Solo Verificar (No Modificar)

**Cuando usar:** Quieres ver el estado sin hacer cambios

```bash
cd /var/www/bintelx/install

# Verificar dependencias
bash modules/check-deps.sh

# Verificar instalación completa
bash modules/verify.sh
```

**Salida ejemplo:**
```
Testing: .env file exists... ✓ OK
Testing: SSL certificate exists... ✓ OK
Testing: PHP version >= 8.1... ✓ OK
Testing: Database connection... ✓ OK
Testing: Nginx is running... ✓ OK

✓ Passed:  28/30
⚠ Warnings: 2/30

╔═══════════════════════════════════════╗
║  ✓ INSTALLATION VERIFIED SUCCESSFULLY ║
╚═══════════════════════════════════════╝
```

---

## 🚨 ESCENARIO 6: Rollback (Revertir Cambios)

**Cuando usar:** Algo salió mal y necesitas volver atrás

```bash
cd /var/www/bintelx/install

# Ver backups disponibles
sudo bash modules/rollback.sh

# Restaurar backup específico
sudo bash modules/rollback.sh 20251112_193000
```

**Lo que restaura:**
- ✓ Archivo `.env`
- ✓ Certificados SSL
- ✓ Configuración Nginx
- ✓ Base de datos (opcional, pregunta)

---

## 🎛️ Opciones Avanzadas

### Dry-run (Simular sin Ejecutar)

```bash
# Ver qué haría update.sh sin ejecutar
sudo bash update.sh --dry-run
```

### Skip Modules (Saltar Pasos)

```bash
# Solo actualizar código, sin tocar servicios
sudo bash update.sh --skip-services

# Instalación solo de nginx y SSL
sudo bash install.sh --skip-deps --skip-env --skip-db
```

### Force (Sin Confirmaciones)

```bash
# Actualización automática sin preguntas
sudo bash update.sh --force
```

---

## 📊 Comparación de Scripts

| Característica | install.sh | update.sh | setup-instance.sh |
|----------------|-----------|-----------|-------------------|
| Instala dependencias | ✅ | ❌ | ❌ |
| Crea .env | ✅ Interactivo | ❌ Solo valida | ⚠️ Reusa existente |
| Genera SSL | ✅ | ❌ | ✅ |
| Configura Nginx | ✅ | ❌ | ✅ Con pool propio |
| Importa SQL | ✅ | ❌ | ⚠️ Opcional |
| Hace backup | ❌ | ✅ Automático | ❌ |
| Seguro en producción | ⚠️ | ✅ | ✅ |
| Crea PHP-FPM pool | ✅ Único | ❌ | ✅ Por instancia |

---

## 🏗️ Arquitectura Multi-Instancia Completa

### Ejemplo: 3 Instancias

```
SERVIDOR (nginx + mysql compartidos)
│
├─ /var/www/bintelx-production/
│  ├── .env (DB: bnx_production, URL: api.example.com)
│  ├── ssl/ → letsencrypt
│  └── PHP-FPM: /run/php/php8.4-fpm-production.sock
│
├─ /var/www/bintelx-staging/
│  ├── .env (DB: bnx_staging, URL: staging.example.com)
│  ├── ssl/ → letsencrypt
│  └── PHP-FPM: /run/php/php8.4-fpm-staging.sock
│
└─ /var/www/bintelx-dev/
   ├── .env (DB: bnx_dev, URL: dev.local)
   ├── ssl/ → self-signed
   └── PHP-FPM: /run/php/php8.4-fpm-dev.sock

NGINX
├── /etc/nginx/sites-enabled/bintelx-production → api.example.com
├── /etc/nginx/sites-enabled/bintelx-staging   → staging.example.com
└── /etc/nginx/sites-enabled/bintelx-dev       → dev.local

MYSQL
├── bnx_production (tablas de producción)
├── bnx_staging (tablas de staging)
└── bnx_dev (tablas de desarrollo)
```

### Setup de 3 Instancias:

```bash
# === INSTANCIA 1: PRODUCTION ===
git clone <repo> /var/www/bintelx-production
cd /var/www/bintelx-production
cp .env.example .env
nano .env  # DB_DATABASE=bnx_production, APP_URL=https://api.example.com
cd install
sudo bash modules/setup-instance.sh  # Nombre: production
bash modules/setup-ssl.sh  # Opción 2: Let's Encrypt
bash modules/setup-db.sh

# === INSTANCIA 2: STAGING ===
git clone <repo> /var/www/bintelx-staging
cd /var/www/bintelx-staging
cp .env.example .env
nano .env  # DB_DATABASE=bnx_staging, APP_URL=https://staging.example.com
cd install
sudo bash modules/setup-instance.sh  # Nombre: staging
bash modules/setup-ssl.sh  # Opción 2: Let's Encrypt
bash modules/setup-db.sh

# === INSTANCIA 3: DEV ===
git clone <repo> /var/www/bintelx-dev
cd /var/www/bintelx-dev
cp .env.example .env
nano .env  # DB_DATABASE=bnx_dev, APP_URL=https://dev.local
cd install
sudo bash modules/setup-instance.sh  # Nombre: dev
bash modules/setup-ssl.sh  # Opción 1: Self-signed
bash modules/setup-db.sh
```

**Resultado:**
- 3 instancias completamente aisladas
- Mismo servidor, mismo nginx, mismo mysql
- Pools PHP-FPM independientes
- Bases de datos independientes
- Código independiente (diferentes ramas git)

---

## 🔧 Mantenimiento

### Actualizar Instancia Específica

```bash
cd /var/www/bintelx-staging
git pull origin staging-branch

# Reiniciar solo su pool PHP-FPM
sudo systemctl restart php8.4-fpm  # Reinicia todos los pools
# O editar /etc/php/8.4/fpm/pool.d/bintelx-staging.conf si necesitas cambios
```

### Ver Estado de Todas las Instancias

```bash
# PHP-FPM pools activos
sudo systemctl status php8.4-fpm | grep bintelx

# Nginx sites habilitados
ls -l /etc/nginx/sites-enabled/ | grep bintelx

# Sockets activos
ls -lh /run/php/*.sock | grep bintelx
```

### Eliminar Instancia

```bash
INSTANCE="staging"

# 1. Deshabilitar nginx site
sudo rm /etc/nginx/sites-enabled/bintelx-${INSTANCE}
sudo systemctl reload nginx

# 2. Eliminar PHP-FPM pool
sudo rm /etc/php/8.4/fpm/pool.d/bintelx-${INSTANCE}.conf
sudo systemctl restart php8.4-fpm

# 3. Backup y eliminar BD (opcional)
mysqldump bnx_${INSTANCE} > backup_${INSTANCE}.sql
mysql -e "DROP DATABASE bnx_${INSTANCE}"

# 4. Eliminar directorio (opcional)
sudo rm -rf /var/www/bintelx-${INSTANCE}
```

---

## 📋 Checklist de Despliegue

### ✅ Pre-Despliegue
- [ ] Servidor tiene acceso a internet
- [ ] DNS configurado (si usando dominio público)
- [ ] Puerto 80 y 443 abiertos en firewall
- [ ] Credenciales de BD disponibles
- [ ] Backup del sistema existente (si aplica)

### ✅ Durante Despliegue
- [ ] `.env` configurado correctamente
- [ ] Certificados SSL válidos
- [ ] Nginx test pasa: `sudo nginx -t`
- [ ] PHP-FPM corriendo: `systemctl status php8.4-fpm`
- [ ] MySQL accesible: `mysql -u user -p`

### ✅ Post-Despliegue
- [ ] Verificación pasa: `bash modules/verify.sh`
- [ ] Health endpoint responde: `curl https://domain/health`
- [ ] Logs sin errores: `tail -f log/nginx-error.log`
- [ ] API responde: `curl https://domain/api/`

---

## 🆘 Troubleshooting

### Error: "nginx: configuration test failed"
```bash
# Ver error específico
sudo nginx -t

# Verificar sintaxis del config
cat /var/www/bintelx/bintelx/config/server/nginx.bintelx.conf

# Verificar paths de SSL
ls -lh /var/www/bintelx/ssl/

# Rollback si es necesario
sudo bash modules/rollback.sh
```

### Error: "Database connection failed"
```bash
# Verificar MySQL corriendo
sudo systemctl status mysql

# Probar credenciales
mysql -h127.0.0.1 -uUSER -pPASS bnx_database -e "SELECT 1"

# Verificar .env
cat .env | grep DB_
```

### Error: "PHP-FPM socket not found"
```bash
# Ver pools configurados
ls -l /etc/php/8.4/fpm/pool.d/

# Ver sockets activos
ls -lh /run/php/*.sock

# Verificar config de pool
cat /etc/php/8.4/fpm/pool.d/bintelx-instance.conf

# Reiniciar PHP-FPM
sudo systemctl restart php8.4-fpm

# Ver logs
sudo tail -f /var/log/php8.4-fpm.log
```

---

**Autor:** Sistema de instalación Bintelx
**Versión:** 1.0.0
**Última actualización:** 2025-11-12
