#!/usr/bin/env bash
#
# setup-instance.sh - Crear instancia de Bintelx
# Permite múltiples instancias paralelas con diferentes sockets PHP-FPM
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

print_header() { echo -e "\n${BLUE}╔═══════════════════════════════════════╗${NC}"; echo -e "${BLUE}║  $1${NC}"; echo -e "${BLUE}╚═══════════════════════════════════════╝${NC}\n"; }
print_success() { echo -e "${GREEN}✓ $1${NC}"; }
print_error() { echo -e "${RED}✗ $1${NC}"; }
print_info() { echo -e "${CYAN}→ $1${NC}"; }
print_warning() { echo -e "${YELLOW}⚠ $1${NC}"; }

# Project root
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROJECT_NAME=$(basename "$PROJECT_ROOT")
ENV_FILE="${PROJECT_ROOT}/.env"

# Load system configuration from .env
if [ -f "$ENV_FILE" ]; then
    SYSTEM_WEB_USER=$(grep ^SYSTEM_WEB_USER "$ENV_FILE" | cut -d'=' -f2 | tr -d '"' | tr -d "'")
    SYSTEM_WEB_GROUP=$(grep ^SYSTEM_WEB_GROUP "$ENV_FILE" | cut -d'=' -f2 | tr -d '"' | tr -d "'")
fi
SYSTEM_WEB_USER=${SYSTEM_WEB_USER:-www-data}
SYSTEM_WEB_GROUP=${SYSTEM_WEB_GROUP:-www-data}

clear
print_header "Configurador de Instancia Bintelx"

echo -e "${CYAN}Directorio actual:${NC} ${PROJECT_ROOT}"
echo ""

# ==========================================
# DETERMINAR NOMBRE DE INSTANCIA
# ==========================================
print_info "Determinando nombre de instancia..."
echo ""

# Intentar detectar desde el nombre del directorio
if [[ "$PROJECT_NAME" =~ bintelx-(.+) ]]; then
    SUGGESTED_INSTANCE="${BASH_REMATCH[1]}"
else
    SUGGESTED_INSTANCE="default"
fi

read -p "Nombre de esta instancia [${SUGGESTED_INSTANCE}]: " INSTANCE_NAME
INSTANCE_NAME=${INSTANCE_NAME:-$SUGGESTED_INSTANCE}

# Sanitize instance name
INSTANCE_NAME=$(echo "$INSTANCE_NAME" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9_-]//g')

print_success "Instancia: ${INSTANCE_NAME}"

# ==========================================
# DETECTAR INSTANCIAS EXISTENTES
# ==========================================
print_header "Detectando Instancias Existentes"

EXISTING_INSTANCES=()

# Check PHP-FPM pools
if [ -d "/etc/php/8.4/fpm/pool.d" ]; then
    for pool in /etc/php/8.4/fpm/pool.d/bintelx-*.conf; do
        if [ -f "$pool" ]; then
            pool_name=$(basename "$pool" .conf)
            EXISTING_INSTANCES+=("$pool_name")
        fi
    done
fi

