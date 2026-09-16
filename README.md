# YSRTech_OtpLogin

Email-based **One-Time-Password (OTP) login & registration** for **OpenMage LTS / Magento 1.x**.

This is a ground-up port of a Magento 2 SMS OTP module. The SMS / Twilio
delivery, the international telephone input and the `mobile_number` customer
attribute have all been removed. OTP codes are now generated server-side and
delivered by a standard transactional email.

## Features

- Sign in with an emailed OTP (no password needed).
- Optional account creation via OTP verification.
- Passwordless only: the standard email + password form is replaced everywhere, including OneStepCheckout.
- Optional "Require Sign-in at Checkout" gate for OneStepCheckout (off by default, so guest checkout keeps working).
- Configurable OTP type (numeric / alphabetic / alphanumeric), length and expiry.
- OTP codes are stored **hashed** (SHA-256), single-use, and tied to the email.
- Uses OpenMage's transactional email system (sender identity + template are
  configurable in the admin).

## Requirements

- OpenMage LTS (19.x / 20.x) or Magento CE 1.9.x.
- A working transactional email configuration.

## Installation

1. Copy the contents of this package into your OpenMage root so that the
   folders merge:
   ```
   app/etc/modules/YSRTech_OtpLogin.xml
   app/code/local/YSRTech/OtpLogin/...
   app/design/frontend/base/default/layout/ysrtech_otplogin.xml
   app/design/frontend/base/default/template/ysrtech/otplogin/*.phtml
   app/locale/en_US/template/email/ysrtech_otplogin_otp.html
   app/locale/en_US/YSRTech_OtpLogin.csv
   skin/frontend/base/default/ysrtech_otplogin/...
   ```
2. Flush the cache: **System → Cache Management → Flush Magento Cache**
   (the install script creating the `ysrtech_email_otp` table runs automatically
   on the next request).
3. Configure under **System → Configuration → YSRTech → Email OTP Login**.

## Configuration

**System → Configuration → YSRTech → Email OTP Login**

| Setting | Description |
|---------|-------------|
| Enabled | Turns the popups and routes on/off. |
| Allow Registration via OTP | If yes, unknown emails can create an account after verifying an OTP. |
| Require Sign-in at Checkout | Yes: guests must sign in with a code before placing an order on OneStepCheckout (the form is replaced by an inline sign-in panel and a guest order submission is rejected server-side). No (default): guest checkout stays available; OneStepCheckout attaches an order placed with a registered email to that account. |
| OTP Type | Numeric / Alphabetic / Alphanumeric. |
| OTP Length | Number of characters in the code. |
| Expiry Time | Seconds the code stays valid. |
| Email Sender | Which store email identity sends the OTP. |
| Email Template | The transactional template used (defaults to the bundled one). |

## How it works

1. The customer clicks **Log In** (or **Create an Account**). The theme link is
   intercepted and a popup opens.
2. They enter their email (plus their name for registration) and request an OTP.
   - `otplogin/account/otploginpost` validates, generates and emails the code.
3. They enter the code in the verification popup.
   - `otplogin/account/otppost` checks the hash + expiry, then logs them in or
     creates the account.
4. `otplogin/account/resendotp` issues a fresh code if needed.

## Theme note

The popups are injected into the `before_body_end` layout block, which exists in
the OpenMage RWD theme. If your theme does not render that block, edit
`app/design/frontend/base/default/layout/ysrtech_otplogin.xml` and change the
reference from `before_body_end` to `content`. The popups stay hidden until
opened, so the placement is purely cosmetic.

## Google Sign-In (optional)

A "Sign in with Google" button can appear in the login and registration popups.
Account matching is **by verified email address only**: Google returns the
verified email, the store finds the matching customer and logs them in, or
creates one if registration is allowed.

**Setup in Google Cloud (one-time):**

1. Create a project at <https://console.cloud.google.com/>.
2. Configure the OAuth consent screen.
3. Create an **OAuth 2.0 Client ID** (type: Web application).
4. Under **Authorized redirect URIs**, add exactly:
   `https://<your-domain>/otplogin/google/callback`
5. Copy the **Client ID** and **Client Secret**.

**Setup in Magento:**

Under **System → Configuration → YSRTech → Email OTP Login → Google Sign-In**,
set *Enable Google Sign-In* to Yes and paste the Client ID and Client Secret
(the secret is stored encrypted). HTTPS is required.

Notes:
- Uses OpenMage's bundled `Varien_Http_Client` — no Composer package needed.
- Only logins where Google reports `email_verified = true` are accepted.
- A CSRF `state` token is generated per attempt and checked on callback.
- If a customer changes their Google email later, they will be treated as a new
  customer (this is the simple email-only matching mode).

## Removed from the original M2 module

- Twilio SDK dependency and all SMS sending.
- `intlTelInput` library, flag images and country configuration.
- The `mobile_number` customer EAV attribute and mobile-based lookups.
