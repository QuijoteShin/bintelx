#!/usr/bin/env bash
#
# verify.sh - Verificar instalación completa
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

PASSED=0
FAILED=0
WARNINGS=0

test_item() {
    local desc=$1
    local command=$2

    echo -n "  Testing: $desc... "

    if eval "$command" &>/dev/null; then
        print_success "OK"
        ((PASSED++))
        return 0
    else
        print_error "FAIL"
        ((FAILED++))
        return 1
    fi
}

test_item_warn() {
    local desc=$1
    local command=$2

    echo -n "  Testing: $desc... "

    if eval "$command" &>/dev/null; then
        print_success "OK"
        ((PASSED++))
        return 0
    else
        print_warning "WARN"
        ((WARNINGS++))
        return 1
    fi
}

clear
echo -e "${BLUE}"
cat << "EOF"
╔═══════════════════════════════════════════════════════╗
║                BINTELX VERIFICATION                    ║
║           Verificando instalación completa            ║
╚═══════════════════════════════════════════════════════╝
EOF
echo -e "${NC}"

# ==========================================
# 1. Archivos de configuración
# ==========================================
print_header "1. Archivos de Configuración"

test_item ".env file exists" "[ -f '$ENV_FILE' ]"
test_item ".env is readable" "[ -r '$ENV_FILE' ]"
test_item ".env has secure permissions" "[ $(stat -c '%a' '$ENV_FILE') -le 600 ]"
test_item "WarmUp.php exists" "[ -f '$PROJECT_ROOT/bintelx/WarmUp.php' ]"
test_item "Config.php exists" "[ -f '$PROJECT_ROOT/bintelx/kernel/Config.php' ]"
test_item "api.php exists" "[ -f '$PROJECT_ROOT/app/api.php' ]"

# ==========================================
# 2. Configuración SSL
# ==========================================
print_header "2. Certificados SSL"

SSL_DIR="${PROJECT_ROOT}/ssl"

test_item "SSL directory exists" "[ -d '$SSL_DIR' ]"
test_item "SSL certificate exists" "[ -f '$SSL_DIR/cert.pem' ]"
test_item "SSL private key exists" "[ -f '$SSL_DIR/key.pem' ]"
test_item "DH parameters exist" "[ -f '$SSL_DIR/dhparam.pem' ]"
test_item_warn "QUIC key exists" "[ -f '$SSL_DIR/quic_host_key_file' ]"

# Verify certificate validity
if [ -f "$SSL_DIR/cert.pem" ]; then
    CERT_EXPIRY=$(openssl x509 -in "$SSL_DIR/cert.pem" -noout -enddate 2>/dev/null | cut -d= -f2)
    CERT_DAYS=$(( ( $(date -d "$CERT_EXPIRY" +%s) - $(date +%s) ) / 86400 ))

    if [ "$CERT_DAYS" -gt 30 ]; then
        print_success "Certificate valid for $CERT_DAYS days"
        ((PASSED++))
    elif [ "$CERT_DAYS" -gt 0 ]; then
        print_warning "Certificate expires in $CERT_DAYS days"
        ((WARNINGS++))
    else
        print_error "Certificate expired!"
        ((FAILED++))
    fi
fi

# ==========================================
# 3. PHP Configuration
# ==========================================
print_header "3. PHP Configuration"

test_item "PHP is installed" "command -v php"
test_item "PHP version >= 8.1" "php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'"
test_item "PHP extension: pdo_mysql" "php -m | grep -q pdo_mysql"
test_item "PHP extension: mbstring" "php -m | grep -q mbstring"
test_item "PHP extension: json" "php -m | grep -q json"
test_item "PHP extension: curl" "php -m | grep -q curl"
test_item_warn "PHP extension: opcache" "php -m | grep -q opcache"

# PHP-FPM
test_item "PHP-FPM is installed" "command -v php-fpm8.4 || command -v php-fpm"
test_item "PHP-FPM is running" "systemctl is-active --quiet php8.4-fpm || systemctl is-active --quiet php-fpm"

# ==========================================
# 4. Database Configuration
# ==========================================
print_header "4. Database Configuration"

if [ -f "$ENV_FILE" ]; then
    source <(grep -E '^DB_' "$ENV_FILE" | sed 's/^/export /')

    test_item "MySQL is running" "systemctl is-active --quiet mysql || systemctl is-active --quiet mariadb"
    test_item "Database connection" "mysql -h'$DB_HOST' -P'$DB_PORT' -u'$DB_USERNAME' -p'$DB_PASSWORD' -e 'SELECT 1' 2>/dev/null"
    test_item "Database exists" "mysql -h'$DB_HOST' -P'$DB_PORT' -u'$DB_USERNAME' -p'$DB_PASSWORD' -e 'USE \`$DB_DATABASE\`' 2>/dev/null"

    TABLE_COUNT=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
        -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}'" -sN 2>/dev/null || echo "0")

    if [ "$TABLE_COUNT" -gt 0 ]; then
        print_success "Database has $TABLE_COUNT tables"
        ((PASSED++))
    else
        print_warning "Database has no tables"
        ((WARNINGS++))
    fi
else
    print_error ".env not found"
    ((FAILED++))
fi

