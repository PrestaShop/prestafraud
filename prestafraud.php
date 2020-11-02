<?php

/**
 * 2007-2018 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author PrestaShop SA <contact@prestashop.com>
 * @copyright  2007-2018 PrestaShop SA
 * @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registred Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PrestaFraud extends Module
{
    protected $_html;
    public $_errors = array();
    protected $_trustUrl;

    protected $_activities;
    protected $_payment_types;

    public function __construct()
    {
        $this->name = 'prestafraud';
        $this->tab = 'payment_security';
        $this->version = '1.1.7';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;
        $this->module_key = '755a646c90363062eacab8fa7c047605';

        parent::__construct();

        $this->displayName = $this->trans('PrestaShop Security', array(), 'Modules.Prestafraud.Admin');
        $this->description = $this->trans('Protect your business, get help to make it grow peacefully and keep an eye on risky orders.', array(), 'Modules.Prestafraud.Admin');

        $this->_activities = array(
            0 => $this->trans('-- Please choose your main activity --', array(), 'Modules.Prestafraud.Admin'),
            1 => $this->trans('Adult', array(), 'Admin.Global'),
            2 => $this->trans('Animals and Pets', array(), 'Admin.Global'),
            3 => $this->trans('Art and Culture', array(), 'Admin.Global'),
            4 => $this->trans('Babies', array(), 'Admin.Global'),
            5 => $this->trans('Beauty and Personal Care', array(), 'Admin.Global'),
            6 => $this->trans('Cars', array(), 'Admin.Global'),
            7 => $this->trans('Computer Hardware and Software', array(), 'Admin.Global'),
            8 => $this->trans('Virtual Products', array(), 'Admin.Global'),
            9 => $this->trans('Fashion and Accessories', array(), 'Admin.Global'),
            10 => $this->trans('Flowers, Gifts and Crafts', array(), 'Admin.Global'),
            11 => $this->trans('Food and Beverage', array(), 'Admin.Global'),
            12 => $this->trans('HiFi, Photo and Video', array(), 'Admin.Global'),
            13 => $this->trans('Home and Garden', array(), 'Admin.Global'),
            14 => $this->trans('Home Appliances', array(), 'Admin.Global'),
            15 => $this->trans('Jewelry', array(), 'Admin.Global'),
            16 => $this->trans('Mobile and Telecom', array(), 'Admin.Global'),
            17 => $this->trans('Services', array(), 'Admin.Global'),
            18 => $this->trans('Shoes and Accessories', array(), 'Admin.Global'),
            19 => $this->trans('Sport and Entertainment', array(), 'Admin.Global'),
            20 => $this->trans('Travel', array(), 'Admin.Global'),
        );

        $this->_payment_types = array(
            0 => $this->trans('Check', array(), 'Admin.Global'),
            1 => $this->trans('Bank wire', array(), 'Admin.Global'),
            2 => $this->trans('Credit card', array(), 'Admin.Global'),
            3 => $this->trans('Credit card multiple', array(), 'Admin.Global'),
            4 => $this->trans('Other payment method', array(), 'Admin.Global'),
        );

        $this->_trustUrl = 'http'.(extension_loaded('openssl') ? 's' : '').'://trust.prestashop.com/';
        // $this->_trustUrl = 'http://127.0.0.1/trust.prestashop.com/';
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        foreach (array('updatecarrier', 'newOrder', 'adminOrder', 'cart') as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        $sql = file_get_contents(__DIR__.'/install.sql');
        if (!$sql) {
            $this->_errors[] = Tools::displayError('File install.sql is not readable');
            return false;
        }

        $sql = str_replace(array('PREFIX_', 'ENGINE_TYPE'), array(_DB_PREFIX_, _MYSQL_ENGINE_), $sql);
        $sql = preg_split("/;\s*[\r\n]+/", $sql);
        foreach ($sql as $query) {
            $query = trim($query);
            if (empty($query)) {
                continue;
            }
            if (!Db::getInstance()->execute($query)) {
                $this->_errors[] = Db::getInstance()->getMsgError();
                return false;
            }
        }

        $payments = PaymentModule::getInstalledPaymentModules();
        foreach ($payments as $payment) {
            if ($payment['name'] == 'cheque') {
                Db::getInstance()->execute(
                    'INSERT IGNORE INTO '._DB_PREFIX_.'prestafraud_payment (id_module, id_prestafraud_payment_type)
                     VALUES ('.(int) $payment['id_module'].', 0)'
                );
            } elseif ($payment['name'] == 'bankwire') {
                Db::getInstance()->execute(
                    'INSERT IGNORE INTO '._DB_PREFIX_.'prestafraud_payment (id_module, id_prestafraud_payment_type)
                     VALUES ('.(int) $payment['id_module'].', 1)'
                );
            }
        }

        return true;
    }

    public function getContent()
    {
        $this->postProcess();
        $this->_displayConfiguration();

        return $this->_html;
    }

    private function _displayConfiguration()
    {
        $this->_html .= '
        <script type="text/javascript">
            $(document).ready(function() {
                $(\'#submitCreateAccount\').unbind(\'click\').click(function() {
                    if (!$(\'#terms_and_conditions\').attr(\'checked\')) {
                        alert(\''.addslashes($this->trans('Please accept the terms of service.', array(), 'Modules.Prestafraud.Admin')).'\');
                        return false;
                    }
                });										
            });
        </script>
        <fieldset><legend>'.$this->trans('PrestaShop Security configuration', array(), 'Modules.Prestafraud.Admin').'</legend>
            <div id="choose_account">
                <center>
                <form>
                    <input type="radio" '.(!Configuration::get(
                'PS_TRUST_SHOP_ID'
            ) ? 'checked="checked"' : '').' onclick="$(\'#create_account\').show(); $(\'#module_configuration\').hide();" id="trust_account_on" name="trust_account" value="0"/> <b>'.$this->trans(
                'My shop does not have a PrestaShop Security account yet', array(), 'Modules.Prestafraud.Admin'
            ).'</b>&nbsp;&nbsp;&nbsp;
                    <input type="radio" '.(Configuration::get(
                'PS_TRUST_SHOP_ID'
            ) ? 'checked="checked"' : '').' onclick="$(\'#create_account\').hide(); $(\'#module_configuration\').show();"  id="trust_account_off" name="trust_account" value="1" /> <b>'.$this->trans(
                'I already have an account', array(), 'Modules.Prestafraud.Admin'
            ).'</b>
                </form>
                </center>
            </div>
            <div class="clear">&nbsp;</div>
            <div id="create_account" '.(Configuration::get('PS_TRUST_SHOP_ID') ? 'style="display:none;"' : '').'>
                <form action="'.Tools::htmlentitiesUTF8($_SERVER['REQUEST_URI']).'" method="post" name="prestashop_trust" id="prestashop_trust">
                    <label>'.$this->trans('Email address', array(), 'Admin.Global').'</label>
                    <div class="margin-form">
                        <input type="text" style="width:200px;" name="email" value="'.Tools::safeOutput(
                Tools::getValue('email')
            ).'" />
                    </div>
                    <label>'.$this->trans('Shop URL', array(), 'Admin.Advparameters.Feature').'</label>
                    <div class="margin-form">
                        <input type="text" style="width:400px;" name="shop_url" value="http://www.'.Tools::getHttpHost(
            ).__PS_BASE_URI__.'"/>
                    </div>
                    <div class="margin-form">
                        <input id="terms_and_conditions" type="checkbox" value="1" /> '.$this->trans(
                'I agree with the terms of PrestaShop Security service and I adhere to them unconditionally.', array(), 'Modules.Prestafraud.Admin'
            ).'</label>
                    </div>
                    <div id="terms" class="margin-form">';
        $terms = Tools::file_get_contents($this->_trustUrl.'terms.php?lang='.$this->context->language->iso_code);
        $this->_html .= '<div style="height:300px;border:1px solid #E0D0B1;overflow-y:scroll;padding:8px;color:black">'.Tools::nl2br(
                strip_tags($terms)
            ).'</div>';
        $this->_html .= '</div>
                    <div class="margin-form">
                        <input class="button" type="submit" id="submitCreateAccount" name="submitCreateAccount" value="'.$this->trans(
                'Create an account', array(), 'Modules.Prestafraud.Admin'
            ).'"/>
                    </div>
                </form>
                <div class="clear">&nbsp;</div>
            </div>
            <div id="module_configuration" '.(!Configuration::get('PS_TRUST_SHOP_ID') ? 'style="display:none"' : '').'>
            <form action="'.Tools::htmlentitiesUTF8($_SERVER['REQUEST_URI']).'" method="post" name="prestashop_trust" id="prestashop_trust">
                <label>'.$this->trans('Shop ID', array(), 'Admin.Global').'</label>
                <div class="margin-form">
                    <input type="text" style="width:150px"  name="shop_id" value="'.Configuration::get(
                'PS_TRUST_SHOP_ID'
            ).'"/>
                </div>
                <label>'.$this->trans('Shop KEY', array(), 'Admin.Global').'</label>
                <div class="margin-form">
                    <input type="text" style="width:300px" name="shop_key" value="'.Configuration::get(
                'PS_TRUST_SHOP_KEY'
            ).'"/>
                </div>
                <div class="clear">&nbsp;</div>
                <label>'.$this->trans('Shop activity', array(), 'Admin.Global').'</label>
                <div class="margin-form">
                    <select name="shop_activity">';
        foreach ($this->_activities as $k => $activity) {
            $this->_html .= '<option value="'.$k.'" '.($k == Configuration::get(
                    'PS_SHOP_ACTIVITY'
                ) ? 'selected="selected"' : '').'>'.$activity.'</option>';
        }
        $this->_html .= '</select>
                </div>';

        $carriers = Carrier::getCarriers($this->context->language->id, true);
        $trust_carriers_type = $this->_getPrestaTrustCarriersType();
        $configured_carriers = $this->_getConfiguredCarriers();

        $this->_html .= '
                <label>'.$this->trans('Carriers', array(), 'Admin.Global').'</label>
                <div class="margin-form">
                    <table cellspacing="0" cellpadding="0" class="table">
                        <thead><tr><th>'.$this->trans('Carrier', array(), 'Admin.Global').'</th><th>'.$this->trans(
                'Transit time', array(), 'Admin.Global'
            ).'</th></tr></thead><tbody>';

        foreach ($carriers as $carrier) {
            $this->_html .= '<tr><td>'.$carrier['name'].'</td><td><select name="carrier_'.$carrier['id_carrier'].'">
            <option value="0">'.$this->trans('Select a transit time...', array(), 'Admin.Actions').'</option>';
            foreach ($this->_getPrestaTrustCarriersType() as $type => $name) {
                $this->_html .= '<option value="'.$type.'"'.((isset($configured_carriers[$carrier['id_carrier']]) and $type == $configured_carriers[$carrier['id_carrier']]) ? ' selected="selected"' : '').'>'.$name.'</option>';
            }
            $this->_html .= '</select></td>';
        }
        $this->_html .= '</tbody></table></margin>
            </div>';
        $modules = PaymentModule::getInstalledPaymentModules();
        $configured_payments = $this->_getConfiguredPayments();

        $this->_html .= '
                <label>'.$this->trans('Payments', array(), 'Admin.Global').'</label>
                <div class="margin-form">
                    <table cellspacing="0" cellpadding="0" class="table">
                        <thead><tr><th>'.$this->trans('Payment module', array(), 'Admin.Global').'</th><th>'.$this->trans(
                'Payment method', array(), 'Admin.Global'
            ).'</th></tr></thead><tbody>';

        foreach ($modules as $module) {
            $mod = Module::getInstanceByName($module['name']);
            $this->_html .= '<tr><td>'.$mod->displayName.'</td><td><select name="paymentmodule_'.$mod->id.'">
            <option value="0">'.$this->trans('Select a payment method...', array(), 'Admin.Actions').'</option>';
            foreach ($this->_payment_types as $type => $name) {
                $this->_html .= '<option value="'.$type.'"'.((isset($configured_payments[$mod->id]) and $type == $configured_payments[$mod->id]) ? ' selected="true"' : '').'>'.$name.'</option>';
            }
            $this->_html .= '</select></td>';
        }
        $this->_html .= '</tbody></table></margin>
            </div>';
        $this->_html .= '<center><input type="submit" name="submitSettings" value="'.$this->trans('Save', array(), 'Admin.Actions').'" class="button" /></center>
        </form>
        </div>
        </fieldset>';
        return $this->_html;
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitSettings')) {
            if (isset($_POST['login'])) {
                Configuration::updateValue('PS_TRUST_EMAIL', $_POST['email']);
            }
            if (isset($_POST['passwd'])) {
                Configuration::updateValue('PS_TRUST_PASSWD', $_POST['passwd']);
            }
            if ($activity = Tools::getValue('shop_activity')) {
                Configuration::updateValue('PS_SHOP_ACTIVITY', $activity);
            }
            $carriers_configuration = array();
            $payments_configuration = array();
            foreach ($_POST as $field => $val) {
                if (preg_match('/^carrier_([0-9]+)$/Ui', $field, $res)) {
                    $carriers_configuration[$res[1]] = $val;
                } elseif (preg_match('/^paymentmodule_([0-9]+)$/Ui', $field, $pay_res)) {
                    $payments_configuration[$pay_res[1]] = $val;
                }
            }

            $this->_setCarriersConfiguration($carriers_configuration);
            $this->_setPaymentsConfiguration($payments_configuration);
        } elseif (Tools::isSubmit('submitCreateAccount')) {
            if (!Validate::isEmail($email = Tools::getValue('email'))) {
                $this->_errors[] = $this->trans('Email address is invalid', array(), 'Modules.Prestafraud.Admin');
            }
            if (!Validate::isAbsoluteUrl($shop_url = Tools::getValue('shop_url'))) {
                $this->_errors[] = $this->trans('Shop URL is invalid', array(), 'Modules.Prestafraud.Admin');
            }

            if (!count($this->_errors)) {
                if ($this->_createAccount($email, $shop_url)) {
                    $this->_html .= $this->displayConfirmation('Account successfull created');
                }
            }
        }

        if (sizeof($this->_errors)) {
            $err = '';
            foreach ($this->_errors as $error) {
                $err .= $error.'<br />';
            }
            $this->_html .= $this->displayError($err);
        }
    }

    public function _createAccount($email, $shop_url)
    {
        $root = new SimpleXMLElement("<?xml version=\"1.0\"?><fraud_monitor></fraud_monitor>");
        $xml = $root->addChild('create_account');
        $xml->addChild('email', $email);
        $xml->addChild('shop_url', $shop_url);
        $result = $this->_pushDatas($root->asXml());

        if ($result == 'nok' || !($xml_result = simplexml_load_string($result))) {
            $this->_errors[] = $this->trans(
                'Impossible to create a new account, please report this bug on https://github.com/PrestaShop/PrestaShop/issues', array(), 'Modules.Prestafraud.Admin'
            );
            return false;
        }
        if (!(int) $xml_result->create_account->result) {
            $this->_errors[] = (string) $xml_result->create_account->errors;
            return false;
        }

        Configuration::updateValue('PS_TRUST_SHOP_ID', (string) $xml_result->create_account->shop_id);
        Configuration::updateValue('PS_TRUST_SHOP_KEY', (string) $xml_result->create_account->shop_key);
        return true;
    }

    public function hookUpdateCarrier($params)
    {
        $this->_updateConfiguredCarrier((int) $params['id_carrier'], (int) $params['carrier']->id);
    }

    public function hookNewOrder($params)
    {
        if (!Configuration::get('PS_TRUST_SHOP_ID') or !Configuration::get('PS_TRUST_SHOP_KEY')) {
            return;
        }

        $customer = new Customer((int) $params['order']->id_customer);

        $address_delivery = new Address((int) $params['order']->id_address_delivery);
        $address_invoice = new Address((int) $params['order']->id_address_invoice);
        $root = new SimpleXMLElement("<?xml version=\"1.0\"?><trust></trust>");
        $xml = $root->addChild('new_order');
        $shop_configuration = $xml->addChild('shop');

        $default_country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        $default_currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $shop_configuration->addChild('default_country', $default_country->iso_code);
        $shop_configuration->addChild('default_currency', $default_currency->iso_code);
        $shop_configuration->addChild('shop_id', Configuration::get('PS_TRUST_SHOP_ID'));
        $shop_configuration->addChild('shop_password', Configuration::get('PS_TRUST_SHOP_KEY'));

        if ($activity = Configuration::get('PS_SHOP_ACTIVITY')) {
            $shop_configuration->addChild('shop_activity', $activity);
        }
        $customer_infos = $xml->addChild('customer');
        $customer_infos->addChild('customer_id', $customer->id);
        $customer_infos->addChild('lastname', $customer->lastname);
        $customer_infos->addChild('firstname', $customer->firstname);
        $customer_infos->addChild('email', $customer->email);
        $customer_infos->addChild('is_guest', (int) $customer->is_guest);
        $customer_infos->addChild('birthday', $customer->birthday);

        $delivery = $xml->addChild('delivery');
        $delivery->addChild('lastname', $address_delivery->lastname);
        $delivery->addChild('firstname', $address_delivery->firstname);
        $delivery->addChild('company', $address_delivery->company);
        $delivery->addChild('dni', $address_delivery->dni);
        $delivery->addChild('address1', $address_delivery->address1);
        $delivery->addChild('address2', $address_delivery->address2);
        $delivery->addChild('phone', $address_delivery->phone);
        $delivery->addChild('phone_mobile', $address_delivery->phone_mobile);
        $delivery->addChild('city', $address_delivery->city);
        $delivery->addChild('postcode', $address_delivery->postcode);
        if ($address_delivery->id_state !== null or $address_delivery->id_state != '') {
            $State = new State((int) $address_delivery->id_state);
            $delivery->addChild('state', $State->iso_code);
        }
        $delivery->addChild('country', Country::getIsoById((int) $address_delivery->id_country));

        $invoice = $xml->addChild('invoice');
        $invoice->addChild('lastname', $address_invoice->lastname);
        $invoice->addChild('firstname', $address_invoice->firstname);
        $invoice->addChild('company', $address_invoice->company);
        $invoice->addChild('dni', $address_invoice->dni);
        $invoice->addChild('address1', $address_invoice->address1);
        $invoice->addChild('address2', $address_invoice->address2);
        $invoice->addChild('phone', $address_invoice->phone);
        $invoice->addChild('phone_mobile', $address_invoice->phone_mobile);
        $invoice->addChild('city', $address_invoice->city);
        $invoice->addChild('postcode', $address_invoice->postcode);
        if ($address_invoice->id_state !== null or $address_invoice->id_state != '') {
            $State = new State((int) $address_invoice->id_state);
            $invoice->addChild('state', $State->iso_code);
        }
        $invoice->addChild('country', Country::getIsoById((int) $address_invoice->id_country));

        $infos = $this->_getCustomerInfos($params['order']);
        $history = $xml->addChild('customer_history');
        $history->addChild('customer_date_last_order', $infos['customer_date_last_order']);
        $history->addChild('customer_orders_valid_count', (int) $infos['customer_orders_valid_count']);
        $history->addChild('customer_orders_valid_sum', (float) $infos['customer_orders_valid_sum']);
        $history->addChild('customer_orders_unvalid_count', (int) $infos['customer_orders_unvalid_count']);
        $history->addChild('customer_orders_unvalid_sum', (float) $infos['customer_orders_unvalid_sum']);
        $history->addChild('customer_ip_addresses_history', $infos['customer_ip_addresses_history']);

        $history->addChild('customer_date_add', $customer->date_add);

        $product_list = $params['order']->getProductsDetail();

        $order = $xml->addChild('order_detail');
        $order->addChild('order_id', (int) $params['order']->id);
        $order->addChild('order_amount', $params['order']->total_paid);
        $currency = new Currency((int) $params['order']->id_currency);
        $order->addChild('currency', $currency->iso_code);
        $products = $order->addChild('products');
        foreach ($product_list as $p) {
            $products->addChild('name', $p['product_name']);
            $products->addChild('price', $p['product_price']);
            $products->addChild('quantity', $p['product_quantity']);
            $products->addChild('is_virtual', (int) !empty($p['download_hash']));
        }

        $sources = ConnectionsSource::getOrderSources($params['order']->id);
        $referers = array();
        if ($sources) {
            foreach ($sources as $source) {
                $referers[] = $source['http_referer'];
            }
        }
        if (sizeof($referers)) {
            $order->addChild('order_referers', serialize($referers));
        }

        $configured_payments = $this->_getConfiguredPayments();
        $paymentModule = Module::getInstanceByName($params['order']->module);
        $order->addChild('payment_name', $paymentModule->displayName);
        $order->addChild('payment_type', (int) $configured_payments[$paymentModule->id]);
        $order->addChild('order_date', $params['order']->date_add);
        $order->addChild('order_ip_address', $this->_getIpByCart((int) $params['order']->id_cart));

        $carrier = new Carrier((int) $params['order']->id_carrier);

        if (Validate::isLoadedObject($carrier)) {
            $carrier_infos = $order->addChild('carrier_infos');
            $carrier_infos->addChild('name', $carrier->name);
            $carriers_type = $this->_getConfiguredCarriers();
            $carrier_infos->addChild(
                'type',
                isset($carriers_type[$carrier->id]) ? $carriers_type[$carrier->id] : 0
            );
        }

        if ($this->_pushDatas($root->asXml()) !== false) {
            if (!Configuration::get('PRESTAFRAUD_CONFIGURATION_OK')) {
                Configuration::updateValue('PRESTAFRAUD_CONFIGURATION_OK', true);
            }
            Db::getInstance()->execute(
                'INSERT IGNORE INTO '._DB_PREFIX_.'prestafraud_orders (id_order)
                 VALUES ('.(int) $params['order']->id.')'
            );
        }
        return true;
    }

    public function hookCart($params)
    {
        if ($_SERVER['REMOTE_ADDR'] == '0.0.0.0'
            || empty($_SERVER['REMOTE_ADDR'])
            || $_SERVER['REMOTE_ADDR'] === '::1'
        ) {
            return;
        }
        if (!$params['cart'] || !$params['cart']->id) {
            return;
        }

        $id_cart = Db::getInstance()->getValue(
            'SELECT `id_cart`
            FROM '._DB_PREFIX_.'prestafraud_carts
            WHERE id_cart = '.(int) $params['cart']->id
        );
        if ($id_cart) {
            Db::getInstance()->execute(
                'UPDATE `'._DB_PREFIX_.'prestafraud_carts`
                SET `ip_address` = '.(int) ip2long($_SERVER['REMOTE_ADDR']).', `date` = \''.pSQL(date('Y-m-d H:i:s')).'\'
                WHERE `id_cart` = '.(int) $params['cart']->id.' LIMIT 1'
            );
        } else {
            Db::getInstance()->execute(
                'INSERT INTO `'._DB_PREFIX_.'prestafraud_carts` (`id_cart`, `ip_address`, `date`)
                VALUES ('.(int) $params['cart']->id.', '.(int) ip2long($_SERVER['REMOTE_ADDR']).', \''.date(
                    'Y-m-d H:i:s'
                ).'\')'
            );
        }
        return true;
    }

    private function _getCustomerInfos($order)
    {
        $last_order = Db::getInstance()->getValue(
            'SELECT date_add
            FROM '._DB_PREFIX_.'orders
            WHERE id_customer = '.(int) $order->id_customer.' AND id_order != '.(int) $order->id.'
            ORDER BY date_add DESC'
        );

        $orders_valid = Db::getInstance()->getRow(
            'SELECT COUNT(*) nb_valid, SUM(total_paid) sum_valid
            FROM '._DB_PREFIX_.'orders
            WHERE valid = 1 AND id_order != '.(int) $order->id.' AND id_customer = '.(int) $order->id_customer
        );

        $orders_unvalid = Db::getInstance()->getRow(
            'SELECT COUNT(*) nb_unvalid, SUM(total_paid) sum_unvalid
            FROM '._DB_PREFIX_.'orders
            WHERE valid = 0 AND id_order != '.(int) $order->id.' AND id_customer = '.(int) $order->id_customer
        );

        $ip_addresses = Db::getInstance()->executeS(
            'SELECT c.ip_address
            FROM '._DB_PREFIX_.'guest g
            LEFT JOIN '._DB_PREFIX_.'connections c ON (c.id_guest = g.id_guest)
            WHERE g.id_customer='.(int) $order->id_customer.'
            ORDER BY c.id_connections DESC'
        );
        $address_list = array();
        foreach ($ip_addresses as $ip) {
            $address_list[] = $ip['ip_address'];
        }

        return array(
            'customer_date_last_order' => $last_order,
            'customer_orders_valid_count' => $orders_valid['nb_valid'],
            'customer_orders_valid_sum' => $orders_valid['sum_valid'],
            'customer_orders_unvalid_count' => $orders_unvalid['nb_unvalid'],
            'customer_orders_unvalid_sum' => $orders_unvalid['sum_unvalid'],
            'customer_ip_addresses_history' => serialize($address_list),
        );
    }

    private static function _getIpByCart($id_cart)
    {
        return long2ip(
            Db::getInstance()->getValue(
                'SELECT `ip_address`
                FROM '._DB_PREFIX_.'prestafraud_carts
                WHERE id_cart = '.(int) $id_cart
            )
        );
    }

    public function hookAdminOrder($params)
    {
        $id_order = Db::getInstance()->getValue(
            'SELECT id_order FROM '._DB_PREFIX_.'prestafraud_orders WHERE id_order = '.(int) $params['id_order']
        );
        $this->_html .= '<br /><fieldset><legend>'.$this->trans('PrestaShop Security', array(), 'Modules.Prestafraud.Admin').'</legend>';
        if (!$id_order) {
            $this->_html .= $this->trans('This order has not been sent to PrestaShop Security.', array(), 'Modules.Prestafraud.Admin');
        } else {
            $scoring = $this->_getScoring((int) $id_order, $this->context->language->id);
            $this->_html .= '<p><b>'.$this->trans('Scoring:', array(), 'Modules.Prestafraud.Admin').'</b> '.($scoring['scoring'] < 0 ? $this->trans(
                    'Unknown', array(), 'Admin.Global'
                ) : (float) $scoring['scoring']).'</p>
            <p><b>'.$this->trans('Comment:', array(), 'Modules.Prestafraud.Admin').'</b> '.htmlentities($scoring['comment']).'</p>
            <p><center><a target="_BLANK" href="'.$this->_trustUrl.'fraud_report.php?shop_id='.Configuration::get(
                    'PS_TRUST_SHOP_ID'
                ).'&shop_key='.Configuration::get('PS_TRUST_SHOP_KEY').'&order_id='.$id_order.'">'.$this->trans(
                    'Report this order as a fraud to PrestaShop', array(), 'Modules.Prestafraud.Admin'
                ).'</a></center></p>';
        }
        $this->_html .= '</fieldset>';
        return $this->_html;
    }

    public function _getScoring($id_order, $id_lang)
    {
        $scoring = Db::getInstance()->getRow(
            'SELECT * FROM '._DB_PREFIX_.'prestafraud_orders
             WHERE scoring IS NOT NULL AND id_order = '.(int) $id_order
        );
        if (!$scoring) {
            $root = new SimpleXMLElement("<?xml version=\"1.0\"?><trust></trust>");
            $xml = $root->addChild('get_scoring');
            $xml->addChild('shop_id', Configuration::get('PS_TRUST_SHOP_ID'));
            $xml->addChild('shop_password', Configuration::get('PS_TRUST_SHOP_KEY'));
            $xml->addChild('id_order', (int) $id_order);
            $xml->addChild('lang', Language::getIsoById((int) $id_lang));
            $result = $this->_pushDatas($root->asXml());
            if (!$result) {
                return false;
            }
            $xml = simplexml_load_string($result);
            if ((int) $xml->check_scoring->status != -1) {
                Db::getInstance()->execute(
                    'UPDATE '._DB_PREFIX_.'prestafraud_orders SET scoring = '.(float) $xml->check_scoring->scoring
                    .', comment = \''.pSQL(
                        $xml->check_scoring->comment
                    ).'\' WHERE id_order='.(int) $id_order
                );
            }
            $scoring = array(
                'scoring' => (float) $xml->check_scoring->scoring,
                'comment' => (string) $xml->check_scoring->comment,
            );
        }
        return $scoring;
    }

    private function _getPrestaTrustCarriersType()
    {
        return array(
            '1' => $this->trans('Pick up in-store', array(), 'Admin.Global'),
            '2' => $this->trans('Withdrawal point', array(), 'Admin.Global'),
            '3' => $this->trans('Slow shipping more than 3 days', array(), 'Admin.Global'),
            '4' => $this->trans('Shipping express', array(), 'Admin.Global'),
        );
    }

    private function _getConfiguredCarriers()
    {
        $result = Db::getInstance()->executeS('SELECT * FROM '._DB_PREFIX_.'prestafraud_carrier');
        $carriers = array();
        foreach ($result as $row) {
            $carriers[$row['id_carrier']] = $row['id_prestafraud_carrier_type'];
        }

        return $carriers;
    }

    private function _getConfiguredPayments()
    {
        $result = Db::getInstance()->executeS('SELECT * FROM '._DB_PREFIX_.'prestafraud_payment');
        $payments = array();
        foreach ($result as $row) {
            $payments[$row['id_module']] = $row['id_prestafraud_payment_type'];
        }

        return $payments;
    }

    private function _setCarriersConfiguration($carriers)
    {
        Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.'prestafraud_carrier');
        foreach ($carriers as $id_carrier => $id_carrier_type) {
            Db::getInstance()->execute(
                'INSERT INTO '._DB_PREFIX_.'prestafraud_carrier (id_carrier, id_prestafraud_carrier_type)
                 VALUES ('.(int) $id_carrier.', '.(int) $id_carrier_type.')'
            );
        }
    }

    private function _setPaymentsConfiguration($payments)
    {
        Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.'prestafraud_payment');
        foreach ($payments as $id_module => $id_payment_type) {
            Db::getInstance()->execute(
                'INSERT INTO '._DB_PREFIX_.'prestafraud_payment (id_module, id_prestafraud_payment_type)
                 VALUES ('.(int) $id_module.', '.(int) $id_payment_type.')'
            );
        }
    }

    private function _updateConfiguredCarrier($old, $new)
    {
        return Db::getInstance()->execute(
            'UPDATE '._DB_PREFIX_.'prestafraud_carrier SET id_carrier='.(int) $new.' WHERE id_carrier = '.(int) $old
        );
    }

    private function _pushDatas($xml)
    {
        $content = http_build_query(array('xml' => preg_replace("/\r|\n/", '', $xml)));
        $stream_context = stream_context_create(
            array(
                'http' => array(
                    'method' => 'POST',
                    'content' => $content,
                    'header' => 'Content-type:application/x-www-form-urlencoded',
                    'timeout' => 12,
                ),
            )
        );
        return Tools::file_get_contents($this->_trustUrl, false, $stream_context);
    }
}
