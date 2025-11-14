#!/usr/bin/env bash
#
# rollback.sh - Restaurar sistema desde backup
# Revierte cambios usando backups automáticos
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
BACKUP_BASE_DIR="${PROJECT_ROOT}/.backups"

# Check if backup ID provided
BACKUP_ID="$1"

clear
print_header "Sistema de Rollback Bintelx"

# List available backups if no ID provided
if [ -z "$BACKUP_ID" ]; then
    echo "Backups disponibles:"
    echo ""

    if [ ! -d "$BACKUP_BASE_DIR" ] || [ -z "$(ls -A "$BACKUP_BASE_DIR" 2>/dev/null)" ]; then
        print_error "No hay backups disponibles"
        exit 1
    fi

    # List backups with details
    COUNT=1
    for backup_dir in $(ls -1t "$BACKUP_BASE_DIR"); do
        BACKUP_PATH="${BACKUP_BASE_DIR}/${backup_dir}"

        if [ -f "${BACKUP_PATH}/MANIFEST.txt" ]; then
            BACKUP_DATE=$(head -n 5 "${BACKUP_PATH}/MANIFEST.txt" | grep "Timestamp:" | cut -d':' -f2- | xargs)
            BACKUP_SIZE=$(du -sh "$BACKUP_PATH" | cut -f1)
        else
            BACKUP_DATE="Unknown"
            BACKUP_SIZE=$(du -sh "$BACKUP_PATH" | cut -f1)
        fi

        echo -e "  ${COUNT}. ${CYAN}${backup_dir}${NC}"
        echo -e "     Fecha: ${BACKUP_DATE}"
        echo -e "     Tamaño: ${BACKUP_SIZE}"
        echo ""

        ((COUNT++))
    done

    echo "Uso:"
    echo "  sudo bash rollback.sh <BACKUP_ID>"
    echo ""
    echo "Ejemplo:"
    echo "  sudo bash rollback.sh $(ls -1t "$BACKUP_BASE_DIR" | head -n1)"
    exit 0
fi

# Validate backup exists
BACKUP_DIR="${BACKUP_BASE_DIR}/${BACKUP_ID}"

if [ ! -d "$BACKUP_DIR" ]; then
    print_error "Backup no encontrado: $BACKUP_ID"
    echo ""
    echo "Ejecute sin argumentos para ver backups disponibles:"
    echo "  sudo bash rollback.sh"
    exit 1
fi

print_info "Backup encontrado: $BACKUP_ID"

# Show manifest
if [ -f "${BACKUP_DIR}/MANIFEST.txt" ]; then
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════${NC}"
    head -n 10 "${BACKUP_DIR}/MANIFEST.txt"
    echo -e "${BLUE}═══════════════════════════════════════${NC}"
    echo ""
fi

print_warning "Esta operación restaurará el sistema al estado del backup"
echo ""
read -p "¿Desea continuar con el rollback? [y/N]: " confirm_rollback

if [[ ! "$confirm_rollback" =~ ^[Yy]$ ]]; then
    print_info "Rollback cancelado"
    exit 0
fi

# ==========================================
# 1. Restore .env
# ==========================================
print_header "Restaurando Archivos"

if [ -f "${BACKUP_DIR}/.env" ]; then
    print_info "Restaurando .env..."

    # Backup current before restoring
    if [ -f "${PROJECT_ROOT}/.env" ]; then
        cp "${PROJECT_ROOT}/.env" "${PROJECT_ROOT}/.env.pre-rollback"
    fi

    cp "${BACKUP_DIR}/.env" "${PROJECT_ROOT}/.env"
    chmod 600 "${PROJECT_ROOT}/.env"
    print_success ".env restaurado"
else
    print_info ".env no estaba en el backup"
fi

# ==========================================
# 2. Restore SSL certificates
# ==========================================
if [ -d "${BACKUP_DIR}/ssl" ]; then
    print_info "Restaurando certificados SSL..."

    # Backup current before restoring
    if [ -d "${PROJECT_ROOT}/ssl" ]; then
        mv "${PROJECT_ROOT}/ssl" "${PROJECT_ROOT}/ssl.pre-rollback"
    fi

    cp -r "${BACKUP_DIR}/ssl" "${PROJECT_ROOT}/ssl"
    print_success "Certificados SSL restaurados"
else
    print_info "Certificados SSL no estaban en el backup"
fi