if [ ${#EXISTING_INSTANCES[@]} -gt 0 ]; then
    echo "Instancias existentes detectadas:"
    printf '  - %s\n' "${EXISTING_INSTANCES[@]}"
    echo ""
else
    print_info "No se detectaron instancias previas"
    echo ""
fi

# Check if this instance already exists
POOL_NAME="bintelx-${INSTANCE_NAME}"

if printf '%s\n' "${EXISTING_INSTANCES[@]}" | grep -q "^${POOL_NAME}$"; then
    print_warning "La instancia '${INSTANCE_NAME}' ya existe"
    read -p "¿Desea reconfigurarla? [y/N]: " reconfigure
    if [[ ! "$reconfigure" =~ ^[Yy]$ ]]; then
        exit 0
    fi
fi

# ==========================================
# CONFIGURAR PHP-FPM POOL
# ==========================================
print_header "Configurando PHP-FPM Pool"

# Determinar versión de PHP
PHP_VERSION="8.4"
if ! [ -d "/etc/php/${PHP_VERSION}/fpm/pool.d" ]; then
    PHP_VERSION="8.3"
    if ! [ -d "/etc/php/${PHP_VERSION}/fpm/pool.d" ]; then
        PHP_VERSION="8.2"
    fi
fi

print_info "Usando PHP ${PHP_VERSION}"

# Pool configuration
FPM_POOL_CONF="/etc/php/${PHP_VERSION}/fpm/pool.d/${POOL_NAME}.conf"
FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm-${INSTANCE_NAME}.sock"

print_info "Socket: ${FPM_SOCKET}"
echo ""

# Puerto sugerido (para múltiples instancias)
BASE_PORT=9000
INSTANCE_PORT=$((BASE_PORT + ${#EXISTING_INSTANCES[@]} + 1))

read -p "¿Usar socket (s) o puerto TCP (t)? [S/t]: " use_socket
use_socket=${use_socket:-s}

if [[ "$use_socket" =~ ^[Tt]$ ]]; then
    read -p "Puerto TCP [${INSTANCE_PORT}]: " FPM_PORT
    FPM_PORT=${FPM_PORT:-$INSTANCE_PORT}
    FPM_LISTEN="127.0.0.1:${FPM_PORT}"
else
    FPM_LISTEN="$FPM_SOCKET"
fi

# Create PHP-FPM pool config
print_info "Creando configuración de pool..."

sudo tee "$FPM_POOL_CONF" > /dev/null <<EOF
[${POOL_NAME}]
user = ${SYSTEM_WEB_USER}
group = ${SYSTEM_WEB_GROUP}

listen = ${FPM_LISTEN}
$(if [[ "$FPM_LISTEN" == *".sock" ]]; then
cat <<SOCK_EOF
listen.owner = ${SYSTEM_WEB_USER}
listen.group = ${SYSTEM_WEB_GROUP}
listen.mode = 0660
SOCK_EOF
fi)

; Pool configuration
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500

; Logging
php_admin_value[error_log] = ${PROJECT_ROOT}/log/php-fpm-error.log
php_admin_flag[log_errors] = on

; PHP settings
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 10M
php_admin_value[max_execution_time] = 300

; Working directory
chdir = ${PROJECT_ROOT}

; Environment variables (optional, can also use .env)
;env[APP_ENV] = production
EOF

print_success "Pool configurado: ${POOL_NAME}"

# Test PHP-FPM configuration
print_info "Probando configuración PHP-FPM..."
if sudo php-fpm${PHP_VERSION} -t &>/dev/null; then
    print_success "Configuración válida"
else
    print_error "Configuración inválida"
    sudo php-fpm${PHP_VERSION} -t
    exit 1
fi

# Restart PHP-FPM
print_info "Reiniciando PHP-FPM..."
sudo systemctl restart php${PHP_VERSION}-fpm

# Check if socket was created
sleep 1
if [[ "$FPM_LISTEN" == *".sock" ]]; then
    if [ -S "$FPM_LISTEN" ]; then
        print_success "Socket creado: ${FPM_LISTEN}"
    else
        print_error "Socket no creado. Verifique logs:"
        echo "  sudo tail -f /var/log/php${PHP_VERSION}-fpm.log"
        exit 1
    fi
else
    print_success "Puerto TCP: ${FPM_LISTEN}"
fi

# ==========================================
# CONFIGURAR NGINX SITE
# ==========================================
print_header "Configurando Sitio Nginx"

# Load domain from .env
if [ -f "$ENV_FILE" ]; then
    APP_URL=$(grep ^APP_URL "$ENV_FILE" | cut -d'=' -f2)
    DOMAIN=$(echo "$APP_URL" | sed 's~https\?://~~g' | cut -d'/' -f1)
else
    DOMAIN="bintelx-${INSTANCE_NAME}.local"
fi

read -p "Dominio para esta instancia [${DOMAIN}]: " SITE_DOMAIN
SITE_DOMAIN=${SITE_DOMAIN:-$DOMAIN}

print_info "Dominio: ${SITE_DOMAIN}"

# Nginx site name
NGINX_SITE_NAME="bintelx-${INSTANCE_NAME}"
NGINX_CONF="${PROJECT_ROOT}/bintelx/config/server/nginx.${NGINX_SITE_NAME}.conf"
NGINX_AVAILABLE="/etc/nginx/sites-available/${NGINX_SITE_NAME}"
NGINX_ENABLED="/etc/nginx/sites-enabled/${NGINX_SITE_NAME}"

# Create nginx config
print_info "Creando configuración nginx..."

mkdir -p "$(dirname "$NGINX_CONF")"

cat > "$NGINX_CONF" <<'NGINX_EOF'
# Bintelx Nginx Configuration
# Instance: INSTANCE_NAME
# Generated: TIMESTAMP

server {
    listen 80;
    listen [::]:80;
    server_name SERVER_NAME;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name SERVER_NAME;
    root PROJECT_ROOT/app;
    index index.php index.html;

    # SSL Configuration
    ssl_certificate PROJECT_ROOT/ssl/cert.pem;
    ssl_certificate_key PROJECT_ROOT/ssl/key.pem;
    ssl_dhparam PROJECT_ROOT/ssl/dhparam.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000" always;

    # Logging
    access_log PROJECT_ROOT/log/nginx-access.log;
    error_log PROJECT_ROOT/log/nginx-error.log warn;

    # API Endpoint
    location /api/ {
        try_files $uri $uri/ /api.php?$query_string;

        location ~ \.php$ {
            try_files $uri =404;
            fastcgi_pass FPM_BACKEND;
            fastcgi_index api.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
            fastcgi_read_timeout 300s;
        }
    }

    # Health check
    location = /health {
        access_log off;
        return 200 "OK - Instance: INSTANCE_NAME\n";
        add_header Content-Type text/plain;
    }

    # Deny sensitive files
    location ~ /\.(env|git|htaccess) { deny all; return 404; }
    location ^~ /log/ { deny all; return 404; }
    location ^~ /bintelx/ { deny all; return 404; }
    location ^~ /install/ { deny all; return 404; }

    # Static files
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg)$ {
        expires 1y;
        access_log off;
    }
}
NGINX_EOF

# Replace placeholders
sed -i "s|SERVER_NAME|${SITE_DOMAIN}|g" "$NGINX_CONF"
sed -i "s|PROJECT_ROOT|${PROJECT_ROOT}|g" "$NGINX_CONF"
sed -i "s|INSTANCE_NAME|${INSTANCE_NAME}|g" "$NGINX_CONF"
sed -i "s|TIMESTAMP|$(date)|g" "$NGINX_CONF"

if [[ "$FPM_LISTEN" == *".sock" ]]; then
    sed -i "s|FPM_BACKEND|unix:${FPM_LISTEN}|g" "$NGINX_CONF"
else
    sed -i "s|FPM_BACKEND|${FPM_LISTEN}|g" "$NGINX_CONF"
fi

print_success "Configuración creada: ${NGINX_CONF}"

# Create symlinks
print_info "Creando enlaces simbólicos..."

sudo ln -sf "$NGINX_CONF" "$NGINX_AVAILABLE"
sudo ln -sf "$NGINX_AVAILABLE" "$NGINX_ENABLED"

print_success "Sitio habilitado: ${NGINX_SITE_NAME}"

# Test nginx
print_info "Probando configuración nginx..."
if sudo nginx -t 2>&1 | grep -q "successful"; then
    print_success "Configuración válida"

    # Reload nginx
    print_info "Recargando nginx..."
    sudo systemctl reload nginx
    print_success "Nginx recargado"
else
    print_error "Configuración inválida"
    sudo nginx -t
    exit 1
fi

# ==========================================
# RESUMEN
# ==========================================
print_header "Instancia Configurada"

echo ""
echo -e "${GREEN}✓ Instancia:${NC} ${INSTANCE_NAME}"
echo -e "${GREEN}✓ Directorio:${NC} ${PROJECT_ROOT}"
echo -e "${GREEN}✓ Dominio:${NC} ${SITE_DOMAIN}"
echo ""
echo -e "${CYAN}PHP-FPM:${NC}"
echo -e "  Pool: ${POOL_NAME}"
echo -e "  Listen: ${FPM_LISTEN}"
echo -e "  Config: ${FPM_POOL_CONF}"
echo ""
echo -e "${CYAN}Nginx:${NC}"
echo -e "  Site: ${NGINX_SITE_NAME}"
echo -e "  Config: ${NGINX_CONF}"
echo -e "  Enabled: ${NGINX_ENABLED}"
echo ""

# Database info from .env
if [ -f "$ENV_FILE" ]; then
    DB_NAME=$(grep ^DB_DATABASE "$ENV_FILE" | cut -d'=' -f2)
    echo -e "${CYAN}Base de Datos:${NC} ${DB_NAME}"
    echo ""
fi

echo -e "${YELLOW}Próximos pasos:${NC}"
echo ""
echo "  1. Agregar dominio a /etc/hosts (si es local):"
echo "     echo '127.0.0.1 ${SITE_DOMAIN}' | sudo tee -a /etc/hosts"
echo ""
echo "  2. Probar la instancia:"
echo "     curl -k https://${SITE_DOMAIN}/health"
echo ""
echo "  3. Ver logs:"
echo "     tail -f ${PROJECT_ROOT}/log/nginx-error.log"
echo "     tail -f ${PROJECT_ROOT}/log/php-fpm-error.log"
echo ""

print_success "¡Instancia lista!"
