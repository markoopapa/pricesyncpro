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
        
        // --- 1. JAVÍTÁS: MINDIG CSAK A SIMA CIKKSZÁMOT KERESSÜK! ---
        // Nincs több supplier_reference és bonyolult Mód-vizsgálat.
        // Bárki is küldi (Beszállító vagy Electrob.ro), a saját "Reference" mezőnkben keressük a párt.
        $sql = 'SELECT id_product, reference FROM ' . _DB_PREFIX_ . 'product 
                WHERE reference = "' . pSQL($ref) . '"';

        $row = Db::getInstance()->getRow($sql);
        
        // --- 2. JAVÍTÁS: KECSES KIHAGYÁS HIBA HELYETT ---
        if (!$row) {
            // Nem dobunk 'error'-t, így nem fagy le a küldő oldalon a Progress Bar!
            // Ha akarod, hogy a logba bekerüljön, kiveheted a // jelet a következő sor elől:
            // PriceSyncPro::log($ref, "INFO: Termék nem található, békésen kihagyva.", 'warning');
            
            return ['status' => 'skipped', 'message' => 'Product not found'];
        }

        $id_product = (int)$row['id_product'];
        $internalRef = $row['reference'];

        // TILTÓLISTA (Ezt megtartjuk, nagyon hasznos!)
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
