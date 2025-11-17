<?php

class MoovEnipakStatusService
{
    /** @var Db */
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function refreshVenipakTracking(int $limit): int
    {
        if ($limit <= 0) {
            $limit = 100;
        }

        $courierRef = (int) Configuration::get('MJVP_COURIER_ID_REFERENCE');
        $pickupRef = (int) Configuration::get('MJVP_PICKUP_ID_REFERENCE');

        $refs = [];
        if ($courierRef > 0) {
            $refs[] = $courierRef;
        }
        if ($pickupRef > 0) {
            $refs[] = $pickupRef;
        }

        if (!$refs) {
            return 0;
        }

        $inRefs = implode(',', array_map('intval', $refs));

                $sql = 'SELECT mo.*
                                FROM ' . _DB_PREFIX_ . "mjvp_orders mo
                                LEFT JOIN " . _DB_PREFIX_ . "orders o ON o.id_order = mo.id_order
                                LEFT JOIN " . _DB_PREFIX_ . "carrier c ON o.id_carrier = c.id_carrier
                                WHERE mo.id_order IS NOT NULL
                                    AND mo.`labels_numbers` IS NOT NULL
                                    AND (mo.`status` IS NULL OR LOWER(mo.`status`) != 'delivered')
                                    AND c.id_reference IN (" . $inRefs . ')
                                ORDER BY mo.id_order ASC
                                LIMIT ' . (int) $limit;

        $rows = $this->db->executeS($sql);
        if ($rows === false) {
            throw new Exception('SQL error in refreshVenipakTracking: ' . pSQL($this->db->getMsgError()) . ' | SQL: ' . $sql);
        }
        if (!$rows) {
            return 0;
        }

        $api = new MjvpApi();
        $updated = 0;

        foreach ($rows as $row) {
            if (empty($row['labels_numbers'])) {
                continue;
            }

            $labels = json_decode($row['labels_numbers'], true);
            if (!is_array($labels) || !$labels) {
                continue;
            }

            $lastStatus = null;

            foreach ($labels as $trackingNumber) {
                $trackingNumber = trim((string) $trackingNumber);
                if ($trackingNumber === '') {
                    continue;
                }

                $csv = $api->getTrackingShipment($trackingNumber);
                if (!$csv) {
                    continue;
                }

                $handle = fopen('data://text/csv,' . $csv, 'r');
                if ($handle === false) {
                    continue;
                }
                $rowIndex = 0;
                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                    $rowIndex++;
                    if ($rowIndex === 1) {
                        // header row; skip
                        continue;
                    }
                    if (isset($data[3]) && $data[3] !== '' && strtolower($data[3]) !== 'status') {
                        $lastStatus = trim($data[3]);
                    }
                }
                fclose($handle);
            }

            if ($lastStatus === null) {
                continue;
            }

            $normalized = Tools::strtolower($lastStatus);

            $this->db->update(
                'mjvp_orders',
                [
                    'status' => pSQL($normalized),
                    'last_select' => date('Y-m-d H:i:s'),
                ],
                'id_order = ' . (int) $row['id_order']
            );

            $updated++;
        }

        return $updated;
    }

