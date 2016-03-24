<?php
/**
 * 2007-2016 PrestaShop
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
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2016 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

include_once _PS_MODULE_DIR_.'paypal/api/paypal_lib.php';
include_once _PS_MODULE_DIR_.'paypal/api/CallApiPaypalPlus.php';
include_once _PS_MODULE_DIR_.'paypal/paypal_logos.php';
include_once _PS_MODULE_DIR_.'paypal/paypal_orders.php';
include_once _PS_MODULE_DIR_.'paypal/paypal_tools.php';
include_once _PS_MODULE_DIR_.'paypal/paypal_login/paypal_login.php';
include_once _PS_MODULE_DIR_.'paypal/paypal_login/PayPalLoginUser.php';
include_once _PS_MODULE_DIR_.'paypal/classes/PaypalCapture.php';
include_once _PS_MODULE_DIR_.'paypal/classes/AuthenticatePaymentMethods.php';
include_once _PS_MODULE_DIR_.'paypal/classes/TLSVerificator.php';

define('WPS', 1); //Paypal Integral
define('HSS', 2); //Paypal Integral Evolution
define('ECS', 4); //Paypal Option +
define('PPP', 5); //Paypal Plus

/* Tracking */
define('TRACKING_INTEGRAL_EVOLUTION', 'FR_PRESTASHOP_H3S');
define('TRACKING_INTEGRAL', 'PRESTASHOP_EC');
define('TRACKING_OPTION_PLUS', 'PRESTASHOP_ECM');
define('TRACKING_PAYPAL_PLUS', 'PRESTASHOP_PPP');
define('PAYPAL_HSS_REDIRECTION', 0);
define('PAYPAL_HSS_IFRAME', 1);
define('TRACKING_EXPRESS_CHECKOUT_SEAMLESS', 'PrestaShopCEMEA_Cart_LIPP');

define('TRACKING_CODE', 'FR_PRESTASHOP_H3S');
define('SMARTPHONE_TRACKING_CODE', 'Prestashop_Cart_smartphone_EC');
define('TABLET_TRACKING_CODE', 'Prestashop_Cart_tablet_EC');

/* Traking APAC */
define('APAC_TRACKING_INTEGRAL_EVOLUTION', 'PSAPAC_PRESTASHOP_H3S');
define('APAC_TRACKING_INTEGRAL', 'PSAPAC_PRESTASHOP_EC');
define('APAC_TRACKING_OPTION_PLUS', 'PSAPAC_PRESTASHOP_ECM');
define('APAC_TRACKING_PAYPAL_PLUS', 'PSAPAC_PRESTASHOP_PPP');
define('APAC_TRACKING_EXPRESS_CHECKOUT_SEAMLESS', 'PSAPAC_PRESTASHOP_LIPP');

define('APAC_TRACKING_CODE', 'PSAPAC_PRESTASHOP_H3S');
define('APAC_SMARTPHONE_TRACKING_CODE', 'PSAPAC_PRESTASHOP_MOB_EC');
define('APAC_TABLET_TRACKING_CODE', 'PSAPAC_PRESTASHOP_TAB_EC');

define('_PAYPAL_LOGO_XML_', 'logos.xml');
define('_PAYPAL_MODULE_DIRNAME_', 'paypal');
define('_PAYPAL_TRANSLATIONS_XML_', 'translations.xml');

class PayPal extends PaymentModule
{

    protected $_html = '';
    public $_errors = array();
    public $context;
    public $iso_code;
    public $default_country;
    public $paypal_logos;
    public $module_key = '646dcec2b7ca20c4e9a5aebbbad98d7e';

    const BACKWARD_REQUIREMENT = '0.4';
    const ONLY_PRODUCTS = 1;
    const ONLY_DISCOUNTS = 2;
    const BOTH = 3;
    const BOTH_WITHOUT_SHIPPING = 4;
    const ONLY_SHIPPING = 5;
    const ONLY_WRAPPING = 6;
    const ONLY_PRODUCTS_WITHOUT_SHIPPING = 7;

    public function __construct()
    {
        $this->name = 'paypal';
        $this->tab = 'payments_gateways';
        $this->version = '3.10.6';
        $this->author = 'PrestaShop';
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'radio';

        parent::__construct();

        $this->displayName = $this->l('PayPal');
        $this->description = $this->l('Accepts payments by credit cards (CB, Visa, MasterCard, Amex, Aurore, Cofinoga, 4 stars) with PayPal.');
        $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');

        $this->page = basename(__FILE__, '.php');

        if (version_compare(_PS_VERSION_, '1.5', '<')) {
            $mobile_enabled = (int) Configuration::get('PS_MOBILE_DEVICE');
            require _PS_MODULE_DIR_.$this->name.'/backward_compatibility/backward.php';
        } else {
            $mobile_enabled = (int) Configuration::get('PS_ALLOW_MOBILE_DEVICE');
        }

        if (self::isInstalled($this->name)) {
            $this->loadDefaults();
            if ($mobile_enabled && $this->active) {
                $this->checkMobileCredentials();
            } elseif ($mobile_enabled && !$this->active) {
                $this->checkMobileNeeds();
            }

        } else {
            $this->checkMobileNeeds();
        }

        $tls_verificator = new TLSVerificator(true, $this);
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHook('payment') || !$this->registerHook('paymentReturn')
            ||
            !$this->registerHook('shoppingCartExtra') || !$this->registerHook('backBeforePayment')
            || !$this->registerHook('rightColumn') ||
            !$this->registerHook('cancelProduct') || !$this->registerHook('productFooter')
            || !$this->registerHook('header') ||
            !$this->registerHook('adminOrder') || !$this->registerHook('backOfficeHeader')) {
            return false;
        }

        if ((_PS_VERSION_ >= '1.5') && (!$this->registerHook('displayMobileHeader')
            ||
            !$this->registerHook('displayMobileShoppingCartTop') || !$this->registerHook('displayMobileAddToCartTop')
            || !$this->registerHook('displayPaymentEU') || !$this->registerHook('actionPSCleanerGetModulesTables'))) {
            return false;
        }

        include_once _PS_MODULE_DIR_.$this->name.'/paypal_install.php';
        $paypal_install = new PayPalInstall();
        $paypal_install->createTables();
        $paypal_install->updateConfiguration($this->version);
        $paypal_install->createOrderState();

        $paypal_tools = new PayPalTools($this->name);
        $paypal_tools->moveTopPayments(1);
        $paypal_tools->moveRightColumn(3);

        $this->runUpgrades(true);

