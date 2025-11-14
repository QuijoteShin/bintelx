#!/usr/bin/env bash
#
# update.sh - Actualización SEGURA para sistemas existentes
# Para sistemas EN PRODUCCIÓN o que YA funcionan
#
# Diferencias con install.sh:
# - Hace BACKUP automático antes de cada cambio
# - NO sobrescribe .env (solo valida)
# - NO sobrescribe nginx sin confirmación explícita
# - NO importa schemas SQL (solo verifica)
# - Puede ejecutarse sin miedo en producción
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m'

# Project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
MODULES_DIR="${SCRIPT_DIR}/modules"

# Flags
DRY_RUN=false
FORCE=false
SKIP_BACKUP=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --force)
            FORCE=true
            shift
            ;;
        --skip-backup)
            SKIP_BACKUP=true
            shift
            ;;
        --help)
            cat << EOF
Bintelx Update Script - Para sistemas EXISTENTES

Este script es SEGURO para ejecutar en producción.
Hace backup automático antes de cualquier cambio.

Uso: sudo bash update.sh [opciones]

Opciones:
  --dry-run       Simular actualización sin hacer cambios
  --force         Forzar actualización sin confirmaciones
  --skip-backup   NO RECOMENDADO: Omitir backup automático
  --help          Mostrar esta ayuda

Diferencias con install.sh:
  ✓ Hace backup automático
  ✓ NO sobrescribe .env
  ✓ NO sobrescribe nginx sin confirmación
  ✓ NO importa schemas SQL
  ✓ Seguro para producción

Ejemplos:
  # Actualización segura (recomendado)
  sudo bash update.sh

  # Ver qué haría sin ejecutar
  sudo bash update.sh --dry-run

  # Actualización rápida sin confirmaciones
  sudo bash update.sh --force

EOF
            exit 0
            ;;
        *)
            echo -e "${RED}Opción desconocida: $1${NC}"
            echo "Use --help para ayuda"
            exit 1
            ;;
    esac
done

# Welcome screen
clear
echo -e "${MAGENTA}"
cat << "EOF"
╔═══════════════════════════════════════════════════════════════╗
║                                                               ║
║   ██████╗ ██╗███╗   ██╗████████╗███████╗██╗     ██╗  ██╗    ║
║   ██╔══██╗██║████╗  ██║╚══██╔══╝██╔════╝██║     ╚██╗██╔╝    ║
║   ██████╔╝██║██╔██╗ ██║   ██║   █████╗  ██║      ╚███╔╝     ║
║   ██╔══██╗██║██║╚██╗██║   ██║   ██╔══╝  ██║      ██╔██╗     ║
║   ██████╔╝██║██║ ╚████║   ██║   ███████╗███████╗██╔╝ ██╗    ║
║   ╚═════╝ ╚═╝╚═╝  ╚═══╝   ╚═╝   ╚══════╝╚══════╝╚═╝  ╚═╝    ║
║                                                               ║
║                 ACTUALIZACIÓN SEGURA                          ║
║             Para Sistemas Existentes/Producción              ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
EOF
echo -e "${NC}"

if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}⚠  MODO DRY-RUN: No se harán cambios reales${NC}"
    echo ""
fi

echo -e "${CYAN}Sistema:${NC} ${PROJECT_ROOT}"
echo ""

# Check if running as root
if [[ $EUID -ne 0 ]]; then
    echo -e "${YELLOW}⚠  Este script requiere privilegios de superusuario${NC}"
    exit 1
fi

# ==========================================
# DETECTAR SI ES SISTEMA NUEVO O EXISTENTE
# ==========================================
IS_EXISTING_SYSTEM=false

if [ -f "${PROJECT_ROOT}/.env" ] || \
   [ -d "${PROJECT_ROOT}/ssl" ] || \
   [ -f "${PROJECT_ROOT}/bintelx/config/server/nginx.bintelx.conf" ]; then
    IS_EXISTING_SYSTEM=true
fi

if [ "$IS_EXISTING_SYSTEM" = true ]; then
    echo -e "${GREEN}✓ Sistema existente detectado${NC}"
    echo ""
    echo "Este sistema ya está configurado. Procederé con actualización segura."
else
    echo -e "${YELLOW}⚠  Parece ser una instalación nueva${NC}"
    echo ""
    echo "Para instalación nueva, use:"
    echo "  sudo bash install.sh"
    echo ""
    read -p "¿Desea continuar con update.sh de todas formas? [y/N]: " continue_update
    if [[ ! "$continue_update" =~ ^[Yy]$ ]]; then
        exit 0
    fi
fi

echo ""

if [ "$FORCE" = false ]; then
    echo -e "${CYAN}Este script:${NC}"
    echo "  1. Hará backup automático del sistema"
    echo "  2. Verificará dependencias"
    echo "  3. Validará configuración .env (sin modificar)"
    echo "  4. Actualizará código del framework"
    echo "  5. Verificará que todo funcione"
    echo ""
    read -p "¿Desea continuar? [Y/n]: " confirm_update
    if [[ "$confirm_update" =~ ^[Nn]$ ]]; then
        exit 0
    fi