# ==========================================
# 5. Nginx Configuration
# ==========================================
print_header "5. Nginx Configuration"

test_item "Nginx is installed" "command -v nginx"
test_item "Nginx is running" "systemctl is-active --quiet nginx"
test_item "Nginx config is valid" "sudo nginx -t"
test_item "Bintelx site enabled" "[ -L '/etc/nginx/sites-enabled/bintelx' ]"

# Check nginx version and HTTP/3
if command -v nginx &>/dev/null; then
    NGINX_VERSION=$(nginx -v 2>&1 | awk -F'/' '{print $2}')
    echo -e "  ${CYAN}→ Nginx version:${NC} $NGINX_VERSION"

    if nginx -V 2>&1 | grep -q "http_v3"; then
        print_success "HTTP/3 (QUIC) supported"
        ((PASSED++))
    else
        print_warning "HTTP/3 (QUIC) not supported"
        ((WARNINGS++))
    fi
fi

# ==========================================
# 6. Directory Permissions
# ==========================================
print_header "6. Directory Permissions"

test_item "Log directory exists" "[ -d '$PROJECT_ROOT/log' ]"
test_item "Log directory writable" "[ -w '$PROJECT_ROOT/log' ]"
test_item_warn "Upload directory exists" "[ -d '$PROJECT_ROOT/uploads' ]"
if [ -d "$PROJECT_ROOT/uploads" ]; then
    test_item_warn "Upload directory writable" "[ -w '$PROJECT_ROOT/uploads' ]"
fi

# ==========================================
# 7. Application Tests
# ==========================================
print_header "7. Application Tests"

# Test config loading
if php -r "
    require '${PROJECT_ROOT}/bintelx/WarmUp.php';
    exit(\bX\Config::has('DB_HOST') ? 0 : 1);
" 2>/dev/null; then
    print_success "Config class loads correctly"
    ((PASSED++))
else
    print_error "Config class failed to load"
    ((FAILED++))
fi

# Test database connection from PHP
if php -r "
    require '${PROJECT_ROOT}/bintelx/WarmUp.php';
    try {
        \$dsn = \bX\Config::databaseDSN();
        \$db = \bX\Config::database();
        new PDO(\$dsn, \$db['username'], \$db['password']);
        exit(0);
    } catch (Exception \$e) {
        exit(1);
    }
" 2>/dev/null; then
    print_success "PHP can connect to database"
    ((PASSED++))
else
    print_error "PHP database connection failed"
    ((FAILED++))
fi

# ==========================================
# 8. HTTP Tests
# ==========================================
print_header "8. HTTP Tests"

if [ -f "$ENV_FILE" ]; then
    source <(grep APP_URL "$ENV_FILE" | sed 's/^/export /')

    # Test health endpoint
    print_info "Testing health endpoint..."
    if curl -k -s -f "${APP_URL}/health" &>/dev/null; then
        print_success "Health endpoint responds"
        ((PASSED++))
    else
        print_warning "Health endpoint not responding (may not be implemented yet)"
        ((WARNINGS++))
    fi

    # Test API endpoint
    print_info "Testing API endpoint..."
    API_RESPONSE=$(curl -k -s -o /dev/null -w "%{http_code}" "${APP_URL}/api/" 2>/dev/null || echo "000")
    if [ "$API_RESPONSE" != "000" ]; then
        print_success "API endpoint responds (HTTP $API_RESPONSE)"
        ((PASSED++))
    else
        print_warning "API endpoint not responding"
        ((WARNINGS++))
    fi
fi

# ==========================================
# RESUMEN FINAL
# ==========================================
print_header "Verification Summary"

TOTAL=$((PASSED + FAILED + WARNINGS))

echo ""
echo -e "${GREEN}✓ Passed:  ${PASSED}/${TOTAL}${NC}"
[ $WARNINGS -gt 0 ] && echo -e "${YELLOW}⚠ Warnings: ${WARNINGS}/${TOTAL}${NC}"
[ $FAILED -gt 0 ] && echo -e "${RED}✗ Failed:  ${FAILED}/${TOTAL}${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}╔═══════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║  ✓ INSTALLATION VERIFIED SUCCESSFULLY ║${NC}"
    echo -e "${GREEN}╚═══════════════════════════════════════╝${NC}"
    echo ""

    if [ -f "$ENV_FILE" ]; then
        source <(grep APP_URL "$ENV_FILE" | sed 's/^/export /')
        echo -e "Your Bintelx installation is ready!"
        echo ""
        echo -e "  ${CYAN}→ Application URL:${NC} ${APP_URL}"
        echo -e "  ${CYAN}→ API Endpoint:${NC} ${APP_URL}/api/"
        echo -e "  ${CYAN}→ Logs:${NC} ${PROJECT_ROOT}/log/"
        echo ""
    fi

    [ $WARNINGS -gt 0 ] && echo -e "${YELLOW}Note: There are ${WARNINGS} warnings that should be addressed.${NC}"

    exit 0
else
    echo -e "${RED}╔═══════════════════════════════════════╗${NC}"
    echo -e "${RED}║  ✗ INSTALLATION HAS ISSUES            ║${NC}"
    echo -e "${RED}╚═══════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${RED}Please fix the failed tests above before using Bintelx.${NC}"
    echo ""
    exit 1
fi
