<?php
/**
 * Price Sync Pro
 * Dashboard Design Update - FINAL VERSION
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\Entity\Product;
use PrestaShop\PrestaShop\Adapter\Entity\Db;
use PrestaShop\PrestaShop\Adapter\Entity\Link;
use PrestaShop\PrestaShop\Adapter\Entity\Validate;

class PriceSyncPro extends Module
{
    protected $tableName = 'pricesyncpro_blacklist';

    public function __construct()
    {
        $this->name = 'pricesyncpro';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'MP Development';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Price Sync Pro', [], 'Modules.Pricesyncpro.Admin');
        $this->description = $this->trans('Árszinkronizáló Dashboard felülettel.', [], 'Modules.Pricesyncpro.Admin');
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    public function install(): bool
    {
        return parent::install() &&
            $this->registerHook('actionProductUpdate') &&
            $this->registerHook('actionProductAdd') &&
            $this->registerHook('displayBackOfficeHeader') &&
            $this->installDb();
    }

    public function uninstall(): bool
    {
        return parent::uninstall();
    }

    protected function installDb(): bool
    {
        // Töröljük a régit, ha nem kompatibilis (opcionális, de fejlesztésnél hasznos)
        // Db::getInstance()->execute("DROP TABLE IF EXISTS `" . _DB_PREFIX_ . $this->tableName . "`");

        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . $this->tableName . "` (
            `id_blacklist` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `reference` varchar(64) NOT NULL,
            `shop_id` int(1) DEFAULT 1,
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_blacklist`),
            UNIQUE KEY `idx_ref` (`reference`, `shop_id`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";

        return Db::getInstance()->execute($sql);
    }

    public function hookDisplayBackOfficeHeader()
    {
        if ($this->context->controller->php_self == 'AdminModules' && Tools::getValue('configure') == $this->name) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        }
    }

    /**
     * DASHBOARD LOGIKA
     */
    public function getContent(): string
    {
        $output = '';

        // 1. Mentés
        if (Tools::isSubmit('submitPriceSyncConfig')) {
            Configuration::updateValue('PSP_MODE', Tools::getValue('PSP_MODE'));
            Configuration::updateValue('PSP_TOKEN', Tools::getValue('PSP_TOKEN'));
            Configuration::updateValue('PSP_S1_URL', Tools::getValue('PSP_S1_URL'));
            Configuration::updateValue('PSP_S1_MULTIPLIER', Tools::getValue('PSP_S1_MULTIPLIER'));
            Configuration::updateValue('PSP_S2_URL', Tools::getValue('PSP_S2_URL'));
            Configuration::updateValue('PSP_S2_MULTIPLIER', Tools::getValue('PSP_S2_MULTIPLIER'));
            
            $output .= $this->displayConfirmation("Beállítások mentve.");
        }

        // 2. Blacklist Hozzáadás
        if (Tools::isSubmit('submitBlacklistAdd')) {
            $ref = Tools::getValue('blacklist_ref');
            $shopTarget = (int)Tools::getValue('blacklist_shop_target');
            
            if (!empty($ref)) {
                if ($this->addToBlacklist($ref, $shopTarget)) {
                    $output .= $this->displayConfirmation("Cikkszám ($ref) hozzáadva a Shop $shopTarget tiltólistához.");
                } else {
                    $output .= $this->displayError("Hiba: Már létezik vagy adatbázis hiba.");
                }
            }
        }
        
        // 3. Blacklist Törlés
        if (Tools::isSubmit('deleteblacklist')) {
            $id = (int)Tools::getValue('id_blacklist');
            Db::getInstance()->delete($this->tableName, 'id_blacklist = ' . $id);
            $output .= $this->displayConfirmation("Törölve.");
        }

        // Változók átadása a TPL-nek
        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'action_url' => $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name,
            
            'psp_mode' => Configuration::get('PSP_MODE', 'OFF'),
            'psp_token' => Configuration::get('PSP_TOKEN'),
            'psp_s1_url' => Configuration::get('PSP_S1_URL'),
            'psp_s1_multiplier' => Configuration::get('PSP_S1_MULTIPLIER', '1.5'),
            'psp_s2_url' => Configuration::get('PSP_S2_URL'),
            'psp_s2_multiplier' => Configuration::get('PSP_S2_MULTIPLIER', '85'),

            'blacklist_s1' => $this->getBlacklist(1),
            'blacklist_s2' => $this->getBlacklist(2),
            
            'last_sync_date' => date('Y-m-d H:i'),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /**
     * HOOK: Termék Frissítése (Sender Logic)
     */
    public function hookActionProductUpdate($params)
    {
        $this->processHook($params);
    }

    public function hookActionProductAdd($params)
    {
        $this->processHook($params);
    }

    protected function processHook($params)
    {
        if (Configuration::get('PSP_MODE') !== 'SENDER') {
            return;
        }

        if (!isset($params['product'])) {
             if (isset($params['id_product'])) {
                $product = new Product((int)$params['id_product']);
            } else {
                return;
            }
        } else {
            $product = $params['product'];
        }

        // Bruttó ár lekérése kedvezménnyel
        $price = Product::getPriceStatic($product->id, true, null, 6, null, false, true);

        $payload = [
            'reference' => $product->reference,
            'price' => $price,
            'token' => Configuration::get('PSP_TOKEN')
        ];

        // Küldés Shop 1-nek
        $url1 = Configuration::get('PSP_S1_URL');
        if (!empty($url1)) {
            $this->sendWebhook($url1, $payload);
        }

        // Küldés Shop 2-nek
        $url2 = Configuration::get('PSP_S2_URL');
        if (!empty($url2)) {
            $this->sendWebhook($url2, $payload);
        }
    }

    protected function sendWebhook($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * SEGÉDFÜGGVÉNY: Blacklist lekérése Shop ID alapján
     */
    protected function getBlacklist($shopId)
    {
        $sql = "SELECT * FROM `" . _DB_PREFIX_ . $this->tableName . "` 
                WHERE shop_id = " . (int)$shopId . "
                ORDER BY date_add DESC";

        try {
            $items = Db::getInstance()->executeS($sql);
        } catch (Exception $e) {
            return [];
        }

        if (!$items) {
            return [];
        }
        
        foreach ($items as &$item) {
             $id_product = (int)Product::getIdByReference($item['reference']);
             $item['image_url'] = '';
             $item['product_name'] = '<span class="text-muted">Ismeretlen termék</span>';
             
             if ($id_product) {
                 $product = new Product($id_product, false, $this->context->language->id);
                 if (Validate::isLoadedObject($product)) {
                     $item['product_name'] = $product->name;
                     $cover = Product::getCover($id_product);
                     if ($cover) {
                         $link = new Link();
                         $item['image_url'] = $link->getImageLink($product->link_rewrite, (string)$cover['id_image'], 'small_default');
                     }
                 }
             }
        }
        return $items;
    }

    /**
     * SEGÉDFÜGGVÉNY: Hozzáadás a Blacklisthez
     */
    protected function addToBlacklist($ref, $shopId)
    {
        // Duplikáció elkerülése végett töröljük, ha már van (bár az insert ignore is jó lenne)
        // De itt most simán insert, mert van Unique kulcs a DB-ben
        return Db::getInstance()->insert($this->tableName, [
            'reference' => pSQL($ref),
            'shop_id' => (int)$shopId,
            'date_add' => date('Y-m-d H:i:s')
        ]);
    }
}