fi

START_TIME=$(date +%s)

# ==========================================
# PASO 1: BACKUP AUTOMÁTICO
# ==========================================
if [ "$SKIP_BACKUP" = false ]; then
    echo ""
    echo -e "${BLUE}╔═══════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║ PASO 1: Creando Backup de Seguridad${NC}"
    echo -e "${BLUE}╚═══════════════════════════════════════════════════════╝${NC}"
    echo ""

    if [ "$DRY_RUN" = false ]; then
        chmod +x "${MODULES_DIR}/backup.sh"

        if bash "${MODULES_DIR}/backup.sh"; then
            echo ""
            echo -e "${GREEN}✓ Backup creado${NC}"
            echo -e "${CYAN}→ Si algo sale mal, ejecute:${NC}"
            echo -e "  cd ${PROJECT_ROOT}/install"
            echo -e "  sudo bash modules/rollback.sh"
            echo ""
        else
            echo ""
            echo -e "${RED}✗ Error al crear backup${NC}"
            read -p "¿Desea continuar sin backup? [y/N]: " continue_no_backup
            if [[ ! "$continue_no_backup" =~ ^[Yy]$ ]]; then
                exit 1
            fi
        fi
    else
        echo -e "${YELLOW}[DRY-RUN] Se crearía backup en: ${PROJECT_ROOT}/.backups/$(date +%Y%m%d_%H%M%S)${NC}"
    fi
else
    echo -e "${YELLOW}⚠  Backup omitido (--skip-backup)${NC}"
fi

