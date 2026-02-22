<?php
# bintelx/geo/geo.endpoint.php
# GeoService - REST Endpoints for Geographic and Currency Configuration

use bX\Router;
use bX\Response;
use bX\Args;
use bX\GeoService;
use bX\Profile;

# =============================================================================
# COUNTRIES
# =============================================================================

# GET /api/geo/countries.json
Router::register(['GET'], 'countries\.json', function () {
    $countries = GeoService::listCountries();
    return Response::json(['data' => [
        'success' => true,
        'items' => $countries,
        'total' => count($countries)
    ]]);
}, ROUTER_SCOPE_PUBLIC);

# GET /api/geo/countries/{code}.json|scon
Router::register(['GET'], 'countries/(?P<code>[A-Z]{2})\.(?P<fmt>json|scon)', function ($code) {
    $country = GeoService::getCountry($code);
    if (!$country) {
        return ['data' => [
            'success' => false,
            'error' => 'NOT_FOUND',
            'message' => "Country {$code} not found"
        ]];
    }

    # Enriquecer con tipos de documento soportados
    $country['documents'] = GeoService::getSupportedIdTypes(strtoupper($code));

    return ['data' => [
        'success' => true,
        'country' => $country
    ]];
}, ROUTER_SCOPE_PUBLIC);

# =============================================================================
# CURRENCIES
# =============================================================================

# GET /api/geo/currencies.json - all active currencies
Router::register(['GET'], 'currencies\.json', function () {
    $currencies = GeoService::listCurrencies();
    return Response::json(['data' => [
        'success' => true,
        'items' => $currencies,
        'total' => count($currencies)
    ]]);
}, ROUTER_SCOPE_READ);

# GET /api/geo/currencies/{code}.json - single currency by code
Router::register(['GET'], 'currencies/(?P<code>[A-Z]{2,3})\.json', function ($code) {
    $currency = GeoService::getCurrency($code);
    if (!$currency) {
        return Response::json(['data' => [
            'success' => false,
            'error' => 'NOT_FOUND',
            'message' => "Currency {$code} not found"
        ]], 404);
    }
    return Response::json(['data' => [
        'success' => true,
        'currency' => $currency
    ]]);
}, ROUTER_SCOPE_READ);

# GET /api/geo/countries/{country}/currencies.json - currencies for a specific country
Router::register(['GET'], 'countries/(?P<country>[A-Z]{2})/currencies\.json', function ($country) {
    $currencies = GeoService::listCurrencies($country);
    return Response::json(['data' => [
        'success' => true,
        'country' => strtoupper($country),
        'items' => $currencies,
        'total' => count($currencies)
    ]]);
}, ROUTER_SCOPE_READ);

# =============================================================================
# EXCHANGE RATES
# =============================================================================

# GET /api/geo/rates/{country}.json?date=2026-01-20
# Returns ALL exchange rates and tax rates in one call
Router::register(['GET'], 'rates/(?P<country>[A-Z]{2})\.json', function ($country) {
    $country = strtoupper($country);
    $date = $_GET['date'] ?? null;

    # Get country's default currency as base
    $countryData = GeoService::getCountry($country);
    $baseCurrency = $countryData['default_currency_code'] ?? 'CLP';

    # Get ALL exchange rates for this base currency (no filtering)
    $exchangeRates = GeoService::getAllExchangeRates($baseCurrency, $date);

    # Get tax rates for this country
    $taxRates = GeoService::getTaxRatesMap($country, $date);

    return Response::json(['data' => [
        'success' => true,
        'country' => $country,
        'baseCurrency' => $baseCurrency,
        'date' => $date ?? date('Y-m-d'),
        'exchangeRates' => $exchangeRates,
        'taxRates' => $taxRates
    ]]);
}, ROUTER_SCOPE_READ);

# GET /api/geo/exchange-rate.json?base=CLP&target=UF&date=2026-01-20
Router::register(['GET'], 'exchange-rate\.json', function () {
    $base = $_GET['base'] ?? 'CLP';
    $target = $_GET['target'] ?? 'UF';
    $date = $_GET['date'] ?? null;

    $rate = GeoService::getExchangeRate($base, $target, $date);

    if (!$rate) {
        return Response::json(['data' => [
            'success' => false,
            'error' => 'NOT_FOUND',
            'message' => "Exchange rate {$base}/{$target} not found for " . ($date ?? date('Y-m-d'))
        ]], 404);
    }

    return Response::json(['data' => [
        'success' => true,
        'rate' => $rate
    ]]);
}, ROUTER_SCOPE_READ);

