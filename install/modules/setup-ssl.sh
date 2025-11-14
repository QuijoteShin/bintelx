#!/usr/bin/env bash
#
# setup-ssl.sh - Configurar certificados SSL
# Soporta: auto-firmados, Let's Encrypt (symlinks), o certificados existentes
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
SSL_DIR="${PROJECT_ROOT}/ssl"
ENV_FILE="${PROJECT_ROOT}/.env"

# Load domain from .env
if [ -f "$ENV_FILE" ]; then
    source <(grep APP_URL "$ENV_FILE" | sed 's/^/export /')
    DOMAIN=$(echo "$APP_URL" | sed 's~https\?://~~g' | cut -d'/' -f1)
else
    print_error ".env no encontrado. Ejecute wizard-env.sh primero."
    exit 1
fi

clear
print_header "Configuración de Certificados SSL"

echo -e "${CYAN}Dominio detectado:${NC} ${DOMAIN}"
echo ""

# Create SSL directory
mkdir -p "$SSL_DIR"

# Menu de opciones
echo "Seleccione el tipo de certificado SSL:"
echo ""
echo "  1) Auto-firmado (self-signed) - Para desarrollo local"
echo "  2) Let's Encrypt - Certificado gratuito y válido (requiere dominio público)"
echo "  3) Certificados existentes - Usar certificados que ya posee"
echo ""

read -p "Opción [1-3]: " ssl_option

case $ssl_option in
    1)
        # ==========================================
        # OPCIÓN 1: Certificado Auto-firmado
        # ==========================================
        print_header "Generando Certificado Auto-firmado"

        CERT_FILE="${SSL_DIR}/cert.pem"
        KEY_FILE="${SSL_DIR}/key.pem"

        print_info "Generando certificado para: ${DOMAIN}"

        openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
            -keyout "$KEY_FILE" \
            -out "$CERT_FILE" \
            -subj "/C=CL/ST=Chile/L=Santiago/O=Bintelx/CN=${DOMAIN}" \
            -addext "subjectAltName=DNS:${DOMAIN},DNS:*.${DOMAIN}" \
            2>/dev/null

        print_success "Certificado generado"
        print_warning "Este es un certificado auto-firmado. Los navegadores mostrarán advertencia."
        ;;

    2)
        # ==========================================
        # OPCIÓN 2: Let's Encrypt con Symlinks
        # ==========================================
        print_header "Configurando Let's Encrypt"

        # Check if certbot is installed
        if ! command -v certbot &> /dev/null; then
            print_error "Certbot no está instalado"
            echo ""
            echo "Para instalar certbot:"
            echo "  sudo apt install certbot python3-certbot-nginx"
            echo ""
            exit 1
        fi

        print_info "Certbot está instalado"
        echo ""

        # Check if certificates already exist
        LETSENCRYPT_DIR="/etc/letsencrypt/live/${DOMAIN}"

        if [ -d "$LETSENCRYPT_DIR" ]; then
            print_success "Certificados Let's Encrypt ya existen para ${DOMAIN}"
        else
            print_warning "No se encontraron certificados existentes"
            echo ""
            print_info "Para obtener certificados Let's Encrypt, ejecute:"
            echo ""
            echo "  sudo certbot certonly --nginx -d ${DOMAIN}"
            echo ""
            echo "O si prefiere standalone (detiene temporalmente el servidor):"
            echo "  sudo certbot certonly --standalone -d ${DOMAIN}"
            echo ""
            read -p "¿Desea ejecutar certbot ahora? [y/N]: " run_certbot

            if [[ "$run_certbot" =~ ^[Yy]$ ]]; then
                sudo certbot certonly --nginx -d "$DOMAIN"

                if [ ! -d "$LETSENCRYPT_DIR" ]; then
                    print_error "Falló la obtención de certificados"
                    exit 1
                fi
            else
                print_info "Ejecute certbot manualmente y luego vuelva a correr este script"
                exit 0
            fi
        fi

        # Create symlinks
        print_info "Creando enlaces simbólicos..."

        ln -sf "${LETSENCRYPT_DIR}/fullchain.pem" "${SSL_DIR}/cert.pem"
        ln -sf "${LETSENCRYPT_DIR}/privkey.pem" "${SSL_DIR}/key.pem"

        print_success "Enlaces simbólicos creados:"
        echo "  ${SSL_DIR}/cert.pem -> ${LETSENCRYPT_DIR}/fullchain.pem"
        echo "  ${SSL_DIR}/key.pem -> ${LETSENCRYPT_DIR}/privkey.pem"

        # Setup auto-renewal
        print_info "Verificando renovación automática..."

        if systemctl is-enabled certbot.timer &>/dev/null; then
            print_success "Renovación automática ya configurada"
        else
            print_warning "Renovación automática no está configurada"
            read -p "¿Desea habilitar renovación automática? [Y/n]: " enable_renewal
            if [[ ! "$enable_renewal" =~ ^[Nn]$ ]]; then
                sudo systemctl enable certbot.timer
                sudo systemctl start certbot.timer
                print_success "Renovación automática habilitada"
            fi
        fi
        ;;

    3)
        # ==========================================
        # OPCIÓN 3: Certificados Existentes
        # ==========================================
        print_header "Usar Certificados Existentes"

        echo "Ingrese las rutas de sus certificados:"
        echo ""

        read -p "Ruta del certificado (cert/fullchain): " existing_cert
        read -p "Ruta de la clave privada (key): " existing_key

        # Validate files exist
        if [ ! -f "$existing_cert" ]; then
            print_error "Archivo de certificado no encontrado: $existing_cert"
            exit 1
        fi

        if [ ! -f "$existing_key" ]; then
            print_error "Archivo de clave privada no encontrado: $existing_key"
            exit 1
        fi

        # Create symlinks or copy
        read -p "¿Desea crear enlaces simbólicos (s) o copiar archivos (c)? [s/C]: " link_or_copy
        link_or_copy=${link_or_copy:-c}

        if [[ "$link_or_copy" =~ ^[Ss]$ ]]; then
            ln -sf "$existing_cert" "${SSL_DIR}/cert.pem"
            ln -sf "$existing_key" "${SSL_DIR}/key.pem"
            print_success "Enlaces simbólicos creados"
        else
            cp "$existing_cert" "${SSL_DIR}/cert.pem"
            cp "$existing_key" "${SSL_DIR}/key.pem"
            chmod 644 "${SSL_DIR}/cert.pem"
            chmod 600 "${SSL_DIR}/key.pem"
            print_success "Certificados copiados"
        fi
        ;;

    *)
        print_error "Opción inválida"
        exit 1
        ;;
