<?php
/**
 * YSRTech OtpLogin - OTP verification popup.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Block_Form_Otp extends Mage_Core_Block_Template
{
    /**
     * Suppress output entirely when the module is disabled or the visitor is
     * already signed in.
     *
     * @return string
     */
    protected function _toHtml()
    {
        if (!Mage::helper('ysrtech_otplogin')->isEnabled()
            || Mage::getSingleton('customer/session')->isLoggedIn()) {
            return '';
        }
        return parent::_toHtml();
    }

    /**
     * @return string
     */
    public function getVerifyUrl()
    {
        return $this->getUrl('otplogin/account/otppost', array('_secure' => true));
    }

    /**
     * @return string
     */
    public function getResendUrl()
    {
        return $this->getUrl('otplogin/account/resendotp', array('_secure' => true));
    }

    /**
     * @return string
     */
    public function getFormKey()
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }
}
