<?php
# bintelx/kernel/GeoService.php
# Centralized service for geographic and currency configuration
#
# Features:
#   - Country/Region configuration (timezone, locale, phone codes)
#   - Currency management (CLP, UF, UTM, USD, EUR)
#   - Exchange rates with effective dating
#   - Tax rates by country with effective dating
#   - Labor policies by country with effective dating
#   - Multi-tenant via Tenant:: (force_scope + priorityOrderBy)
#   - ALCOA+ audit logging
#
# Usage:
#   GeoService::getUFValue('2026-01-20');              // '38123.45'
#   GeoService::clpToUF('1000000', '2026-01-20');      // '26.22'
#   GeoService::getTaxRatesMap('CL');                  // ['VAT19'=>'19.00',...]
#   GeoService::getLaborPolicyValue('CL','workday_hours','2026-05-01'); // '40'
#
# Currency Display Pattern (main_currency):
#   Los valores monetarios se almacenan SIEMPRE en CLP en la base de datos.
#   El campo `main_currency` indica cómo MOSTRAR los valores al usuario.
#
#   Backend: Guarda/calcula todo en CLP
#   Frontend: Si main_currency === 'UF', convierte CLP→UF para mostrar
#
#   Ejemplo en frontend (JS):
#     const ufRate = await api.get('/geo/uf.json');  // { value: 38123.45 }
#     const displayValue = main_currency === 'UF'
#         ? `UF ${(clpAmount / ufRate).toFixed(2)}`
#         : formatCLP(clpAmount);
#
#   Endpoint: GET /api/geo/uf.json → { success: true, value: '38123.45' }
#
# @version 1.0.0

namespace bX;

class GeoService
{
    public const VERSION = '1.0.0';

    # Cache namespaces (transparente: Swoole\Table o static array via bX\Cache)
    private const CACHE_RATES = 'geo:rates';
    private const CACHE_TAX = 'geo:tax';
    private const CACHE_POLICY = 'geo:policy';
    private const CACHE_COUNTRY = 'geo:country';
    private const CACHE_CURRENCY = 'geo:currency';
    private const TTL_SCOPED = 3600;    # 1h for tenant-scoped data
    private const TTL_GLOBAL = 86400;   # 24h for global catalogs

    # =========================================================================
    # EXCHANGE RATES
    # =========================================================================

    /**
     * Get exchange rate between two currencies for a specific date
     *
     * @param string $base Base currency code (CLP)
     * @param string $target Target currency code (UF)
     * @param string|null $date Effective date (default: today)
     * @param int|null $scopeEntityId Tenant scope (default: Profile scope)
     * @return array|null Rate info or null if not found
     */
    public static function getExchangeRate(
        string $base,
        string $target,
        ?string $date = null,
        ?int $scopeEntityId = null
    ): ?array {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = $scopeEntityId ?? Profile::$scope_entity_id;

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $cacheKey = "{$base}|{$target}|{$date}|" . (Tenant::resolve($opts) ?? 0);
        return Cache::getOrSet(self::CACHE_RATES, $cacheKey, self::TTL_SCOPED, function() use ($base, $target, $date, $opts) {
            $sql = "SELECT * FROM geo_exchange_rates
                    WHERE base_currency_code = :base
                      AND target_currency_code = :target
                      AND effective_from <= :date
                      AND effective_to >= :date";

            $params = [
                ':base' => strtoupper($base),
                ':target' => strtoupper($target),
                ':date' => $date,
            ];

            $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
            $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", effective_from DESC LIMIT 1";

            $rows = CONN::dml($sql, $params);
            return !empty($rows) ? $rows[0] : null;
        });
    }

    /**
     * Convert amount from one currency to another
     *
     * @param string $amount Amount to convert
     * @param string $from Source currency code
     * @param string $to Target currency code
     * @param string|null $date Effective date
     * @return array Result with converted amount and metadata
     */
    public static function convert(
        string $amount,
        string $from,
        string $to,
        ?string $date = null
    ): array {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return [
                'success' => true,
                'original' => $amount,
                'converted' => $amount,
                'rate' => '1',
                'from' => $from,
                'to' => $to,
                'date' => $date ?? date('Y-m-d')
            ];
        }

        # Try direct rate (from → to)
        $rateInfo = self::getExchangeRate($from, $to, $date);