esac

# ==========================================
# Generar DH Parameters (para seguridad)
# ==========================================
print_header "Generando Parámetros DH"

DHPARAM_FILE="${SSL_DIR}/dhparam.pem"

if [ -f "$DHPARAM_FILE" ]; then
    print_info "dhparam.pem ya existe"
else
    print_info "Generando dhparam.pem (esto puede tomar varios minutos)..."
    openssl dhparam -out "$DHPARAM_FILE" 2048 2>/dev/null
    print_success "dhparam.pem generado"
fi

# ==========================================
# Generar QUIC Host Key (para HTTP/3)
# ==========================================
print_header "Generando QUIC Host Key"

QUIC_KEY_FILE="${SSL_DIR}/quic_host_key_file"

if [ -f "$QUIC_KEY_FILE" ]; then
    print_info "quic_host_key_file ya existe"
else
    openssl rand -base64 64 > "$QUIC_KEY_FILE"
    chmod 600 "$QUIC_KEY_FILE"
    print_success "quic_host_key_file generado"
fi

# ==========================================
# Verificar Certificados
# ==========================================
print_header "Verificando Certificados"

CERT_FILE="${SSL_DIR}/cert.pem"
KEY_FILE="${SSL_DIR}/key.pem"

if [ -f "$CERT_FILE" ] && [ -f "$KEY_FILE" ]; then
    print_success "Certificado: ${CERT_FILE}"
    print_success "Clave privada: ${KEY_FILE}"
    print_success "DH Params: ${DHPARAM_FILE}"
    print_success "QUIC Key: ${QUIC_KEY_FILE}"

    # Show certificate info
    echo ""
    print_info "Información del certificado:"
    openssl x509 -in "$CERT_FILE" -noout -subject -dates 2>/dev/null || print_warning "No se pudo leer info del certificado"

else
    print_error "Error: Archivos de certificado no encontrados"
    exit 1
fi

# ==========================================
# Resumen
# ==========================================
print_header "Configuración SSL Completada"

echo ""
echo -e "${GREEN}Archivos SSL creados en:${NC} ${SSL_DIR}"
echo ""
ls -lah "$SSL_DIR"
echo ""

print_success "Certificados SSL configurados exitosamente!"
echo ""
print_info "Siguiente paso: Ejecutar setup-nginx.sh para configurar el servidor web"