        return true;
    }

    public function uninstall()
    {
        include_once _PS_MODULE_DIR_.$this->name.'/paypal_install.php';
        $paypal_install = new PayPalInstall();
        $paypal_install->deleteConfiguration();
        return parent::uninstall();
    }

    /**
     * Launch upgrade process
     */
    public function runUpgrades($install = false)
    {
        if (version_compare(_PS_VERSION_, '1.5', '<')) {
            foreach (array('2.8', '3.0', '3.7', '3.8.3', '3.9', '3.10.1', '3.10.4') as $version) {
                $file = dirname(__FILE__).'/upgrade/install-'.$version.'.php';
                if (version_compare(Configuration::get('PAYPAL_VERSION'), $version, '<') && file_exists($file)) {
                    include_once $file;
                    call_user_func('upgrade_module_'.str_replace('.', '_', $version), $this, $install);
                }
            }
        }

    }

    private function compatibilityCheck()
    {
        if (file_exists(_PS_MODULE_DIR_.'paypalapi/paypalapi.php') && $this->active) {
            $this->warning = $this->l('All features of Paypal API module are included in the new Paypal module. In order to do not have any conflict, please do not use and remove PayPalAPI module.').'<br />';
        }

        /* For 1.4.3 and less compatibility */
        $update_config = array('PS_OS_CHEQUE' => 1, 'PS_OS_PAYMENT' => 2, 'PS_OS_PREPARATION' => 3,
            'PS_OS_SHIPPING' => 4,
            'PS_OS_DELIVERED' => 5, 'PS_OS_CANCELED' => 6, 'PS_OS_REFUND' => 7, 'PS_OS_ERROR' => 8,
            'PS_OS_OUTOFSTOCK' => 9,
            'PS_OS_BANKWIRE' => 10, 'PS_OS_PAYPAL' => 11, 'PS_OS_WS_PAYMENT' => 12);

        foreach ($update_config as $key => $value) {
            if (!Configuration::get($key) || (int) Configuration::get($key) < 1) {
                if (defined('_'.$key.'_') && (int) constant('_'.$key.'_')
                    > 0) {
                    Configuration::updateValue($key, constant('_'.$key.'_'));
                } else {
                    Configuration::updateValue($key, $value);
                }

            }
        }

    }

    public function isPayPalAPIAvailable()
    {
        $payment_method = Configuration::get('PAYPAL_PAYMENT_METHOD');

        if (($payment_method == WPS || $payment_method == ECS) && (!is_null(Configuration::get('PAYPAL_API_USER'))
            && !is_null(Configuration::get('PAYPAL_API_PASSWORD')) && !is_null(Configuration::get('PAYPAL_API_SIGNATURE')))) {
            return true;
        }

        if ($payment_method == PPP && (!is_null(Configuration::get('PAYPAL_PLUS_CLIENT_ID'))
            || !is_null(Configuration::get('PAYPAL_PLUS_SECRET')))) {
            return true;
        }

        if ($payment_method == HSS && !is_null(Configuration::get('PAYPAL_BUSINESS_ACCOUNT'))) {
            return true;
        }

        return false;
    }

    /**
     * Initialize default values
     */
    protected function loadDefaults()
    {
        $this->loadLangDefault();
        $this->paypal_logos = new PayPalLogos($this->iso_code);
        $payment_method = Configuration::get('PAYPAL_PAYMENT_METHOD');
        $order_process_type = (int) Configuration::get('PS_ORDER_PROCESS_TYPE');

        if (Tools::getValue('paypal_ec_canceled') || $this->context->cart === false) {
            unset($this->context->cookie->express_checkout);
        }

        if (version_compare(_PS_VERSION_, '1.5.0.2', '>=')) {
            $version = Db::getInstance()->getValue('SELECT version FROM `'._DB_PREFIX_.'module` WHERE name = \''.$this->name.'\'');
            if (empty($version) === true) {
                Db::getInstance()->execute('
                    UPDATE `'._DB_PREFIX_.'module` m
                    SET m.version = \''.bqSQL($this->version).'\'
                    WHERE m.name = \''.bqSQL($this->name).'\'');
            }

        }

        if (defined('_PS_ADMIN_DIR_')) {
            /* Backward compatibility */
            if (version_compare(_PS_VERSION_, '1.5', '<')) {
                $this->backwardCompatibilityChecks();
            }

            /* Upgrade and compatibility checks */
            $this->runUpgrades();
            $this->compatibilityCheck();
            $this->warningsCheck();
        } else {
            if (isset($this->context->cookie->express_checkout)) {
                $this->context->smarty->assign('paypal_authorization', true);
            }

            if (($order_process_type == 1) && ((int) $payment_method == HSS) && !$this->useMobile()) {
                $this->context->smarty->assign('paypal_order_opc', true);
            } elseif (($order_process_type == 1) && ((bool) Tools::getValue('isPaymentStep')
                == true)) {
                $shop_url = PayPal::getShopDomainSsl(true, true);
                if (version_compare(_PS_VERSION_, '1.5', '<')) {
                    $link = $shop_url._MODULE_DIR_.$this->name.'/express_checkout/payment.php';
                    $this->context->smarty->assign(
                        'paypal_confirmation',
                        $link.'?'.http_build_query(array('get_confirmation' => true), '', '&')
                    );
                } else {
                    $values = array('fc' => 'module', 'module' => 'paypal', 'controller' => 'confirm',
                        'get_confirmation' => true);
                    $this->context->smarty->assign('paypal_confirmation', $shop_url.__PS_BASE_URI__.'?'.http_build_query($values));
                }
            }
        }
    }

    protected function checkMobileCredentials()
    {
        $payment_method = Configuration::get('PAYPAL_PAYMENT_METHOD');

        if (((int) $payment_method == HSS) && (
            (!(bool) Configuration::get('PAYPAL_API_USER')) &&
            (!(bool) Configuration::get('PAYPAL_API_PASSWORD')) &&
            (!(bool) Configuration::get('PAYPAL_API_SIGNATURE')))) {
            $this->warning .= $this->l('You must set your PayPal Integral credentials in order to have the mobile theme work correctly.').'<br />';
        }

    }

    protected function checkMobileNeeds()
    {
        $iso_code = Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        $paypal_countries = array('ES', 'FR', 'PL', 'IT');

        if (method_exists($this->context->shop, 'getTheme')) {
            if (($this->context->shop->getTheme() == 'default') && in_array($iso_code, $paypal_countries)) {
                $this->warning .= $this->l('The mobile theme only works with the PayPal\'s payment module at this time. Please activate the module to enable payments.').'<br />';
            }

        } else {
            $this->warning .= $this->l('In order to use the module you need to install the backward compatibility.').'<br />';
        }

    }

    /* Check status of backward compatibility module */

    protected function backwardCompatibilityChecks()
    {
        if (Module::isInstalled('backwardcompatibility')) {
            $backward_module = Module::getInstanceByName('backwardcompatibility');
            if (!$backward_module->active) {
                $this->warning .= $this->l('To work properly the module requires the backward compatibility module enabled').'<br />';
            } elseif ($backward_module->version < PayPal::BACKWARD_REQUIREMENT) {
                $this->warning .= $this->l('To work properly the module requires at least the backward compatibility module v').PayPal::BACKWARD_REQUIREMENT.'.<br />';
            }

        } else {
            $this->warning .= $this->l('In order to use the module you need to install the backward compatibility.').'<br />';
        }

    }

    public function getContent()
    {
        $this->_postProcess();

        if (($id_lang = Language::getIdByIso('EN')) == 0) {
            $english_language_id = (int) $this->context->employee->id_lang;
        } else {
            $english_language_id = (int) $id_lang;
        }

        $this->context->smarty->assign(array(
            'PayPal_WPS' => (int) WPS,
            'PayPal_HSS' => (int) HSS,
            'PayPal_ECS' => (int) ECS,
            'PayPal_PPP' => (int) PPP,
            'PP_errors' => $this->_errors,
            'PayPal_logo' => $this->paypal_logos->getLogos(),
            'PayPal_allowed_methods' => $this->getPaymentMethods(),
            'PayPal_country' => Country::getNameById((int) $english_language_id, (int) $this->default_country),
            'PayPal_country_id' => (int) $this->default_country,
            'PayPal_business' => Configuration::get('PAYPAL_BUSINESS'),
            'PayPal_payment_method' => (int) Configuration::get('PAYPAL_PAYMENT_METHOD'),
            'PayPal_api_username' => Configuration::get('PAYPAL_API_USER'),
            'PayPal_api_password' => Configuration::get('PAYPAL_API_PASSWORD'),
            'PayPal_api_signature' => Configuration::get('PAYPAL_API_SIGNATURE'),
            'PayPal_api_business_account' => Configuration::get('PAYPAL_BUSINESS_ACCOUNT'),
            'PayPal_express_checkout_shortcut' => (int) Configuration::get('PAYPAL_EXPRESS_CHECKOUT_SHORTCUT'),
            'PayPal_in_context_checkout' => (int) Configuration::get('PAYPAL_IN_CONTEXT_CHECKOUT'),
            'use_paypal_in_context' => (int) $this->useInContextCheckout(),
            'PayPal_in_context_checkout_merchant_id' => Configuration::get('PAYPAL_IN_CONTEXT_CHECKOUT_M_ID'),
            'PayPal_sandbox_mode' => (int) Configuration::get('PAYPAL_SANDBOX'),
            'PayPal_payment_capture' => (int) Configuration::get('PAYPAL_CAPTURE'),
            'PayPal_country_default' => (int) $this->default_country,
            'PayPal_change_country_url' => 'index.php?tab=AdminCountries&token='.Tools::getAdminTokenLite('AdminCountries').'#footer',
            'Countries' => Country::getCountries($english_language_id),
            'One_Page_Checkout' => (int) Configuration::get('PS_ORDER_PROCESS_TYPE'),
            'PayPal_integral_evolution_template' => Configuration::get('PAYPAL_HSS_TEMPLATE'),
            'PayPal_integral_evolution_solution' => Configuration::get('PAYPAL_HSS_SOLUTION'),
            'PayPal_login' => (int) Configuration::get('PAYPAL_LOGIN'),
            'PayPal_login_client_id' => Configuration::get('PAYPAL_LOGIN_CLIENT_ID'),
            'PayPal_login_secret' => Configuration::get('PAYPAL_LOGIN_SECRET'),
            'PayPal_login_tpl' => (int) Configuration::get('PAYPAL_LOGIN_TPL'),
            'default_lang_iso' => Language::getIsoById($this->context->employee->id_lang),
            'PayPal_plus_client' => Configuration::get('PAYPAL_PLUS_CLIENT_ID'),
            'PayPal_plus_secret' => Configuration::get('PAYPAL_PLUS_SECRET'),
            'PayPal_plus_webprofile' => (Configuration::get('PAYPAL_WEB_PROFILE_ID') != '0') ? Configuration::get('PAYPAL_WEB_PROFILE_ID') : 0,
            'PayPal_version_tls_checked' => Configuration::get('PAYPAL_VERSION_TLS_CHECKED'),
            'Presta_version' => _PS_VERSION_,
        ));

        $this->getTranslations();

        $output = $this->fetchTemplate('/views/templates/admin/back_office.tpl');

        if ($this->active == false) {
            return $output.$this->hookBackOfficeHeader();
        }

        return $output;
    }

    /**
     * Hooks methods
     */
    public function hookHeader()
    {
        if ($this->useMobile()) {
            $id_hook = (int) Configuration::get('PS_MOBILE_HOOK_HEADER_ID');
            if ($id_hook > 0) {
                $module = Hook::getModulesFromHook($id_hook, $this->id);
                if (!$module) {
                    $this->registerHook('displayMobileHeader');
                }

            }
        }

        if (isset($this->context->cart) && $this->context->cart->id) {
            $this->context->smarty->assign('id_cart', (int) $this->context->cart->id);
        }

        /* Added for PrestaBox */
        if (method_exists($this->context->controller, 'addCSS')) {
            $this->context->controller->addCSS(_MODULE_DIR_.$this->name.'/views/css/paypal.css');
        } else {
            Tools::addCSS(_MODULE_DIR_.$this->name.'/views/css/paypal.css');
        }

        $smarty = $this->context->smarty;
        $smarty->assign(array(
            'ssl_enabled' => Configuration::get('PS_SSL_ENABLED'),
            'PAYPAL_SANDBOX' => Configuration::get('PAYPAL_SANDBOX'),
            'PayPal_in_context_checkout' => Configuration::get('PAYPAL_IN_CONTEXT_CHECKOUT'),
            'use_paypal_in_context' => (int) $this->useInContextCheckout(),
            'PayPal_in_context_checkout_merchant_id' => Configuration::get('PAYPAL_IN_CONTEXT_CHECKOUT_M_ID'),
        ));

        $process = '<script type="text/javascript">'.$this->fetchTemplate('views/js/paypal.js').'</script>';
        if ($this->useInContextCheckout()) {
            $process .= '<script defer src="//www.paypalobjects.com/api/checkout.js"></script>';
        }

        if ((
            (method_exists($smarty, 'getTemplateVars') && ($smarty->getTemplateVars('page_name')
                == 'authentication' || $smarty->getTemplateVars('page_name') == 'order-opc'))
            || (isset($smarty->_tpl_vars) && ($smarty->_tpl_vars['page_name']
                == 'authentication' || $smarty->_tpl_vars['page_name'] == 'order-opc')))
            &&
            (int) Configuration::get('PAYPAL_LOGIN') == 1) {
            $this->context->smarty->assign(array(
                'paypal_locale' => $this->getLocale(),
                'PAYPAL_LOGIN_CLIENT_ID' => Configuration::get('PAYPAL_LOGIN_CLIENT_ID'),
                'PAYPAL_LOGIN_TPL' => Configuration::get('PAYPAL_LOGIN_TPL'),
                'PAYPAL_RETURN_LINK' => PayPalLogin::getReturnLink(),
            ));
            $process .= '
                    <script src="https://www.paypalobjects.com/js/external/api.js"></script>
                    <script>'.$this->fetchTemplate('views/js/paypal_login.js').'</script>';
        }

        if (Configuration::get('PAYPAL_PAYMENT_METHOD') == PPP) {

            $this->context->smarty->assign(array(
                'paypal_locale' => $this->getLocalePayPalPlus(),
                'PAYPAL_LOGIN_CLIENT_ID' => Configuration::get('PAYPAL_LOGIN_CLIENT_ID'),
                'PAYPAL_LOGIN_TPL' => Configuration::get('PAYPAL_LOGIN_TPL'),
                'PAYPAL_RETURN_LINK' => PayPalLogin::getReturnLink(),
            ));
            $process .= '<script src="https://www.paypalobjects.com/webstatic/ppplus/ppplus.min.js" type="text/javascript"></script>';
        }

        return $process;
    }

    public function useInContextCheckout()
    {
        return Configuration::get('PAYPAL_IN_CONTEXT_CHECKOUT') && Configuration::get('PAYPAL_IN_CONTEXT_CHECKOUT_M_ID')
            != null;
    }

    public function getLocalePayPalPlus()
    {
        switch (Tools::strtolower($this->getCountryCode())) {
            case 'fr':
                return 'fr_FR';
            case 'hk':
                return 'zh_HK';
            case 'cn':
                return 'zh_CN';
            case 'tw':
                return 'zh_TW';
            case 'xc':
                return 'zh_XC';
            case 'dk':
                return 'da_DK';
            case 'nl':
                return 'nl_NL';
            case 'gb':
                return 'en_GB';
            case 'de':
                return 'de_DE';
            case 'il':
                return 'he_IL';
            case 'id':
                return 'id_ID';
            case 'il':
                return 'it_IT';
            case 'jp':
                return 'ja_JP';
            case 'no':
                return 'no_NO';
            case 'pt':
                return 'pt_PT';
            case 'pl':
                return 'pl_PL';
            case 'ru':
                return 'ru_RU';
            case 'es':
                return 'es_ES';
            case 'se':
                return 'sv_SE';
            case 'th':
                return 'th_TH';
            case 'tr':
                return 'tr_TR';
            default:
                return 'en_GB';
        }
    }

    public function getLocale()
    {
        switch (Language::getIsoById($this->context->language->id)) {
            case 'fr':
                return 'fr-fr';
            case 'hk':
                return 'zh-hk';
            case 'cn':
                return 'zh-cn';
            case 'tw':
                return 'zh-tw';
            case 'xc':
                return 'zh-xc';
            case 'dk':
                return 'da-dk';
            case 'nl':
                return 'nl-nl';
            case 'gb':
                return 'en-gb';
            case 'de':
                return 'de-de';
            case 'il':
                return 'he-il';
            case 'id':
                return 'id-id';
            case 'il':
                return 'it-it';
            case 'jp':
                return 'ja-jp';
            case 'no':
                return 'no-no';
            case 'pt':
                return 'pt-pt';
            case 'pl':
                return 'pl-pl';
            case 'ru':
                return 'ru-ru';
            case 'es':
                return 'es-es';
            case 'se':
                return 'sv-se';
            case 'th':
                return 'th-th';
            case 'tr':
                return 'tr-tr';
            default:
                return 'en-gb';
        }
    }

    public function canBeUsed()
    {
        if (!$this->active) {
            return false;
        }

        //If merchant has not upgraded and payment method is out of country's specs
        if (!Configuration::get('PAYPAL_UPDATED_COUNTRIES_OK') && !in_array((int) Configuration::get('PAYPAL_PAYMENT_METHOD'), $this->getPaymentMethods())) {
            return false;
        }

        return true;
    }

    public function hookDisplayMobileHeader()
    {
        return $this->hookHeader();
    }

    public function hookDisplayMobileShoppingCartTop()
    {
        return $this->renderExpressCheckoutButton('cart').$this->renderExpressCheckoutForm('cart');
    }

    public function hookDisplayMobileAddToCartTop()
    {
        return $this->renderExpressCheckoutButton('cart');
    }

    public function hookProductFooter()
    {
        $content = (!$this->useMobile()) ? $this->renderExpressCheckoutButton('product')
        : null;
        return $content.$this->renderExpressCheckoutForm('product');
    }

    public function hookPayment($params)
    {
        if (!$this->canBeUsed()) {
            return;
        }

        $use_mobile = $this->useMobile();

        if ($use_mobile) {
            $method = ECS;
        } else {
            $method = (int) Configuration::get('PAYPAL_PAYMENT_METHOD');
        }

        if (isset($this->context->cookie->express_checkout)) {
            $this->redirectToConfirmation();
        }

        $iso_lang = array(
            'en' => 'en_US',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
        );

        $this->context->smarty->assign(array(
            'logos' => $this->paypal_logos->getLogos(),
            'sandbox_mode' => Configuration::get('PAYPAL_SANDBOX'),
            'use_mobile' => $use_mobile,
            'PayPal_lang_code' => (isset($iso_lang[$this->context->language->iso_code]))
            ? $iso_lang[$this->context->language->iso_code] : 'en_US',
        ));

        if ($method == HSS) {
            $billing_address = new Address($this->context->cart->id_address_invoice);
            $delivery_address = new Address($this->context->cart->id_address_delivery);
            $billing_address->country = new Country($billing_address->id_country);
            $delivery_address->country = new Country($delivery_address->id_country);
            $billing_address->state = new State($billing_address->id_state);
            $delivery_address->state = new State($delivery_address->id_state);

            $cart = $this->context->cart;
            $cart_details = $cart->getSummaryDetails(null, true);

            if ((int) Configuration::get('PAYPAL_SANDBOX') == 1) {
                $action_url = 'https://securepayments.sandbox.paypal.com/acquiringweb';
            } else {
                $action_url = 'https://securepayments.paypal.com/acquiringweb';
            }

            $shop_url = PayPal::getShopDomainSsl(true, true);

            $this->context->smarty->assign(array(
                'action_url' => $action_url,
                'cart' => $cart,
                'cart_details' => $cart_details,
                'currency' => new Currency((int) $cart->id_currency),
                'customer' => $this->context->customer,
                'business_account' => Configuration::get('PAYPAL_BUSINESS_ACCOUNT'),
                'custom' => Tools::jsonEncode(array('id_cart' => $cart->id, 'hash' => sha1(serialize($cart->nbProducts())))),
                'gift_price' => (float) $this->getGiftWrappingPrice(),
                'billing_address' => $billing_address,
                'delivery_address' => $delivery_address,
                'shipping' => $cart_details['total_shipping_tax_exc'],
                'subtotal' => $cart_details['total_price_without_tax'] - $cart_details['total_shipping_tax_exc'],
                'time' => time(),
                'cancel_return' => $this->context->link->getPageLink('order.php'),
                'notify_url' => $shop_url._MODULE_DIR_.$this->name.'/ipn.php',
                'return_url' => $shop_url._MODULE_DIR_.$this->name.'/integral_evolution/submit.php?id_cart='.(int) $cart->id,
                'tracking_code' => $this->getTrackingCode($method),
                'iso_code' => Tools::strtoupper($this->context->language->iso_code),
                'payment_hss_solution' => Configuration::get('PAYPAL_HSS_SOLUTION'),
                'payment_hss_template' => Configuration::get('PAYPAL_HSS_TEMPLATE'),
            ));
            $this->getTranslations();
            return $this->fetchTemplate('integral_evolution_payment.tpl');
        } elseif ($method == WPS || $method == ECS) {
            $this->getTranslations();
            $this->context->smarty->assign(array(
                'PayPal_integral' => WPS,
                'PayPal_express_checkout' => ECS,
                'PayPal_payment_method' => $method,
                'PayPal_payment_type' => 'payment_cart',
                'PayPal_current_page' => $this->getCurrentUrl(),
                'PayPal_tracking_code' => $this->getTrackingCode($method),
                'PayPal_in_context_checkout' => Configuration::get('PAYPAL_IN_CONTEXT_CHECKOUT'),
                'use_paypal_in_context' => (int) $this->useInContextCheckout(),
                'PayPal_in_context_checkout_merchant_id' => Configuration::get('PAYPAL_IN_CONTEXT_CHECKOUT_M_ID'),
            ));

            return $this->fetchTemplate('express_checkout_payment.tpl');
        } elseif ($method == PPP) {

            $CallApiPaypalPlus = new CallApiPaypalPlus();
            $CallApiPaypalPlus->setParams($params);

            $approuval_url = $CallApiPaypalPlus->getApprovalUrl();

            $this->context->smarty->assign(
                array(
                    'approval_url' => $approuval_url,
                    'language' => $this->getLocalePayPalPlus(),
                    'country' => $this->getCountryCode(),
                    'mode' => Configuration::get('PAYPAL_SANDBOX') ? 'sandbox'
                    : 'live',
                )
            );

            return $this->fetchTemplate('paypal_plus_payment.tpl');
        }
    }

    public function hookDisplayPaymentEU($params)
    {
        if (!$this->active) {
            return;
        }

        if ($this->hookPayment($params) == null) {
            return null;
        }

        $use_mobile = $this->useMobile();

        if ($use_mobile) {
            $method = ECS;
        } else {
            $method = (int) Configuration::get('PAYPAL_PAYMENT_METHOD');
        }

        if (isset($this->context->cookie->express_checkout)) {
            $this->redirectToConfirmation();
        }

        $logos = $this->paypal_logos->getLogos();

        if (isset($logos['LocalPayPalHorizontalSolutionPP']) && $method == WPS) {
            $logo = $logos['LocalPayPalHorizontalSolutionPP'];
        } else {
            $logo = $logos['LocalPayPalLogoMedium'];
        }

        if ($method == HSS) {
            return array(
                'cta_text' => $this->l('Paypal'),
                'logo' => $logo,
                'form' => $this->fetchTemplate('integral_evolution_payment_eu.tpl'),
            );
        } elseif ($method == WPS || $method == ECS) {
            return array(
                'cta_text' => $this->l('Paypal'),
                'logo' => $logo,
                'form' => $this->fetchTemplate('express_checkout_payment_eu.tpl'),
            );
        }
    }

    public function hookShoppingCartExtra()
    {
        if (!$this->active
            || (((int) Configuration::get('PAYPAL_PAYMENT_METHOD') == HSS) && !$this->context->getMobileDevice())
            || !Configuration::get('PAYPAL_EXPRESS_CHECKOUT_SHORTCUT')
            || !in_array(ECS, $this->getPaymentMethods())
            || isset($this->context->cookie->express_checkout)) {
            return null;
        }

        $values = array('en' => 'en_US', 'fr' => 'fr_FR', 'de' => 'de_DE');
        $paypal_logos = $this->paypal_logos->getLogos();

        $this->context->smarty->assign(array(
            'PayPal_payment_type' => 'cart',
            'paypal_express_checkout_shortcut_logo' => isset($paypal_logos['ExpressCheckoutShortcutButton'])
            ? $paypal_logos['ExpressCheckoutShortcutButton'] : false,
            'PayPal_current_page' => $this->getCurrentUrl(),
            'PayPal_lang_code' => (isset($values[$this->context->language->iso_code])
                ? $values[$this->context->language->iso_code] : 'en_US'),
            'PayPal_tracking_code' => $this->getTrackingCode((int) Configuration::get('PAYPAL_PAYMENT_METHOD')),
            'include_form' => true,
            'template_dir' => dirname(__FILE__).'/views/templates/hook/'));

        return $this->fetchTemplate('express_checkout_shortcut_button.tpl');
    }

    public function hookPaymentReturn()
    {
        if (!$this->active) {
            return null;
        }

        return $this->fetchTemplate('confirmation.tpl');
    }

    public function hookRightColumn()
    {
        $this->context->smarty->assign('logo', $this->paypal_logos->getCardsLogo(true));
        return $this->fetchTemplate('column.tpl');
    }

    public function hookLeftColumn()
    {
        return $this->hookRightColumn();
    }

    public function hookBackBeforePayment($params)
    {
        if (!$this->active) {
            return null;
        }

        /* Only execute if you use PayPal API for payment */
        if (((int) Configuration::get('PAYPAL_PAYMENT_METHOD') != HSS) && $this->isPayPalAPIAvailable()) {
            if ($params['module'] != $this->name || !$this->context->cookie->paypal_token
                || !$this->context->cookie->paypal_payer_id) {
                return false;
            }

            Tools::redirect('modules/'.$this->name.'/express_checkout/submit.php?confirm=1&token='.$this->context->cookie->paypal_token.'&payerID='.$this->context->cookie->paypal_payer_id);
        }
    }

    public function setPayPalAsConfigured()
    {
        Configuration::updateValue('PAYPAL_CONFIGURATION_OK', true);
    }

    public function hookAdminOrder($params)
    {
        if (Tools::isSubmit('submitPayPalCapture')) {
            if ($capture_amount = Tools::getValue('totalCaptureMoney')) {
                if ($capture_amount = PaypalCapture::parsePrice($capture_amount)) {
                    if (Validate::isFloat($capture_amount)) {
                        $capture_amount = Tools::ps_round($capture_amount, '6');
                        $ord = new Order((int) $params['id_order']);
                        $cpt = new PaypalCapture();

                        if (($capture_amount > Tools::ps_round(0, '6')) && (Tools::ps_round($cpt->getRestToPaid($ord), '6') >= $capture_amount)) {
                            $complete = false;

                            if ($capture_amount > Tools::ps_round((float) $ord->total_paid, '6')) {
                                $capture_amount = Tools::ps_round((float) $ord->total_paid, '6');
                                $complete = true;
                            }
                            if ($capture_amount == Tools::ps_round($cpt->getRestToPaid($ord), '6')) {
                                $complete = true;
                            }

                            $this->_doCapture($params['id_order'], $capture_amount, $complete);
                        }
                    }
                }
            }
        } elseif (Tools::isSubmit('submitPayPalRefund')) {
            $this->_doTotalRefund($params['id_order']);
        }

        $admin_templates = array();
        if ($this->isPayPalAPIAvailable()) {
            if ($this->_needValidation((int) $params['id_order'])) {
                $admin_templates[] = 'validation';
            }

            if ($this->_needCapture((int) $params['id_order'])) {
                $admin_templates[] = 'capture';
            }

            if ($this->_canRefund((int) $params['id_order'])) {
                $admin_templates[] = 'refund';
            }

        }

        if (count($admin_templates) > 0) {
            $order = new Order((int) $params['id_order']);
            $currency = new Currency($order->id_currency);
            $cpt = new PaypalCapture();
            $cpt->id_order = (int) $order->id;

            if (version_compare(_PS_VERSION_, '1.5', '>=')) {
                $order_state = $order->current_state;
            } else {
                $order_state = OrderHistory::getLastOrderState($order->id);
            }

            $this->context->smarty->assign(
                array(
                    'authorization' => (int) Configuration::get('PAYPAL_OS_AUTHORIZATION'),
                    'base_url' => _PS_BASE_URL_.__PS_BASE_URI__,
                    'module_name' => $this->name,
                    'order_state' => $order_state,
                    'params' => $params,
                    'id_currency' => $currency->getSign(),
                    'rest_to_capture' => Tools::ps_round($cpt->getRestToPaid($order), '6'),
                    'list_captures' => $cpt->getListCaptured(),
                    'ps_version' => _PS_VERSION_,
                )
            );

            foreach ($admin_templates as $admin_template) {
                $this->_html .= $this->fetchTemplate('/views/templates/admin/admin_order/'.$admin_template.'.tpl');
                $this->_postProcess();
                $this->_html .= '</fieldset>';
            }
        }

        return $this->_html;
    }

    public function hookCancelProduct($params)
    {
        if (Tools::isSubmit('generateDiscount') || !$this->isPayPalAPIAvailable()
            || Tools::isSubmit('generateCreditSlip')) {
            return false;
        } elseif ($params['order']->module != $this->name || !($order = $params['order'])
            || !Validate::isLoadedObject($order)) {
            return false;
        } elseif (!$order->hasBeenPaid()) {
            return false;
        }

        $order_detail = new OrderDetail((int) $params['id_order_detail']);
        if (!$order_detail || !Validate::isLoadedObject($order_detail)) {
            return false;
        }

        $paypal_order = PayPalOrder::getOrderById((int) $order->id);
        if (!$paypal_order) {
            return false;
        }

        $products = $order->getProducts();
        $cancel_quantity = Tools::getValue('cancelQuantity');
        $message = $this->l('Cancel products result:').'<br>';

        $amount = (float) ($products[(int) $order_detail->id]['product_price_wt']
             * (int) $cancel_quantity[(int) $order_detail->id]);
        $refund = $this->_makeRefund($paypal_order['id_transaction'], (int) $order->id, $amount);
        $this->formatMessage($refund, $message);
        $this->_addNewPrivateMessage((int) $order->id, $message);
    }

    public function hookActionPSCleanerGetModulesTables()
    {
        return array('paypal_customer', 'paypal_order');
    }

    public function hookBackOfficeHeader()
    {
        if (Configuration::get('PAYPAL_VERSION_TLS_LAST_UPDATE') < date('Ymd')) {
            $paypal = new Paypal();
            $ssl_verif = new TLSVerificator(true, $this);
            Configuration::updateValue('PAYPAL_VERSION_TLS_CHECKED', $ssl_verif->getVersion());
            Configuration::updateValue('PAYPAL_VERSION_TLS_LAST_UPDATE', date('Ymd'));
        }

        if ((strcmp(Tools::getValue('configure'), $this->name) === 0) ||
            (strcmp(Tools::getValue('module_name'), $this->name) === 0)) {
            if (version_compare(_PS_VERSION_, '1.5', '<')) {
                $output = '<script type="text/javascript" src="'.__PS_BASE_URI__.'js/jquery/jquery-ui-1.8.10.custom.min.js"></script>
                    <script type="text/javascript" src="'.__PS_BASE_URI__.'js/jquery/jquery.fancybox-1.3.4.js"></script>
                    <link type="text/css" rel="stylesheet" href="'.__PS_BASE_URI__.'css/jquery.fancybox-1.3.4.css" />
                    <link type="text/css" rel="stylesheet" href="'._MODULE_DIR_.$this->name.'/views/css/paypal.css" />';
            } else {
                $this->context->controller->addJquery();
                $this->context->controller->addJQueryPlugin('fancybox');
                $this->context->controller->addCSS(_MODULE_DIR_.$this->name.'/views/css/paypal.css');
            }

            $this->context->smarty->assign(array(
                'PayPal_module_dir' => _MODULE_DIR_.$this->name,
                'PayPal_WPS' => (int) WPS,
                'PayPal_HSS' => (int) HSS,
                'PayPal_ECS' => (int) ECS,
                'PayPal_PPP' => (int) PPP,
            ));

            return (isset($output) ? $output : null).$this->fetchTemplate('/views/templates/admin/header.tpl');
        }
        return null;
    }

    public function renderExpressCheckoutButton($type)
    {
        if ((!Configuration::get('PAYPAL_EXPRESS_CHECKOUT_SHORTCUT') && !$this->useMobile())) {
            return null;
        }

        if (!in_array(ECS, $this->getPaymentMethods()) || (((int) Configuration::get('PAYPAL_BUSINESS')
            == 1) &&
            (int) Configuration::get('PAYPAL_PAYMENT_METHOD') == HSS) && !$this->useMobile()) {
            return null;
        }

        $paypal_logos = $this->paypal_logos->getLogos();
        $iso_lang = array(
            'en' => 'en_US',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
        );

        $this->context->smarty->assign(array(
            'use_mobile' => (bool) $this->useMobile(),
            'PayPal_payment_type' => $type,
            'PayPal_current_page' => $this->getCurrentUrl(),
            'PayPal_lang_code' => (isset($iso_lang[$this->context->language->iso_code]))
            ? $iso_lang[$this->context->language->iso_code] : 'en_US',
            'PayPal_tracking_code' => $this->getTrackingCode((int) Configuration::get('PAYPAL_PAYMENT_METHOD')),
            'paypal_express_checkout_shortcut_logo' => isset($paypal_logos['ExpressCheckoutShortcutButton'])
            ? $paypal_logos['ExpressCheckoutShortcutButton'] : false,
        ));

        return $this->fetchTemplate('express_checkout_shortcut_button.tpl');
    }

    public function renderExpressCheckoutForm($type)
    {
        if ((!Configuration::get('PAYPAL_EXPRESS_CHECKOUT_SHORTCUT') && !$this->useMobile())
            || !in_array(ECS, $this->getPaymentMethods()) ||
            (((int) Configuration::get('PAYPAL_BUSINESS') == 1) && ((int) Configuration::get('PAYPAL_PAYMENT_METHOD')
                == HSS) && !$this->useMobile())) {
            return;
        }

        $id_product = (int) Tools::getValue('id_product');
        $id_product_attribute = (int) Product::getDefaultAttribute($id_product);
        if ($id_product_attribute) {
            $minimal_quantity = Attribute::getAttributeMinimalQty($id_product_attribute);
        } else {
            $product = new Product($id_product);
            $minimal_quantity = $product->minimal_quantity;
        }

        $this->context->smarty->assign(array(
            'PayPal_payment_type' => $type,
            'PayPal_current_page' => $this->getCurrentUrl(),
            'id_product_attribute_ecs' => $id_product_attribute,
            'product_minimal_quantity' => $minimal_quantity,
            'PayPal_tracking_code' => $this->getTrackingCode((int) Configuration::get('PAYPAL_PAYMENT_METHOD')),
        ));

        return $this->fetchTemplate('express_checkout_shortcut_form.tpl');
    }

    public function useMobile()
    {
        if ((method_exists($this->context, 'getMobileDevice') && $this->context->getMobileDevice())
            || Tools::getValue('ps_mobile_site')) {
            return true;
        }

        return false;
    }

    public function isCountryAPAC()
    {
        $country = new Country(Configuration::get('PS_COUNTRY_DEFAULT'));

        $tabCountryApac = array('CN', 'JP', 'AU', 'HK', 'TW', 'NZ', 'BU', 'BN', 'KH',
            'ID', 'LA', 'MY', 'PH', 'SG', 'TH',
            'TL', 'VN');

        if (in_array($country->iso_code, $tabCountryApac)) {
            return true;
        }
        return false;
    }

    public function getTrackingCode($method)
    {
        $isApacCountry = $this->isCountryAPAC();

        if ((_PS_VERSION_ < '1.5') && (_THEME_NAME_ == 'prestashop_mobile' || Tools::getValue('ps_mobile_site')
            == 1)) {
            if (_PS_MOBILE_TABLET_) {
                return $isApacCountry ? APAC_TABLET_TRACKING_CODE : TABLET_TRACKING_CODE;
            } elseif (_PS_MOBILE_PHONE_) {
                return $isApacCountry ? APAC_SMARTPHONE_TRACKING_CODE : SMARTPHONE_TRACKING_CODE;
            }

        }
        //Get Seamless checkout

        $login_user = false;
        if (Configuration::get('PAYPAL_LOGIN')) {
            $login_user = PaypalLoginUser::getByIdCustomer((int) $this->context->customer->id);

            if ($login_user && $login_user->expires_in <= time()) {
                $obj = new PayPalLogin();
                $login_user = $obj->getRefreshToken();
            }
        }

        if ($method == WPS) {
            if ($login_user) {
                return $isApacCountry ? APAC_TRACKING_EXPRESS_CHECKOUT_SEAMLESS : TRACKING_EXPRESS_CHECKOUT_SEAMLESS;
            } else {
                return $isApacCountry ? APAC_TRACKING_INTEGRAL : TRACKING_INTEGRAL;
            }

        }
        if ($method == HSS) {
            return $isApacCountry ? APAC_TRACKING_INTEGRAL_EVOLUTION : TRACKING_INTEGRAL_EVOLUTION;
        }

        if ($method == ECS) {
            if ($login_user) {
                return $isApacCountry ? APAC_TRACKING_EXPRESS_CHECKOUT_SEAMLESS : TRACKING_EXPRESS_CHECKOUT_SEAMLESS;
            } else {
                return $isApacCountry ? APAC_TRACKING_OPTION_PLUS : TRACKING_OPTION_PLUS;
            }

        }
        if ($method == PPP) {
            return $isApacCountry ? APAC_TRACKING_PAYPAL_PLUS : TRACKING_PAYPAL_PLUS;
        }

        return TRACKING_CODE;
    }

    public function getTranslations()
    {
        $file = dirname(__FILE__).'/'._PAYPAL_TRANSLATIONS_XML_;
        if (file_exists($file)) {
            $xml = simplexml_load_file($file);
            if (isset($xml) && $xml) {
                $index = -1;
                $content = $default = array();

                while (isset($xml->country[++$index])) {
                    $country = $xml->country[$index];
                    $country_iso = $country->attributes()->iso_code;

                    if (($this->iso_code != 'default') && ($country_iso == $this->iso_code)) {
                        $content = (array) $country;
                    } elseif ($country_iso == 'default') {
                        $default = (array) $country;
                    }

                }

                $content += $default;
                $this->context->smarty->assign('PayPal_content', $content);

                return true;
            }
        }
        return false;
    }

    public function getPayPalURL()
    {
        return 'www'.(Configuration::get('PAYPAL_SANDBOX') ? '.sandbox' : '').'.paypal.com';
    }

    public function getPaypalIntegralEvolutionUrl()
    {
        if (Configuration::get('PAYPAL_SANDBOX')) {
            return 'https://'.$this->getPayPalURL().'/cgi-bin/acquiringweb';
        }

        return 'https://securepayments.paypal.com/acquiringweb?cmd=_hosted-payment';
    }

    public function getPaypalStandardUrl()
    {
        return 'https://'.$this->getPayPalURL().'/cgi-bin/webscr';
    }

    public function getAPIURL()
    {
        return 'api-3t'.(Configuration::get('PAYPAL_SANDBOX') ? '.sandbox' : '').'.paypal.com';
    }

    public function getAPIScript()
    {
        return '/nvp';
    }

    public function getPaymentMethods()
    {
        if (Configuration::get('PAYPAL_UPDATED_COUNTRIES_OK')) {
            return AuthenticatePaymentMethods::authenticatePaymentMethodByLang(Tools::strtoupper($this->context->language->iso_code));
        } else {
            $country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
            return AuthenticatePaymentMethods::authenticatePaymentMethodByCountry($country->iso_code);
        }
    }

    public function getCountryCode()
    {
        $cart = new Cart((int) $this->context->cookie->id_cart);
        $address = new Address((int) $cart->id_address_invoice);
        $country = new Country((int) $address->id_country);

        return $country->iso_code;
    }

    public function displayPayPalAPIError($message, $log = false)
    {
        $send = true;
        // Sanitize log
        foreach ($log as $key => $string) {
            if ($string == 'ACK -> Success') {
                $send = false;
            } elseif (Tools::substr($string, 0, 6) == 'METHOD') {
                $values = explode('&', $string);
                foreach ($values as $key2 => $value) {
                    $values2 = explode('=', $value);
                    foreach ($values2 as $key3 => $value2) {
                        if ($value2 == 'PWD' || $value2 == 'SIGNATURE') {
                            $values2[$key3 + 1] = '*********';
                        }
                    }

                    $values[$key2] = implode('=', $values2);
                }
                $log[$key] = implode('&', $values);
            }
        }

        $this->context->smarty->assign(array('message' => $message, 'logs' => $log));

        if ($send) {
            $id_lang = (int) $this->context->language->id;
            $iso_lang = Language::getIsoById($id_lang);

            if (!is_dir(dirname(__FILE__).'/mails/'.Tools::strtolower($iso_lang))) {
                $id_lang = Language::getIdByIso('en');
            }

            Mail::Send(
                $id_lang,
                'error_reporting',
                Mail::l('Error reporting from your PayPal module', (int) $this->context->language->id),
                array('{logs}' => implode('<br />', $log)),
                Configuration::get('PS_SHOP_EMAIL'),
                null,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_.$this->name.'/mails/'
            );
        }

        return $this->fetchTemplate('error.tpl');
    }

    private function _canRefund($id_order)
    {
        if (!(bool) $id_order) {
            return false;
        }

        $paypal_order = Db::getInstance()->getRow('
            SELECT `payment_status`, `capture`
            FROM `'._DB_PREFIX_.'paypal_order`
            WHERE `id_order` = '.(int) $id_order);

        return $paypal_order && ($paypal_order['payment_status'] == 'Completed' || $paypal_order['payment_status']
            == 'approved') && $paypal_order['capture'] == 0;
    }

    private function _needValidation($id_order)
    {
        if (!(int) $id_order) {
            return false;
        }

        $order = Db::getInstance()->getRow('
            SELECT `payment_method`, `payment_status`
            FROM `'._DB_PREFIX_.'paypal_order`
            WHERE `id_order` = '.(int) $id_order);

        return $order && $order['payment_method'] != HSS && $order['payment_status']
            == 'Pending_validation';
    }

    private function _needCapture($id_order)
    {
        if (!(int) $id_order) {
            return false;
        }

        $result = Db::getInstance()->getRow('
            SELECT `payment_method`, `payment_status`
            FROM `'._DB_PREFIX_.'paypal_order`
            WHERE `id_order` = '.(int) $id_order.' AND `capture` = 1');

        return $result && $result['payment_method'] != HSS && $result['payment_status']
            == 'Pending_capture';
    }

    private function _preProcess()
    {
        if (Tools::isSubmit('submitPaypal')) {
            $business = Tools::getValue('business') !== false ? (int) Tools::getValue('business')
            : false;
            $payment_method = Tools::getValue('paypal_payment_method') !== false
            ? (int) Tools::getValue('paypal_payment_method') : false;
            $payment_capture = Tools::getValue('payment_capture') !== false ? (int) Tools::getValue('payment_capture')
            : false;
            $sandbox_mode = Tools::getValue('sandbox_mode') !== false ? (int) Tools::getValue('sandbox_mode')
            : false;

            if ($this->default_country === false || $sandbox_mode === false || $payment_capture
                === false || $business === false || $payment_method === false) {
                $this->_errors[] = $this->l('Some fields are empty.');
            } elseif ($business == 0) {
                $this->_errors[] = $this->l('Credentials fields cannot be empty');
            } elseif ($business == 1) {

                if (($payment_method == WPS || $payment_method == ECS) && (!Tools::getValue('api_username')
                    || !Tools::getValue('api_password') || !Tools::getValue('api_signature'))) {
                    $this->_errors[] = $this->l('Credentials fields cannot be empty');
                }

                if ($payment_method == PPP && (Tools::getValue('paypalplus_webprofile')
                    != 0 && (!Tools::getValue('client_id') && !Tools::getValue('secret')))) {
                    $this->_errors[] = $this->l('Credentials fields cannot be empty');
                }

                if ($payment_method == HSS && !Tools::getValue('api_business_account')) {
                    $this->_errors[] = $this->l('Business e-mail field cannot be empty');
                }

            }
        }

        return !count($this->_errors);
    }

    private function _postProcess()
    {
        if (Tools::isSubmit('submitPaypal')) {
            if (Tools::getValue('paypal_country_only')) {
                Configuration::updateValue('PAYPAL_COUNTRY_DEFAULT', (int) Tools::getValue('paypal_country_only'));
            } elseif ($this->_preProcess()) {
                Configuration::updateValue('PAYPAL_BUSINESS', (int) Tools::getValue('business'));
                Configuration::updateValue('PAYPAL_PAYMENT_METHOD', (int) Tools::getValue('paypal_payment_method'));
                Configuration::updateValue('PAYPAL_API_USER', trim(Tools::getValue('api_username')));
                Configuration::updateValue('PAYPAL_API_PASSWORD', trim(Tools::getValue('api_password')));
                Configuration::updateValue('PAYPAL_API_SIGNATURE', trim(Tools::getValue('api_signature')));
                Configuration::updateValue('PAYPAL_BUSINESS_ACCOUNT', trim(Tools::getValue('api_business_account')));
                Configuration::updateValue('PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', (int) Tools::getValue('express_checkout_shortcut'));
                Configuration::updateValue('PAYPAL_IN_CONTEXT_CHECKOUT_M_ID', Tools::getValue('in_context_checkout_merchant_id'));
                Configuration::updateValue('PAYPAL_SANDBOX', (int) Tools::getValue('sandbox_mode'));
                Configuration::updateValue('PAYPAL_CAPTURE', (int) Tools::getValue('payment_capture'));
                /* USE PAYPAL LOGIN */
                Configuration::updateValue('PAYPAL_LOGIN', (int) Tools::getValue('paypal_login'));
                Configuration::updateValue('PAYPAL_LOGIN_CLIENT_ID', Tools::getValue('paypal_login_client_id'));
                Configuration::updateValue('PAYPAL_LOGIN_SECRET', Tools::getValue('paypal_login_client_secret'));
                Configuration::updateValue('PAYPAL_LOGIN_TPL', (int) Tools::getValue('paypal_login_client_template'));

                /* USE PAYPAL PLUS */
                if ((int) Tools::getValue('paypal_payment_method') == 5) {
                    Configuration::updateValue('PAYPAL_PLUS_CLIENT_ID', Tools::getValue('client_id'));
                    Configuration::updateValue('PAYPAL_PLUS_SECRET', Tools::getValue('secret'));

                    if ((int) Tools::getValue('paypalplus_webprofile') == 1) {

                        $ApiPaypalPlus = new ApiPaypalPlus();
                        $idWebProfile = $ApiPaypalPlus->getWebProfile();

                        if ($idWebProfile) {
                            Configuration::updateValue('PAYPAL_WEB_PROFILE_ID', $idWebProfile);
                        } else {
                            Configuration::updateValue('PAYPAL_WEB_PROFILE_ID', 0);
                        }
                    }
                }
                /* IS IN_CONTEXT_CHECKOUT ENABLED */
                if ((int) Tools::getValue('paypal_payment_method') != 2) {
                    Configuration::updateValue('PAYPAL_IN_CONTEXT_CHECKOUT', (int) Tools::getValue('in_context_checkout'));
                } else {
                    Configuration::updateValue('PAYPAL_IN_CONTEXT_CHECKOUT', 0);
                }

                /* /IS IN_CONTEXT_CHECKOUT ENABLED */

                //EXPRESS CHECKOUT TEMPLATE
                Configuration::updateValue('PAYPAL_HSS_SOLUTION', (int) Tools::getValue('integral_evolution_solution'));
                if (Tools::getValue('integral_evolution_solution') == PAYPAL_HSS_IFRAME) {
                    Configuration::updateValue('PAYPAL_HSS_TEMPLATE', 'D');
                } else {
                    Configuration::updateValue('PAYPAL_HSS_TEMPLATE', Tools::getValue('integral_evolution_template'));
                }

                $this->context->smarty->assign('PayPal_save_success', true);
            } else {
                $this->_html = $this->displayError(implode('<br />', $this->_errors)); // Not displayed at this time
                $this->context->smarty->assign('PayPal_save_failure', true);
            }
        }

        return $this->loadLangDefault();
    }

    private function _makeRefund($id_transaction, $id_order, $amt = false)
    {
        if (!$this->isPayPalAPIAvailable()) {
            die(Tools::displayError('Fatal Error: no API Credentials are available'));
        } elseif (!$id_transaction) {
            die(Tools::displayError('Fatal Error: id_transaction is null'));
        }

        $payment_method = Configuration::get('PAYPAL_PAYMENT_METHOD');

        if ($payment_method != PPP) {

            if (!$amt) {
                $params = array('TRANSACTIONID' => $id_transaction, 'REFUNDTYPE' => 'Full');
            } else {
                $iso_currency = Db::getInstance()->getValue('
                    SELECT `iso_code`
                    FROM `'._DB_PREFIX_.'orders` o
                    LEFT JOIN `'._DB_PREFIX_.'currency` c ON (o.`id_currency` = c.`id_currency`)
                    WHERE o.`id_order` = '.(int) $id_order);

                $params = array('TRANSACTIONID' => $id_transaction, 'REFUNDTYPE' => 'Partial',
                    'AMT' => (float) $amt, 'CURRENCYCODE' => Tools::strtoupper($iso_currency));
            }

            $paypal_lib = new PaypalLib();

            return $paypal_lib->makeCall(
                $this->getAPIURL(),
                $this->getAPIScript(),
                'RefundTransaction',
                '&'.http_build_query($params, '', '&')
            );
        } else {

            if (!$amt) {

                $params = new stdClass();
            } else {

                $result = Db::getInstance()->ExecuteS('SELECT * FROM '._DB_PREFIX_.'paypal_order WHERE id_transaction = "'.pSQL($id_transaction).'"');
                $result = current($result);

                $amount = new stdClass();
                $amount->total = $amt;
                $amount->currency = $result['currency'];

                $params = new stdClass();
                $params->amount = $amount;
            }

            $callApiPaypalPlus = new CallApiPaypalPlus();

            return Tools::jsonDecode($callApiPaypalPlus->executeRefund($id_transaction, $params));
        }
    }

    public function _addNewPrivateMessage($id_order, $message)
    {
        if (!(bool) $id_order) {
            return false;
        }

        $new_message = new Message();
        $message = strip_tags($message, '<br>');

        if (!Validate::isCleanHtml($message)) {
            $message = $this->l('Payment message is not valid, please check your module.');
        }

        $new_message->message = $message;
        $new_message->id_order = (int) $id_order;
        $new_message->private = 1;

        return $new_message->add();
    }

    private function _doTotalRefund($id_order)
    {
        $paypal_order = PayPalOrder::getOrderById((int) $id_order);
        if (!$this->isPayPalAPIAvailable() || !$paypal_order) {
            return false;
        }

        $order = new Order((int) $id_order);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $products = $order->getProducts();
        $currency = new Currency((int) $order->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            $this->_errors[] = $this->l('Not a valid currency');
        }

        if (count($this->_errors)) {
            return false;
        }

        $decimals = (is_array($currency) ? (int) $currency['decimals'] : (int) $currency->decimals) * _PS_PRICE_DISPLAY_PRECISION_;

        // Amount for refund
        $amt = 0.00;

        foreach ($products as $product) {
            $amt += (float) ($product['product_price_wt']) * ($product['product_quantity'] - $product['product_quantity_refunded']);
        }

        $amt += (float) ($order->total_shipping) + (float) ($order->total_wrapping) - (float) ($order->total_discounts);

        // check if total or partial
        if (Tools::ps_round($order->total_paid_real, $decimals) == Tools::ps_round($amt, $decimals)) {
            $response = $this->_makeRefund($paypal_order['id_transaction'], $id_order);
        } else {
            $response = $this->_makeRefund($paypal_order['id_transaction'], $id_order, (float) ($amt));
        }

        $message = $this->l('Refund operation result:')." \r\n";
        foreach ($response as $key => $value) {
            if (is_object($value) || is_array($value)) {
                $message .= $key.': '.Tools::jsonEncode($value)." \r\n";
            } else {
                $message .= $key.': '.$value." \r\n";
            }
        }
        if ((array_key_exists('ACK', $response) && $response['ACK'] == 'Success'
            && $response['REFUNDTRANSACTIONID'] != '') || (isset($response->state)
            && $response->state == 'completed')) {
            $message .= $this->l('PayPal refund successful!');
            if (!Db::getInstance()->Execute('UPDATE `'._DB_PREFIX_.'paypal_order` SET `payment_status` = \'Refunded\' WHERE `id_order` = '.(int) $id_order)) {
                die(Tools::displayError('Error when updating PayPal database'));
            }

            $history = new OrderHistory();
            $history->id_order = (int) $id_order;
            $history->changeIdOrderState((int) Configuration::get('PS_OS_REFUND'), $history->id_order);
            $history->addWithemail();
            $history->save();
        } else {
            $message .= $this->l('Transaction error!');
        }

        $this->_addNewPrivateMessage((int) $id_order, $message);

        Tools::redirect($_SERVER['HTTP_REFERER']);
    }

    private function _doCapture($id_order, $capture_amount = false, $is_complete = false)
    {
        $paypal_order = PayPalOrder::getOrderById((int) $id_order);
        if (!$this->isPayPalAPIAvailable() || !$paypal_order) {
            return false;
        }

        $order = new Order((int) $id_order);
        $currency = new Currency((int) $order->id_currency);

        if (!$capture_amount) {
            $capture_amount = (float) $order->total_paid;
        }

        $complete = 'Complete';
        if (!$is_complete) {
            $complete = 'NotComplete';
        }

        $paypal_lib = new PaypalLib();
        $response = $paypal_lib->makeCall(
            $this->getAPIURL(),
            $this->getAPIScript(),
            'DoCapture',
            '&'.http_build_query(array('AMT' => $capture_amount, 'AUTHORIZATIONID' => $paypal_order['id_transaction'], 'CURRENCYCODE' => $currency->iso_code, 'COMPLETETYPE' => $complete), '', '&')
        );
        $message = $this->l('Capture operation result:').'<br>';

        foreach ($response as $key => $value) {
            $message .= $key.': '.$value.'<br>';
        }

        $capture = new PaypalCapture();
        $capture->id_order = (int) $id_order;
        $capture->capture_amount = (float) $capture_amount;

        if ((array_key_exists('ACK', $response)) && ($response['ACK'] == 'Success')
            && ($response['PAYMENTSTATUS'] == 'Completed')) {
            $capture->result = pSQL($response['PAYMENTSTATUS']);
            if ($capture->save()) {
                if (!($capture->getRestToCapture($capture->id_order))) {
                    //plus d'argent a capturer
                    if (!Db::getInstance()->Execute(
                        'UPDATE `'._DB_PREFIX_.'paypal_order`
                        SET `capture` = 0, `payment_status` = \''.pSQL($response['PAYMENTSTATUS']).'\', `id_transaction` = \''.pSQL($response['TRANSACTIONID']).'\'
                        WHERE `id_order` = '.(int) $id_order
                    )
                    ) {
                        die(Tools::displayError('Error when updating PayPal database'));
                    }

                    $order_history = new OrderHistory();
                    $order_history->id_order = (int) $id_order;

                    if (version_compare(_PS_VERSION_, '1.5', '<')) {
                        $order_history->changeIdOrderState(Configuration::get('PS_OS_WS_PAYMENT'), (int) $id_order);
                    } else {
                        $order_history->changeIdOrderState(Configuration::get('PS_OS_WS_PAYMENT'), $order);
                    }

                    $order_history->addWithemail();
                    $message .= $this->l('Order finished with PayPal!');
                }
            }
        } elseif (isset($response['PAYMENTSTATUS'])) {
            $capture->result = pSQL($response['PAYMENTSTATUS']);
            $capture->save();
            $message .= $this->l('Transaction error!');
        }

        $this->_addNewPrivateMessage((int) $id_order, $message);

        Tools::redirect($_SERVER['HTTP_REFERER']);
    }

    private function _updatePaymentStatusOfOrder($id_order)
    {
        if (!(bool) $id_order || !$this->isPayPalAPIAvailable()) {
            return false;
        }

        $paypal_order = PayPalOrder::getOrderById((int) $id_order);
        if (!$paypal_order) {
            return false;
        }

        $paypal_lib = new PaypalLib();
        $response = $paypal_lib->makeCall(
            $this->getAPIURL(),
            $this->getAPIScript(),
            'GetTransactionDetails',
            '&'.http_build_query(array('TRANSACTIONID' => $paypal_order['id_transaction']), '', '&')
        );

        if (array_key_exists('ACK', $response)) {
            if ($response['ACK'] == 'Success' && isset($response['PAYMENTSTATUS'])) {
                $history = new OrderHistory();
                $history->id_order = (int) $id_order;

                if ($response['PAYMENTSTATUS'] == 'Completed') {
                    $history->changeIdOrderState(Configuration::get('PS_OS_PAYMENT'), (int) $id_order);
                } elseif (($response['PAYMENTSTATUS'] == 'Pending') && ($response['PENDINGREASON']
                    == 'authorization')) {
                    $history->changeIdOrderState((int) (Configuration::get('PAYPAL_OS_AUTHORIZATION')), (int) $id_order);
                } elseif ($response['PAYMENTSTATUS'] == 'Reversed') {
                    $history->changeIdOrderState(Configuration::get('PS_OS_ERROR'), (int) $id_order);
                }

                $history->addWithemail();

                if (!Db::getInstance()->Execute(
                    'UPDATE `'._DB_PREFIX_.'paypal_order`
                    SET `payment_status` = \''.pSQL($response['PAYMENTSTATUS']).($response['PENDINGREASON'] == 'authorization' ? '_authorization' : '').'\'
                    WHERE `id_order` = '.(int) $id_order
                )
                ) {
                    die(Tools::displayError('Error when updating PayPal database'));
                }

            }

            $message = $this->l('Verification status :').'<br>';
            $this->formatMessage($response, $message);
            $this->_addNewPrivateMessage((int) $id_order, $message);

            return $response;
        }

        return false;
    }

    public function fetchTemplate($name)
    {
        if (version_compare(_PS_VERSION_, '1.4', '<')) {
            $this->context->smarty->currentTemplate = $name;
        } elseif (version_compare(_PS_VERSION_, '1.5', '<')) {
            $views = 'views/templates/';
            if (@filemtime(dirname(__FILE__).'/'.$name)) {
                return $this->display(__FILE__, $name);
            } elseif (@filemtime(dirname(__FILE__).'/'.$views.'hook/'.$name)) {
                return $this->display(__FILE__, $views.'hook/'.$name);
            } elseif (@filemtime(dirname(__FILE__).'/'.$views.'front/'.$name)) {
                return $this->display(__FILE__, $views.'front/'.$name);
            } elseif (@filemtime(dirname(__FILE__).'/'.$views.'admin/'.$name)) {
                return $this->display(__FILE__, $views.'admin/'.$name);
            }

        }

        return $this->display(__FILE__, $name);
    }

    public static function getPayPalCustomerIdByEmail($email)
    {
        return Db::getInstance()->getValue(
            'SELECT `id_customer`
            FROM `'._DB_PREFIX_.'paypal_customer`
            WHERE paypal_email = \''.pSQL($email).'\''
        );
    }

    public static function getPayPalEmailByIdCustomer($id_customer)
    {
        return Db::getInstance()->getValue(
            'SELECT `paypal_email`
            FROM `'._DB_PREFIX_.'paypal_customer`
            WHERE `id_customer` = '.(int) $id_customer
        );
    }

    public static function addPayPalCustomer($id_customer, $email)
    {
        if (!PayPal::getPayPalEmailByIdCustomer($id_customer)) {
            Db::getInstance()->Execute(
                'INSERT INTO `'._DB_PREFIX_.'paypal_customer` (`id_customer`, `paypal_email`)
                VALUES('.(int) $id_customer.', \''.pSQL($email).'\')'
            );

            return Db::getInstance()->Insert_ID();
        }

        return false;
    }

    private function warningsCheck()
    {
        if (Configuration::get('PAYPAL_PAYMENT_METHOD') == HSS && Configuration::get('PAYPAL_BUSINESS_ACCOUNT') == 'paypal@prestashop.com') {
            $this->warning = $this->l('You are currently using the default PayPal e-mail address, please enter your own e-mail address.').'<br />';
        }

        /* Check preactivation warning */
        if (Configuration::get('PS_PREACTIVATION_PAYPAL_WARNING')) {
            $this->warning .= (!empty($this->warning)) ? ', ' : Configuration::get('PS_PREACTIVATION_PAYPAL_WARNING').'<br />';
        }

        if (!function_exists('curl_init')) {
            $this->warning .= $this->l('In order to use your module, please activate cURL (PHP extension)');
        }

    }

    private function loadLangDefault()
    {

        if (Configuration::get('PAYPAL_UPDATED_COUNTRIES_OK')) {
            $this->iso_code = Tools::strtoupper($this->context->language->iso_code);
            $this->default_country = Country::getByIso($this->iso_code);
        } else {
            $this->default_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
            $country = new Country($this->default_country);
            $this->iso_code = Tools::strtoupper($country->iso_code);
        }

        //$this->iso_code = AuthenticatePaymentMethods::getCountryDependency($iso_code);
    }

    public function formatMessage($response, &$message)
    {
        foreach ($response as $key => $value) {
            $message .= $key.': '.$value.'<br>';
        }

    }

    private function checkCurrency($cart)
    {
        $currency_module = $this->getCurrency((int) $cart->id_currency);

        if ((int) $cart->id_currency == (int) $currency_module->id) {
            return true;
        } else {
            return false;
        }

    }

    public static function getShopDomainSsl($http = false, $entities = false)
    {
        if (method_exists('Tools', 'getShopDomainSsl')) {
            return Tools::getShopDomainSsl($http, $entities);
        } else {
            if (!($domain = Configuration::get('PS_SHOP_DOMAIN_SSL'))) {
                $domain = self::getHttpHost();
            }

            if ($entities) {
                $domain = htmlspecialchars($domain, ENT_COMPAT, 'UTF-8');
            }

            if ($http) {
                $domain = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').$domain;
            }

            return $domain;
        }
    }

    public function validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method = 'Unknown', $message = null, $transaction = array(), $currency_special = null, $dont_touch_amount = false, $secure_key = false, Shop $shop = null)
    {
        if ($this->active) {
            // Set transaction details if pcc is defined in PaymentModule class_exists
            if (isset($this->pcc)) {
                $this->pcc->transaction_id = (isset($transaction['transaction_id']) ? $transaction['transaction_id'] : '');
            }

            if (version_compare(_PS_VERSION_, '1.5', '<')) {
                parent::validateOrder(
                    (int) $id_cart,
                    (int) $id_order_state,
                    (float) $amount_paid,
                    $payment_method,
                    $message,
                    $transaction,
                    $currency_special,
                    $dont_touch_amount,
                    $secure_key
                );
            } else {
                parent::validateOrder(
                    (int) $id_cart,
                    (int) $id_order_state,
                    (float) $amount_paid,
                    $payment_method,
                    $message,
                    $transaction,
                    $currency_special,
                    $dont_touch_amount,
                    $secure_key,
                    $shop
                );
            }

            if (count($transaction) > 0) {
                PayPalOrder::saveOrder((int) $this->currentOrder, $transaction);
            }

            $this->setPayPalAsConfigured();
        }
    }

    protected function getGiftWrappingPrice()
    {
        if (version_compare(_PS_VERSION_, '1.5.3.0', '>=')) {
            $wrapping_fees_tax_inc = $this->context->cart->getGiftWrappingPrice();
        } else {
            $wrapping_fees = (float) (Configuration::get('PS_GIFT_WRAPPING_PRICE'));
            $wrapping_fees_tax = new Tax((int) (Configuration::get('PS_GIFT_WRAPPING_TAX')));
            $wrapping_fees_tax_inc = $wrapping_fees * (1 + (((float) ($wrapping_fees_tax->rate) / 100)));
        }

        return (float) Tools::convertPrice($wrapping_fees_tax_inc, $this->context->currency);
    }

    public function redirectToConfirmation()
    {
        $shop_url = PayPal::getShopDomainSsl(true, true);

        // Check if user went through the payment preparation detail and completed it
        $detail = unserialize($this->context->cookie->express_checkout);

        if (!empty($detail['payer_id']) && !empty($detail['token'])) {
            $values = array('get_confirmation' => true);
            $link = $shop_url._MODULE_DIR_.$this->name.'/express_checkout/payment.php';

            if (version_compare(_PS_VERSION_, '1.5', '<')) {
                Tools::redirectLink($link.'?'.http_build_query($values, '', '&'));
            } else {
                Tools::redirect(Context::getContext()->link->getModuleLink('paypal', 'confirm', $values));
            }

        }
    }

    /**
     * Check if the current page use SSL connection on not
     *
     * @return bool uses SSL
     */
    public function usingSecureMode()
    {
        if (isset($_SERVER['HTTPS'])) {
            return ($_SERVER['HTTPS'] == 1 || Tools::strtolower($_SERVER['HTTPS']) == 'on');
        }

        // $_SERVER['SSL'] exists only in some specific configuration
        if (isset($_SERVER['SSL'])) {
            return ($_SERVER['SSL'] == 1 || Tools::strtolower($_SERVER['SSL']) == 'on');
        }

        return false;
    }

    protected function getCurrentUrl()
    {
        $protocol_link = $this->usingSecureMode() ? 'https://' : 'http://';
        $request = $_SERVER['REQUEST_URI'];
        $pos = strpos($request, '?');

        if (($pos !== false) && ($pos >= 0)) {
            $request = Tools::substr($request, 0, $pos);
        }

        $params = urlencode($_SERVER['QUERY_STRING']);

        return $protocol_link.Tools::getShopDomainSsl().$request.'?'.$params;
    }

    /**
     * Use $this->comp instead of bccomp which is not added in all versions of PHP
     * @param float $num1  number 1 to compare
     * @param float $num2  number 2 to compare
     * @param [type] $scale [description]
     */
    public function comp($num1, $num2, $scale = null)
    {
        // check if they're valid positive numbers, extract the whole numbers and decimals
        if (!preg_match("/^\+?(\d+)(\.\d+)?$/", $num1, $tmp1) || !preg_match("/^\+?(\d+)(\.\d+)?$/", $num2, $tmp2)) {
            return ('0');
        }

        // remove leading zeroes from whole numbers
        $num1 = ltrim($tmp1[1], '0');
        $num2 = ltrim($tmp2[1], '0');

        // first, we can just check the lengths of the numbers, this can help save processing time
        // if $num1 is longer than $num2, return 1.. vice versa with the next step.
        if (Tools::strlen($num1) > Tools::strlen($num2)) {
            return 1;
        } else {
            if (Tools::strlen($num1) < Tools::strlen($num2)) {
                return -1;
            } else {
                // if the two numbers are of equal length, we check digit-by-digit

                // remove ending zeroes from decimals and remove point
                $dec1 = isset($tmp1[2]) ? rtrim(Tools::substr($tmp1[2], 1), '0') : '';
                $dec2 = isset($tmp2[2]) ? rtrim(Tools::substr($tmp2[2], 1), '0') : '';

                // if the user defined $scale, then make sure we use that only
                if ($scale != null) {
                    $dec1 = Tools::substr($dec1, 0, $scale);
                    $dec2 = Tools::substr($dec2, 0, $scale);
                }

                // calculate the longest length of decimals
                $d_len = max(Tools::strlen($dec1), Tools::strlen($dec2));

                // append the padded decimals onto the end of the whole numbers
                $num1 .= str_pad($dec1, $d_len, '0');
                $num2 .= str_pad($dec2, $d_len, '0');

                // check digit-by-digit, if they have a difference, return 1 or -1 (greater/lower than)
                for ($i = 0; $i < Tools::strlen($num1); $i++) {
                    if ((int) $num1{$i} > (int) $num2{$i}) {
                        return 1;
                    } elseif ((int) $num1{$i} < (int) $num2{$i}) {
                        return -1;
                    }

                }
                // if the two numbers have no difference (they're the same).. return 0
                return 0;
            }
        }
    }

    public function assignCartSummary()
    {
        $currency = new Currency((int) $this->context->cart->id_currency);

        $this->context->smarty->assign(array(
            'total' => Tools::displayPrice($this->context->cart->getOrderTotal(true), $currency),
            'logos' => $this->paypal_logos->getLogos(),
            'use_mobile' => (bool) $this->useMobile(),
            'address_shipping' => new Address($this->context->cart->id_address_delivery),
            'address_billing' => new Address($this->context->cart->id_address_invoice),
            'cart' => $this->context->cart,
            'patternRules' => array('avoid' => array()),
            'cart_image_size' => version_compare(_PS_VERSION_, '1.5', '<') ? 'small' : version_compare(_PS_VERSION_, '1.6', '<') ? 'small_default' : 'cart_default',
            'useStyle14' => version_compare(_PS_VERSION_, '1.5', '<'),
            'useStyle15' => version_compare(_PS_VERSION_, '1.5', '>') && version_compare(_PS_VERSION_, '1.6', '<'),
        ));

        $this->context->smarty->assign(array(
            'paypal_cart_summary' => $this->display(__FILE__, 'views/templates/hook/paypal_cart_summary.tpl'),
        ));
    }
}
