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
        // 1. ADATOK ÁTVÉTELE
        // Ha a te kódodban máshogy hívják a változókat, itt írd át!
        $ref = $data['reference']; 
        $incomingGross = (float)$data['price'];

        // --- INNEN KEZDŐDIK AZ ÚJ LOGIKA ---

        // 2. KERESÉS: Beszállítói cikkszám VAGY Saját cikkszám alapján
        // Ez a rész nagyon fontos, ez találja meg a terméket akkor is, ha a beszállító küldi!
        $sql = 'SELECT id_product, reference FROM ' . _DB_PREFIX_ . 'product 
                WHERE supplier_reference = "' . pSQL($ref) . '" 
                OR reference = "' . pSQL($ref) . '"';

        $row = Db::getInstance()->getRow($sql);
        
        if (!$row) {
            // HIBA NAPLÓZÁSA (Hogy lásd a magyar oldalon is, ha baj van)
            PriceSyncPro::log($ref, "API HIBA: Termék nem található (se saját, se beszállítói kód alapján)!", 'error');
            return ['error' => 'Product not found'];
        }

        $id_product = (int)$row['id_product'];
        $internalRef = $row['reference']; // Megszerezzük a belső cikkszámot

        // 3. TILTÓLISTA ELLENŐRZÉS (A belső cikkszám alapján)
        if ($this->isBlacklisted($internalRef)) {
            PriceSyncPro::log($internalRef, "INFO: Tiltólistán van, frissítés blokkolva.", 'warning');
            return ['status' => 'skipped'];
        }

        // 4. TERMÉK BETÖLTÉSE
        $product = new Product($id_product);
        
        // Szorzó alkalmazása (Admin beállítás alapján)
        $multiplier = (float)Configuration::get('PSP_MULTIPLIER', 1);
        $targetGross = $incomingGross * $multiplier;

        // 5. KEREKÍTÉS (HUF vs RON felismerése)
        $currency = Context::getContext()->currency;
        if ($currency->iso_code === 'HUF') {
            // Magyar: 5-re kerekítés
            $targetGross = round($targetGross / 5) * 5;
        } else {
            // Román: Lépcsőzetes (1 tizedes, 0.5-ös lépcső, vagy egész)
            if ($targetGross < 1) {
                $targetGross = round($targetGross, 1);
            } elseif ($targetGross >= 1 && $targetGross < 2) {
                $targetGross = round($targetGross * 2) / 2;
            } else {
                $targetGross = round($targetGross);
            }
        }

        // 6. NETTÓSÍTÁS ÉS MENTÉS
        $taxRate = (float)$product->getTaxesRate();
        // A PrestaShop 6 tizedesjegyet használ a nettó árnál
        $newNettoPrice = (float)Tools::ps_round($targetGross / (1 + ($taxRate / 100)), 6);

        // Csak akkor mentünk, ha változott az ár (kíméljük az adatbázist)
        if (abs((float)$product->price - $newNettoPrice) > 0.01) {
            $product->price = $newNettoPrice;
            
            if ($product->update()) {
                // SIKER NAPLÓZÁSA
                PriceSyncPro::log($internalRef, "SIKER: Ár frissítve. Új bruttó: $targetGross ($currency->iso_code)", 'success');
                return ['status' => 'success'];
            } else {
                PriceSyncPro::log($internalRef, "HIBA: A termék mentése nem sikerült.", 'error');
                return ['error' => 'Update failed'];
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
