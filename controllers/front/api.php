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
        $ref = $data['reference']; // Beérkező kód (beszállítói vagy saját)
        $incomingGross = (float)$data['price'];

        // 1. KERESÉS: Megnézzük a beszállítói mezőben ÉS a saját cikkszámban is
        // Lekérjük az id_product-ot ÉS a belső reference mezőt is
        $sql = 'SELECT id_product, reference FROM ' . _DB_PREFIX_ . 'product 
                WHERE supplier_reference = "' . pSQL($ref) . '" 
                OR reference = "' . pSQL($ref) . '"';

        $row = Db::getInstance()->getRow($sql);
        
        if (!$row) {
            return ['error' => 'Product not found'];
        }

        $id_product = (int)$row['id_product'];
        $internalRef = $row['reference']; // Ez a webshop saját, belső cikkszáma

        // 2. TILTÓLISTA ELLENŐRZÉSE: A belső cikkszám alapján nézzük
        if ($this->isBlacklisted($internalRef)) {
            PriceSyncPro::log($internalRef, "INFO: Tiltólistán van. Frissítés blokkolva.", 'warning');
            return ['status' => 'skipped', 'reason' => 'Blacklisted'];
        }

        // 3. TERMÉK BETÖLTÉSE ÉS SZORZÓ
        $product = new Product($id_product);
        $multiplier = (float)Configuration::get('PSP_MULTIPLIER', 1);
        $targetGross = $incomingGross * $multiplier;

        // 4. KEREKÍTÉS (Pénznem specifikus)
        $currency = Context::getContext()->currency;
        if ($currency->iso_code === 'HUF') {
            // Magyarország: 5-ös kerekítés
            $targetGross = round($targetGross / 5) * 5;
        } else {
            // ROMÁN (RON) SPECIÁLIS LÉPCSŐZETES KEREKÍTÉS
            if ($targetGross < 1) {
                $targetGross = round($targetGross, 1); // 0.30, 0.80
            } elseif ($targetGross >= 1 && $targetGross < 2) {
                $targetGross = round($targetGross * 2) / 2; // 1.0, 1.5, 2.0
            } else {
                $targetGross = round($targetGross); // Egész szám (2, 6, 256)
            }
        }

        // 5. PONTOS NETTÓSÍTÁS ÉS MENTÉS
        $taxRate = (float)$product->getTaxesRate();
        $newNettoPrice = (float)Tools::ps_round($targetGross / (1 + ($taxRate / 100)), 6);

        // Csak akkor frissítünk, ha legalább 0.01 eltérés van a régi és az új nettó között
        if (abs((float)$product->price - $newNettoPrice) > 0.01) {
            $product->price = $newNettoPrice;
            
            if (!$product->update()) {
                PriceSyncPro::log($internalRef, "HIBA: A termék mentése sikertelen.", 'error');
                return ['error' => 'Save failed'];
            }
            
            PriceSyncPro::log($internalRef, "SIKER: Ár frissítve. Bruttó: $targetGross", 'success');
            return ['status' => 'success'];
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
