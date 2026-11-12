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
     * "Require Sign-in at Checkout", for TM FireCheckout.
     *
     * Two requests are guarded. Opening the checkout as a guest leads to the
     * store's sign-in page, which emails a code and comes straight back.
     * Placing the order is an AJAX request that expects JSON, so a guest who
     * posts it directly is answered in FireCheckout's own error format.
     *
     * Does nothing unless the setting is on.
     *
     * @param Varien_Event_Observer $observer
     */
    public function requireLoginAtFirecheckout(Varien_Event_Observer $observer)
    {
        $helper = Mage::helper('ysrtech_otplogin');
        if (!$helper->isLoginRequiredAtCheckout() || Mage::getSingleton('customer/session')->isLoggedIn()) {
            return;
        }

        /** @var Mage_Core_Controller_Front_Action $action */
        $action  = $observer->getControllerAction();
        $request = $action->getRequest();
        $message = $helper->__('Please sign in to complete your order.');

        $action->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);

        if (strtolower((string) $request->getActionName()) === 'saveorder') {
            $action->getResponse()
                ->setHeader('Content-Type', 'application/json', true)
                ->setBody(Mage::helper('core')->jsonEncode(array(
                    'success'        => false,
                    'error'          => true,
                    'error_messages' => $message,
                )));

            return;
        }

        // Back to the checkout once signed in: the sign-in page reads this
        $checkout = Mage::getUrl('firecheckout', array('_secure' => true));
        Mage::getSingleton('customer/session')->setBeforeAuthUrl($checkout)->addNotice($message);
        $action->getResponse()->setRedirect(Mage::getUrl('customer/account/login', array(
            '_secure' => true,
            'referer' => Mage::helper('core')->urlEncode($checkout),
        )));
    }

    /**
     * An account made while checking out with FireCheckout ("create an
     * account for later use") is given a random password.
     *
     * Customers sign in with a code and never set a password, so the two
     * password boxes are hidden on the page; FireCheckout still insists on
     * one. The page fills them in, and this fills them in again here, so the
     * order does not depend on a script having run.
     *
     * @param Varien_Event_Observer $observer
     */
    public function passwordlessCheckoutAccount(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('ysrtech_otplogin')->isEnabled() || Mage::getSingleton('customer/session')->isLoggedIn()) {
            return;
        }

        $request = $observer->getControllerAction()->getRequest();
        $billing = $request->getPost('billing');

        if (!is_array($billing) || empty($billing['register_account'])) {
            return;
        }

        $password = Mage::helper('core')->getRandomString(24);

        $billing['customer_password'] = $password;
        $billing['confirm_password']  = $password;
        $request->setPost('billing', $billing);
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
     * controller_action_predispatch_customer_account_editpost
     *
     * Customers who sign in with a code never set a password, but the account edit form
     * checks the current password on every save (name, email, ...). The customer is signed
     * in, so the check is satisfied here: a fresh random password is stored and posted as the
     * current one. A password change is not possible.
     *
     * @param Varien_Event_Observer $observer
     */
    public function passwordlessAccountEdit(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('ysrtech_otplogin')->isEnabled()) {
            return;
        }
        $session = Mage::getSingleton('customer/session');
        $request = $observer->getControllerAction()->getRequest();
        if (!$request->isPost() || !$session->isLoggedIn()) {
            return;
        }
        $customer = $session->getCustomer();
        $password = $customer->generatePassword(16);
        // Only the hash: setPassword() would also leave the plain password on the customer,
        // which the edit form's validation then treats as a password change.
        $customer->setPasswordHash($customer->hashPassword($password));
        $customer->getResource()->saveAttribute($customer, 'password_hash');
        $request->setPost('current_password', $password);
        $request->setPost('change_password', '');
        $request->setParam('change_password', null);
        $request->setPost('password', '');
        $request->setPost('confirmation', '');
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