# GET /api/geo/uf.json?date=2026-01-20
Router::register(['GET'], 'uf\.json', function () {
    $date = $_GET['date'] ?? null;
    $value = GeoService::getUFValue($date);

    if ($value === null) {
        return Response::json(['data' => [
            'success' => false,
            'error' => 'NOT_FOUND',
            'message' => "UF value not found for " . ($date ?? date('Y-m-d'))
        ]], 404);
    }

    return Response::json(['data' => [
        'success' => true,
        'currency' => 'UF',
        'value' => $value,
        'date' => $date ?? date('Y-m-d')
    ]]);
}, ROUTER_SCOPE_READ);

# GET /api/geo/utm.json?date=2026-01-20
Router::register(['GET'], 'utm\.json', function () {
    $date = $_GET['date'] ?? null;
    $value = GeoService::getUTMValue($date);

    if ($value === null) {
        return Response::json(['data' => [
            'success' => false,
            'error' => 'NOT_FOUND',
            'message' => "UTM value not found for " . ($date ?? date('Y-m-d'))
        ]], 404);
    }

    return Response::json(['data' => [
        'success' => true,
        'currency' => 'UTM',
        'value' => $value,
        'date' => $date ?? date('Y-m-d')
    ]]);
}, ROUTER_SCOPE_READ);

# POST /api/geo/convert.json
# Body: { "amount": "1000000", "from": "CLP", "to": "UF", "date": "2026-01-20" }
Router::register(['POST'], 'convert\.json', function () {
    $amount = Args::ctx()->opt['amount'] ?? '0';
    $from = Args::ctx()->opt['from'] ?? 'CLP';
    $to = Args::ctx()->opt['to'] ?? 'UF';
    $date = Args::ctx()->opt['date'] ?? null;

    $result = GeoService::convert($amount, $from, $to, $date);
    return Response::json(['data' => $result]);
}, ROUTER_SCOPE_READ);