        if ($rateInfo) {
            # Direct rate: from * (1 / rate) = to
            # Example: CLP to UF -> CLP / rate = UF
            $converted = Math::div($amount, $rateInfo['rate'], 8);
            return [
                'success' => true,
                'original' => $amount,
                'converted' => $converted,
                'rate' => $rateInfo['rate'],
                'rate_type' => 'direct',
                'from' => $from,
                'to' => $to,
                'date' => $date ?? date('Y-m-d'),
                'source_reference' => $rateInfo['source_reference']
            ];
        }

        # Try inverse rate (to → from)
        $inverseInfo = self::getExchangeRate($to, $from, $date);

        if ($inverseInfo) {
            # Inverse rate: amount * rate = converted
            $converted = Math::mul($amount, $inverseInfo['rate'], 8);
            return [
                'success' => true,
                'original' => $amount,
                'converted' => $converted,
                'rate' => $inverseInfo['rate'],
                'rate_type' => 'inverse',
                'from' => $from,
                'to' => $to,
                'date' => $date ?? date('Y-m-d'),
                'source_reference' => $inverseInfo['source_reference']
            ];
        }

        return [
            'success' => false,
            'error' => 'RATE_NOT_FOUND',
            'message' => "No exchange rate found for {$from} to {$to} on " . ($date ?? date('Y-m-d'))
        ];
    }

    /**
     * Get UF value in CLP for a specific date
     *
     * @param string|null $date Effective date
     * @return string|null UF value in CLP or null if not found
     */
    public static function getUFValue(?string $date = null): ?string
    {
        $rate = self::getExchangeRate('CLP', 'UF', $date);
        return $rate ? $rate['rate'] : null;
    }

    /**
     * Get UTM value in CLP for a specific date
     *
     * @param string|null $date Effective date
     * @return string|null UTM value in CLP or null if not found
     */
    public static function getUTMValue(?string $date = null): ?string
    {
        $rate = self::getExchangeRate('CLP', 'UTM', $date);
        return $rate ? $rate['rate'] : null;
    }

    /**
     * Convert CLP to UF
     *
     * @param string $clpAmount Amount in CLP
     * @param string|null $date Effective date
     * @return string|null Amount in UF or null if rate not found
     */
    public static function clpToUF(string $clpAmount, ?string $date = null): ?string
    {
        $result = self::convert($clpAmount, 'CLP', 'UF', $date);
        return $result['success'] ? Math::round($result['converted'], 2) : null;
    }

    /**
     * Convert UF to CLP
     *
     * @param string $ufAmount Amount in UF
     * @param string|null $date Effective date
     * @return string|null Amount in CLP or null if rate not found
     */
    public static function ufToCLP(string $ufAmount, ?string $date = null): ?string
    {
        $result = self::convert($ufAmount, 'UF', 'CLP', $date);
        return $result['success'] ? Math::round($result['converted'], 0) : null;
    }

    /**
     * Get exchange rates map for multiple currencies (batch - avoids N+1 queries)
     * Useful for Units Calculator where multiple conversions are needed
     *
     * @param array $currencies List of currency codes to fetch rates for
     * @param string $baseCurrency Base currency (default: CLP)
     * @param string|null $date Effective date
     * @return array Map of currency_code => rate (relative to base)
     */
    public static function getExchangeRatesMap(
        array $currencies,
        string $baseCurrency = 'CLP',
        ?string $date = null
    ): array {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id;
        $baseCurrency = strtoupper($baseCurrency);

        if (empty($currencies)) {
            return [];
        }

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        # Normalize and dedupe
        $currencies = array_unique(array_map('strtoupper', $currencies));

        # Build placeholders for IN clause
        $placeholders = [];
        $params = [':base' => $baseCurrency, ':date' => $date];
        foreach ($currencies as $i => $code) {
            $key = ":curr{$i}";
            $placeholders[] = $key;
            $params[$key] = $code;
        }
        $inClause = implode(',', $placeholders);

        # Fetch all rates in one query (both direct and inverse)
        $sql = "SELECT base_currency_code, target_currency_code, rate, scope_entity_id
                FROM geo_exchange_rates
                WHERE ((base_currency_code = :base AND target_currency_code IN ({$inClause}))
                    OR (target_currency_code = :base AND base_currency_code IN ({$inClause})))
                  AND effective_from <= :date
                  AND effective_to >= :date";

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
        $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", effective_from DESC";

        $rows = CONN::dml($sql, $params);

        # Build map with priority: tenant-specific > global, direct > inverse
        # ORDER BY already puts tenant rows first → first-seen wins
        $map = [$baseCurrency => '1'];
        $seen = [];

        foreach ($rows as $row) {
            $base = $row['base_currency_code'];
            $target = $row['target_currency_code'];
            $rate = $row['rate'];

            if ($base === $baseCurrency) {
                $key = "{$target}|direct";
                if (!isset($seen[$key])) {
                    $map[$target] = $rate;
                    $seen[$key] = true;
                }
            } else {
                $key = "{$base}|inverse";
                if (!isset($seen[$key]) && !isset($map[$base])) {
                    $map[$base] = Math::div('1', $rate, 8);
                    $seen[$key] = true;
                }
            }
        }

        return $map;
    }

    /**
     * Get ALL exchange rates for a base currency (no filtering by currency list)
     * Returns all available rates from geo_exchange_rates table
     *
     * Uses DML callback for memory efficiency - processes rows as they stream
     *
     * @param string $baseCurrency Base currency code (default: CLP)
     * @param string|null $date Effective date
     * @return array Map of target_currency_code => rate
     */
    public static function getAllExchangeRates(
        string $baseCurrency = 'CLP',
        ?string $date = null
    ): array {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id;
        $baseCurrency = strtoupper($baseCurrency);

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = "SELECT target_currency_code, rate
                FROM geo_exchange_rates
                WHERE base_currency_code = :base
                  AND effective_from <= :date
                  AND effective_to >= :date";

        $params = [
            ':base' => $baseCurrency,
            ':date' => $date,
        ];

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
        $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", effective_from DESC";

        # Build map — ORDER BY puts tenant first → first-seen wins
        $map = [];

        CONN::dml($sql, $params, function($row) use (&$map) {
            $target = $row['target_currency_code'];
            if (!isset($map[$target])) {
                $map[$target] = (float)$row['rate'];
            }
        });

        return $map;
    }

    /**
     * Save exchange rate with ALCOA+ audit
     *
     * Global rates: admin enters global workspace (scope=GLOBAL_TENANT_ID) to save
     *
     * @param string $base Base currency code
     * @param string $target Target currency code
     * @param string $rate Rate value
     * @param string $effectiveFrom Effective from date
     * @param string|null $effectiveTo Effective to date (default: 9999-12-31)
     * @param string|null $sourceReference Source reference (SII, BCCH, manual)
     * @param int|null $scopeEntityId Tenant scope (from Profile if not provided)
     * @return array Result with success status
     */
    public static function saveExchangeRate(
        string $base,
        string $target,
        string $rate,
        string $effectiveFrom,
        ?string $effectiveTo = null,
        ?string $sourceReference = null,
        ?int $scopeEntityId = null
    ): array {
        $profileId = Profile::$profile_id ?? 0;
        $scopeEntityId = $scopeEntityId ?? Profile::$scope_entity_id;
        if ($scopeEntityId <= 0) $scopeEntityId = null;

        # Validate rate
        if (Math::lte($rate, '0')) {
            return ['success' => false, 'error' => 'INVALID_RATE', 'message' => 'Rate must be positive'];
        }

        # Close previous period if exists (exact scope match)
        $sqlUpdate = "UPDATE geo_exchange_rates
                      SET effective_to = DATE_SUB(:effective_from, INTERVAL 1 DAY)
                      WHERE base_currency_code = :base
                        AND target_currency_code = :target
                        AND effective_to = '9999-12-31'
                        AND scope_entity_id = :scope";

        CONN::nodml($sqlUpdate, [
            ':base' => strtoupper($base),
            ':target' => strtoupper($target),
            ':effective_from' => $effectiveFrom,
            ':scope' => $scopeEntityId
        ]);

        # Calculate hash for ALCOA+
        $hashData = json_encode([
            'base' => strtoupper($base),
            'target' => strtoupper($target),
            'rate' => $rate,
            'effective_from' => $effectiveFrom,
            'timestamp' => microtime(true)
        ]);
        $rateHash = hash('sha256', $hashData);

        # Insert new rate
        $sqlInsert = "INSERT INTO geo_exchange_rates
                      (base_currency_code, target_currency_code, rate, effective_from, effective_to,
                       source_reference, scope_entity_id, rate_hash, created_by)
                      VALUES (:base, :target, :rate, :effective_from, :effective_to,
                              :source, :scope, :hash, :created_by)";

        $result = CONN::nodml($sqlInsert, [
            ':base' => strtoupper($base),
            ':target' => strtoupper($target),
            ':rate' => $rate,
            ':effective_from' => $effectiveFrom,
            ':effective_to' => $effectiveTo ?? '9999-12-31',
            ':source' => $sourceReference,
            ':scope' => $scopeEntityId,
            ':hash' => $rateHash,
            ':created_by' => $profileId
        ]);

        if (!$result['success']) {
            return ['success' => false, 'error' => 'INSERT_FAILED', 'message' => $result['error']];
        }

        # Audit log
        self::auditLog('geo_exchange_rates', (int)$result['last_id'], 'INSERT', null, [
            'base' => strtoupper($base),
            'target' => strtoupper($target),
            'rate' => $rate,
            'effective_from' => $effectiveFrom
        ]);

        # Clear cache (local + Channel Server)
        Cache::flush(self::CACHE_RATES);
        Cache::notifyChannel(self::CACHE_RATES);

        return [
            'success' => true,
            'exchange_rate_id' => $result['last_id'],
            'rate_hash' => $rateHash
        ];
    }

    # =========================================================================
    # TAX RATES
    # =========================================================================

    /**
     * Get a specific tax rate
     *
     * @param string $country Country code (CL)
     * @param string $code Tax code (VAT19)
     * @param string|null $date Effective date
     * @return array|null Tax rate info or null
     */
    public static function getTaxRate(
        string $country,
        string $code,
        ?string $date = null
    ): ?array {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id;

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $cacheKey = "{$country}|{$code}|{$date}|" . (Tenant::resolve($opts) ?? 0);
        return Cache::getOrSet(self::CACHE_TAX, $cacheKey, self::TTL_SCOPED, function() use ($country, $code, $date, $opts) {
            $sql = "SELECT * FROM geo_tax_rates
                    WHERE country_code = :country
                      AND tax_code = :code
                      AND effective_from <= :date
                      AND effective_to >= :date";

            $params = [
                ':country' => strtoupper($country),
                ':code' => $code,
                ':date' => $date,
            ];

            $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
            $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", effective_from DESC LIMIT 1";

            $rows = CONN::dml($sql, $params);
            return !empty($rows) ? $rows[0] : null;
        });
    }

    /**
     * Get tax rates map for a country (code => rate)
     *
     * Uses DML callback for memory efficiency
     *
     * @param string $country Country code
     * @param string|null $date Effective date
     * @return array Map of tax_code => rate
     */
    public static function getTaxRatesMap(string $country, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id;

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = "SELECT tax_code, rate FROM geo_tax_rates
                WHERE country_code = :country
                  AND effective_from <= :date
                  AND effective_to >= :date";

        $params = [
            ':country' => strtoupper($country),
            ':date' => $date,
        ];

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
        $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", effective_from DESC";

        $map = [];

        CONN::dml($sql, $params, function($row) use (&$map) {
            # First occurrence wins (tenant > global via ORDER BY priority)
            if (!isset($map[$row['tax_code']])) {
                $map[$row['tax_code']] = Math::round($row['rate'], 2);
            }
        });

        return $map;
    }

    /**
     * Get default tax rate for a country
     *
     * @param string $country Country code
     * @param string|null $date Effective date
     * @return array|null Default tax rate info
     */
    public static function getDefaultTaxRate(string $country, ?string $date = null): ?array
    {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id;

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = "SELECT * FROM geo_tax_rates
                WHERE country_code = :country
                  AND is_default = 1
                  AND effective_from <= :date
                  AND effective_to >= :date";

        $params = [
            ':country' => strtoupper($country),
            ':date' => $date,
        ];

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
        $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", effective_from DESC LIMIT 1";

        $rows = CONN::dml($sql, $params);
        return !empty($rows) ? $rows[0] : null;
    }

    # =========================================================================
    # LABOR POLICIES
    # =========================================================================

    /**
     * Get a labor policy
     *
     * @param string $country Country code
     * @param string $key Policy key (workday_hours, vacation_days)
     * @param string|null $date Effective date
     * @return array|null Policy info or null
     */
    public static function getLaborPolicy(
        string $country,
        string $key,
        ?string $date = null
    ): ?array {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id;

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $cacheKey = "{$country}|{$key}|{$date}|" . (Tenant::resolve($opts) ?? 0);
        return Cache::getOrSet(self::CACHE_POLICY, $cacheKey, self::TTL_SCOPED, function() use ($country, $key, $date, $opts) {
            $sql = "SELECT * FROM geo_labor_policies
                    WHERE country_code = :country
                      AND policy_key = :key
                      AND effective_from <= :date
                      AND effective_to >= :date";

            $params = [
                ':country' => strtoupper($country),
                ':key' => $key,
                ':date' => $date,
            ];

            $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
            $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", effective_from DESC LIMIT 1";

            $rows = CONN::dml($sql, $params);
            return !empty($rows) ? $rows[0] : null;
        });
    }

    /**
     * Get labor policy value (extracted from JSON)
     *
     * @param string $country Country code
     * @param string $key Policy key
     * @param string|null $date Effective date
     * @return string|null Policy value or null
     */
    public static function getLaborPolicyValue(
        string $country,
        string $key,
        ?string $date = null
    ): ?string {
        $policy = self::getLaborPolicy($country, $key, $date);

        if (!$policy) {
            return null;
        }

        $valueJson = is_string($policy['value_json'])
            ? json_decode($policy['value_json'], true)
            : $policy['value_json'];

        return isset($valueJson['value']) ? (string)$valueJson['value'] : null;
    }

    /**
     * Get all labor policies for a country
     *
     * @param string $country Country code
     * @param string|null $date Effective date
     * @return array List of policies
     */
    public static function getAllLaborPolicies(string $country, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id;

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = "SELECT * FROM geo_labor_policies
                WHERE country_code = :country
                  AND effective_from <= :date
                  AND effective_to >= :date";

        $params = [
            ':country' => strtoupper($country),
            ':date' => $date,
        ];

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
        $sql .= " ORDER BY policy_key, " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", effective_from DESC";

        $rows = CONN::dml($sql, $params);

        # Dedupe by policy_key (first per key = tenant-specific via ORDER BY)
        $policies = [];
        foreach ($rows as $row) {
            if (!isset($policies[$row['policy_key']])) {
                $policies[$row['policy_key']] = $row;
            }
        }

        return array_values($policies);
    }

    # =========================================================================
    # COUNTRIES & CURRENCIES
    # =========================================================================

    /**
     * Get country by code
     *
     * @param string $code Country code (CL)
     * @return array|null Country info or null
     */
    public static function getCountry(string $code): ?array
    {
        $code = strtoupper($code);

        return Cache::getOrSet(self::CACHE_COUNTRY, $code, self::TTL_GLOBAL, function() use ($code) {
            $sql = "SELECT * FROM geo_countries WHERE country_code = :code AND is_active = 1";
            $rows = CONN::dml($sql, [':code' => $code]);
            return !empty($rows) ? $rows[0] : null;
        });
    }

    /**
     * List all active countries
     *
     * @return array List of countries
     */
    public static function listCountries(): array
    {
        $sql = "SELECT * FROM geo_countries WHERE is_active = 1 ORDER BY name_en";
        return CONN::dml($sql, []);
    }

    /**
     * Get currency by code
     *
     * @param string $code Currency code (CLP, UF)
     * @return array|null Currency info or null
     */
    public static function getCurrency(string $code): ?array
    {
        $code = strtoupper($code);

        return Cache::getOrSet(self::CACHE_CURRENCY, $code, self::TTL_GLOBAL, function() use ($code) {
            $sql = "SELECT * FROM geo_currencies WHERE currency_code = :code AND is_active = 1";
            $rows = CONN::dml($sql, [':code' => $code]);
            return !empty($rows) ? $rows[0] : null;
        });
    }

    /**
     * List currencies (optionally filtered by country)
     *
     * @param string|null $country Country code filter
     * @return array List of currencies
     */
    public static function listCurrencies(?string $country = null): array
    {
        $sql = "SELECT * FROM geo_currencies WHERE is_active = 1";
        $params = [];

        if ($country !== null) {
            $sql .= " AND (country_code = :country OR country_code IS NULL)";
            $params[':country'] = strtoupper($country);
        }

        $sql .= " ORDER BY currency_code";
        return CONN::dml($sql, $params);
    }

    # =========================================================================
    # REGIONS & COMMUNES
    # =========================================================================

    /**
     * Get regions for a country
     *
     * @param string $country Country code
     * @return array List of regions
     */
    public static function getRegions(string $country): array
    {
        $sql = "SELECT * FROM geo_regions
                WHERE country_code = :country AND is_active = 1
                ORDER BY region_ordinal, region_name";
        return CONN::dml($sql, [':country' => strtoupper($country)]);
    }

    /**
     * Get communes for a region
     *
     * @param int $regionId Region ID
     * @return array List of communes
     */
    public static function getCommunes(int $regionId): array
    {
        $sql = "SELECT * FROM geo_communes
                WHERE region_id = :region_id AND is_active = 1
                ORDER BY commune_name";
        return CONN::dml($sql, [':region_id' => $regionId]);
    }

    /**
     * Get commune by code
     *
     * @param string $country Country code
     * @param string $code Commune code
     * @return array|null Commune info or null
     */
    public static function getCommuneByCode(string $country, string $code): ?array
    {
        $sql = "SELECT c.*, r.region_name, r.region_code
                FROM geo_communes c
                JOIN geo_regions r ON c.region_id = r.region_id
                WHERE c.country_code = :country AND c.commune_code = :code AND c.is_active = 1";

        $rows = CONN::dml($sql, [
            ':country' => strtoupper($country),
            ':code' => $code
        ]);

        return !empty($rows) ? $rows[0] : null;
    }

    # =========================================================================
    # FORMATTING HELPERS
    # =========================================================================

    /**
     * Format currency amount according to currency rules
     *
     * @param string $amount Amount to format
     * @param string $currencyCode Currency code
     * @param bool $includeSymbol Include currency symbol
     * @return string Formatted amount
     */
    public static function formatCurrency(
        string $amount,
        string $currencyCode,
        bool $includeSymbol = true
    ): string {
        $currency = self::getCurrency($currencyCode);
        $decimals = $currency ? (int)$currency['decimal_digits'] : 2;
        $symbol = $currency ? $currency['currency_symbol'] : '';

        $formatted = Math::format($amount, $decimals, ',', '.');

        if ($includeSymbol && $symbol) {
            return "{$symbol} {$formatted}";
        }

        return $formatted;
    }

    # =========================================================================
    # AUDIT LOG
    # =========================================================================

    /**
     * Record audit log entry
     *
     * @param string $tableName Table name
     * @param int $recordId Record ID
     * @param string $action Action (INSERT, UPDATE, DELETE)
     * @param array|null $oldValue Old value
     * @param array|null $newValue New value
     */
    private static function auditLog(
        string $tableName,
        int $recordId,
        string $action,
        ?array $oldValue,
        ?array $newValue
    ): void {
        $sql = "INSERT INTO geo_audit_log
                (table_name, record_id, action, old_value, new_value, profile_id, ip_address, user_agent)
                VALUES (:table, :record, :action, :old, :new, :profile, :ip, :ua)";

        CONN::nodml($sql, [
            ':table' => $tableName,
            ':record' => $recordId,
            ':action' => $action,
            ':old' => $oldValue ? json_encode($oldValue) : null,
            ':new' => $newValue ? json_encode($newValue) : null,
            ':profile' => Profile::$profile_id ?? 0,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null
        ]);
    }

    /**
     * Clear all caches
     */
    public static function clearCache(): void
    {
        Cache::flush(self::CACHE_RATES);
        Cache::flush(self::CACHE_TAX);
        Cache::flush(self::CACHE_POLICY);
        Cache::flush(self::CACHE_COUNTRY);
        Cache::flush(self::CACHE_CURRENCY);
        # Notificar al Channel Server
        Cache::notifyChannel(self::CACHE_RATES);
        Cache::notifyChannel(self::CACHE_TAX);
        Cache::notifyChannel(self::CACHE_POLICY);
        Cache::notifyChannel(self::CACHE_COUNTRY);
        Cache::notifyChannel(self::CACHE_CURRENCY);
    }
}
