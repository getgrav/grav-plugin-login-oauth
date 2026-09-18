# v1.4.3
## 09/17/2026

1. [](#bugfix)
    * **[security] Accounts created by a social login can no longer be signed into with the normal login form.** Their username and password were both derived from the provider's user id — a public value, for GitHub and Google alike — so anyone who looked up that id could work out the password and log in as them without touching the provider. Social-login accounts now hold a random password nobody is given, existing ones are recognized and blocked, and each is retired to a random password the next time its owner signs in. Thanks to @AlpetGexha
    * **[security] A provider callback that arrives without its anti-forgery `state` value is now rejected.** The value was passed to the OAuth library as `null`, which told the library to skip the check entirely, so a callback with no `state` was accepted — the one case the check exists to catch. Thanks to @AlpetGexha
    * Signing in through a provider no longer depends on the account's stored password, so someone who had changed their password can sign in again.

# v1.4.1
## 05/01/2026

1. [](#improved)
    * Added 1.7|2.0 compatibility flags

# v1.4.0
## 12/06/2017

1. [](#bugfix)
    * Compatibility fixes with Login plugin v2.5.0 - Messages system
1. [](#improved)
    * Updated OAuth library to v0.8.10 [#14](https://github.com/getgrav/grav-plugin-login-oauth/issues/14)

# v1.3.1
## 09/12/2017

1. [](#bugfix)
    * Compatibility fixes with Login plugin v2.4.0 [Login#130](https://github.com/getgrav/grav-plugin-login/issues/130)

# v1.3.0
## 04/7/2017

1. [](#new)
    * Added Blacklist and Whitelist support to Google

# v1.2.0
## 1/18/2017

1. [](#new)
    * Added LinkedIn provider [#11](https://github.com/getgrav/grav-plugin-login-oauth/pull/11)
1. [](#improved)
    * Added Facebook email scope [#10](https://github.com/getgrav/grav-plugin-login-oauth/pull/10)
    * Updated OAuth library to v0.8.9

# v1.1.0
## 09/06/2016

1. [](#improved)
    * Added Romanian and German translations
    * Tidy up spacing
1. [](#bugfix)
    * Fixed Twitter error
    * Fixed a typo

# v1.0.0
## 07/14/2016

1. [](#new)
    * First stable release of the Login with OAuth plugin

# v1.0.0-beta.1
## 05/05/2016

1. [](#new)
    * First beta release of the Login with OAuth plugin
