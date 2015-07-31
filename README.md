# MessageBird Magento Plugin

The purpose of this extension is to allow Magento Shop owners to keep their users informed about their orders using the power of MessageBird's SMS platform.

Currently this extension is **under development** and only two events are supported.

1. Send an SMS message when a new order is placed to either Customer, Seller or to both
2. Send an SMS to the customer when the status of the order has changed.

These events can be enabled/disabled and configured in the configuration section of the Magento Shop. General information such as Access Key to the API, Originator and Seller’s numbers can be configured in this section.


## How to install ##

####Make sure that you have a backup of your Magento files and database before doing anything.####

1. Using any FTP client (for a remote server) to move the files to the appropriate destination within your Magento project.
2. Go to Magento's Admin page and make sure you refresh the Configuration cache where modules configuration are stored ( **System >> Cache Management** ). 
3. Go to **System >> Configuration >> Advanced** and make sure that the MessagedBird_SmsConnector module is visible and enabled.
4. Log out and log in again (if you skip this step, the Module’s configuration may not work correctly).


## Configure ##

1. Go to **System >> Configuration >> MessageBird SMS Configuration**
2. Click on 'MessageBird Account Configuration' to expand the view and fill in the required information. If you do not have an API Access Key, go to your MessageBird account and [create one](https://www.messagebird.com/app/en/settings/developers/access). Make sure to add your country code in your Seller's phone number (e.g +31694...).
3. There is a section for each event. To enable them click on the name to expand and set the 'Enabled' field to 'Yes'.
4. We provide some default messages that will be sent out to your customers. You can customize them to your liking. At the moment there 4 variables that can be used (:firstname:, :lastname:, :orderid: and :orderstatus:). The real values will be placed into the messages upon creation.
5. Click on Save Config
