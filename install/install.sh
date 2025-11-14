#!/usr/bin/env bash
#
# install.sh - Instalador principal de Bintelx
# Orquesta todos los módulos de instalación
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

# Installation options
SKIP_DEPS=false
SKIP_ENV=false
SKIP_SSL=false
SKIP_NGINX=false
SKIP_DB=false
SKIP_VERIFY=false
UNATTENDED=false

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --skip-deps) SKIP_DEPS=true; shift ;;
        --skip-env) SKIP_ENV=true; shift ;;
        --skip-ssl) SKIP_SSL=true; shift ;;
        --skip-nginx) SKIP_NGINX=true; shift ;;
        --skip-db) SKIP_DB=true; shift ;;
        --skip-verify) SKIP_VERIFY=true; shift ;;
        --unattended) UNATTENDED=true; shift ;;
        --help)
            cat << EOF
Bintelx Installation Script

Usage: sudo bash install.sh [options]

Options:
  --skip-deps      Skip dependency checking
  --skip-env       Skip environment configuration
  --skip-ssl       Skip SSL certificate setup
  --skip-nginx     Skip Nginx configuration
  --skip-db        Skip database setup
  --skip-verify    Skip installation verification
  --unattended     Run in unattended mode
  --help           Show this help message

Examples:
  # Full installation
  sudo bash install.sh

  # Skip database setup (use existing DB)
  sudo bash install.sh --skip-db

  # Only configure nginx and SSL
  sudo bash install.sh --skip-deps --skip-env --skip-db

EOF
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Welcome screen
clear
echo -e "${BLUE}"
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
║        Framework Headless para Aplicaciones Empresariales    ║
║                                                               ║
║                    INSTALADOR AUTOMATIZADO                   ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
EOF
echo -e "${NC}"

echo ""
echo -e "${CYAN}Directorio de instalación:${NC} ${PROJECT_ROOT}"
echo ""

if [ "$UNATTENDED" = false ]; then
    read -p "Presione Enter para iniciar la instalación o Ctrl+C para cancelar..."
fi

# Check if running as root
if [[ $EUID -ne 0 ]]; then
    echo -e "${YELLOW}⚠  Este script requiere privilegios de superusuario${NC}"
    echo ""
    read -p "¿Desea ejecutar con sudo? [Y/n]: " use_sudo
    if [[ ! "$use_sudo" =~ ^[Nn]$ ]]; then
        exec sudo bash "$0" "$@"
    fi
fi

echo ""
echo -e "${MAGENTA}═══════════════════════════════════════════════════════${NC}"
echo -e "${MAGENTA}             INICIANDO INSTALACIÓN                     ${NC}"
echo -e "${MAGENTA}═══════════════════════════════════════════════════════${NC}"
echo ""

# Track installation progress
STEP=1
TOTAL_STEPS=6
START_TIME=$(date +%s)

run_module() {
    local skip_flag=$1
    local module_script=$2
    local module_name=$3

    echo ""
    echo -e "${BLUE}╔═══════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║ PASO ${STEP}/${TOTAL_STEPS}: ${module_name}${NC}"
    echo -e "${BLUE}╚═══════════════════════════════════════════════════════╝${NC}"
    echo ""

    if [ "$skip_flag" = true ]; then
        echo -e "${YELLOW}⊘ Omitido (--skip flag)${NC}"
    else
        if [ ! -f "${MODULES_DIR}/${module_script}" ]; then
            echo -e "${RED}✗ Error: Módulo no encontrado: ${module_script}${NC}"
            exit 1
        fi

        chmod +x "${MODULES_DIR}/${module_script}"

        if bash "${MODULES_DIR}/${module_script}"; then
            echo ""
            echo -e "${GREEN}✓ ${module_name} completado${NC}"
        else
            echo ""
            echo -e "${RED}✗ ${module_name} falló${NC}"
            echo ""
            read -p "¿Desea continuar con la instalación? [y/N]: " continue_install
            if [[ ! "$continue_install" =~ ^[Yy]$ ]]; then
                echo ""
                echo -e "${RED}Instalación cancelada.${NC}"
                exit 1
            fi
        fi
    fi

    ((STEP++))
}

# Execute installation modules in order
run_module "$SKIP_DEPS" "check-deps.sh" "Verificar Dependencias"
run_module "$SKIP_ENV" "wizard-env.sh" "Configurar Entorno (.env)"
run_module "$SKIP_SSL" "setup-ssl.sh" "Configurar Certificados SSL"
run_module "$SKIP_NGINX" "setup-nginx.sh" "Configurar Nginx"
run_module "$SKIP_DB" "setup-db.sh" "Configurar Base de Datos"
run_module "$SKIP_VERIFY" "verify.sh" "Verificar Instalación"

# Calculate installation time
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
MINUTES=$((DURATION / 60))
SECONDS=$((DURATION % 60))

# Final success message
clear
echo -e "${GREEN}"
cat << "EOF"
╔═══════════════════════════════════════════════════════════════╗
║                                                               ║
║              ✓  INSTALACIÓN COMPLETADA EXITOSAMENTE          ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
EOF
echo -e "${NC}"

echo ""
echo -e "${GREEN}✓ Bintelx ha sido instalado correctamente${NC}"
echo -e "${CYAN}→ Tiempo de instalación: ${MINUTES}m ${SECONDS}s${NC}"
echo ""

# Load APP_URL if env exists
if [ -f "${PROJECT_ROOT}/.env" ]; then
    APP_URL=$(grep ^APP_URL "${PROJECT_ROOT}/.env" | cut -d'=' -f2)
    DOMAIN=$(echo "$APP_URL" | sed 's~https\?://~~g' | cut -d'/' -f1)

    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  INFORMACIÓN DE ACCESO${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  ${CYAN}→ URL de la aplicación:${NC} ${APP_URL}"
    echo -e "  ${CYAN}→ API Endpoint:${NC} ${APP_URL}/api/"
    echo -e "  ${CYAN}→ Health Check:${NC} ${APP_URL}/health"
    echo ""
    echo -e "  ${CYAN}→ Directorio del proyecto:${NC} ${PROJECT_ROOT}"
    echo -e "  ${CYAN}→ Archivo de configuración:${NC} ${PROJECT_ROOT}/.env"
    echo -e "  ${CYAN}→ Logs:${NC} ${PROJECT_ROOT}/log/"
    echo ""
fi

echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  PRÓXIMOS PASOS${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo ""
echo "  1. Agregar el dominio a /etc/hosts (si es local):"
echo "     echo '127.0.0.1 ${DOMAIN:-dev.local}' | sudo tee -a /etc/hosts"
echo ""
echo "  2. Probar la instalación:"
echo "     curl -k ${APP_URL:-https://dev.local}/health"
echo ""
echo "  3. Ver logs del sistema:"
echo "     tail -f ${PROJECT_ROOT}/log/bintelx.log"
echo ""
echo "  4. Reiniciar servicios si es necesario:"
echo "     sudo systemctl restart php8.4-fpm nginx"
echo ""
echo "  5. Consultar documentación:"
echo "     ${PROJECT_ROOT}/bintelx/doc/"
echo "     ${PROJECT_ROOT}/MIGRATION_TO_ENV.md"
echo ""

echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ¡Gracias por instalar Bintelx!${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo ""
