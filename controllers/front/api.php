<?php
declare(strict_types=1);

class PriceSyncProApiModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;

    public function initContent()
    {
        parent::initContent();
        header('Content-Type: application/json');

        try {
            require_once _PS_MODULE_DIR_ . 'pricesyncpro/pricesyncpro.php';
            
            $json = Tools::file_get_contents('php://input');
            $data = json_decode($json, true);

            if (!$data || !isset($data['reference'])) {
                die(json_encode(['error' => 'No data received']));
            }

            // Token ellenőrzés
            if (!isset($data['token']) || $data['token'] !== Configuration::get('PSP_TOKEN')) {
                PriceSyncPro::log($data['reference'], "API: Hibás Token!", 'error');
                die(json_encode(['error' => 'Invalid Token']));
            }

            $res = $this->processSync($data);
            die(json_encode($res));

        } catch (Exception $e) {
            // MOST MÁR A HELYI NAPLÓBA IS BEÍRJUK A HIBÁT, HA ÖSSZEOMLIK
            $ref = isset($data['reference']) ? $data['reference'] : 'UNKNOWN';
            PriceSyncPro::log($ref, "KRITIKUS HIBA: " . $e->getMessage(), 'error');
            
            die(json_encode([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ]));
        }
    }

    protected function processSync($data)
    {
        $ref = $data['reference']; 
        $incomingGross = (float)$data['price'];
        
        // --- INTELLIGENS ÉS BIZTONSÁGOS KERESÉS ---
        $mode = Configuration::get('PSP_MODE');

        if ($mode === 'CHAIN') {
            // ELECTROB.RO (Román oldal):
            // Itt a Beszállító küld adatot. SZIGORÚAN csak a supplier_reference-t nézzük!
            // Így ha a beszállító cikkszáma véletlenül egyezik egy másik termékedével, nem lesz hiba.
            $sql = 'SELECT id_product, reference FROM ' . _DB_PREFIX_ . 'product 
                    WHERE supplier_reference = "' . pSQL($ref) . '"';
        } elseif ($mode === 'RECEIVER') {
            // ELEKTROB.HU (Magyar oldal):
            // Itt az Electrob.ro küld, aki már a pontos saját cikkszámot (Reference) adja meg.
            $sql = 'SELECT id_product, reference FROM ' . _DB_PREFIX_ . 'product 
                    WHERE reference = "' . pSQL($ref) . '"';
        } else {
            // BIZTONSÁGI TARTALÉK:
            $sql = 'SELECT id_product, reference FROM ' . _DB_PREFIX_ . 'product 
                    WHERE reference = "' . pSQL($ref) . '" 
                    OR supplier_reference = "' . pSQL($ref) . '"';
        }

        $row = Db::getInstance()->getRow($sql);
        
        if (!$row) {
            PriceSyncPro::log($ref, "API HIBA: Termék nem található ($mode módban)!", 'error');
            return ['error' => 'Product not found'];
        }

        $id_product = (int)$row['id_product'];
        $internalRef = $row['reference'];

        // TILTÓLISTA
        if ($this->isBlacklisted($internalRef)) {
            PriceSyncPro::log($internalRef, "INFO: Tiltólistán van.", 'warning');
            return ['status' => 'skipped'];
        }

        $product = new Product($id_product);
        $multiplier = (float)Configuration::get('PSP_MULTIPLIER', 1);
        $targetGross = $incomingGross * $multiplier;

        // KEREKÍTÉS (HUF/RON)
        $currency = Context::getContext()->currency;
        if ($currency->iso_code === 'HUF') {
            $targetGross = round($targetGross / 5) * 5;
        } else {
            if ($targetGross < 1) $targetGross = round($targetGross, 1);
            elseif ($targetGross < 2) $targetGross = round($targetGross * 2) / 2;
            else $targetGross = round($targetGross);
        }

        // MENTÉS
        $taxRate = (float)$product->getTaxesRate();
        $newNettoPrice = (float)Tools::ps_round($targetGross / (1 + ($taxRate / 100)), 6);

        if (abs((float)$product->price - $newNettoPrice) > 0.01) {
            $product->price = $newNettoPrice;
            if ($product->update()) {
                PriceSyncPro::log($internalRef, "SIKER: Ár frissítve. Új bruttó: $targetGross", 'success');
                return ['status' => 'success'];
            }
        }
        return ['status' => 'no_change'];
    }
	
	protected function isBlacklisted($ref)
    {
        // Lekérdezzük, hogy a cikkszám szerepel-e a tiltólista táblában
        $sql = "SELECT id_blacklist FROM `" . _DB_PREFIX_ . "pricesyncpro_blacklist` 
                WHERE reference = '" . pSQL($ref) . "'";
        
        return (bool)Db::getInstance()->getValue($sql);
    }
}
