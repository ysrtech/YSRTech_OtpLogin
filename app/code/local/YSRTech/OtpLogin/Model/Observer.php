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
}
