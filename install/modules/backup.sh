#!/usr/bin/env bash
#
# backup.sh - Sistema de backup automático
# Crea backup completo antes de modificar el sistema
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
BACKUP_BASE_DIR="${PROJECT_ROOT}/.backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="${BACKUP_BASE_DIR}/${TIMESTAMP}"

# Export for other scripts
export BINTELX_BACKUP_DIR="$BACKUP_DIR"
export BINTELX_BACKUP_TIMESTAMP="$TIMESTAMP"

print_header "Sistema de Backup Bintelx"

# Create backup directory
mkdir -p "$BACKUP_DIR"

print_info "Directorio de backup: $BACKUP_DIR"
echo ""

# ==========================================
# 1. Backup .env
# ==========================================
if [ -f "$ENV_FILE" ]; then
    print_info "Respaldando .env..."
    cp "$ENV_FILE" "${BACKUP_DIR}/.env"
    print_success ".env respaldado"
else
    print_info ".env no existe (instalación nueva)"
fi

# ==========================================
# 2. Backup SSL certificates
# ==========================================
if [ -d "${PROJECT_ROOT}/ssl" ]; then
    print_info "Respaldando certificados SSL..."
    cp -r "${PROJECT_ROOT}/ssl" "${BACKUP_DIR}/ssl"
    print_success "Certificados SSL respaldados"
else
    print_info "ssl/ no existe (no hay certificados locales)"
fi

# ==========================================
# 3. Backup nginx config
# ==========================================
NGINX_CONF="${PROJECT_ROOT}/bintelx/config/server/nginx.bintelx.conf"
if [ -f "$NGINX_CONF" ]; then
    print_info "Respaldando configuración de nginx..."
    mkdir -p "${BACKUP_DIR}/nginx"
    cp "$NGINX_CONF" "${BACKUP_DIR}/nginx/"
    print_success "Configuración de nginx respaldada"
fi

# Backup system nginx (requires sudo)
if [ -d "/etc/nginx/sites-enabled" ]; then
    print_info "Respaldando /etc/nginx/sites-enabled..."
    sudo cp -r /etc/nginx/sites-enabled "${BACKUP_DIR}/nginx/" 2>/dev/null || print_warning "No se pudo respaldar /etc/nginx (no crítico)"
fi

# ==========================================
# 4. Backup database (optional)
# ==========================================
if [ -f "$ENV_FILE" ]; then
    source <(grep -E '^DB_' "$ENV_FILE" 2>/dev/null | sed 's/^/export /' || true)

    if [ -n "$DB_DATABASE" ] && [ -n "$DB_USERNAME" ]; then
        echo ""
        read -p "¿Desea respaldar la base de datos? [y/N]: " backup_db

        if [[ "$backup_db" =~ ^[Yy]$ ]]; then
            print_info "Respaldando base de datos ${DB_DATABASE}..."

            if mysqldump -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" \
                -u"${DB_USERNAME}" -p"${DB_PASSWORD}" \
                "${DB_DATABASE}" > "${BACKUP_DIR}/database.sql" 2>/dev/null; then

                # Compress database backup
                gzip "${BACKUP_DIR}/database.sql" 2>/dev/null || true
                print_success "Base de datos respaldada (comprimida)"
            else
                print_warning "No se pudo respaldar la base de datos (no crítico)"
            fi
        else
            print_info "Backup de base de datos omitido"
        fi
    fi
fi

# ==========================================
# 5. Create manifest
# ==========================================
print_info "Creando manifiesto de backup..."

cat > "${BACKUP_DIR}/MANIFEST.txt" <<EOF
Bintelx Backup Manifest
=======================

Timestamp: $(date)
Backup ID: ${TIMESTAMP}
System: $(uname -a)

Backed up files:
EOF

find "$BACKUP_DIR" -type f -exec ls -lh {} \; >> "${BACKUP_DIR}/MANIFEST.txt"

# Calculate backup size
BACKUP_SIZE=$(du -sh "$BACKUP_DIR" | cut -f1)

cat >> "${BACKUP_DIR}/MANIFEST.txt" <<EOF

Total backup size: ${BACKUP_SIZE}

Restore instructions:
---------------------
cd $(dirname "$BACKUP_DIR")
sudo bash rollback.sh ${TIMESTAMP}
EOF

print_success "Manifiesto creado"

# ==========================================
# 6. Keep only last 5 backups
# ==========================================
print_info "Limpiando backups antiguos..."

BACKUP_COUNT=$(ls -1 "$BACKUP_BASE_DIR" 2>/dev/null | wc -l)

if [ "$BACKUP_COUNT" -gt 5 ]; then
    BACKUPS_TO_DELETE=$((BACKUP_COUNT - 5))
    print_warning "Eliminando ${BACKUPS_TO_DELETE} backups antiguos (manteniendo últimos 5)..."

    ls -1t "$BACKUP_BASE_DIR" | tail -n +6 | while read -r old_backup; do
        rm -rf "${BACKUP_BASE_DIR}/${old_backup}"
        print_info "Eliminado: $old_backup"
    done
fi

# ==========================================
# Summary
# ==========================================
print_header "Backup Completado"

echo ""
echo -e "${GREEN}✓ Backup ID:${NC} ${TIMESTAMP}"
echo -e "${GREEN}✓ Ubicación:${NC} ${BACKUP_DIR}"
echo -e "${GREEN}✓ Tamaño:${NC} ${BACKUP_SIZE}"
echo ""

if [ -f "${BACKUP_DIR}/.env" ]; then
    echo -e "${CYAN}→ .env respaldado${NC}"
fi

if [ -d "${BACKUP_DIR}/ssl" ]; then
    echo -e "${CYAN}→ Certificados SSL respaldados${NC}"
fi

if [ -d "${BACKUP_DIR}/nginx" ]; then
    echo -e "${CYAN}→ Configuración nginx respaldada${NC}"
fi

if [ -f "${BACKUP_DIR}/database.sql.gz" ]; then
    DB_SIZE=$(du -sh "${BACKUP_DIR}/database.sql.gz" | cut -f1)
    echo -e "${CYAN}→ Base de datos respaldada (${DB_SIZE})${NC}"
fi

echo ""
echo -e "${YELLOW}Para restaurar este backup:${NC}"
echo -e "  cd ${PROJECT_ROOT}/install"
echo -e "  sudo bash modules/rollback.sh ${TIMESTAMP}"
echo ""

print_success "Sistema respaldado exitosamente"
