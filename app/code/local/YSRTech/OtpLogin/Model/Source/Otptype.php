<?php
/**
 * YSRTech OtpLogin - OTP type source model (admin dropdown).
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Model_Source_Otptype
{
    public function toOptionArray()
    {
        $helper = Mage::helper('ysrtech_otplogin');
        return array(
            array('value' => 'number',       'label' => $helper->__('Numeric')),
            array('value' => 'alphabets',    'label' => $helper->__('Alphabetic')),
            array('value' => 'alphanumeric', 'label' => $helper->__('Alphanumeric')),
        );
    }
}