    public function applyScenarios(int $limit): int
    {
        if ($limit <= 0) {
            $limit = 100;
        }

        $json = Configuration::get('MOOVENIPAK_AUTO_SCENARIOS');
        $scenarios = $json ? json_decode($json, true) : [];
        if (!is_array($scenarios) || !$scenarios) {
            return 0;
        }

        $totalUpdated = 0;
        $remaining = $limit;

        foreach ($scenarios as $index => $scenario) {
            if ($remaining <= 0) {
                break;
            }

            if (empty($scenario['enabled'])) {
                continue;
            }

            $sourceId = (int) ($scenario['source_state_id'] ?? 0);
            $targetId = (int) ($scenario['target_state_id'] ?? 0);
            $statuses = isset($scenario['venipak_statuses']) && is_array($scenario['venipak_statuses']) ? $scenario['venipak_statuses'] : [];

            if ($sourceId <= 0 || $targetId <= 0 || !$statuses) {
                continue;
            }

            $normalizedStatuses = [];
            foreach ($statuses as $st) {
                $st = trim(Tools::strtolower((string) $st));
                if ($st !== '') {
                    $normalizedStatuses[] = $st;
                }
            }

            if (!$normalizedStatuses) {
                continue;
            }

            $inStatuses = "'" . implode("','", array_map('pSQL', $normalizedStatuses)) . "'";

                        $sql = 'SELECT o.id_order
                                        FROM ' . _DB_PREFIX_ . "orders o
                                        INNER JOIN " . _DB_PREFIX_ . "mjvp_orders mo ON mo.id_order = o.id_order
                                        WHERE o.current_state = " . (int) $sourceId . '
                                            AND LOWER(mo.`status`) IN (' . $inStatuses . ')
                                        ORDER BY o.id_order ASC
                                        LIMIT ' . (int) $remaining;

            $orders = $this->db->executeS($sql);
            if ($orders === false) {
                throw new Exception('SQL error in applyScenarios: ' . pSQL($this->db->getMsgError()) . ' | SQL: ' . $sql);
            }
            if (!$orders) {
                continue;
            }

            foreach ($orders as $row) {
                $idOrder = (int) $row['id_order'];

                try {
                    $order = new Order($idOrder);
                    if (!Validate::isLoadedObject($order)) {
                        continue;
                    }

                    if ((int) $order->current_state !== $sourceId) {
                        continue;
                    }

                    // Ensure proper Context for computations/emails in CLI
                    $context = Context::getContext();
                    if (!$context->shop || (int)$context->shop->id !== (int)$order->id_shop) {
                        $context->shop = new Shop((int)$order->id_shop);
                    }
                    if (!$context->currency || (int)$context->currency->id !== (int)$order->id_currency) {
                        $context->currency = new Currency((int)$order->id_currency);
                    }
                    if (!$context->language || (int)$context->language->id !== (int)$order->id_lang) {
                        $context->language = new Language((int)$order->id_lang);
                    }

                    $history = new OrderHistory();
                    $history->id_order = (int) $order->id;
                    $history->changeIdOrderState($targetId, $order);
                    $added = $history->addWithemail();
                    if ($added === false) {
                        // As a safety net, at least persist the history row without email
                        $h = new OrderHistory();
                        $h->id_order = (int) $order->id;
                        $h->id_order_state = (int) $targetId;
                        $h->add(true);
                    }

                    PrestaShopLogger::addLog(
                        sprintf(
                            'moovenipakstatus: order %d state %d -> %d (scenario %d)',
                            $idOrder,
                            $sourceId,
                            $targetId,
                            $index + 1
                        ),
                        1,
                        null,
                        'Order',
                        $idOrder,
                        true
                    );

                    $totalUpdated++;
                    $remaining--;

                    if ($remaining <= 0) {
                        break 2;
                    }
                } catch (Exception $e) {
                    PrestaShopLogger::addLog(
                        'moovenipakstatus error: ' . $e->getMessage(),
                        2,
                        null,
                        'Order',
                        isset($idOrder) ? $idOrder : 0,
                        true
                    );
                }
            }

            // Reconciliation pass: if order already in target state but history entry missing, create it
            if ($remaining > 0) {
                $sqlRecon = 'SELECT o.id_order
                             FROM ' . _DB_PREFIX_ . "orders o
                             INNER JOIN " . _DB_PREFIX_ . "mjvp_orders mo ON mo.id_order = o.id_order
                             WHERE o.current_state = " . (int)$targetId . '
                               AND LOWER(mo.`status`) IN (' . $inStatuses . ")
                               AND NOT EXISTS (
                                   SELECT 1 FROM " . _DB_PREFIX_ . "order_history oh
                                   WHERE oh.id_order = o.id_order AND oh.id_order_state = " . (int)$targetId . '
                               )
                             ORDER BY o.id_order ASC
                             LIMIT ' . (int)$remaining;

                $recon = $this->db->executeS($sqlRecon);
                if ($recon) {
                    foreach ($recon as $r) {
                        $idOrder = (int)$r['id_order'];
                        try {
                            $order = new Order($idOrder);
                            if (!Validate::isLoadedObject($order)) { continue; }

                            $context = Context::getContext();
                            if (!$context->shop || (int)$context->shop->id !== (int)$order->id_shop) {
                                $context->shop = new Shop((int)$order->id_shop);
                            }
                            if (!$context->currency || (int)$context->currency->id !== (int)$order->id_currency) {
                                $context->currency = new Currency((int)$order->id_currency);
                            }
                            if (!$context->language || (int)$context->language->id !== (int)$order->id_lang) {
                                $context->language = new Language((int)$order->id_lang);
                            }

                            $h = new OrderHistory();
                            $h->id_order = (int)$order->id;
                            $h->id_order_state = (int)$targetId;
                            $h->add(true);

                            PrestaShopLogger::addLog(
                                sprintf('moovenipakstatus: reconciled missing history for order %d state %d (scenario %d)', $idOrder, $targetId, $index + 1),
                                1,
                                null,
                                'Order',
                                $idOrder,
                                true
                            );

                            $totalUpdated++;
                            $remaining--;
                            if ($remaining <= 0) { break; }
                        } catch (Exception $e) {
                            PrestaShopLogger::addLog('moovenipakstatus reconcile error: ' . $e->getMessage(), 2, null, 'Order', $idOrder, true);
                        }
                    }
                }
            }
        }

        return $totalUpdated;
    }
}
