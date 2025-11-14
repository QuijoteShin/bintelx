#!/usr/bin/env bash
#
# setup-nginx.sh - Configurar Nginx con enlaces simbólicos
# Detecta nginx existente y sugiere nuestra configuración
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
ENV_FILE="${PROJECT_ROOT}/.env"
SSL_DIR="${PROJECT_ROOT}/ssl"
NGINX_TEMPLATE="${PROJECT_ROOT}/install/templates/nginx.bintelx.conf.tpl"

# Load environment
if [ ! -f "$ENV_FILE" ]; then
    print_error ".env no encontrado. Ejecute wizard-env.sh primero."
    exit 1
fi

source <(grep -E '^(APP_URL|APP_DOMAIN|APP_DOMAIN_NORMALIZED|DB_HOST)' "$ENV_FILE" | sed 's/^/export /')

# Fallback if old .env without normalized domain
if [ -z "$APP_DOMAIN" ]; then
    APP_DOMAIN=$(echo "$APP_URL" | sed 's~https\?://~~g' | cut -d'/' -f1)
fi

if [ -z "$APP_DOMAIN_NORMALIZED" ]; then
    APP_DOMAIN_NORMALIZED=$(echo "$APP_DOMAIN" | tr '.' '_' | tr -cd '[:alnum:]_-')
fi

DOMAIN="$APP_DOMAIN"

# Use normalized domain for config file name
NGINX_CONF_OUTPUT="${PROJECT_ROOT}/bintelx/config/server/nginx.${APP_DOMAIN_NORMALIZED}.conf"
NGINX_SITE_NAME="${APP_DOMAIN_NORMALIZED}"

clear
print_header "Configuración de Nginx"

print_info "Nombre de sitio: ${NGINX_SITE_NAME}"

# Check if nginx is installed
if ! command -v nginx &> /dev/null; then
    print_error "Nginx no está instalado"
    echo ""
    read -p "¿Desea instalar Nginx ahora? [Y/n]: " install_nginx
    if [[ ! "$install_nginx" =~ ^[Nn]$ ]]; then
        sudo apt update
        sudo apt install -y nginx
        print_success "Nginx instalado"
    else
        exit 1
    fi
fi

NGINX_VERSION=$(nginx -v 2>&1 | awk -F'/' '{print $2}')
print_info "Nginx detectado: ${NGINX_VERSION}"

# Check for HTTP/3 support
if nginx -V 2>&1 | grep -q "http_v3"; then
    print_success "HTTP/3 (QUIC) soportado"
    HTTP3_ENABLED=true
else
    print_warning "HTTP/3 (QUIC) no soportado"
    print_info "Para soporte HTTP/3, debe compilar nginx con --with-http_v3_module"
    HTTP3_ENABLED=false
fi

# ==========================================
# Preguntar qué desea configurar
# ==========================================
print_header "Opciones de Configuración"

echo "Seleccione qué desea configurar:"
echo ""
echo "  1. Archivo de configuración de nginx (${NGINX_CONF_OUTPUT})"
echo "  2. Enlaces simbólicos en sites-available/sites-enabled"
echo ""

read -p "¿Desea generar el archivo de configuración de nginx? [Y/n]: " create_config
CREATE_CONFIG=true
if [[ "$create_config" =~ ^[Nn]$ ]]; then
    CREATE_CONFIG=false
    print_info "No se generará el archivo de configuración"
fi

read -p "¿Desea crear enlaces simbólicos en sites-available/sites-enabled? [Y/n]: " create_symlinks
CREATE_SYMLINKS=true
if [[ "$create_symlinks" =~ ^[Nn]$ ]]; then
    CREATE_SYMLINKS=false
    print_info "No se crearán enlaces simbólicos"
fi

# Si no se va a hacer nada, salir
if [ "$CREATE_CONFIG" = false ] && [ "$CREATE_SYMLINKS" = false ]; then
    print_warning "No se realizarán cambios en la configuración de nginx"
    exit 0
