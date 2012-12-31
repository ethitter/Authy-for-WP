=== Authy for WordPress ===
Contributors: ethitter
Tags: authentication, authy, two factor, security, login, authenticate
Requires at least: 3.5
Tested up to: 3.5
Stable tag: 0.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add Authy two-factor authentication to WordPress. Users opt in for an added level of security that relies on random codes from their mobile devices.

== Description ==
Enable the plugin, enter your [Authy](http://www.authy.com/) API keys, and your users can enable Authy on their accounts.

Once users configure Authy through their WordPress user profiles, any login attempts will require an Authy token in addition to the account username and password.

For users with mobile devices that don't support the Authy app, they can receive their tokens via SMS.

For convenience, especially in a network instance, API keys can be set in `wp-config.php`.

Plugin development is found at https://github.com/ethitter/Authy-for-WP.

== Installation ==

1. Install the plugin either via your site's dashboard, or by downloading the plugin from WordPress.org and uploading the files to your server.
2. Activate plugin through the WordPress Plugins menu.
3. Navigate to **Settings > Authy for WP** to enter your Authy API keys, or set your API keys in `wp-config.php` as described in the FAQ.

== Frequently Asked Questions ==

= How can a user disable Authy after enabling it? =
The user should return to his or her WordPress profile screen and manage connections under the section *Authy for WordPress*.

= What if a user loses the mobile device? =
Any administrator (anyone with the `create_users` capability, actually) can disable Authy on a given user account by navigating to that user's WordPress account profile, and following the instructions under *Authy for WordPress*.

= Can I limit the user roles able to use Authy for WordPress? =
The allowed user roles can be set on the plugin settings page.

= How do I set the API keys in wp-config.php? =
In a variety of situations, setting the API keys via the plugin's settings page can be undesirable. For example, when network-activating *Authy for WordPress* in a WordPress Multisite (Network) setup. Recognizing this, API keys can be set in `wp-config.php`.

To take advantage of this option, add the following entries to your site's `wp-config.php` before the `/* That's all, stop editing! Happy blogging. */` line:

* `define( 'AUTHY_API_KEY_PRODUCTION', '' );`
* `define( 'AUTHY_API_KEY_DEVELOPMENT', '' );`

Fill in each empty argument with the corresponding API key and *Authy for WordPress* will always use these settings.

== Screenshots ==
1. Authy token field added to the WordPress login form.
2. Users manage their individual Authy settings through their WordPress profiles.

== Changelog ==

= 0.3 =
* Allow administrators to control which user roles can be used with *Authy for WordPress*.
* Enhance connection setup experience by adding autocomplete to the *Country* field.
* Specify API keys in `wp-config.php` rather than via the plugin settings page.

= 0.2 =
* Receive tokens via SMS if the site's Authy account supports it. Requires at least the [free starter plan](http://www.authy.com/pricing).

= 0.1 =
* Initial public release.

== Upgrade Notice ==

= 0.3 =
Restrict the user roles able to utilize *Authy for WordPress* and allow API keys to be specified in `wp-config.php`.

= 0.2 =
Support users with mobile devices that don't support the Authy app by letting them receive keys via SMS (text message).