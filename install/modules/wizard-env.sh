#!/usr/bin/env bash
#
# wizard-env.sh - Asistente interactivo para crear .env
# Guía paso a paso con validaciones
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

clear
echo -e "${BLUE}"
cat << "EOF"
╔═══════════════════════════════════════════════════════╗
║                                                       ║
║   ██████╗ ██╗███╗   ██╗████████╗███████╗██╗     ██╗ ║
║   ██╔══██╗██║████╗  ██║╚══██╔══╝██╔════╝██║     ██║ ║
║   ██████╔╝██║██╔██╗ ██║   ██║   █████╗  ██║     ╚██╗║
║   ██╔══██╗██║██║╚██╗██║   ██║   ██╔══╝  ██║      ██║║
║   ██████╔╝██║██║ ╚████║   ██║   ███████╗███████╗ ██║║
║   ╚═════╝ ╚═╝╚═╝  ╚═══╝   ╚═╝   ╚══════╝╚══════╝ ╚═╝║
║                                                       ║
║         Asistente de Configuración (.env)            ║
║                                                       ║
╚═══════════════════════════════════════════════════════╝
EOF
echo -e "${NC}"

# Check if .env already exists
if [ -f "$ENV_FILE" ]; then
    print_warning ".env ya existe en: $ENV_FILE"
    echo ""
    read -p "¿Desea sobrescribirlo? [y/N]: " overwrite
    if [[ ! "$overwrite" =~ ^[Yy]$ ]]; then
        print_info "Usando .env existente"
        exit 0
    fi

    # Backup
    BACKUP_FILE="${ENV_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
    cp "$ENV_FILE" "$BACKUP_FILE"
    print_success "Backup creado: $BACKUP_FILE"
fi

# Helper function for prompts
prompt_with_default() {
    local prompt=$1
    local default=$2
    local variable_name=$3
    local value

    if [ -n "$default" ]; then
        read -p "$(echo -e ${CYAN}${prompt}${NC}) [${default}]: " value
        value=${value:-$default}
    else
        read -p "$(echo -e ${CYAN}${prompt}${NC}): " value
    fi

    eval "$variable_name=\"$value\""
}

prompt_password() {
    local prompt=$1
    local variable_name=$2
    local value

    read -sp "$(echo -e ${CYAN}${prompt}${NC}): " value
    echo
    eval "$variable_name=\"$value\""
}

prompt_with_validation() {
    local prompt=$1
    local default=$2
    local variable_name=$3
    local validation_regex=$4
    local error_msg=$5
    local value

    while true; do
        prompt_with_default "$prompt" "$default" "value"

        if [[ -z "$validation_regex" ]] || [[ "$value" =~ $validation_regex ]]; then
            eval "$variable_name=\"$value\""
            break
        else
            print_error "$error_msg"
        fi
    done
}

# ==========================================
# PASO 1: Información General
# ==========================================
print_header "1/6 - Información General de la Aplicación"

prompt_with_validation \
    "Entorno (production/staging/development)" \
    "production" \
    "APP_ENV" \
    "^(production|staging|development)$" \
    "Debe ser: production, staging o development"

prompt_with_default \
    "¿Habilitar modo debug? (true/false)" \
    "false" \
    "APP_DEBUG"

prompt_with_validation \
    "URL de la aplicación" \
    "https://dev.local" \
    "APP_URL" \
    "^https?://.+" \
    "Debe ser una URL válida (http:// o https://)"

# Extract and normalize domain for file naming
DOMAIN=$(echo "$APP_URL" | sed 's~https\?://~~g' | cut -d'/' -f1)
DOMAIN_NORMALIZED=$(echo "$DOMAIN" | tr '.' '_' | tr -cd '[:alnum:]_-')

print_info "Dominio detectado: ${DOMAIN}"
print_info "Nombre normalizado: ${DOMAIN_NORMALIZED}"

