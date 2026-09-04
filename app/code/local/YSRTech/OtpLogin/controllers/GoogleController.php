<?php
/**
 * YSRTech OtpLogin - Google Sign-In controller.
 *
 *   otplogin/google/connect   -> redirect the customer to Google
 *   otplogin/google/callback  -> handle the return, verify, then log in / register
 *
 * Account matching is by verified email address only.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_GoogleController extends Mage_Core_Controller_Front_Action
{
    /**
     * @return YSRTech_OtpLogin_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('ysrtech_otplogin');
    }

    /**
     * @return Mage_Customer_Model_Session
     */
    protected function _customerSession()
    {
        return Mage::getSingleton('customer/session');
    }

    /**
     * Redirect the visitor to Google's consent screen.
     */
    public function connectAction()
    {
        $helper = $this->_helper();

        if (!$helper->isEnabled() || !$helper->isGoogleEnabled()) {
            $this->_customerSession()->addError($helper->__('Google Sign-In is not available.'));
            return $this->_redirect('customer/account/login');
        }

        if ($this->_customerSession()->isLoggedIn()) {
            return $this->_redirect('customer/account');
        }

        // CSRF state token, remembered for the callback. From the CSPRNG:
        // uniqid() is the clock and mt_rand() is predictable from its own
        // output, so together they are guessable by anyone who can time a page.
        $state = bin2hex(random_bytes(16));
        $this->_customerSession()->setGoogleOauthState($state);

        $this->getResponse()->setRedirect($helper->getGoogleAuthUrl($state));
    }

    /**
     * Handle Google's redirect back to the store.
     */
    public function callbackAction()
    {
        $helper  = $this->_helper();
        $session = $this->_customerSession();

        if (!$helper->isEnabled() || !$helper->isGoogleEnabled()) {
            $session->addError($helper->__('Google Sign-In is not available.'));
            return $this->_redirect('customer/account/login');
        }

        $request      = $this->getRequest();
        $expectedState = $session->getGoogleOauthState();
        $session->unsGoogleOauthState();

        // Surface a Google-side error (e.g. user clicked "cancel").
        if ($request->getParam('error')) {
            $session->addError($helper->__('Google Sign-In was cancelled.'));
            return $this->_redirect('customer/account/login');
        }

        $code  = $request->getParam('code');
        $state = $request->getParam('state');

        if (!$code || !$state || !$expectedState || !hash_equals($expectedState, $state)) {
            $session->addError($helper->__('Could not verify the Google Sign-In request. Please try again.'));
            return $this->_redirect('customer/account/login');
        }

        try {
            $accessToken = $helper->exchangeGoogleCode($code);
            $info        = $helper->getGoogleUserInfo($accessToken);

            $emailVerified = isset($info['email_verified'])
                && filter_var($info['email_verified'], FILTER_VALIDATE_BOOLEAN);

            if (!$emailVerified) {
                $session->addError($helper->__('Your Google email address is not verified.'));
                return $this->_redirect('customer/account/login');
            }

            $email     = $info['email'];
            $firstname = isset($info['given_name']) ? $info['given_name'] : '';
            $lastname  = isset($info['family_name']) ? $info['family_name'] : '';

            $customer = $helper->getOrCreateCustomer($email, $firstname, $lastname);

            $session->setCustomerAsLoggedIn($customer);
            $session->renewSession();
            $session->addSuccess($helper->__('You are now signed in.'));

            $redirectUrl = $session->getBeforeAuthUrl(true);
            if ($redirectUrl) {
                return $this->getResponse()->setRedirect($redirectUrl);
            }
            return $this->_redirect('customer/account');
        } catch (Mage_Core_Exception $e) {
            $session->addError($e->getMessage());
            return $this->_redirect('customer/account/login');
        } catch (Exception $e) {
            Mage::logException($e);
            $session->addError($helper->__('We could not complete Google Sign-In. Please try again later.'));
            return $this->_redirect('customer/account/login');
        }
    }
}