# ==========================================
# 3. Restore nginx config
# ==========================================
if [ -f "${BACKUP_DIR}/nginx/nginx.bintelx.conf" ]; then
    print_info "Restaurando configuración de nginx..."

    NGINX_CONF="${PROJECT_ROOT}/bintelx/config/server/nginx.bintelx.conf"

    # Backup current before restoring
    if [ -f "$NGINX_CONF" ]; then
        cp "$NGINX_CONF" "${NGINX_CONF}.pre-rollback"
    fi

    cp "${BACKUP_DIR}/nginx/nginx.bintelx.conf" "$NGINX_CONF"
    print_success "Configuración de nginx restaurada"

    # Test nginx config
    print_info "Probando configuración de nginx..."
    if sudo nginx -t 2>&1 | grep -q "successful"; then
        print_success "Configuración válida"

        # Reload nginx
        print_info "Recargando nginx..."
        if sudo systemctl reload nginx; then
            print_success "Nginx recargado"
        else
            print_error "Error al recargar nginx"
        fi
    else
        print_error "Configuración de nginx inválida"
        print_warning "Nginx no fue recargado"
    fi
else
    print_info "Configuración de nginx no estaba en el backup"
fi

# ==========================================
# 4. Restore database (optional)
# ==========================================
if [ -f "${BACKUP_DIR}/database.sql.gz" ] || [ -f "${BACKUP_DIR}/database.sql" ]; then
    echo ""
    print_warning "Se encontró backup de base de datos"
    echo ""
    echo -e "${RED}⚠  ADVERTENCIA: Restaurar la base de datos sobrescribirá TODOS los datos actuales${NC}"
    echo ""
    read -p "¿Desea restaurar la base de datos? [y/N]: " restore_db

    if [[ "$restore_db" =~ ^[Yy]$ ]]; then
        # Load DB config
        if [ -f "${PROJECT_ROOT}/.env" ]; then
            source <(grep -E '^DB_' "${PROJECT_ROOT}/.env" | sed 's/^/export /')

            print_info "Restaurando base de datos ${DB_DATABASE}..."

            # Decompress if needed
            if [ -f "${BACKUP_DIR}/database.sql.gz" ]; then
                print_info "Descomprimiendo backup..."
                gunzip -c "${BACKUP_DIR}/database.sql.gz" > "/tmp/bintelx_restore_${BACKUP_ID}.sql"
                SQL_FILE="/tmp/bintelx_restore_${BACKUP_ID}.sql"
            else
                SQL_FILE="${BACKUP_DIR}/database.sql"
            fi

            # Drop and recreate database
            read -p "¿Desea DROP y recrear la base de datos? (Recomendado) [Y/n]: " drop_db
            if [[ ! "$drop_db" =~ ^[Nn]$ ]]; then
                print_warning "Eliminando y recreando base de datos..."
                mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" \
                    -u"${DB_USERNAME}" -p"${DB_PASSWORD}" \
                    -e "DROP DATABASE IF EXISTS \`${DB_DATABASE}\`; CREATE DATABASE \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
            fi

            # Import
            if mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" \
                -u"${DB_USERNAME}" -p"${DB_PASSWORD}" \
                "${DB_DATABASE}" < "$SQL_FILE" 2>/dev/null; then

                print_success "Base de datos restaurada"

                # Cleanup temp file
                if [ -f "/tmp/bintelx_restore_${BACKUP_ID}.sql" ]; then
                    rm "/tmp/bintelx_restore_${BACKUP_ID}.sql"
                fi
            else
                print_error "Error al restaurar base de datos"
            fi
        else
            print_error "No se encontró .env para obtener credenciales de BD"
        fi
    else
        print_info "Restauración de base de datos omitida"
    fi
else
    print_info "Backup de base de datos no disponible"
fi

# ==========================================
# 5. Restart services
# ==========================================
print_header "Reiniciando Servicios"

read -p "¿Desea reiniciar PHP-FPM y Nginx? [Y/n]: " restart_services

if [[ ! "$restart_services" =~ ^[Nn]$ ]]; then
    # PHP-FPM
    if systemctl is-active --quiet php8.4-fpm 2>/dev/null; then
        print_info "Reiniciando PHP-FPM..."
        sudo systemctl restart php8.4-fpm
        print_success "PHP-FPM reiniciado"
    elif systemctl is-active --quiet php-fpm 2>/dev/null; then
        print_info "Reiniciando PHP-FPM..."
        sudo systemctl restart php-fpm
        print_success "PHP-FPM reiniciado"
    fi

    # Nginx
    print_info "Reiniciando Nginx..."
    if sudo systemctl restart nginx; then
        print_success "Nginx reiniciado"
    else
        print_error "Error al reiniciar Nginx"
    fi
fi

# ==========================================
# Summary
# ==========================================
print_header "Rollback Completado"

echo ""
echo -e "${GREEN}✓ Sistema restaurado desde backup: ${BACKUP_ID}${NC}"
echo ""
echo -e "${CYAN}Archivos pre-rollback guardados con extensión .pre-rollback${NC}"
echo ""

# Run verification
read -p "¿Desea ejecutar verificación del sistema? [Y/n]: " run_verify

if [[ ! "$run_verify" =~ ^[Nn]$ ]]; then
    echo ""
    bash "$(dirname "${BASH_SOURCE[0]}")/verify.sh"
fi

echo ""
print_success "Rollback completado exitosamente!"
