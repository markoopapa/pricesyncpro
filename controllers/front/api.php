<?php
/**
 * Price Sync Pro API Endpoint
 * Fogadja az adatokat és frissíti az árat
 */

declare(strict_types=1);

class PriceSyncProApiModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;

    public function initContent()
    {
        parent::initContent();
        $this->ajaxDie($this->processSync());
    }

    protected function processSync()
    {
        // 1. JSON Adat fogadása
        $json = Tools::file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!$data || !isset($data['token']) || !isset($data['reference'])) {
            return json_encode(['error' => 'Invalid Data']);
        }

        // 2. Token Ellenőrzés
        if ($data['token'] !== Configuration::get('PSP_TOKEN')) {
            http_response_code(403);
            return json_encode(['error' => 'Invalid Token']);
        }

        // 3. Blacklist Ellenőrzés
        if ($this->isBlacklisted($data['reference'])) {
            return json_encode(['status' => 'skipped', 'reason' => 'Blacklisted']);
        }

        // 4. Termék Keresése
        $matchBy = Configuration::get('PSP_MATCH_BY', 'reference');
        $id_product = $this->findProduct($data['reference'], $matchBy);

        if (!$id_product) {
            return json_encode(['status' => 'skipped', 'reason' => 'Product not found']);
        }

        // 5. ÁRKÉPZÉS ÉS KEREKÍTÉS
        $updated = $this->updatePrice($id_product, (float)$data['price']);

        return json_encode(['status' => 'success', 'id_product' => $id_product, 'updated' => $updated]);
    }

    /**
     * Itt történik a matematika
     */
    protected function updatePrice(int $id_product, float $sourcePriceGross): bool
    {
        $product = new Product($id_product);
        
        // Szorzó alkalmazása (pl. x1.5 vagy x85)
        $multiplier = (float)Configuration::get('PSP_MULTIPLIER');
        $newPriceGross = $sourcePriceGross * $multiplier;

        // Kerekítési logika
        $currencyId = (int)Configuration::get('PS_CURRENCY_DEFAULT');
        $currency = new Currency($currencyId);
        
        if ($currency->iso_code === 'HUF') {
            // SPECIÁLIS MAGYAR KEREKÍTÉS: 0 vagy 5 a vége
            // Képlet: round(x / 5) * 5
            $newPriceGross = round($newPriceGross / 5) * 5;
        } else {
            // Egyéb valuta (pl. RON, EUR) -> 2 tizedesjegy
            $newPriceGross = round($newPriceGross, 2);
        }

        // Biztonsági védelem: Ne legyen 0 az ár
        if ($newPriceGross <= 0) return false;

        // NETTÓSÍTÁS (Visszaszámolás ÁFÁ-ból)
        // A PS nettó árat tárol, nekünk bruttónk van.
        $taxRate = $product->getTaxesRate(); // Pl. 27
        $newPriceNetto = $newPriceGross / (1 + ($taxRate / 100));

        // Frissítés, csak ha változott (hogy ne fussanak felesleges hookok)
        // Figyelem: A float összehasonlításnál kell egy kis delta
        if (abs($product->price - $newPriceNetto) > 0.00001) {
            $product->price = $newPriceNetto;
            return $product->update();
        }

        return true; // Nem változott, de sikeresnek tekintjük
    }

    protected function findProduct($ref, $matchBy): int
    {
        if ($matchBy === 'supplier_reference') {
            // Keresés beszállítói cikkszám alapján
            $sql = 'SELECT id_product FROM ' . _DB_PREFIX_ . 'product_supplier WHERE product_supplier_reference = "' . pSQL($ref) . '"';
            return (int)Db::getInstance()->getValue($sql);
        } else {
            // Keresés sima cikkszám alapján
            return (int)Product::getIdByReference($ref);
        }
    }

    protected function isBlacklisted($ref): bool
    {
        $table = 'pricesyncpro_blacklist';
        $sql = 'SELECT id_blacklist FROM ' . _DB_PREFIX_ . $table . ' WHERE reference = "' . pSQL($ref) . '"';
        return (bool)Db::getInstance()->getValue($sql);
    }
}