fi

NGINX_SITES_AVAILABLE="/etc/nginx/sites-available"
NGINX_SITES_ENABLED="/etc/nginx/sites-enabled"

# ==========================================
# Verificar configuración existente
# ==========================================
if [ "$CREATE_CONFIG" = true ]; then
    print_header "Verificando Archivo de Configuración Existente"

    if [ -f "$NGINX_CONF_OUTPUT" ]; then
        print_warning "Ya existe un archivo de configuración: ${NGINX_CONF_OUTPUT}"
        read -p "¿Desea sobrescribirlo? [y/N]: " overwrite_config
        if [[ ! "$overwrite_config" =~ ^[Yy]$ ]]; then
            print_info "Conservando archivo de configuración existente"
            CREATE_CONFIG=false
        fi
    fi
fi

if [ "$CREATE_SYMLINKS" = true ]; then
    print_header "Verificando Enlaces Simbólicos Existentes"

    if [ -L "${NGINX_SITES_ENABLED}/${NGINX_SITE_NAME}" ] || [ -f "${NGINX_SITES_ENABLED}/${NGINX_SITE_NAME}" ]; then
        print_warning "Ya existe un enlace simbólico para '${NGINX_SITE_NAME}' en Nginx"
        read -p "¿Desea sobrescribirlo? [y/N]: " overwrite_symlink
        if [[ ! "$overwrite_symlink" =~ ^[Yy]$ ]]; then
            print_info "Conservando enlaces simbólicos existentes"
            CREATE_SYMLINKS=false
        fi
    fi
fi

# ==========================================
# Generar configuración desde plantilla
# ==========================================
if [ "$CREATE_CONFIG" = true ]; then
    print_header "Generando Configuración de Nginx"
else
    print_header "Configuración de Nginx (omitiendo generación de archivo)"
fi

if [ "$CREATE_CONFIG" = true ]; then
    # Detect PHP-FPM socket
    PHP_FPM_SOCKET=""
    for socket in /run/php/php8.4-fpm.sock /run/php/php8.3-fpm.sock /run/php/php8.2-fpm.sock /run/php/php-fpm.sock; do
        if [ -S "$socket" ]; then
            PHP_FPM_SOCKET="$socket"
            break
        fi
    done

    if [ -z "$PHP_FPM_SOCKET" ]; then
        print_warning "No se detectó socket PHP-FPM"
        read -p "Ingrese ruta del socket PHP-FPM: " PHP_FPM_SOCKET
    fi

    print_info "Socket PHP-FPM: ${PHP_FPM_SOCKET}"

    # Create nginx config directory if it doesn't exist
    mkdir -p "$(dirname "$NGINX_CONF_OUTPUT")"

    # Generate configuration
    cat > "$NGINX_CONF_OUTPUT" <<'NGINX_EOF'
# Bintelx Nginx Configuration
# Generated by install script

