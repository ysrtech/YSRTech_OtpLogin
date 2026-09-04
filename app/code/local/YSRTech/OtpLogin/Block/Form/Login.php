<?php
/**
 * YSRTech OtpLogin - Login popup block.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Block_Form_Login extends Mage_Core_Block_Template
{
    /**
     * Suppress output entirely when the module is disabled.
     *
     * @return string
     */
    protected function _toHtml()
    {
        if (!Mage::helper('ysrtech_otplogin')->isEnabled()) {
            return '';
        }
        return parent::_toHtml();
    }

    /**
     * @return bool
     */
    public function customerIsAlreadyLoggedIn()
    {
        return Mage::getSingleton('customer/session')->isLoggedIn();
    }

    /**
     * Standard Magento login post URL (used by the password tab).
     *
     * @return string
     */
    public function getPostActionUrl()
    {
        return $this->getUrl('customer/account/loginPost', array('_secure' => true));
    }

    /**
     * URL that triggers sending an OTP.
     *
     * @return string
     */
    public function getSendOtpUrl()
    {
        return $this->getUrl('otplogin/account/otploginpost', array('_secure' => true));
    }

    /**
     * @return string
     */
    public function getRegistrationUrl()
    {
        return $this->getUrl('customer/account/create');
    }

    /**
     * @return bool
     */
    public function isRegistrationAllowed()
    {
        return Mage::helper('ysrtech_otplogin')->isRegistrationAllowed()
            && Mage::helper('customer')->isRegistrationAllowed();
    }

    /**
     * Form key for the standard login form.
     *
     * @return string
     */
    public function getFormKey()
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }
}
