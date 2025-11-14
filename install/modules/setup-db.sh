#!/usr/bin/env bash
#
# setup-db.sh - Configurar base de datos y cargar esquemas
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

# Load environment
if [ ! -f "$ENV_FILE" ]; then
    print_error ".env no encontrado. Ejecute wizard-env.sh primero."
    exit 1
fi

source <(grep -E '^DB_' "$ENV_FILE" | sed 's/^/export /')

clear
print_header "Configuración de Base de Datos"

# ==========================================
# Verificar conexión
# ==========================================
print_info "Verificando conexión a: ${DB_USERNAME}@${DB_HOST}:${DB_PORT}"

if ! mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT 1" &>/dev/null; then
    print_error "No se puede conectar a la base de datos"
    echo ""
    echo "Verifique:"
    echo "  - MySQL está corriendo: sudo systemctl status mysql"
    echo "  - Credenciales en .env son correctas"
    echo "  - Usuario tiene permisos"
    exit 1
fi

print_success "Conexión exitosa"

# ==========================================
# Crear base de datos si no existe
# ==========================================
print_header "Verificando Base de Datos"

DB_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
    -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${DB_DATABASE}'" \
    -sN)

if [ -z "$DB_EXISTS" ]; then
    print_warning "Base de datos '${DB_DATABASE}' no existe"
    read -p "¿Desea crearla? [Y/n]: " create_db
    if [[ ! "$create_db" =~ ^[Nn]$ ]]; then
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
            -e "CREATE DATABASE \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        print_success "Base de datos '${DB_DATABASE}' creada"
    else
        exit 1
    fi
else
    print_success "Base de datos '${DB_DATABASE}' existe"
fi

# ==========================================
# Verificar esquemas SQL disponibles
# ==========================================
print_header "Esquemas SQL Disponibles"

SCHEMA_FILES=()

# Search for SQL schemas in install/ directory
print_info "Buscando esquemas en: install/"
while IFS= read -r -d '' file; do
    filename=$(basename "$file")
    SCHEMA_FILES+=("${file}|${filename} (install)")
done < <(find "${PROJECT_ROOT}/install" -maxdepth 2 -type f -name "*.sql" -print0 2>/dev/null)

# Search for SQL schemas in bintelx/config/ directory
print_info "Buscando esquemas en: bintelx/config/"
while IFS= read -r -d '' file; do
    filename=$(basename "$file")
    SCHEMA_FILES+=("${file}|${filename} (config)")
done < <(find "${PROJECT_ROOT}/bintelx/config" -type f -name "*.sql" -print0 2>/dev/null)

if [ ${#SCHEMA_FILES[@]} -eq 0 ]; then
    print_warning "No se encontraron archivos de esquema SQL"
    exit 0
fi

echo "Archivos de esquema encontrados:"
echo ""

for i in "${!SCHEMA_FILES[@]}"; do
    IFS='|' read -r file desc <<< "${SCHEMA_FILES[$i]}"
    echo "  $((i+1)). $desc"
    echo "     $(basename "$file")"
    echo ""
done

# ==========================================
# Importar esquemas
# ==========================================
print_header "Importar Esquemas"

read -p "¿Desea importar los esquemas? [Y/n]: " import_schemas
if [[ "$import_schemas" =~ ^[Nn]$ ]]; then
    print_info "Importación de esquemas omitida"
    exit 0
fi

# Check if tables already exist
TABLE_COUNT=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}'" \
    -sN)

if [ "$TABLE_COUNT" -gt 0 ]; then
    print_warning "La base de datos ya contiene ${TABLE_COUNT} tablas"
    read -p "¿Desea continuar de todas formas? [y/N]: " continue_import
    if [[ ! "$continue_import" =~ ^[Yy]$ ]]; then
        print_info "Importación cancelada"
        exit 0
    fi
fi

# Import each schema
for schema_info in "${SCHEMA_FILES[@]}"; do
    IFS='|' read -r file desc <<< "$schema_info"

    echo ""
    print_info "Importando: $desc"
    echo "  Archivo: $(basename "$file")"

    if mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < "$file" 2>&1 | tee /tmp/mysql-import.log | grep -i "error"; then
        print_error "Error al importar $(basename "$file")"
        cat /tmp/mysql-import.log
        read -p "¿Desea continuar con los siguientes esquemas? [y/N]: " continue_next
        if [[ ! "$continue_next" =~ ^[Yy]$ ]]; then
            exit 1
        fi
    else
        print_success "$(basename "$file") importado"
    fi
done

# ==========================================
# Cargar datos de zona horaria
# ==========================================
print_header "Datos de Zona Horaria"

read -p "¿Desea cargar los datos de zona horaria de MySQL? [Y/n]: " load_tz
if [[ ! "$load_tz" =~ ^[Nn]$ ]]; then
    print_info "Cargando datos de zona horaria..."

    if mysql_tzinfo_to_sql /usr/share/zoneinfo 2>/dev/null | \
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" mysql 2>&1 | \
        grep -v "Warning" > /tmp/tz-import.log; then
        print_success "Datos de zona horaria cargados"
    else
        print_warning "No se pudieron cargar los datos de zona horaria (no crítico)"
    fi
fi

# ==========================================
# Verificar tablas creadas
# ==========================================
print_header "Verificando Tablas"

TABLES=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
    -e "SHOW TABLES" -sN)

TABLE_COUNT=$(echo "$TABLES" | wc -l)

if [ "$TABLE_COUNT" -gt 0 ]; then
    print_success "${TABLE_COUNT} tablas en la base de datos"
    echo ""
    echo "Tablas creadas:"
    echo "$TABLES" | head -n 10 | sed 's/^/  - /'
    if [ "$TABLE_COUNT" -gt 10 ]; then
        echo "  ... y $((TABLE_COUNT - 10)) más"
    fi
else
    print_warning "No se encontraron tablas en la base de datos"
fi

# ==========================================
# Resumen
# ==========================================
print_header "Configuración de Base de Datos Completada"

echo ""
echo -e "${GREEN}✓ Base de datos:${NC} ${DB_DATABASE}"
echo -e "${GREEN}✓ Host:${NC} ${DB_HOST}:${DB_PORT}"
echo -e "${GREEN}✓ Usuario:${NC} ${DB_USERNAME}"
echo -e "${GREEN}✓ Tablas:${NC} ${TABLE_COUNT}"
echo ""

print_success "Base de datos configurada exitosamente!"
echo ""
print_info "Siguiente paso: Ejecutar verify.sh para verificar la instalación completa"