server {
    listen 80;
    listen [::]:80;
    server_name SERVER_NAME;

    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
NGINX_EOF

# Add HTTP/3 if supported
if [ "$HTTP3_ENABLED" = true ]; then
    cat >> "$NGINX_CONF_OUTPUT" <<'NGINX_EOF'
    listen 443 quic reuseport;
    listen [::]:443 quic reuseport;
    http3 on;
    add_header Alt-Svc 'h3=":443"; ma=86400';
NGINX_EOF
fi

# Continue with rest of config
cat >> "$NGINX_CONF_OUTPUT" <<'NGINX_EOF'

    server_name SERVER_NAME;
    root PROJECT_ROOT/app;
    index index.php index.html;

    # SSL Configuration
    ssl_certificate SSL_DIR/cert.pem;
    ssl_certificate_key SSL_DIR/key.pem;
    ssl_dhparam SSL_DIR/dhparam.pem;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

NGINX_EOF

# Add QUIC settings if supported
if [ "$HTTP3_ENABLED" = true ]; then
    cat >> "$NGINX_CONF_OUTPUT" <<'NGINX_EOF'
    # QUIC/HTTP3 Settings
    ssl_early_data on;
    quic_retry on;
    quic_gso on;

NGINX_EOF
fi

# Continue with locations and security
cat >> "$NGINX_CONF_OUTPUT" <<'NGINX_EOF'
    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Logging
    access_log PROJECT_ROOT/log/nginx-access.log;
    error_log PROJECT_ROOT/log/nginx-error.log warn;

    # API Endpoint
    location /api/ {
        try_files $uri $uri/ /api.php?$query_string;

        location ~ \.php$ {
            try_files $uri =404;
            fastcgi_pass unix:PHP_FPM_SOCKET;
            fastcgi_index api.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param SCRIPT_NAME $fastcgi_script_name;
            include fastcgi_params;
            fastcgi_read_timeout 300s;
            fastcgi_send_timeout 300s;
        }
    }

    # CLI Endpoint (if needed)
    location ~ ^/cli\.php$ {
        fastcgi_pass unix:PHP_FPM_SOCKET;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive files
    location ~ /\.(env|git|htaccess|gitignore) {
        deny all;
        return 404;
    }

    # Deny access to directories
    location ^~ /log/ { deny all; return 404; }
    location ^~ /bintelx/ { deny all; return 404; }
    location ^~ /custom/ { deny all; return 404; }
    location ^~ /install/ { deny all; return 404; }

    # Static files caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Health check endpoint
    location = /health {
        access_log off;
        return 200 "OK\n";
        add_header Content-Type text/plain;
    }
}
NGINX_EOF

    # Replace placeholders
    sed -i "s|SERVER_NAME|${DOMAIN}|g" "$NGINX_CONF_OUTPUT"
    sed -i "s|PROJECT_ROOT|${PROJECT_ROOT}|g" "$NGINX_CONF_OUTPUT"
    sed -i "s|SSL_DIR|${SSL_DIR}|g" "$NGINX_CONF_OUTPUT"
    sed -i "s|PHP_FPM_SOCKET|${PHP_FPM_SOCKET}|g" "$NGINX_CONF_OUTPUT"

    print_success "Configuración generada: ${NGINX_CONF_OUTPUT}"
fi

# ==========================================
# Crear enlaces simbólicos
# ==========================================
if [ "$CREATE_SYMLINKS" = true ]; then
    print_header "Configurando Enlaces Simbólicos"

    # Verificar que existe el archivo de configuración
    if [ ! -f "$NGINX_CONF_OUTPUT" ]; then
        print_error "No existe el archivo de configuración: ${NGINX_CONF_OUTPUT}"
        print_warning "Debe crear primero el archivo de configuración o especificar uno existente"
        exit 1
    fi
else
    print_info "Omitiendo creación de enlaces simbólicos"
fi

if [ "$CREATE_SYMLINKS" = true ]; then
    # Ensure sites-available and sites-enabled exist
    sudo mkdir -p "$NGINX_SITES_AVAILABLE" "$NGINX_SITES_ENABLED"

    # Remove old symlink if exists
    if [ -L "${NGINX_SITES_ENABLED}/${NGINX_SITE_NAME}" ]; then
        sudo rm "${NGINX_SITES_ENABLED}/${NGINX_SITE_NAME}"
        print_info "Enlace anterior removido"
    fi

    # Create symlink in sites-available
    if [ -L "${NGINX_SITES_AVAILABLE}/${NGINX_SITE_NAME}" ]; then
        sudo rm "${NGINX_SITES_AVAILABLE}/${NGINX_SITE_NAME}"
    fi

    sudo ln -sf "$NGINX_CONF_OUTPUT" "${NGINX_SITES_AVAILABLE}/${NGINX_SITE_NAME}"
    print_success "Enlace creado: sites-available/${NGINX_SITE_NAME} -> ${NGINX_CONF_OUTPUT}"

    # Enable site
    sudo ln -sf "${NGINX_SITES_AVAILABLE}/${NGINX_SITE_NAME}" "${NGINX_SITES_ENABLED}/${NGINX_SITE_NAME}"
    print_success "Sitio habilitado: sites-enabled/${NGINX_SITE_NAME}"

    # ==========================================
    # Deshabilitar default site
    # ==========================================
    if [ -L "${NGINX_SITES_ENABLED}/default" ]; then
        read -p "¿Desea deshabilitar el sitio default de Nginx? [Y/n]: " disable_default
        if [[ ! "$disable_default" =~ ^[Nn]$ ]]; then
            sudo rm "${NGINX_SITES_ENABLED}/default"
            print_success "Sitio default deshabilitado"
        fi
    fi
fi

# ==========================================
# Test configuration and reload
# ==========================================
if [ "$CREATE_CONFIG" = true ] || [ "$CREATE_SYMLINKS" = true ]; then
    print_header "Verificando Configuración"

    print_info "Probando configuración de Nginx..."
    if sudo nginx -t 2>&1 | tee /tmp/nginx-test.log; then
        print_success "Configuración válida"
    else
        print_error "Error en la configuración"
        cat /tmp/nginx-test.log
        echo ""
        if [ "$CREATE_CONFIG" = true ]; then
            read -p "¿Desea ver el archivo de configuración para corregirlo? [y/N]: " view_config
            if [[ "$view_config" =~ ^[Yy]$ ]]; then
                less "$NGINX_CONF_OUTPUT"
            fi
        fi
        exit 1
    fi

    # ==========================================
    # Reload Nginx
    # ==========================================
    print_info "Recargando Nginx..."
    if sudo systemctl reload nginx; then
        print_success "Nginx recargado"
    else
        print_warning "No se pudo recargar. Intentando reiniciar..."
        if sudo systemctl restart nginx; then
            print_success "Nginx reiniciado"
        else
            print_error "Fallo al reiniciar Nginx"
            sudo systemctl status nginx
            exit 1
        fi
    fi

    # Enable nginx on boot
    sudo systemctl enable nginx 2>/dev/null || true
fi

# ==========================================
# Resumen
# ==========================================
print_header "Resumen de Configuración Nginx"

echo ""
echo -e "${GREEN}✓ Dominio:${NC} ${DOMAIN}"
echo -e "${GREEN}✓ Nombre del sitio:${NC} ${NGINX_SITE_NAME}"

if [ "$CREATE_CONFIG" = true ]; then
    echo -e "${GREEN}✓ Archivo de configuración:${NC} ${NGINX_CONF_OUTPUT}"
    echo -e "${GREEN}✓ PHP-FPM Socket:${NC} ${PHP_FPM_SOCKET}"
    echo -e "${GREEN}✓ SSL:${NC} ${SSL_DIR}"
    [ "$HTTP3_ENABLED" = true ] && echo -e "${GREEN}✓ HTTP/3:${NC} Habilitado"
else
    echo -e "${YELLOW}⊘ Archivo de configuración:${NC} No creado"
fi

if [ "$CREATE_SYMLINKS" = true ]; then
    echo -e "${GREEN}✓ Enlace simbólico:${NC} ${NGINX_SITES_ENABLED}/${NGINX_SITE_NAME}"
else
    echo -e "${YELLOW}⊘ Enlaces simbólicos:${NC} No creados"
fi

echo ""

if [ "$CREATE_CONFIG" = true ] || [ "$CREATE_SYMLINKS" = true ]; then
    print_success "Configuración completada exitosamente!"
    echo ""
    print_info "Puede acceder a su aplicación en: ${APP_URL}"
    print_info "Health check: ${APP_URL}/health"
else
    print_warning "No se realizaron cambios en nginx"
fi

echo ""
print_info "Siguiente paso: Ejecutar setup-db.sh para configurar la base de datos"
