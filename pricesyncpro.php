<?php
/**
 * Price Sync Pro - MP Stock Sync Modul utódja
 * Kompatibilitás: PrestaShop 1.7.x - 9.x.x
 * PHP: 7.4 - 8.3 (Strict Types)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\Entity\Product;
use PrestaShop\PrestaShop\Adapter\Entity\Db;
use PrestaShop\PrestaShop\Adapter\Entity\Image;
use PrestaShop\PrestaShop\Adapter\Entity\Link;

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
        $this->description = $this->trans('Automata árszinkronizáló (Sender/Receiver) vizuális tiltólistával és HUF kerekítéssel.', [], 'Modules.Pricesyncpro.Admin');

        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    public function install(): bool
    {
        return parent::install() &&
            $this->registerHook('actionProductUpdate') &&
            $this->registerHook('actionProductAdd') &&
            $this->registerHook('displayBackOfficeHeader') && // CSS-hez
            $this->installDb();
    }

    public function uninstall(): bool
    {
        // Nem töröljük a DB táblát uninstallkor, hogy megmaradjon a blacklist adat
        return parent::uninstall();
    }

    protected function installDb(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . $this->tableName . "` (
            `id_blacklist` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `reference` varchar(64) NOT NULL,
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_blacklist`),
            UNIQUE KEY `idx_ref` (`reference`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";

        return Db::getInstance()->execute($sql);
    }

    /**
     * Admin CSS betöltése a vizuális blacklisthez
     */
    public function hookDisplayBackOfficeHeader()
    {
        if ($this->context->controller->php_self == 'AdminModules' && Tools::getValue('configure') == $this->name) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        }
    }

    /**
     * Konfigurációs oldal (Admin)
     */
    public function getContent(): string
    {
        $output = '';

        // 1. Blacklist Műveletek (Hozzáadás / Törlés)
        if (Tools::isSubmit('submitAddBlacklist')) {
            $ref = trim(Tools::getValue('blacklist_reference'));
            if (!empty($ref)) {
                if ($this->addToBlacklist($ref)) {
                    $output .= $this->displayConfirmation("Termék ($ref) hozzáadva a tiltólistához.");
                } else {
                    $output .= $this->displayError("Hiba: Ez a cikkszám már listázva van, vagy adatbázis hiba történt.");
                }
            }
        } elseif (Tools::isSubmit('deleteblacklist')) {
            $id = (int)Tools::getValue('id_blacklist');
            Db::getInstance()->delete($this->tableName, 'id_blacklist = ' . $id);
            $output .= $this->displayConfirmation("Sikeres törlés.");
        }

        // 2. Beállítások mentése
        if (Tools::isSubmit('submitPriceSyncSettings')) {
            Configuration::updateValue('PSP_MODE', Tools::getValue('PSP_MODE'));
            Configuration::updateValue('PSP_TOKEN', Tools::getValue('PSP_TOKEN'));
            Configuration::updateValue('PSP_TARGETS', Tools::getValue('PSP_TARGETS'));
            Configuration::updateValue('PSP_MATCH_BY', Tools::getValue('PSP_MATCH_BY'));
            Configuration::updateValue('PSP_MULTIPLIER', (string)Tools::getValue('PSP_MULTIPLIER')); // Stringként mentjük a tizedes miatt
            $output .= $this->displayConfirmation("Beállítások mentve.");
        }

        // 3. Megjelenítés
        return $output . $this->renderForm() . $this->renderBlacklistTable();
    }

    /**
     * Fő űrlap renderelése
     */
    protected function renderForm(): string
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPriceSyncSettings';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $fieldsForm = [
            'form' => [
                'legend' => ['title' => 'Beállítások', 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => 'Működési Mód',
                        'name' => 'PSP_MODE',
                        'options' => [
                            'query' => [
                                ['id' => 'OFF', 'name' => 'Kikapcsolva'],
                                ['id' => 'SENDER', 'name' => 'KÜLDŐ (Sender - Beszállító)'],
                                ['id' => 'RECEIVER', 'name' => 'FOGADÓ (Receiver - Shop 1/2)'],
                            ],
                            'id' => 'id', 'name' => 'name'
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => 'Titkos Token (API Key)',
                        'name' => 'PSP_TOKEN',
                        'desc' => 'Ugyanannak kell lennie minden boltban!',
                        'required' => true,
                    ],
                    // Sender beállítások
                    [
                        'type' => 'textarea',
                        'label' => 'Cél URL-ek (Csak Sender módnál)',
                        'name' => 'PSP_TARGETS',
                        'desc' => 'Minden sorba egy URL. Pl: https://shop2.hu/module/pricesyncpro/api',
                        'rows' => 3,
                    ],
                    // Receiver beállítások
                    [
                        'type' => 'select',
                        'label' => 'Termék Azonosítás (Csak Receiver)',
                        'name' => 'PSP_MATCH_BY',
                        'options' => [
                            'query' => [
                                ['id' => 'reference', 'name' => 'Reference (Cikkszám) - Shop 2-höz'],
                                ['id' => 'supplier_reference', 'name' => 'Supplier Reference - Shop 1-hez'],
                            ],
                            'id' => 'id', 'name' => 'name'
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => 'Ár Szorzó (Multiplier)',
                        'name' => 'PSP_MULTIPLIER',
                        'desc' => 'Pl: 1.5 (Shop 1) vagy 85 (Shop 2). Használj pontot tizedesnek.',
                    ],
                ],
                'submit' => ['title' => 'Mentés', 'class' => 'btn btn-primary'],
            ],
        ];

        // Értékek betöltése
        $helper->fields_value['PSP_MODE'] = Configuration::get('PSP_MODE', 'OFF');
        $helper->fields_value['PSP_TOKEN'] = Configuration::get('PSP_TOKEN');
        $helper->fields_value['PSP_TARGETS'] = Configuration::get('PSP_TARGETS');
        $helper->fields_value['PSP_MATCH_BY'] = Configuration::get('PSP_MATCH_BY', 'reference');
        $helper->fields_value['PSP_MULTIPLIER'] = Configuration::get('PSP_MULTIPLIER', '1.0');

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * A Vizuális Blacklist Táblázat Renderelése
     */
    protected function renderBlacklistTable(): string
    {
        // 1. Blacklist lekérése
        $items = Db::getInstance()->executeS("
            SELECT b.* FROM `" . _DB_PREFIX_ . $this->tableName . "` b
            ORDER BY b.date_add DESC
        ");

        // 2. Adatok kiegészítése (Kép, Név) ha létezik a termék
        // Ezt PHP-ban csináljuk, mert lehet, hogy a reference nem egyezik ID-vel, vagy nincs is meg
        foreach ($items as &$item) {
            $ref = $item['reference'];
            $id_product = (int)Product::getIdByReference($ref);
            
            $item['image_url'] = '';
            $item['product_name'] = '<span class="label label-warning">Nincs a shopban</span>';

            if ($id_product) {
                $product = new Product($id_product, false, $this->context->language->id);
                $item['product_name'] = $product->name;
                
                $cover = Product::getCover($id_product);
                if ($cover) {
                    $link = new Link();
                    $item['image_url'] = $link->getImageLink($product->link_rewrite, (string)$cover['id_image'], 'small_default');
                }
            }
        }

        $this->context->smarty->assign([
            'blacklist_items' => $items,
            'form_action' => $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&module_name=' . $this->name,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/blacklist.tpl');
    }

    protected function addToBlacklist(string $ref): bool
    {
        return Db::getInstance()->insert($this->tableName, [
            'reference' => pSQL($ref),
            'date_add' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * HOOK: Termék Frissítésekor (Sender Logic)
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
        // Csak akkor futunk, ha SENDER mód aktív
        if (Configuration::get('PSP_MODE') !== 'SENDER') {
            return;
        }

        // Biztos ami biztos, ellenőrizzük, hogy termékről van-e szó
        if (!isset($params['product']) || !($params['product'] instanceof Product)) {
            // PS 1.7 és PS 8 néha ID-t ad vissza, néha objektumot
            if (isset($params['id_product'])) {
                $product = new Product((int)$params['id_product']);
            } else {
                return;
            }
        } else {
            $product = $params['product'];
        }

        // Ha a termék nem aktív, ne küldjünk (opcionális, de ajánlott)
        // if (!$product->active) return;

        // ÁR LEKÉRÉSE: Specific Price (Akciós) figyelembevételével!
        // getPriceStatic(id, use_tax, id_product_attribute, decimals, divisor_null, only_reduc, use_reduc)
        // use_reduc = true -> Ez a kulcs az akciós árhoz!
        $price = Product::getPriceStatic($product->id, true, null, 6, null, false, true);

        $payload = [
            'reference' => $product->reference,
            'price' => $price, // Ez a bruttó, akciós ár
            'token' => Configuration::get('PSP_TOKEN')
        ];

        // Küldés az összes célpontnak
        $targets = explode("\n", Configuration::get('PSP_TARGETS'));
        foreach ($targets as $url) {
            $url = trim($url);
            if (!empty($url)) {
                $this->sendWebhook($url, $payload);
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Gyors timeout, hogy ne akassza meg az admin mentést
        curl_exec($ch);
        curl_close($ch);
    }
}
