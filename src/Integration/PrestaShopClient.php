<?php

declare(strict_types=1);

namespace SyncBridge\Integration;

use SyncBridge\Support\HttpClient;
use SyncBridge\Support\AttributeTypeGuesser;
use SyncBridge\Support\IntegrationException;

final class PrestaShopClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $languageId;
    private ?array $stateNameById = null;
    private array $optionValueCache = [];
    private array $optionGroupCache = [];
    private int $lastResolvedCarrierId = 0;
    private ?string $lastApiError = null;
    private ?string $lastOutboundXml = null;

    public function __construct(private readonly HttpClient $http)
    {
        $this->baseUrl = rtrim((string) ($_ENV['PS_BASE_URL'] ?? ''), '/');
        $this->apiKey = (string) ($_ENV['PS_API_KEY'] ?? '');
        $this->languageId = (int) ($_ENV['PS_LANGUAGE_ID'] ?? 1);

        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new \RuntimeException('PrestaShop is not configured. Set PS_BASE_URL and PS_API_KEY.');
        }
    }

    public function getAllProducts(): array
    {
        return $this->collectResources('products', []);
    }

    public function getProductsModifiedSince(string $since): array
    {
        $rows = $this->collectResources('products', [
            'filter[date_upd]' => '[' . $since . ',]',
            'date' => '1',
        ]);

        if ($rows !== []) {
            return $rows;
        }

        // Some PrestaShop installations expose products normally but do not honor
        // date filters consistently through the webservice. Fall back to client-side
        // filtering instead of silently reporting zero changed products.
        return array_values(array_filter(
            $this->getAllProducts(),
            static fn (array $product): bool =>
                !empty($product['date_upd']) && strtotime((string) $product['date_upd']) >= strtotime($since)
        ));
    }

    public function getProduct(int $id): ?array
    {
        $rows = $this->collectResources('products', [
            'filter[id]' => '[' . $id . ']',
        ], 1);
        return $rows[0] ?? null;
    }

    public function getCategory(int $id): ?array
    {
        $rows = $this->collectResources('categories', [
            'filter[id]' => '[' . $id . ']',
        ], 1);
        return $rows[0] ?? null;
    }

    public function getAllCategories(): array
    {
        $categories = $this->collectResources('categories', []);
        usort($categories, fn (array $a, array $b): int => strcmp(
            $this->extractLocalizedValue($a['name'] ?? ''),
            $this->extractLocalizedValue($b['name'] ?? '')
        ));
        return $categories;
    }

    public function getAllAttributeValues(): array
    {
        $groups = [];
        foreach ($this->collectResources('product_options', []) as $group) {
            $groups[(int) ($group['id'] ?? 0)] = [
                'id' => (int) ($group['id'] ?? 0),
                'name' => $this->extractLocalizedValue($group['name'] ?? ''),
            ];
        }

        $values = [];
        foreach ($this->collectResources('product_option_values', []) as $value) {
            $groupId = (int) ($value['id_attribute_group'] ?? 0);
            $values[] = [
                'ps_value_id' => (int) ($value['id'] ?? 0),
                'ps_label' => $this->extractLocalizedValue($value['name'] ?? ''),
                'group_id' => $groupId,
                'group_name' => $groups[$groupId]['name'] ?? '',
                'map_type' => $this->guessAttributeMapType($groups[$groupId]['name'] ?? ''),
            ];
        }

        usort($values, static fn (array $a, array $b): int => [$a['group_name'], $a['ps_label']] <=> [$b['group_name'], $b['ps_label']]);
        return $values;
    }

    public function getCombinations(int $productId): array
    {
        $rows = $this->collectResources('combinations', [
            'filter[id_product]' => '[' . $productId . ']',
        ]);

        // Fetch all stock data in one bulk request instead of per-combination
        $stockMap = $this->getStockByProduct($productId);

        $this->prefetchOptionValuesForCombinations($rows);

        foreach ($rows as &$row) {
            $combinationId = (int) ($row['id'] ?? 0);
            $row['quantity'] = $stockMap[$combinationId] ?? 0;
            $row['attributes'] = $this->loadCombinationAttributes($row);
        }

        return $rows;
    }

    /**
     * Collect every product_option_value referenced by the provided combinations
     * and prime the in-memory caches with a single bulk request per resource
     * instead of N GETs per value/group.
     *
     * @param array<int, array<string,mixed>> $combinations
     */
    private function prefetchOptionValuesForCombinations(array $combinations): void
    {
        $valueIds = [];
        foreach ($combinations as $row) {
            $optionValues = $row['associations']['product_option_values'] ?? [];
            if (isset($optionValues['product_option_value'])) {
                $optionValues = $optionValues['product_option_value'];
            }
            if (isset($optionValues['id'])) {
                $optionValues = [$optionValues];
            }
            foreach ($optionValues as $valueRef) {
                $valueId = (int) ($valueRef['id'] ?? 0);
                if ($valueId > 0 && !isset($this->optionValueCache[$valueId])) {
                    $valueIds[$valueId] = true;
                }
            }
        }
        if ($valueIds === []) {
            return;
        }

        $ids = array_keys($valueIds);
        $chunks = array_chunk($ids, 50);
        $groupIds = [];
        foreach ($chunks as $chunk) {
            try {
                $rows = $this->collectResources('product_option_values', [
                    'filter[id]' => '[' . implode('|', array_map('strval', $chunk)) . ']',
                ], 2);
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $value) {
                $valueId = (int) ($value['id'] ?? 0);
                if ($valueId <= 0) {
                    continue;
                }
                $this->optionValueCache[$valueId] = $value;
                $groupId = (int) ($value['id_attribute_group'] ?? 0);
                if ($groupId > 0 && !isset($this->optionGroupCache[$groupId])) {
                    $groupIds[$groupId] = true;
                }
            }
        }

        if ($groupIds === []) {
            return;
        }
        foreach (array_chunk(array_keys($groupIds), 50) as $chunk) {
            try {
                $rows = $this->collectResources('product_options', [
                    'filter[id]' => '[' . implode('|', array_map('strval', $chunk)) . ']',
                ], 2);
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $group) {
                $groupId = (int) ($group['id'] ?? 0);
                if ($groupId > 0) {
                    $this->optionGroupCache[$groupId] = $group;
                }
            }
        }
    }

    private function getStockByProduct(int $productId): array
    {
        // Fetch all stock_availables for this product in a single request
        // This reduces N+1 query problem from 50+ requests down to 1
        try {
            $rows = $this->collectResources('stock_availables', [
                'filter[id_product]' => '[' . $productId . ']',
            ], 100);

            $stock = [];
            foreach ($rows as $row) {
                $combinationId = (int) ($row['id_product_attribute'] ?? 0);
                $quantity = (int) ($row['quantity'] ?? 0);
                $stock[$combinationId] = $quantity;
            }
            return $stock;
        } catch (\Throwable) {
            // If bulk fetch fails, return empty map (quantities will be 0)
            return [];
        }
    }

    public function getProductImageUrls(int $productId, array $product): array
    {
        $images = $product['associations']['images'] ?? $product['images'] ?? [];
        if (isset($images['image'])) {
            $images = $images['image'];
        }
        if (isset($images['id'])) {
            $images = [$images];
        }

        $urls = [];
        foreach ($images as $image) {
            $imageId = (int) ($image['id'] ?? 0);
            if ($imageId <= 0) {
                continue;
            }
            $urls[] = $this->baseUrl . '/api/images/products/' . $productId . '/' . $imageId . '?ws_key=' . rawurlencode($this->apiKey);
        }

        return array_values(array_unique($urls));
    }

    public function findCombinationByReference(string $reference): ?array
    {
        $rows = $this->collectResources('combinations', ['filter[reference]' => '[' . $reference . ']'], 1);
        if ($rows === []) {
            return null;
        }

        return [
            'product_id' => (int) ($rows[0]['id_product'] ?? 0),
            'combo_id' => (int) ($rows[0]['id'] ?? 0),
        ];
    }

    public function findProductIdByReference(string $reference): ?int
    {
        $rows = $this->collectResources('products', ['filter[reference]' => '[' . $reference . ']'], 1);
        return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
    }

    public function findCombinationByEan(string $ean): ?array
    {
        $rows = $this->collectResources('combinations', ['filter[ean13]' => '[' . $ean . ']'], 1);
        if ($rows === []) {
            return null;
        }

        return [
            'product_id' => (int) ($rows[0]['id_product'] ?? 0),
            'combo_id' => (int) ($rows[0]['id'] ?? 0),
        ];
    }

    public function findProductIdByEan(string $ean): ?int
    {
        $rows = $this->collectResources('products', ['filter[ean13]' => '[' . $ean . ']'], 1);
        return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
    }

    public function findOrCreateCustomer(array $customer): ?int
    {
        $email = trim((string) ($customer['email'] ?? ''));
        if ($email === '') {
            return null;
        }

        $rows = $this->collectResources('customers', ['filter[email]' => '[' . $email . ']'], 1);
        if ($rows !== []) {
            return (int) ($rows[0]['id'] ?? 0);
        }

        $payload = [
            'firstname' => $customer['first_name'] ?? 'AboutYou',
            'lastname' => $customer['last_name'] ?? 'Customer',
            'email' => $email,
            'passwd' => password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT),
            'active' => 1,
            'id_lang' => $this->languageId,
        ];

        return $this->createResource('customers', $payload);
    }

    public function findOrCreateAddress(int $customerId, array $address): ?int
    {
        // Reuse an existing address first to avoid strict validation failures
        // on create in customized PrestaShop instances.
        $existing = $this->collectResources('addresses', ['filter[id_customer]' => '[' . $customerId . ']'], 1);
        if ($existing !== []) {
            $existingId = (int) ($existing[0]['id'] ?? 0);
            if ($existingId > 0) {
                return $existingId;
            }
        }

        $alias = trim((string) ($address['alias'] ?? 'Marketplace'));
        $street = trim((string) ($address['address1'] ?? ''));
        $postcode = trim((string) ($address['postcode'] ?? ''));
        $city = trim((string) ($address['city'] ?? ''));
        $countryIso = strtoupper(trim((string) ($address['country_iso'] ?? '')));

        if ($street === '' || $postcode === '' || $city === '' || $countryIso === '') {
            return null;
        }

        $countryId = $this->findCountryIdByIso($countryIso);
        if ($countryId === null) {
            return null;
        }

        $payload = [
            'id_customer' => $customerId,
            'alias' => $alias,
            'firstname' => $address['first_name'] ?? 'AboutYou',
            'lastname' => $address['last_name'] ?? 'Customer',
            'address1' => $street,
            'address2' => $address['address2'] ?? '',
            'postcode' => $postcode,
            'city' => $city,
            'id_country' => $countryId,
            'phone' => $address['phone'] ?? '',
        ];

        $createdId = $this->createResource('addresses', $payload);
        if ($createdId > 0) {
            return $createdId;
        }

        // Final create retry with normalized fields in case shop has stricter validators.
        $fallbackPayload = [
            'id_customer' => $customerId,
            'alias' => substr(($alias !== '' ? $alias : 'Marketplace') . '-' . date('His'), 0, 32),
            'firstname' => trim((string) ($payload['firstname'] ?? 'AboutYou')) ?: 'AboutYou',
            'lastname' => trim((string) ($payload['lastname'] ?? 'Customer')) ?: 'Customer',
            'address1' => $street !== '' ? $street : 'Marketplace Street 1',
            'postcode' => $postcode !== '' ? $postcode : '10115',
            'city' => $city !== '' ? $city : 'Berlin',
            'id_country' => $countryId,
        ];
        $createdId = $this->createResource('addresses', $fallbackPayload);
        return $createdId > 0 ? $createdId : null;
    }

    public function createOrder(array $orderPayload): ?int
    {
        $defaultCarrierId = $this->resolveDefaultCarrierId();
        $this->lastResolvedCarrierId = $defaultCarrierId;
        $defaultCurrencyId = (int) ($_ENV['PS_DEFAULT_CURRENCY_ID'] ?? 1);
        $defaultLanguageId = $this->resolveLanguageId((int) ($_ENV['PS_LANGUAGE_ID'] ?? 1));
        $defaultShopId = (int) ($_ENV['PS_SHOP_ID'] ?? 1);
        [$module, $payment] = $this->resolveOrderModuleAndPayment(
            (string) ($_ENV['PS_ORDER_MODULE'] ?? ''),
            (string) ($_ENV['PS_ORDER_PAYMENT'] ?? '')
        );
        $stateId = $this->resolveOrderStateId((int) ($_ENV['PS_ORDER_STATE_ID'] ?? 3));

        if ($defaultCarrierId <= 0) {
            throw new \RuntimeException('No usable PrestaShop carrier found. Set PS_DEFAULT_CARRIER_ID or enable at least one active carrier.');
        }

        $items = $this->enrichOrderRowItems($orderPayload['items'] ?? []);

        $cartId = $this->createCart([
            'id_currency' => $defaultCurrencyId,
            'id_lang' => $defaultLanguageId,
            'id_customer' => (int) $orderPayload['id_customer'],
            'id_address_delivery' => (int) $orderPayload['id_address_delivery'],
            'id_address_invoice' => (int) $orderPayload['id_address_invoice'],
            'id_carrier' => $defaultCarrierId,
            'id_shop' => $defaultShopId,
            'id_shop_group' => 1,
            'associations' => [
                'cart_rows' => array_map(static fn (array $item): array => [
                    'id_product' => (int) ($item['product_id'] ?? 0),
                    'id_product_attribute' => (int) ($item['combo_id'] ?? 0),
                    'id_address_delivery' => (int) $orderPayload['id_address_delivery'],
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ], $items),
            ],
        ]);

        $totals = $this->calculateTotals($orderPayload + ['items' => $items]);
        $totalPaid6 = $this->fmt6($totals['total_paid']);
        $totalProducts6 = $this->fmt6($totals['total_products']);
        $totalShipping6 = $this->fmt6($totals['total_shipping']);
        $zero6 = '0.000000';
        $secureKey = md5(uniqid((string) $orderPayload['id_customer'], true));
        $zeroDate = '0000-00-00 00:00:00';

        $orderRows = [];
        foreach ($items as $item) {
            $unit = (float) ($item['unit_price'] ?? 0);
            $orderRows[] = [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'product_attribute_id' => (int) ($item['combo_id'] ?? 0),
                'product_quantity' => (int) ($item['quantity'] ?? 1),
                'product_name' => (string) ($item['product_name'] ?? ('Product #' . (int) ($item['product_id'] ?? 0))),
                'product_reference' => (string) ($item['sku'] ?? ''),
                'product_price' => $this->fmt6($unit),
                'unit_price_tax_incl' => $this->fmt6($unit),
                'unit_price_tax_excl' => $this->fmt6($unit),
            ];
        }

        $orderBody = [
            'id_address_delivery' => (int) $orderPayload['id_address_delivery'],
            'id_address_invoice' => (int) $orderPayload['id_address_invoice'],
            'id_cart' => $cartId,
            'id_currency' => $defaultCurrencyId,
            'id_lang' => $defaultLanguageId,
            'id_customer' => (int) $orderPayload['id_customer'],
            'id_carrier' => $defaultCarrierId,
            'current_state' => $stateId,
            'module' => $module,
            'invoice_number' => 0,
            'delivery_number' => 0,
            'delivery_date' => $zeroDate,
            'valid' => 0,
            'id_shop_group' => 1,
            'id_shop' => $defaultShopId,
            'secure_key' => $secureKey,
            'payment' => $payment,
            'recyclable' => 0,
            'gift' => 0,
            'gift_message' => '',
            'mobile_theme' => 0,
            'total_discounts' => $zero6,
            'total_discounts_tax_incl' => $zero6,
            'total_discounts_tax_excl' => $zero6,
            'total_paid' => $totalPaid6,
            'total_paid_tax_incl' => $totalPaid6,
            'total_paid_tax_excl' => $totalPaid6,
            'total_paid_real' => $totalPaid6,
            'total_products' => $totalProducts6,
            'total_products_wt' => $totalProducts6,
            'total_shipping' => $totalShipping6,
            'total_shipping_tax_incl' => $totalShipping6,
            'total_shipping_tax_excl' => $totalShipping6,
            'carrier_tax_rate' => '0.000',
            'total_wrapping' => $zero6,
            'total_wrapping_tax_incl' => $zero6,
            'total_wrapping_tax_excl' => $zero6,
            'round_mode' => 2,
            'round_type' => 2,
            'conversion_rate' => '1.000000',
            'reference' => substr('AY-' . md5((string) ($orderPayload['external_reference'] ?? microtime(true))), 0, 9),
            'associations' => [
                'order_rows' => $orderRows,
            ],
        ];

        $orderId = $this->createResource('orders', $orderBody);
        if ($orderId <= 0) {
            $firstError = $this->lastApiError;
            // Retry with the authoritative synopsis from PrestaShop, filling only required fields.
            $schemaPayload = $this->buildOrdersPayloadFromSchema($orderBody);
            $orderId = $schemaPayload !== null ? $this->createResource('orders', $schemaPayload) : 0;
            $secondError = $this->lastApiError;

            if ($orderId <= 0) {
                // Retry with a minimal payload (no associations) in case strict shop-specific validators reject order_rows.
                $minimal = [
                    'id_address_delivery' => (int) $orderPayload['id_address_delivery'],
                    'id_address_invoice' => (int) $orderPayload['id_address_invoice'],
                    'id_cart' => $cartId,
                    'id_currency' => $defaultCurrencyId,
                    'id_lang' => $defaultLanguageId,
                    'id_customer' => (int) $orderPayload['id_customer'],
                    'id_carrier' => $defaultCarrierId,
                    'id_shop_group' => 1,
                    'id_shop' => $defaultShopId,
                    'current_state' => $stateId,
                    'module' => $module,
                    'payment' => $payment,
                    'secure_key' => $secureKey,
                    'conversion_rate' => '1.000000',
                    'total_paid' => $totalPaid6,
                    'total_paid_real' => $totalPaid6,
                    'total_products' => $totalProducts6,
                    'total_products_wt' => $totalProducts6,
                    'total_shipping' => $totalShipping6,
                    'invoice_number' => 0,
                    'delivery_number' => 0,
                    'delivery_date' => $zeroDate,
                    'valid' => 0,
                ];
                $orderId = $this->createResource('orders', $minimal);
            }

            if ($orderId <= 0) {
                $detail = $this->lastApiError ?: ($secondError ?: $firstError);
                $required = $this->getResourceRequiredFields('orders');
                if ($required !== []) {
                    $missing = array_values(array_diff($required, array_keys($orderBody)));
                    $detail .= ' | ps_required=[' . implode(',', $required) . ']';
                    if ($missing !== []) {
                        $detail .= ' | missing_in_payload=[' . implode(',', $missing) . ']';
                    }
                }
                throw new \RuntimeException('PrestaShop order creation failed. Details: ' . ($detail ?: 'empty 500 body'));
            }
        }

        return $orderId > 0 ? $orderId : null;
    }

    /**
     * Fill in product_name / product_price from PrestaShop when not supplied.
     * @param array<int,array> $items
     * @return array<int,array>
     */
    private function enrichOrderRowItems(array $items): array
    {
        $enriched = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                $enriched[] = $item;
                continue;
            }
            $needsName = empty($item['product_name']);
            $needsPrice = !isset($item['unit_price']) || (float) $item['unit_price'] <= 0;
            if (!$needsName && !$needsPrice) {
                $enriched[] = $item;
                continue;
            }
            $product = null;
            try {
                $product = $this->getProduct($productId);
            } catch (\Throwable) {
                $product = null;
            }
            if (is_array($product)) {
                if ($needsName) {
                    $item['product_name'] = $this->extractLocalizedValue($product['name'] ?? '');
                    if ($item['product_name'] === '') {
                        $item['product_name'] = 'Product #' . $productId;
                    }
                }
                if ($needsPrice) {
                    $price = (float) ($product['price'] ?? 0);
                    if ($price > 0) {
                        $item['unit_price'] = $price;
                    }
                }
            } else {
                if ($needsName) {
                    $item['product_name'] = 'Product #' . $productId;
                }
            }
            $enriched[] = $item;
        }
        return $enriched;
    }

    private function fmt6(float|int|string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    /**
     * Fetch the ?schema=blank XML for a resource from PrestaShop itself and fill
     * it with our payload values. This is the same pattern the official
     * prestashop-webservice-lib uses for add(), ensuring the XML structure
     * (element order, empty nodes, associations wrappers) exactly matches what
     * the shop's WebService expects. Returns null if the schema is unavailable.
     */
    private function buildXmlFromBlankSchema(string $resource, array $payload): ?string
    {
        try {
            $response = $this->request('GET', $resource, ['schema' => 'blank'], null, [
                'Accept' => 'application/xml',
            ], false);
        } catch (\Throwable) {
            return null;
        }

        $body = (string) ($response['body'] ?? '');
        if (trim($body) === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            return null;
        }

        $resourceKey = rtrim($resource, 's');
        $node = $xml->{$resourceKey} ?? null;
        if ($node === null) {
            return null;
        }

        $this->applyPayloadToSchemaNode($node, $payload, $resource);

        $out = $xml->asXML();
        return is_string($out) ? $out : null;
    }

    /**
     * Recursively merge our flat payload into a SimpleXMLElement schema node.
     * - Scalars go into matching leaf nodes.
     * - 'associations' is handled specially so nested rows get expanded.
     */
    private function applyPayloadToSchemaNode(\SimpleXMLElement $node, array $payload, string $resource): void
    {
        foreach ($payload as $key => $value) {
            if ($key === 'associations' && is_array($value)) {
                $assocNode = $node->associations;
                if ($assocNode === null) {
                    continue;
                }
                foreach ($value as $assocName => $rows) {
                    $groupNode = $assocNode->{$assocName} ?? null;
                    if ($groupNode === null || !is_array($rows)) {
                        continue;
                    }
                    // Discover the row node name from the schema template (e.g. <order_row/>).
                    $rowNodeName = null;
                    foreach ($groupNode->children() as $childName => $_child) {
                        $rowNodeName = (string) $childName;
                        break;
                    }
                    if ($rowNodeName === null) {
                        $rowNodeName = rtrim((string) $assocName, 's');
                    }
                    // Clear any template-provided sample rows.
                    unset($groupNode->{$rowNodeName});
                    foreach ($rows as $rowData) {
                        if (!is_array($rowData)) {
                            continue;
                        }
                        $rowNode = $groupNode->addChild($rowNodeName);
                        foreach ($rowData as $field => $fieldValue) {
                            if (is_scalar($fieldValue) || $fieldValue === null) {
                                $rowNode->addChild($field, $this->escapeXmlScalar((string) ($fieldValue ?? '')));
                            }
                        }
                    }
                }
                continue;
            }

            $leaf = $node->{$key} ?? null;
            if ($leaf === null) {
                // Schema doesn't have this field - skip silently (shop doesn't need it).
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $leaf[0] = $this->escapeXmlScalar((string) ($value ?? ''));
            }
        }
    }

    private function escapeXmlScalar(string $value): string
    {
        // SimpleXMLElement handles entity escaping on assignment, but strip
        // control characters PS occasionally rejects.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
    }

    /**
     * Fetch ?schema=synopsis for a resource and return the list of required field names.
     * Returns [] if PS doesn't expose a schema (older versions / permissions).
     */
    public function getResourceRequiredFields(string $resource): array
    {
        try {
            $response = $this->request('GET', $resource, ['schema' => 'synopsis'], null, [
                'Accept' => 'application/xml',
            ], false);
        } catch (\Throwable) {
            return [];
        }

        $body = (string) ($response['body'] ?? '');
        if (trim($body) === '') {
            return [];
        }

        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return [];
        }

        $required = [];
        $key = rtrim($resource, 's');
        $candidates = [$xml->{$key}, $xml->{$resource}];
        $node = null;
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate->count() > 0) {
                $node = $candidate;
                break;
            }
        }
        if ($node === null) {
            return [];
        }

        foreach ($node->children() as $name => $child) {
            $attrs = $child->attributes();
            if ($attrs !== null && (string) ($attrs['required'] ?? '') === 'true') {
                $required[] = (string) $name;
            }
        }

        return $required;
    }

    /**
     * Returns the raw HTTP response for ?schema=synopsis so callers can diagnose
     * empty/permission-restricted responses.
     *
     * @return array{status:int, body:string, error?:string}
     */
    public function rawSchemaResponse(string $resource): array
    {
        try {
            $response = $this->request('GET', $resource, ['schema' => 'synopsis'], null, [
                'Accept' => 'application/xml',
            ], false);
        } catch (IntegrationException $e) {
            return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }

        $body = (string) ($response['body'] ?? '');
        if (strlen($body) > 2000) {
            $body = substr($body, 0, 2000) . '...';
        }
        return [
            'status' => (int) ($response['status'] ?? 0),
            'body' => $body,
        ];
    }

    /**
     * Reads /api which lists resources this API key can see, plus per-resource
     * HTTP verbs. Used to diagnose permission issues when POST /api/orders 500s.
     *
     * @return array{status:int, resources:array<string,array>, raw?:string, error?:string}
     */
    public function probeWebserviceAccount(): array
    {
        try {
            $response = $this->request('GET', '', [], null, ['Accept' => 'application/xml'], false);
        } catch (IntegrationException $e) {
            return ['status' => 0, 'resources' => [], 'error' => $e->getMessage()];
        }

        $body = (string) ($response['body'] ?? '');
        $status = (int) ($response['status'] ?? 0);

        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return [
                'status' => $status,
                'resources' => [],
                'raw' => strlen($body) > 2000 ? substr($body, 0, 2000) . '...' : $body,
            ];
        }

        $resources = [];
        $apiNode = $xml->api ?? $xml;
        foreach ($apiNode->children() as $name => $child) {
            $attrs = $child->attributes();
            $resources[(string) $name] = [
                'get' => ($attrs['get'] ?? null) ? (string) $attrs['get'] : null,
                'post' => ($attrs['post'] ?? null) ? (string) $attrs['post'] : null,
                'put' => ($attrs['put'] ?? null) ? (string) $attrs['put'] : null,
                'delete' => ($attrs['delete'] ?? null) ? (string) $attrs['delete'] : null,
                'head' => ($attrs['head'] ?? null) ? (string) $attrs['head'] : null,
            ];
        }

        return ['status' => $status, 'resources' => $resources];
    }

    private function buildOrdersPayloadFromSchema(array $fullPayload): ?array
    {
        $required = $this->getResourceRequiredFields('orders');
        if ($required === []) {
            return null;
        }

        $payload = [];
        foreach ($required as $field) {
            if (array_key_exists($field, $fullPayload)) {
                $payload[$field] = $fullPayload[$field];
                continue;
            }
            // Reasonable defaults for numeric/tax/date-like fields we didn't pre-populate.
            if (str_starts_with($field, 'total_') || str_contains($field, '_tax_') || $field === 'carrier_tax_rate') {
                $payload[$field] = number_format(0, 2, '.', '');
            } elseif (str_contains($field, 'date')) {
                $payload[$field] = date('Y-m-d H:i:s');
            } else {
                $payload[$field] = '';
            }
        }

        return $payload;
    }

    public function getLastResolvedCarrierId(): int
    {
        return $this->lastResolvedCarrierId;
    }

    public function getLastApiError(): ?string
    {
        return $this->lastApiError;
    }

    public function getLastOutboundXml(): ?string
    {
        return $this->lastOutboundXml;
    }

    public function listOrderStatesBrief(): array
    {
        try {
            $rows = $this->collectResources('order_states', []);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => $this->extractLocalizedValue($row['name'] ?? ''),
                'send_email' => (int) ($row['send_email'] ?? 0),
                'invoice' => (int) ($row['invoice'] ?? 0),
                'paid' => (int) ($row['paid'] ?? 0),
                'shipped' => (int) ($row['shipped'] ?? 0),
                'delivery' => (int) ($row['delivery'] ?? 0),
            ];
        }
        return $out;
    }

    public function listModulesBrief(): array
    {
        try {
            $rows = $this->collectResources('modules', []);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'active' => (int) ($row['active'] ?? 0),
            ];
        }
        return $out;
    }

    public function listCarriersBrief(): array
    {
        try {
            $rows = $this->collectResources('carriers', ['filter[deleted]' => '[0]']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'active' => (int) ($row['active'] ?? 0),
            ];
        }
        return $out;
    }

    public function getOrdersModifiedSince(string $since): array
    {
        $rows = $this->collectResources('orders', [
            'filter[date_upd]' => '[' . $since . ',]',
            'date' => '1',
        ]);

        $states = $this->getOrderStateNames();
        foreach ($rows as &$row) {
            $stateId = (int) ($row['current_state'] ?? 0);
            $row['current_state_name'] = $states[$stateId] ?? null;
        }

        return $rows;
    }

    private function createCart(array $payload): int
    {
        $cartId = $this->createResource('carts', $payload);
        if ($cartId <= 0) {
            // Retry with a minimal cart payload in case associations/cart_rows
            // are rejected by shop-specific validators.
            $fallbackPayload = [
                'id_currency' => (int) ($payload['id_currency'] ?? 1),
                'id_lang' => (int) ($payload['id_lang'] ?? 1),
                'id_customer' => (int) ($payload['id_customer'] ?? 0),
                'id_address_delivery' => (int) ($payload['id_address_delivery'] ?? 0),
                'id_address_invoice' => (int) ($payload['id_address_invoice'] ?? 0),
                'id_carrier' => (int) ($payload['id_carrier'] ?? 0),
                'id_shop' => (int) ($payload['id_shop'] ?? 1),
                'id_shop_group' => (int) ($payload['id_shop_group'] ?? 1),
            ];
            $cartId = $this->createResource('carts', $fallbackPayload);
        }
        if ($cartId <= 0) {
            $detail = $this->lastApiError ? ' Details: ' . $this->lastApiError : '';
            throw new \RuntimeException('Failed to create PrestaShop cart for incoming order.' . $detail);
        }
        return $cartId;
    }

    private function calculateTotals(array $orderPayload): array
    {
        $products = 0.0;
        foreach (($orderPayload['items'] ?? []) as $item) {
            $products += ((float) ($item['unit_price'] ?? 0)) * ((int) ($item['quantity'] ?? 1));
        }

        $shipping = (float) ($orderPayload['total_shipping'] ?? 0);
        $paid = (float) ($orderPayload['total_paid'] ?? ($products + $shipping));

        return [
            'total_products' => number_format($products, 2, '.', ''),
            'total_shipping' => number_format($shipping, 2, '.', ''),
            'total_paid' => number_format($paid, 2, '.', ''),
        ];
    }

    private function resolveDefaultCarrierId(): int
    {
        $configured = (int) ($_ENV['PS_DEFAULT_CARRIER_ID'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        $carriers = $this->collectResources('carriers', ['filter[active]' => '[1]', 'filter[deleted]' => '[0]'], 1);
        foreach ($carriers as $carrier) {
            $id = (int) ($carrier['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private function resolveLanguageId(int $configured): int
    {
        if ($configured > 0) {
            $rows = $this->collectResources('languages', ['filter[id]' => '[' . $configured . ']'], 1);
            $lang = $rows[0] ?? null;
            if (is_array($lang) && (int) ($lang['id'] ?? 0) > 0) {
                return (int) $lang['id'];
            }
        }
        $rows = $this->collectResources('languages', [], 1);
        $fallback = (int) ($rows[0]['id'] ?? 0);
        return $fallback > 0 ? $fallback : max(1, $configured);
    }

    private function resolveOrderStateId(int $configured): int
    {
        if ($configured > 0) {
            $rows = $this->collectResources('order_states', ['filter[id]' => '[' . $configured . ']'], 1);
            $state = $rows[0] ?? null;
            if (is_array($state) && (int) ($state['id'] ?? 0) > 0) {
                return (int) $state['id'];
            }
        }

        $states = $this->listOrderStatesBrief();
        foreach ($states as $state) {
            if ((int) ($state['send_email'] ?? 0) === 0 && (int) ($state['id'] ?? 0) > 0) {
                return (int) $state['id'];
            }
        }
        $fallback = (int) ($states[0]['id'] ?? 0);
        return $fallback > 0 ? $fallback : max(1, $configured);
    }

    /**
     * Select a real installed payment module to avoid empty-body HTTP 500
     * when PrestaShop receives an unknown module in Order::addWs().
     *
     * @return array{0:string,1:string}
     */
    private function resolveOrderModuleAndPayment(string $configuredModule, string $configuredPayment): array
    {
        $modules = $this->listModulesBrief();
        $active = [];
        foreach ($modules as $module) {
            $name = trim((string) ($module['name'] ?? ''));
            if ($name === '' || (int) ($module['active'] ?? 0) !== 1) {
                continue;
            }
            $active[strtolower($name)] = $name;
        }

        $candidate = strtolower(trim($configuredModule));
        if ($candidate !== '' && isset($active[$candidate])) {
            return [$active[$candidate], trim($configuredPayment) !== '' ? trim($configuredPayment) : $active[$candidate]];
        }

        $preferred = ['multisafepay', 'ps_wirepayment', 'bankwire', 'ps_checkpayment', 'checkpayment', 'ps_cashondelivery'];
        foreach ($preferred as $name) {
            if (isset($active[$name])) {
                $payment = trim($configuredPayment) !== '' ? trim($configuredPayment) : $active[$name];
                return [$active[$name], $payment];
            }
        }

        if ($active !== []) {
            $first = reset($active);
            $payment = trim($configuredPayment) !== '' ? trim($configuredPayment) : (string) $first;
            return [(string) $first, $payment];
        }

        // Keep old behavior if module listing isn't accessible.
        $fallbackModule = trim($configuredModule) !== '' ? trim($configuredModule) : 'ps_wirepayment';
        $fallbackPayment = trim($configuredPayment) !== '' ? trim($configuredPayment) : 'Bank wire';
        return [$fallbackModule, $fallbackPayment];
    }



    private function loadCombinationAttributes(array $combination): array
    {
        $optionValues = $combination['associations']['product_option_values'] ?? [];
        if (isset($optionValues['product_option_value'])) {
            $optionValues = $optionValues['product_option_value'];
        }
        if (isset($optionValues['id'])) {
            $optionValues = [$optionValues];
        }

        $attributes = [];
        foreach ($optionValues as $valueRef) {
            $valueId = (int) ($valueRef['id'] ?? 0);
            if ($valueId <= 0) {
                continue;
            }
            $value = $this->optionValueCache[$valueId] ?? $this->getResourceById('product_option_values', $valueId);
            $this->optionValueCache[$valueId] = $value;
            if (!$value) {
                continue;
            }
            $groupId = (int) ($value['id_attribute_group'] ?? 0);
            $group = null;
            if ($groupId > 0) {
                $group = $this->optionGroupCache[$groupId] ?? $this->getResourceById('product_options', $groupId);
                $this->optionGroupCache[$groupId] = $group;
            }
            $attributes[] = [
                'group_id' => $groupId,
                'group_name' => $this->extractLocalizedValue($group['name'] ?? ''),
                'value_id' => $valueId,
                'value_name' => $this->extractLocalizedValue($value['name'] ?? ''),
            ];
        }

        return $attributes;
    }

    private function getOrderStateNames(): array
    {
        if ($this->stateNameById !== null) {
            return $this->stateNameById;
        }

        $states = [];
        foreach ($this->collectResources('order_states', []) as $state) {
            $states[(int) ($state['id'] ?? 0)] = $this->extractLocalizedValue($state['name'] ?? '');
        }

        return $this->stateNameById = $states;
    }

    private function findCountryIdByIso(string $isoCode): ?int
    {
        $rows = $this->collectResources('countries', ['filter[iso_code]' => '[' . $isoCode . ']'], 1);
        return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
    }

    private function collectResources(string $resource, array $filters, int $maxPages = 100): array
    {
        $page = 0;
        $limit = (int) ($_ENV['PS_PAGE_SIZE'] ?? 100);
        $rows = [];

        while ($page < $maxPages) {
            $query = $filters + [
                'display' => 'full',
                'limit' => ($page * $limit) . ',' . $limit,
            ];

            $chunk = $this->requestResourceCollection($resource, $query);
            if ($chunk === []) {
                break;
            }

            $rows = array_merge($rows, $chunk);
            if (count($chunk) < $limit) {
                break;
            }

            $page++;
        }

        return $rows;
    }

    private function getResourceById(string $resource, int $id): ?array
    {
        $response = $this->request('GET', $resource . '/' . $id, [
            'display' => 'full',
        ]);

        $payload = $response['json'] ?? $this->decodeXmlBody($response['body']);
        if (!is_array($payload)) {
            return null;
        }

        $key = rtrim($resource, 's');
        return $payload[$key] ?? null;
    }

    private function createResource(string $resource, array $payload): int
    {
        $this->lastApiError = null;
        // Prefer PrestaShop's own blank schema as a template (same pattern as the
        // official prestashop-webservice-lib's add()). Falls back to a hand-built
        // payload when the shop doesn't expose a schema.
        $xml = $this->buildXmlFromBlankSchema($resource, $payload) ?? $this->buildXmlPayload($resource, $payload);
        $this->lastOutboundXml = $xml;
        try {
            $response = $this->request('POST', $resource, [], $xml, [
                'Content-Type' => 'application/xml',
                'Accept' => 'application/json, application/xml',
            ], true);
        } catch (IntegrationException $e) {
            $this->lastApiError = $e->getMessage();
            return 0;
        }

        $payload = $response['json'] ?? $this->decodeResponseBody((string) ($response['body'] ?? ''));
        $resourceKey = rtrim($resource, 's');
        $id = null;
        if (is_array($payload)) {
            $id = $payload[$resourceKey]['id']
                ?? $payload[$resource][$resourceKey]['id']
                ?? $payload[$resource]['id']
                ?? null;
        }

        if ($id === null) {
            $status = (int) ($response['status'] ?? 0);
            $bodySnippet = trim((string) ($response['body'] ?? ''));
            if (strlen($bodySnippet) > 350) {
                $bodySnippet = substr($bodySnippet, 0, 350) . '...';
            }
            $jsonError = '';
            if (is_array($payload)) {
                $err = $payload['errors'] ?? $payload['error'] ?? null;
                if (is_array($err)) {
                    $jsonError = json_encode($err, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                } elseif ($err !== null) {
                    $jsonError = (string) $err;
                }
            }
            $parts = array_values(array_filter([
                $status > 0 ? ('HTTP ' . $status) : '',
                $jsonError !== '' ? ('error=' . $jsonError) : '',
                $bodySnippet !== '' ? ('body=' . $bodySnippet) : '',
            ]));
            $this->lastApiError = $parts !== [] ? implode(' | ', $parts) : 'Unknown PrestaShop response';
        }

        return $id !== null ? (int) $id : 0;
    }

    private function requestResourceCollection(string $resource, array $query): array
    {
        $response = $this->request('GET', $resource, $query);
        $payload = $response['json'] ?? $this->decodeXmlBody($response['body']);
        if (!is_array($payload)) {
            return [];
        }

        $items = $payload[$resource] ?? [];
        if ($items === []) {
            return [];
        }

        if (isset($items['id'])) {
            return [$items];
        }

        return array_values($items);
    }

    private function request(
        string $method,
        string $path,
        array $query = [],
        ?string $body = null,
        array $headers = [],
        bool $expectJson = true
    ): array {
        $query = $query + ['ws_key' => $this->apiKey, 'output_format' => 'JSON'];
        $url = $this->baseUrl . '/api/' . ltrim($path, '/') . '?' . http_build_query($query);

        return $this->http->request('prestashop', $method, $url, $headers, $body, [
            'expect_json' => $expectJson,
            'min_interval_ms' => (int) ($_ENV['PS_MIN_INTERVAL_MS'] ?? 100),
            'timeout' => (int) ($_ENV['PS_TIMEOUT_SEC'] ?? 60),
        ]);
    }

    private function decodeXmlBody(string $body): ?array
    {
        return $this->decodeResponseBody($body);
    }

    private function decodeResponseBody(string $body): ?array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return null;
        }

        $firstChar = $trimmed[0];
        if ($firstChar === '{' || $firstChar === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $xml = @simplexml_load_string($trimmed);
        if ($xml === false) {
            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $decoded : null;
        }

        try {
            return json_decode(json_encode($xml, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractLocalizedValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['language'])) {
            $value = $value['language'];
        }
        if (isset($value[0])) {
            foreach ($value as $item) {
                if ((int) ($item['id'] ?? 0) === $this->languageId) {
                    return (string) ($item['value'] ?? $item ?? '');
                }
            }
            return (string) ($value[0]['value'] ?? $value[0] ?? '');
        }
        return (string) ($value['value'] ?? '');
    }

    private function buildXmlPayload(string $resource, array $payload): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><prestashop/>');
        $resourceNode = $xml->addChild(rtrim($resource, 's'));
        $this->appendXmlChildren($resourceNode, $payload);
        return $xml->asXML() ?: '';
    }

    private function appendXmlChildren(\SimpleXMLElement $node, array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $wrapper = $node->addChild($key);
                foreach ($value as $row) {
                    $child = $wrapper->addChild(rtrim((string) $key, 's'));
                    if (is_array($row)) {
                        $this->appendXmlChildren($child, $row);
                    } else {
                        $child[0] = htmlspecialchars((string) $row);
                    }
                }
                continue;
            }

            if (is_array($value)) {
                $child = $node->addChild((string) $key);
                $this->appendXmlChildren($child, $value);
                continue;
            }

            $node->addChild((string) $key, htmlspecialchars((string) $value));
        }
    }

    private function guessAttributeMapType(string $groupName): string
    {
        return AttributeTypeGuesser::detect($groupName);
    }
}
