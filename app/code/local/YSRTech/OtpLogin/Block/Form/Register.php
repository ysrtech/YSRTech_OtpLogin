<?php
/**
 * YSRTech OtpLogin - Registration popup block.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Block_Form_Register extends Mage_Core_Block_Template
{
    /**
     * Suppress output entirely when the module or registration is disabled.
     *
     * @return string
     */
    protected function _toHtml()
    {
        $helper = Mage::helper('ysrtech_otplogin');
        if (!$helper->isEnabled() || !$this->isRegistrationAllowed()) {
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
     * @return bool
     */
    public function isRegistrationAllowed()
    {
        return Mage::helper('ysrtech_otplogin')->isRegistrationAllowed()
            && Mage::helper('customer')->isRegistrationAllowed();
    }

    /**
     * Posting the registration form sends an OTP first.
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
    public function getLoginUrl()
    {
        return $this->getUrl('customer/account/login');
    }

    /**
     * @return string
     */
    public function getFormKey()
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }
}
