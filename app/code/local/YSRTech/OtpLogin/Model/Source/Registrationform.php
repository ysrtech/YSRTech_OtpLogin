<?php
/**
 * YSRTech OtpLogin - where customers create an account.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Model_Source_Registrationform
{
    const PAGE  = 'page';
    const POPUP = 'popup';

    public function toOptionArray()
    {
        $helper = Mage::helper('ysrtech_otplogin');
        return array(
            array('value' => self::PAGE, 'label' => $helper->__("The store's own Create Account page")),
            array('value' => self::POPUP, 'label' => $helper->__('Popup (name and email only)')),
        );
    }
}
