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
		
		if ($this->isBlacklisted($ref)) {
            // Ha tiltólistás, beírjuk a naplóba és nem csinálunk semmit
            PriceSyncPro::log($ref, "INFO: Tiltólistán van. Beszállítói frissítés blokkolva.", 'warning');
            return ['status' => 'skipped', 'reason' => 'Blacklisted'];
        }

        $id_product = (int)Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($ref) . '"');
        
        if (!$id_product) {
            return ['error' => 'Product not found'];
        }

        $product = new Product($id_product);
        $multiplier = (float)Configuration::get('PSP_MULTIPLIER', 1);
        $targetGross = $incomingGross * $multiplier;

        // Kerekítés a bolt pénzneme szerint
        $currency = Context::getContext()->currency;
        if ($currency->iso_code === 'HUF') {
            $targetGross = round($targetGross / 5) * 5;
        } else {
            $targetGross = round($targetGross, 2);
        }

        // --- PONTOS NETTÓSÍTÁS PRESTASHOP SZABVÁNY SZERINT ---
        $taxRate = (float)$product->getTaxesRate();
        $newNettoPrice = $targetGross / (1 + ($taxRate / 100));
        
        // A PrestaShop 6 tizedesjegyet vár a nettó árnál
        $newNettoPrice = (float)Tools::ps_round($newNettoPrice, 6);

        // Csak akkor frissítünk, ha legalább 0.01 eltérés van
        if (abs((float)$product->price - $newNettoPrice) > 0.01) {
            $product->price = $newNettoPrice;
            
            // Hibakezelés a mentésnél
            if (!$product->update()) {
                PriceSyncPro::log($ref, "HIBA: A termék mentése sikertelen.", 'error');
                return ['error' => 'Save failed'];
            }
            
            PriceSyncPro::log($ref, "SIKER: Ár frissítve. Bruttó: $targetGross", 'success');
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
