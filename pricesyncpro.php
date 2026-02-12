<?php
/**
 * Price Sync Pro - LOGGING VERSION
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) { exit; }

use PrestaShop\PrestaShop\Adapter\Entity\Product;
use PrestaShop\PrestaShop\Adapter\Entity\Db;

class PriceSyncPro extends Module
{
    protected $tableName = 'pricesyncpro_blacklist';
    protected $logTable = 'pricesyncpro_logs';
    protected static $already_sent = [];

    public function __construct()
    {
        $this->name = 'pricesyncpro';
        $this->tab = 'administration';
        $this->version = '1.1.0'; // Verzió emelés
        $this->author = 'MP Development';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('Price Sync Pro', [], 'Modules.Pricesyncpro.Admin');
        $this->description = $this->trans('Árszinkronizáló Naplózással.', [], 'Modules.Pricesyncpro.Admin');
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    public function install(): bool
    {
        return parent::install() &&
            $this->registerHook('actionProductUpdate') &&
            $this->registerHook('actionProductAdd') &&
            $this->registerHook('displayBackOfficeHeader') &&
            $this->registerHook('actionObjectProductUpdateAfter') &&
            $this->installDb();
    }

    public function uninstall(): bool
    {
        // Opcionális: Logok törlése uninstallkor
        // Db::getInstance()->execute("DROP TABLE IF EXISTS `" . _DB_PREFIX_ . $this->logTable . "`");
        return parent::uninstall();
    }

    protected function installDb(): bool
    {
        // Blacklist tábla
        $sql1 = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . $this->tableName . "` (
            `id_blacklist` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `reference` varchar(64) NOT NULL,
            `shop_id` int(1) DEFAULT 1,
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_blacklist`),
            UNIQUE KEY `idx_ref` (`reference`, `shop_id`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";

        // ÚJ: LOG TÁBLA
        $sql2 = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . $this->logTable . "` (
            `id_log` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `reference` varchar(64) NOT NULL,
            `message` text NOT NULL,
            `type` varchar(20) NOT NULL, -- success, error, warning
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_log`),
            KEY `idx_date` (`date_add`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";

        return Db::getInstance()->execute($sql1) && Db::getInstance()->execute($sql2);
    }

    // ... (hookDisplayBackOfficeHeader maradhat) ...
    public function hookDisplayBackOfficeHeader()
    {
        if ($this->context->controller->php_self == 'AdminModules' && Tools::getValue('configure') == $this->name) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        }
    }

    public function getContent(): string
    {
        // AJAX Bulk Sync kezelés (Maradhat a régi, vagy amit az előbb küldtem)
        if (Tools::isSubmit('ajax_bulk_sync')) { $this->processBulkSyncBatch(); exit; }

        $output = '';

        // Beállítások mentése
        if (Tools::isSubmit('submitPriceSyncConfig')) {
            Configuration::updateValue('PSP_MODE', Tools::getValue('PSP_MODE'));
            Configuration::updateValue('PSP_TOKEN', Tools::getValue('PSP_TOKEN'));
            Configuration::updateValue('PSP_TARGET_URLS', Tools::getValue('PSP_TARGET_URLS'));
            Configuration::updateValue('PSP_MATCH_BY', Tools::getValue('PSP_MATCH_BY'));
            Configuration::updateValue('PSP_MULTIPLIER', Tools::getValue('PSP_MULTIPLIER'));
            Configuration::updateValue('PSP_NEXT_SHOP_URL', Tools::getValue('PSP_NEXT_SHOP_URL'));
            Configuration::updateValue('PSP_CHAIN_MULTIPLIER', Tools::getValue('PSP_CHAIN_MULTIPLIER'));
            $output .= $this->displayConfirmation("Beállítások mentve.");
        }

        // LOG TÖRLÉS
        if (Tools::isSubmit('clear_logs')) {
            Db::getInstance()->execute('TRUNCATE TABLE ' . _DB_PREFIX_ . $this->logTable);
            $output .= $this->displayConfirmation("Napló törölve.");
        }

        // Blacklist kezelés...
        if (Tools::isSubmit('submitBlacklistAdd')) { $this->addToBlacklist(Tools::getValue('blacklist_ref'), 1); }
        if (Tools::isSubmit('deleteblacklist')) { Db::getInstance()->delete($this->tableName, 'id_blacklist='.(int)Tools::getValue('id_blacklist')); }

        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'action_url' => $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name,
            'ajax_url' => $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&ajax_bulk_sync=1',
            
            'psp_mode' => Configuration::get('PSP_MODE', 'OFF'),
            'psp_token' => Configuration::get('PSP_TOKEN'),
            'psp_target_urls' => Configuration::get('PSP_TARGET_URLS'),
            'psp_multiplier' => Configuration::get('PSP_MULTIPLIER', '1.5'),
            'psp_match_by' => Configuration::get('PSP_MATCH_BY', 'reference'),
            'psp_next_shop_url' => Configuration::get('PSP_NEXT_SHOP_URL'),
            'psp_chain_multiplier' => Configuration::get('PSP_CHAIN_MULTIPLIER', '85'),
            
            'blacklist' => $this->getBlacklist(), 
            'logs' => $this->getLogs(), // Logok lekérése
            'total_products' => (int)Db::getInstance()->getValue('SELECT count(id_product) FROM ' . _DB_PREFIX_ . 'product WHERE active=1'),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    protected function getLogs()
    {
        // Utolsó 100 bejegyzés
        return Db::getInstance()->executeS("SELECT * FROM `" . _DB_PREFIX_ . $this->logTable . "` ORDER BY date_add DESC LIMIT 100");
    }

    // STATIKUS LOGOLÓ FÜGGVÉNY (Ezt hívjuk majd az API-ból)
    public static function log($reference, $message, $type = 'info')
    {
        // Biztonsági ellenőrzés, hogy az adatbázis elérhető-e
        try {
            Db::getInstance()->insert('pricesyncpro_logs', [
                'reference' => pSQL($reference),
                'message' => pSQL($message),
                'type' => pSQL($type),
                'date_add' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            // Ha nem tud írni a logba, ne fagyassza le az egész oldalt (HTTP 500 ellen)
        }
    }

    /**
     * ÚJ FÜGGVÉNY: A Tömeges Szinkronizálás Logikája (Batch)
     */
    protected function processBulkSyncBatch()
    {
        header('Content-Type: application/json');

        $page = (int)Tools::getValue('page', 1); // Hanyadik adagnál tartunk
        $limit = 20; // Csak 20 termék egyszerre (Biztonságos!)
        $offset = ($page - 1) * $limit;

        // Csak aktív termékeket kérünk le
        $sql = 'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1 LIMIT ' . $offset . ', ' . $limit;
        $products = Db::getInstance()->executeS($sql);

        if (empty($products)) {
            echo json_encode(['finished' => true, 'count' => 0]);
            return;
        }

        $processed = 0;
        foreach ($products as $row) {
            $product = new Product((int)$row['id_product']);
            $params = ['product' => $product];
            $this->processHook($params);
            
            $processed++;
        }

        echo json_encode([
            'finished' => false,
            'page' => $page,
            'processed_count' => $processed,
            'next_page' => $page + 1
        ]);
    }

    /**
     * HOOK: Termék Frissítése (CSAK A BESZÁLLÍTÓNÁL FUT)
     */
    public function hookActionProductUpdate($params)
    {
        $this->processHook($params);
    }
    
    /**
     * Ez a "Mélyebb" hook. Akkor is lefut, ha az importáló modul
     * nem hívja meg direktben a termék frissítést, de menti az adatbázisba az objektumot.
     */
    public function hookActionObjectProductUpdateAfter($params)
    {
        // Elkerüljük a duplikálást: Ha a sima hook már lefutott, ezt ne futtassuk
        // Ezt egy statikus változóval vagy egyszerű logikával szűrhetjük,
        // de a legegyszerűbb, ha hagyjuk lefutni, a fogadó oldal úgyis csak akkor ment, ha változott az ár.
        
        if (isset($params['object']) && $params['object'] instanceof Product) {
            $this->processHook(['product' => $params['object']]);
        }
    }
    
    public function hookActionProductAdd($params)
    {
        $this->processHook($params);
    }

    protected function processHook($params)
    {
        
        static $is_processing = false;
        if ($is_processing) return;
        $is_processing = true;
        
        $id_product = isset($params['id_product']) ? (int)$params['id_product'] : (isset($params['product']->id) ? (int)$params['product']->id : 0);
        if ($id_product && isset(self::$already_sent[$id_product])) return;
        self::$already_sent[$id_product] = true;
        
        $mode = Configuration::get('PSP_MODE');

        // 1. Ha KIKAPCSOLT vagy csak FOGADÓ (Receiver), akkor nem csinálunk semmit
        if ($mode === 'OFF' || $mode === 'RECEIVER') {
            return;
        }

        // Termék betöltése
        if (!isset($params['product'])) {
             if (isset($params['id_product'])) {
                $product = new Product((int)$params['id_product']);
            } else {
                return;
            }
        } else {
            $product = $params['product'];
        }

        // Aktuális Bruttó (Akciós) ár lekérése
        $price = Product::getPriceStatic($product->id, true, null, 6, null, false, true);
        $token = Configuration::get('PSP_TOKEN');

        // --- A. HA BESZÁLLÍTÓ VAGYOK (SENDER) ---
        if ($mode === 'SENDER') {
            $payload = [
                'reference' => $product->reference,
                'price' => $price, // Nyers ár
                'token' => $token
            ];

            // Küldés a listának (Minmag + Electrob)
            $targets = explode("\n", Configuration::get('PSP_TARGET_URLS'));
            foreach ($targets as $url) {
                $url = trim($url);
                if (!empty($url)) {
                    $this->sendWebhook($url, $payload);
                }
            }
        }

        // --- B. HA KÖZTES SHOP VAGYOK (CHAIN - Electrob.ro) ---
        elseif ($mode === 'CHAIN') {
            $chainMultiplier = (float)Configuration::get('PSP_CHAIN_MULTIPLIER');
            if ($chainMultiplier <= 0) $chainMultiplier = 1;

            $priceToSend = $price * $chainMultiplier;
            
            // LOG: Tudjuk meg, hogy a hook egyáltalán eljutott-e idáig
            self::log($product->reference, "CHAIN: Folyamat indul. Számolt ár küldéshez: " . $priceToSend, 'info');

            $payload = [
                'reference' => $product->reference,
                'price' => $priceToSend,
                'token' => $token
            ];

            $nextUrl = Configuration::get('PSP_NEXT_SHOP_URL');

            if (!empty($nextUrl)) {
                // Megpróbáljuk a küldést
                $this->sendWebhook($nextUrl, $payload);
            } else {
                // LOG: Ha elfelejtetted beírni a magyar shop URL-jét
                self::log($product->reference, "CHAIN HIBA: Nincs megadva a 'Következő Shop URL'!", 'error');
            }
        }
    }

    protected function sendWebhook($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            self::log($data['reference'], "CURL HIBA: " . $error, 'error');
        } elseif ($httpCode !== 200) {
            // CSAK AKKOR NAPLÓZUNK, HA NEM 200 (TEHÁT HIBA VAN)
            self::log($data['reference'], "Szerver válasza (HTTP $httpCode): " . substr($response, 0, 100), 'error');
        }
        // Ha HTTP 200, akkor nem írunk semmit, így tiszta marad a napló.
    }

    public function getBlacklist()
{
    $sql = 'SELECT * FROM `' . _DB_PREFIX_ . $this->tableName . '` ORDER BY id_blacklist DESC';
    $res = Db::getInstance()->executeS($sql);
    
    if (!$res) return [];

    $link = Context::getContext()->link;

    foreach ($res as &$item) {
        // 1. Termék keresése cikkszám alapján
        $id_product = (int)Product::getIdByReference($item['reference']);
        
        if ($id_product > 0) {
            $product = new Product($id_product, false, Context::getContext()->language->id);
            $item['product_name'] = $product->name;

            // 2. Borítókép (Cover) lekérése
            $image = Image::getCover($id_product);
            if ($image) {
                // Legeneráljuk a kiskép URL-jét (small_default vagy cart_default)
                $item['image_url'] = $link->getImageLink($product->link_rewrite, $image['id_image'], 'small_default');
            } else {
                $item['image_url'] = ''; // Nincs kép
            }
        } else {
            $item['product_name'] = 'Ismeretlen termék';
            $item['image_url'] = '';
        }
    }

    return $res;
}

    protected function addToBlacklist($ref, $shopId)
    {
        return Db::getInstance()->insert($this->tableName, [
            'reference' => pSQL($ref),
            'shop_id' => 1,
            'date_add' => date('Y-m-d H:i:s')
        ]);
    }
}