# ==========================================
# PASO 2: Configuración de Base de Datos
# ==========================================
print_header "2/6 - Configuración de Base de Datos"

print_info "Configurando conexión a MySQL/MariaDB"
echo ""

prompt_with_default \
    "Host de la base de datos" \
    "127.0.0.1" \
    "DB_HOST"

prompt_with_validation \
    "Puerto de la base de datos" \
    "3306" \
    "DB_PORT" \
    "^[0-9]+$" \
    "Debe ser un número de puerto válido"

prompt_with_default \
    "Nombre de la base de datos" \
    "bnx_labtronic" \
    "DB_NAME"

prompt_with_default \
    "Usuario de la base de datos" \
    "bintelx_user" \
    "DB_USERNAME"

prompt_password \
    "Contraseña de la base de datos" \
    "DB_PASSWORD"

echo ""

# Test database connection
print_info "Probando conexión a base de datos..."
if mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT 1" &>/dev/null; then
    print_success "Conexión exitosa"
else
    print_warning "No se pudo conectar a la base de datos. Verifique las credenciales."
    read -p "¿Desea continuar de todas formas? [y/N]: " continue_anyway
    if [[ ! "$continue_anyway" =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# ==========================================
# PASO 3: Seguridad (JWT)
# ==========================================
print_header "3/6 - Configuración de Seguridad"

print_info "Generando claves de seguridad..."
echo ""

JWT_SECRET=$(openssl rand -hex 32)
JWT_XOR_KEY=$(openssl rand -base64 16 | tr -d "=+/" | cut -c1-12)

print_success "JWT Secret: ${JWT_SECRET:0:8}... (generado)"
print_success "XOR Key: ${JWT_XOR_KEY:0:3}... (generado)"
echo ""

read -p "¿Desea ingresar sus propias claves? [y/N]: " custom_keys
if [[ "$custom_keys" =~ ^[Yy]$ ]]; then
    prompt_with_default \
        "JWT Secret" \
        "$JWT_SECRET" \
        "JWT_SECRET"

    prompt_with_default \
        "JWT XOR Key" \
        "$JWT_XOR_KEY" \
        "JWT_XOR_KEY"
fi

prompt_with_validation \
    "Tiempo de expiración del token JWT (segundos)" \
    "3600" \
    "JWT_EXPIRATION" \
    "^[0-9]+$" \
    "Debe ser un número válido de segundos"

# ==========================================
# PASO 4: CORS
# ==========================================
print_header "4/6 - Configuración CORS"

print_info "Configurando Cross-Origin Resource Sharing"
echo ""

prompt_with_default \
    "Orígenes permitidos (separados por coma, o * para todos)" \
    "$APP_URL" \
    "CORS_ALLOWED_ORIGINS"

prompt_with_default \
    "Métodos HTTP permitidos" \
    "GET,POST,PATCH,DELETE,OPTIONS" \
    "CORS_ALLOWED_METHODS"

prompt_with_default \
    "Headers permitidos" \
    "Origin,X-Auth-Token,X-Requested-With,Content-Type,Accept,Authorization" \
    "CORS_ALLOWED_HEADERS"

# ==========================================
# PASO 5: Configuración Regional
# ==========================================
print_header "5/6 - Configuración Regional"

prompt_with_default \
    "Zona horaria por defecto" \
    "America/Santiago" \
    "DEFAULT_TIMEZONE"

# ==========================================
# PASO 6: Rutas y Características
# ==========================================
print_header "6/6 - Rutas y Características"

prompt_with_default \
    "Ruta para logs" \
    "${PROJECT_ROOT}/log" \
    "LOG_PATH"

prompt_with_default \
    "Ruta para uploads" \
    "${PROJECT_ROOT}/uploads" \
    "UPLOAD_PATH"

print_info "Configuración del sistema..."
echo ""

prompt_with_default \
    "Usuario del servidor web" \
    "www-data" \
    "SYSTEM_WEB_USER"

prompt_with_default \
    "Grupo del servidor web" \
    "www-data" \
    "SYSTEM_WEB_GROUP"

echo ""

prompt_with_default \
    "¿Habilitar audit log? (true/false)" \
    "true" \
    "ENABLE_AUDIT_LOG"

prompt_with_default \
    "¿Habilitar query log? (true/false)" \
    "false" \
    "ENABLE_QUERY_LOG"

# ==========================================
# GENERAR ARCHIVO .env
# ==========================================
print_header "Generando archivo .env"

cat > "$ENV_FILE" <<EOF
# Bintelx Environment Configuration
# Generated on $(date)
# Environment: ${APP_ENV}

# ==========================================
# APPLICATION
# ==========================================
APP_ENV=${APP_ENV}
APP_DEBUG=${APP_DEBUG}
APP_URL=${APP_URL}
APP_DOMAIN=${DOMAIN}
APP_DOMAIN_NORMALIZED=${DOMAIN_NORMALIZED}

# ==========================================
# DATABASE
# ==========================================
DB_CONNECTION=mysql
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

# ==========================================
# SECURITY (JWT)
# ==========================================
JWT_SECRET=${JWT_SECRET}
JWT_XOR_KEY=${JWT_XOR_KEY}
JWT_ALGORITHM=HS256
JWT_EXPIRATION=${JWT_EXPIRATION}

# ==========================================
# CORS
# ==========================================
CORS_ALLOWED_ORIGINS=${CORS_ALLOWED_ORIGINS}
CORS_ALLOWED_METHODS=${CORS_ALLOWED_METHODS}
CORS_ALLOWED_HEADERS=${CORS_ALLOWED_HEADERS}

# ==========================================
# REGIONAL
# ==========================================
DEFAULT_TIMEZONE=${DEFAULT_TIMEZONE}

# ==========================================
# FILE STORAGE
# ==========================================
LOG_PATH=${LOG_PATH}
UPLOAD_PATH=${UPLOAD_PATH}

# ==========================================
# SYSTEM CONFIGURATION
# ==========================================
SYSTEM_WEB_USER=${SYSTEM_WEB_USER}
SYSTEM_WEB_GROUP=${SYSTEM_WEB_GROUP}

# ==========================================
# FEATURES
# ==========================================
ENABLE_AUDIT_LOG=${ENABLE_AUDIT_LOG}
ENABLE_QUERY_LOG=${ENABLE_QUERY_LOG}
EOF

# Set secure permissions
chmod 600 "$ENV_FILE"

print_success "Archivo .env creado: $ENV_FILE"
echo ""

# Create directories if needed
print_info "Creando directorios necesarios..."
mkdir -p "$LOG_PATH"
mkdir -p "$UPLOAD_PATH"
chmod 775 "$LOG_PATH"
chmod 775 "$UPLOAD_PATH"

# Set group ownership
if command -v chgrp &> /dev/null; then
    chgrp "${SYSTEM_WEB_GROUP}" "$LOG_PATH" 2>/dev/null || true
    chgrp "${SYSTEM_WEB_GROUP}" "$UPLOAD_PATH" 2>/dev/null || true
fi

print_success "Directorios creados con grupo: ${SYSTEM_WEB_GROUP}"
echo ""

# Summary
print_header "Resumen de Configuración"
echo -e "${GREEN}✓ Base de datos:${NC} ${DB_USERNAME}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo -e "${GREEN}✓ Entorno:${NC} ${APP_ENV}"
echo -e "${GREEN}✓ URL:${NC} ${APP_URL}"
echo -e "${GREEN}✓ Timezone:${NC} ${DEFAULT_TIMEZONE}"
echo -e "${GREEN}✓ Logs:${NC} ${LOG_PATH}"
echo ""

print_success "Configuración completada exitosamente!"
echo ""
print_info "Siguiente paso: Ejecutar setup-ssl.sh para configurar certificados SSL"