# ==========================================
# PASO 2: VERIFICAR DEPENDENCIAS
# ==========================================
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║ PASO 2: Verificando Dependencias${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════╝${NC}"
echo ""

if [ "$DRY_RUN" = false ]; then
    chmod +x "${MODULES_DIR}/check-deps.sh"
    bash "${MODULES_DIR}/check-deps.sh" || true
else
    echo -e "${YELLOW}[DRY-RUN] Se verificarían dependencias${NC}"
fi

# ==========================================
# PASO 3: VALIDAR .ENV (NO MODIFICAR)
# ==========================================
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║ PASO 3: Validando Configuración${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════╝${NC}"
echo ""

ENV_FILE="${PROJECT_ROOT}/.env"

if [ -f "$ENV_FILE" ]; then
    echo -e "${GREEN}✓ .env encontrado${NC}"

    # Validate required keys
    MISSING_KEYS=()
    REQUIRED_KEYS=("DB_HOST" "DB_DATABASE" "DB_USERNAME" "JWT_SECRET")

    for key in "${REQUIRED_KEYS[@]}"; do
        if ! grep -q "^${key}=" "$ENV_FILE"; then
            MISSING_KEYS+=("$key")
        fi
    done

    if [ ${#MISSING_KEYS[@]} -gt 0 ]; then
        echo -e "${YELLOW}⚠  Claves faltantes en .env:${NC}"
        printf '  %s\n' "${MISSING_KEYS[@]}"
        echo ""
        echo "Ejecute el wizard para agregar claves faltantes:"
        echo "  bash ${MODULES_DIR}/wizard-env.sh"
        echo ""
    else
        echo -e "${GREEN}✓ Todas las claves requeridas presentes${NC}"
    fi

    # Test PHP can load config
    if [ "$DRY_RUN" = false ]; then
        echo ""
        echo -n "Probando carga de configuración... "
        if php -r "require '${PROJECT_ROOT}/bintelx/WarmUp.php'; exit(\bX\Config::has('DB_HOST') ? 0 : 1);" 2>/dev/null; then
            echo -e "${GREEN}✓ OK${NC}"
        else
            echo -e "${RED}✗ FAIL${NC}"
            echo ""
            echo -e "${RED}Error: PHP no puede cargar la configuración${NC}"
            exit 1
        fi
    fi
else
    echo -e "${RED}✗ .env no encontrado${NC}"
    echo ""
    echo "Ejecute el wizard para crear .env:"
    echo "  bash ${MODULES_DIR}/wizard-env.sh"
    exit 1
fi

# ==========================================
# PASO 4: ACTUALIZAR CÓDIGO (Git Pull)
# ==========================================
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║ PASO 4: Actualizando Código${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════╝${NC}"
echo ""

cd "$PROJECT_ROOT"

if [ -d ".git" ]; then
    # Check for uncommitted changes
    if [ -n "$(git status --porcelain)" ]; then
        echo -e "${YELLOW}⚠  Hay cambios sin commitear en el repositorio${NC}"
        echo ""
        git status --short
        echo ""

        if [ "$FORCE" = false ]; then
            read -p "¿Desea hacer stash de los cambios y continuar? [y/N]: " do_stash
            if [[ "$do_stash" =~ ^[Yy]$ ]]; then
                if [ "$DRY_RUN" = false ]; then
                    git stash push -m "update.sh auto-stash $(date +%Y%m%d_%H%M%S)"
                    echo -e "${GREEN}✓ Cambios guardados en stash${NC}"
                else
                    echo -e "${YELLOW}[DRY-RUN] Se haría git stash${NC}"
                fi
            else
                echo -e "${YELLOW}Actualización de código omitida${NC}"
            fi
        fi
    fi

    # Git pull
    echo -e "${CYAN}→ Obteniendo actualizaciones...${NC}"

    if [ "$DRY_RUN" = false ]; then
        CURRENT_BRANCH=$(git branch --show-current)
        echo "Rama actual: $CURRENT_BRANCH"

        git fetch origin
        BEHIND=$(git rev-list HEAD..origin/$CURRENT_BRANCH --count)

        if [ "$BEHIND" -gt 0 ]; then
            echo "Hay $BEHIND commits nuevos disponibles"
            git pull origin "$CURRENT_BRANCH"
            echo -e "${GREEN}✓ Código actualizado${NC}"
        else
            echo -e "${GREEN}✓ Código ya está actualizado${NC}"
        fi
    else
        echo -e "${YELLOW}[DRY-RUN] Se haría git pull${NC}"
    fi
else
    echo -e "${YELLOW}⚠  No es un repositorio git${NC}"
    echo "Actualización de código omitida"
fi

# ==========================================
# PASO 5: REINICIAR SERVICIOS
# ==========================================
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║ PASO 5: Reiniciando Servicios${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════╝${NC}"
echo ""

if [ "$FORCE" = false ]; then
    read -p "¿Desea reiniciar PHP-FPM y Nginx? [Y/n]: " restart_services
else
    restart_services="y"
fi

if [[ ! "$restart_services" =~ ^[Nn]$ ]]; then
    if [ "$DRY_RUN" = false ]; then
        # PHP-FPM
        if systemctl is-active --quiet php8.4-fpm 2>/dev/null; then
            echo -n "Reiniciando PHP-FPM... "
            sudo systemctl restart php8.4-fpm
            echo -e "${GREEN}✓${NC}"
        elif systemctl is-active --quiet php-fpm 2>/dev/null; then
            echo -n "Reiniciando PHP-FPM... "
            sudo systemctl restart php-fpm
            echo -e "${GREEN}✓${NC}"
        fi

        # Nginx (reload, not restart - less disruptive)
        if systemctl is-active --quiet nginx 2>/dev/null; then
            echo -n "Recargando Nginx... "
            sudo nginx -t &>/dev/null
            if [ $? -eq 0 ]; then
                sudo systemctl reload nginx
                echo -e "${GREEN}✓${NC}"
            else
                echo -e "${RED}✗ Config inválida${NC}"
            fi
        fi
    else
        echo -e "${YELLOW}[DRY-RUN] Se reiniciarían PHP-FPM y Nginx${NC}"
    fi
else
    echo "Reinicio de servicios omitido"
fi

# ==========================================
# PASO 6: VERIFICACIÓN FINAL
# ==========================================
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║ PASO 6: Verificación Final${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════╝${NC}"
echo ""

if [ "$DRY_RUN" = false ]; then
    chmod +x "${MODULES_DIR}/verify.sh"

    if bash "${MODULES_DIR}/verify.sh"; then
        VERIFICATION_OK=true
    else
        VERIFICATION_OK=false
        echo ""
        echo -e "${RED}✗ Verificación falló${NC}"
        echo ""
        echo "Para revertir cambios:"
        echo "  cd ${PROJECT_ROOT}/install"
        echo "  sudo bash modules/rollback.sh"
    fi
else
    echo -e "${YELLOW}[DRY-RUN] Se ejecutaría verificación${NC}"
    VERIFICATION_OK=true
fi

# ==========================================
# RESUMEN FINAL
# ==========================================
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

clear
echo -e "${GREEN}"
cat << "EOF"
╔═══════════════════════════════════════════════════════════════╗
║                                                               ║
║              ✓  ACTUALIZACIÓN COMPLETADA                     ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
EOF
echo -e "${NC}"

echo ""
echo -e "${GREEN}✓ Sistema actualizado exitosamente${NC}"
echo -e "${CYAN}→ Tiempo total: ${DURATION}s${NC}"
echo ""

if [ "$VERIFICATION_OK" = true ]; then
    echo -e "${GREEN}✓ Verificación: OK${NC}"
else
    echo -e "${YELLOW}⚠ Verificación: CON ADVERTENCIAS${NC}"
fi

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  INFORMACIÓN${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo ""

if [ -f "${PROJECT_ROOT}/.env" ]; then
    APP_URL=$(grep ^APP_URL "${PROJECT_ROOT}/.env" | cut -d'=' -f2)
    echo -e "  ${CYAN}→ URL:${NC} ${APP_URL}"
fi

if [ -d "${PROJECT_ROOT}/.backups" ]; then
    LATEST_BACKUP=$(ls -1t "${PROJECT_ROOT}/.backups" | head -n1)
    echo -e "  ${CYAN}→ Último backup:${NC} ${LATEST_BACKUP}"
    echo -e "  ${CYAN}→ Rollback:${NC} sudo bash modules/rollback.sh ${LATEST_BACKUP}"
fi

echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ¡Actualización completada!${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo ""