# POST /api/geo/exchange-rate.json (WRITE - save new rate)
# Body: { "base": "CLP", "target": "UF", "rate": "38500.00", "effective_from": "2026-01-20", "source_reference": "SII" }
Router::register(['POST'], 'exchange-rate\.json', function () {
    # Validate required fields
    $required = ['base', 'target', 'rate', 'effective_from'];
    foreach ($required as $field) {
        if (empty(Args::ctx()->opt[$field])) {
            return Response::json(['data' => [
                'success' => false,
                'error' => 'MISSING_FIELD',
                'message' => "Field '{$field}' is required"
            ]], 400);
        }
    }

    $base = Args::ctx()->opt['base'];
    $target = Args::ctx()->opt['target'];
    $rate = Args::ctx()->opt['rate'];
    $effectiveFrom = Args::ctx()->opt['effective_from'];
    $effectiveTo = Args::ctx()->opt['effective_to'] ?? null;
    $sourceReference = Args::ctx()->opt['source_reference'] ?? null;

    # scope_entity_id: null = global (requires admin), otherwise use current tenant
    $isGlobal = isset(Args::ctx()->opt['is_global']) && Args::ctx()->opt['is_global'] === true;
    $scopeEntityId = $isGlobal ? null : (Profile::ctx()->scopeEntityId ?? null);

    # Only admins can write global rates
    if ($isGlobal && !Profile::hasRole(roleCode: 'system.admin')) {
        return Response::json(['data' => [
            'success' => false,
            'error' => 'FORBIDDEN',
            'message' => 'Only administrators can set global exchange rates'
        ]], 403);
    }

    $result = GeoService::saveExchangeRate(
        $base,
        $target,
        $rate,
        $effectiveFrom,
        $effectiveTo,
        $sourceReference,
        $scopeEntityId
    );

    $code = $result['success'] ? 200 : 400;
    return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_WRITE);

# =============================================================================
# TAX RATES
# =============================================================================

# GET /api/geo/countries/{country}/tax-rates.json?date=2026-01-20
Router::register(['GET'], 'countries/(?P<country>[A-Z]{2})/tax-rates\.json', function ($country) {
    $date = $_GET['date'] ?? null;
    $map = GeoService::getTaxRatesMap($country, $date);

    return Response::json(['data' => [
        'success' => true,
        'country' => strtoupper($country),
        'date' => $date ?? date('Y-m-d'),
        'rates' => $map
    ]]);
}, ROUTER_SCOPE_READ);

# GET /api/geo/countries/{country}/tax-rates/{code}.json?date=2026-01-20
Router::register(['GET'], 'countries/(?P<country>[A-Z]{2})/tax-rates/(?P<code>[A-Z0-9]+)\.json', function ($country, $code) {
    $date = $_GET['date'] ?? null;
    $rate = GeoService::getTaxRate($country, $code, $date);

    if (!$rate) {
        return Response::json(['data' => [
            'success' => false,
            'error' => 'NOT_FOUND',
            'message' => "Tax rate {$code} not found for {$country}"
        ]], 404);
    }

    return Response::json(['data' => [
        'success' => true,
        'rate' => $rate
    ]]);
}, ROUTER_SCOPE_READ);

# =============================================================================
# LABOR POLICIES
# =============================================================================

# GET /api/geo/countries/{country}/labor-policies.json?date=2026-01-20
Router::register(['GET'], 'countries/(?P<country>[A-Z]{2})/labor-policies\.json', function ($country) {
    $date = $_GET['date'] ?? null;
    $policies = GeoService::getAllLaborPolicies($country, $date);

    return Response::json(['data' => [
        'success' => true,
        'country' => strtoupper($country),
        'date' => $date ?? date('Y-m-d'),
        'items' => $policies,
        'total' => count($policies)
    ]]);
}, ROUTER_SCOPE_READ);

# GET /api/geo/countries/{country}/labor-policies/{key}.json?date=2026-01-20
Router::register(['GET'], 'countries/(?P<country>[A-Z]{2})/labor-policies/(?P<key>[a-z_]+)\.json', function ($country, $key) {
    $date = $_GET['date'] ?? null;
    $policy = GeoService::getLaborPolicy($country, $key, $date);

    if (!$policy) {
        return Response::json(['data' => [
            'success' => false,
            'error' => 'NOT_FOUND',
            'message' => "Labor policy '{$key}' not found for {$country}"
        ]], 404);
    }

    # Decode value_json for convenience
    $policy['value_decoded'] = is_string($policy['value_json'])
        ? json_decode($policy['value_json'], true)
        : $policy['value_json'];

    return Response::json(['data' => [
        'success' => true,
        'policy' => $policy
    ]]);
}, ROUTER_SCOPE_READ);

# =============================================================================
# REGIONS & COMMUNES
# =============================================================================

# GET /api/geo/countries/{country}/regions.json
Router::register(['GET'], 'countries/(?P<country>[A-Z]{2})/regions\.json', function ($country) {
    $regions = GeoService::getRegions($country);

    return Response::json(['data' => [
        'success' => true,
        'country' => strtoupper($country),
        'items' => $regions,
        'total' => count($regions)
    ]]);
}, ROUTER_SCOPE_READ);

# GET /api/geo/regions/{region_id}/communes.json
Router::register(['GET'], 'regions/(?P<region_id>\d+)/communes\.json', function ($region_id) {
    $regionId = (int)$region_id;
    $communes = GeoService::getCommunes($regionId);

    return Response::json(['data' => [
        'success' => true,
        'region_id' => $regionId,
        'items' => $communes,
        'total' => count($communes)
    ]]);
}, ROUTER_SCOPE_READ);

# GET /api/geo/countries/{country}/communes/{code}.json
Router::register(['GET'], 'countries/(?P<country>[A-Z]{2})/communes/(?P<code>[0-9]+)\.json', function ($country, $code) {
    $commune = GeoService::getCommuneByCode($country, $code);

    if (!$commune) {
        return Response::json(['data' => [
            'success' => false,
            'error' => 'NOT_FOUND',
            'message' => "Commune {$code} not found in {$country}"
        ]], 404);
    }

    return Response::json(['data' => [
        'success' => true,
        'commune' => $commune
    ]]);
}, ROUTER_SCOPE_READ);

# =============================================================================
# IDENTITY — Validación de documentos nacionales
# =============================================================================

# GET /api/geo/countries/{code}/identity.json|scon?value=77.475.404-0&type=TAX_ID
# Sin value → retorna tipos de documento soportados
# Con value → valida, normaliza y formatea el identificador
Router::register(['GET'], 'countries/(?P<code>[A-Z]{2})/identity\.(?P<fmt>json|scon)', function ($code) {
    $country = strtoupper($code);
    $value = $_GET['value'] ?? '';
    $type = strtoupper($_GET['type'] ?? '');

    # Sin value → solo retorna documentos soportados por este país
    if (empty($value)) {
        return ['data' => [
            'success' => true,
            'country' => $country,
            'documents' => GeoService::getSupportedIdTypes($country)
        ]];
    }

    # Con value → validar identidad
    $idType = !empty($type) ? $type : (GeoService::detectNationalIdType($country, $value) ?? 'TAX_ID');
    $validation = GeoService::validateNationalId($country, $value, $idType);

    return ['data' => [
        'success' => true,
        'country' => $country,
        'type' => $idType,
        'valid' => $validation['valid'],
        'validation' => $validation['validation'] ?? null,
        'error' => $validation['error'] ?? null,
        'normalized' => GeoService::normalizeNationalId($country, $value, $idType),
        'formatted' => GeoService::formatNationalId($country, $value, $idType)
    ]];
}, ROUTER_SCOPE_PUBLIC);
