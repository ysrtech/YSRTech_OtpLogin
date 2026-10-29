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
- Brute-force and inbox-flooding limits: a set number of wrong guesses per code
  (then it is burned), and a cap on codes requested per email and per IP address
  within a time window. Requests for unknown addresses count too, so the limits
  never reveal which addresses have an account.
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
| Create Account Form | The store's own Create Account page (default) or the popup. See *Store pages*. |
| Require Sign-in at Checkout | Yes: guests must sign in with a code before placing an order on OneStepCheckout (the form is replaced by an inline sign-in panel and a guest order submission is rejected server-side). No (default): guest checkout stays available; OneStepCheckout attaches an order placed with a registered email to that account. |
| OTP Type | Numeric / Alphabetic / Alphanumeric. |
| OTP Length | Number of characters in the code. |
| Expiry Time | Seconds the code stays valid. |
| Wrong Guesses per Code | Default 5. After this many wrong codes the code stops working and a new one must be requested. |
| Codes per Email Address | Default 5. Most codes (resends included) one email address can request within the window. |
| Codes per IP Address | Default 20. Most codes one visitor IP can request, for any addresses, within the window. Behind Cloudflare the `CF-Connecting-IP` header is used. |
| Request Limit Window (minutes) | Default 15. |
| Email Sender | Which store email identity sends the OTP. |
| Email Template | The transactional template used (defaults to the bundled one). |

## Store pages

With the module enabled, the theme's own pages are adapted in the browser (`otplogin.js`), so
they keep the theme's look and fields:

- **Login page** (and every My Account page, which redirects there): the password field, the
  "forgot password" link and "remember me" are removed; the button emails a code, and after the
  code the customer returns to the page they wanted (the page's `referer`).
- **Create Account page** (*Create Account Form* = the store's own page, the default): the
  password fields are removed and the button emails a confirmation code. Once the code is
  entered, the page's own form is submitted with all its fields (address, VAT, ...), so the
  theme's and other modules' registration handling runs unchanged. Server side, an account is
  only created for an address confirmed with a code in that session; the customer never sets a
  password (a random one is filled in). A Cloudflare Turnstile token in the form is refreshed
  before submitting, since finding the code can take longer than the token lasts.
- *Create Account Form* = popup: the earlier name-and-email popup instead.

## How it works

1. The customer clicks **Log In** (or **Create an Account**). The theme link is
   intercepted and a popup opens.
2. They enter their email (plus their name for registration) and request an OTP.
   - `otplogin/account/otploginpost` validates, generates and emails the code.
3. They enter the code in the verification popup.
   - `otplogin/account/otppost` checks the code against the email's latest code
     (hash, expiry and remaining guesses), then logs them in or creates the account.
     Each guess is counted before it is compared, by one conditional `UPDATE` that
     refuses at the limit, so guesses fired in parallel cannot get past it.
4. `otplogin/account/resendotp` issues a fresh code if needed (same request limits).
5. A daily cron job (`ysrtech_otplogin_cleanup`) deletes code rows older than a week.

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
