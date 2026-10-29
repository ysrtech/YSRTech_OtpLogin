<?php
/**
 * YSRTech OtpLogin - observers.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Model_Observer
{
    /**
     * Require a signed-in customer before an order can be placed.
     *
     * The checkout template already replaces the form with a sign-in panel for
     * guests, but that is only the visible half - a guest could still POST the
     * order directly. This guards the OneStepCheckout order-submission action
     * (onestepcheckout/index/index) server-side: an unauthenticated POST is
     * stopped and sent back to checkout with a message. The GET that renders the
     * page is left alone so the sign-in panel can show.
     *
     * Does nothing unless "Require Sign-in at Checkout" is on, so standard guest
     * checkout returns untouched.
     *
     * @param Varien_Event_Observer $observer
     */
    public function requireLoginAtCheckout(Varien_Event_Observer $observer)
    {
        $helper = Mage::helper('ysrtech_otplogin');
        if (!$helper->isLoginRequiredAtCheckout()) {
            return;
        }
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            return;
        }

        /** @var Mage_Core_Controller_Front_Action $action */
        $action  = $observer->getControllerAction();
        $request = $action->getRequest();

        // Only the order submission is blocked; the page render (GET) must pass
        // so the sign-in panel is shown.
        if (!$request->isPost()) {
            return;
        }

        Mage::getSingleton('checkout/session')->addError(
            $helper->__('Please sign in to complete your order.')
        );
        $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
        $action->getResponse()->setRedirect(Mage::getUrl('onestepcheckout', array('_secure' => true)));
    }

    /**
     * controller_action_predispatch_customer_account_createpost
     *
     * With accounts created on the store's own Create Account page, an account is only
     * created for an address confirmed with a code in this session. The customer never sets
     * a password, so a random one is filled in for the form's own password checks.
     *
     * @param Varien_Event_Observer $observer
     */
    public function guardRegistration(Varien_Event_Observer $observer)
    {
        $helper = Mage::helper('ysrtech_otplogin');
        if (!$helper->isRegistrationPageMode()) {
            return;
        }
        /** @var Mage_Core_Controller_Front_Action $action */
        $action  = $observer->getControllerAction();
        $request = $action->getRequest();
        if (!$request->isPost() || Mage::getSingleton('customer/session')->isLoggedIn()) {
            return;
        }

        if (!$helper->isEmailVerified($request->getPost('email'))) {
            $session = Mage::getSingleton('customer/session');
            $session->setCustomerFormData($request->getPost());
            $session->addError($helper->__('Please confirm your email address with the code we email you.'));
            $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
            $action->getResponse()->setRedirect(Mage::getUrl('customer/account/create', array('_secure' => true)));
            return;
        }

        $password = Mage::getModel('customer/customer')->generatePassword(16);
        $request->setPost('password', $password);
        $request->setPost('confirmation', $password);
    }

    /**
     * customer_register_success: the confirmed address has been used.
     *
     * @param Varien_Event_Observer $observer
     */
    public function forgetVerifiedEmail(Varien_Event_Observer $observer)
    {
        Mage::helper('ysrtech_otplogin')->forgetVerifiedEmail();
    }

    /**
     * Daily cron: drop code rows older than a week. Request limits only look back
     * minutes, and every request (including for unknown addresses) adds a row.
     */
    public function cleanupOldCodes()
    {
        $resource = Mage::getSingleton('core/resource');
        $resource->getConnection('core_write')->delete(
            $resource->getTableName('ysrtech_otplogin/otp'),
            array('created_at < ?' => date('Y-m-d H:i:s', time() - 7 * 86400))
        );
    }
}
