# Gravity Forms Stripe Payment Add-On
http://wordpress.org/extend/plugins/gravity-forms-stripe/

[Stripe](https://stripe.com) allows you to process credit cards directly on your site, securely and easily, without having to deal with merchant accounts, PCI-compliance, or PayPal.

This [Gravity Forms](http://naomicbush.com/getgravityforms) add-on integrates Stripe with your forms (adapted from the Gravity Forms Authorize.net Add-On) using the provided [Stripe.js](https://stripe.com/docs/stripe.js) to make sure sensitive card information never hits your server.

## Features
### Current
* One-time payments
* Recurring/Subscription Payments

### Up Next
* Receipts
* [Make a request](http://naomicbush.com/gravity-forms-stripe-add-on/)

## Requirements
* WordPress 3.3.1
* PHP 5.3 (although it worked for me when initially tested on 5.2 server)
* Gravity Forms 1.6.3.3 - [Grab a license](http://naomicbush.com/getgravityforms "purchase Gravity Forms!") if you don't already have one
* [Stripe](https://stripe.com) account

## Support
* Please note that this plugin has not been extensively tested so use at your own risk - I needed it for a few projects and it works for me.
* Feel free to open an issue or pull request - no guarantees, though
* If you want to be sure that I see & respond to your request or issue, I consider [donations](http://naomicbush.com) a fair exchange or you can gift me something from my [wishlist](http://amzn.com/w/AHME2QWE75ES) :-)

## Current Limitations
* Subscription product can be the *only* product on the form
* When canceling a subscription through the Gravity Forms entry detail screen, the subscription will remain active until the end of the period, however it will reflect in WordPress as 'canceled'
* Cannot have Stripe Add-On "activated" at the same time as Authorize.Net or PayPal Pro Add-Ons
* Can only handle subscription status updates from Stripe when customer only has 'one' active subscription

## Known Issues
https://github.com/naomicbush/Gravity-Forms-Stripe/issues

## Installation

1. Upload `gravity-forms-stripe` to the `/wp-content/plugins/` directory
2. Make sure that Gravity Forms is activated
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Add your Stripe API keys to the Stripe tab on the Settings page (Forms->Settings).
5. If using Stripe subscriptions, follow the instructions to setup your Stripe webhooks and check the box once you are done.
6. Create a form, adding at least one product field along with the new 'Credit Card' field that appears under 'Pricing Fields.' Keep in mind that Stripe accepts a minimum charge of $0.50 - this means that the total amount of your form must be at least $0.50.
7. Under Forms->Stripe, add a Stripe feed for your new form.

## Changelog
### 0.2 (beta)
* Process subscriptions/recurring payments
* Create a new customer in Stripe for all transactions

### 0.1.3 (April 19, 2012)
* Fix credit card field conflict with other GF payment add-ons
* Load Stripe JS only when form with a credit card field *and* Stripe feed is loaded
* Fix removal of credit card expiration date from information sent to server
* Add validation check for cardholder name and address
* Don't process payment if total is less than $0.50

### 0.1.2
* Fix error handling

### 0.1.1
* Fix "Class 'Stripe' Not Found" error

### 0.1 (April 3, 2012)
* First release

## License
This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program; if not, write to:

Free Software Foundation, Inc. 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.