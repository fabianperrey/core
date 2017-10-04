<?php

namespace Isotope\EventListener;

use Isotope\Interfaces\IsotopePurchasableCollection;
use Isotope\Model\Address;
use Isotope\Model\Config;
use Isotope\Model\ProductCollection\Order;
use Isotope\Model\Shipping\DHLBusiness;
use Petschko\DHL\BusinessShipment;
use Petschko\DHL\Credentials;
use Petschko\DHL\Receiver;
use Petschko\DHL\Sender;
use Petschko\DHL\SendPerson;
use Petschko\DHL\ShipmentDetails;

class DHLBusinessCheckoutListener
{
    public function onPostCheckout(IsotopePurchasableCollection $order)
    {
        $shipping = $order->getShippingMethod();
        $config = $order->getConfig();

        if (!$order instanceof Order || !$shipping instanceof DHLBusiness || !$config instanceof Config) {
            return;
        }

        $credentials = $this->getCredentials($shipping);

        $dhl = new BusinessShipment($credentials);
        $dhl->setShipmentDetails($this->getShipmentDetails($credentials, $shipping));
        $dhl->setSender($this->getSender($config->getOwnerAddress()));
        $dhl->setReceiver($this->getReceiver($order->getBillingAddress()));

        $response = $dhl->createShipment();

        if (false === $response) {
            dump($dhl->getErrors());
            return;
        }

        $data = deserialize($order->shipping_data, true);
        $data['dhl_shipment_number'] = $response->getShipmentNumber();
        $order->shipping_data = $data;
        $order->save();
    }

    private function getCredentials(DHLBusiness $shipping)
    {
        $credentials = new Credentials((bool) $shipping->debug);

        $credentials->setApiUser($shipping->dhl_user);
        $credentials->setApiPassword($shipping->dhl_signature);

        if (!$shipping->debug) {
            $credentials->setEpk($shipping->dhl_epk);
            $credentials->setApiUser($shipping->dhl_app);
            $credentials->setApiPassword($shipping->dhl_token);
        }

        return $credentials;
    }

    private function getSender(Address $address)
    {
        $person = new Sender();

        $this->createPerson($person, $address);

        return $person;
    }

    private function getReceiver(Address $address)
    {
        $person = new Receiver();

        $this->createPerson($person, $address);

        return $person;
    }

    private function createPerson(SendPerson $person, Address $address)
    {
        if ($address->company) {
            $person->setName($address->company);

            if ($address->firstname && $address->lastname) {
                $person->setContactPerson($address->firstname . ' ' . $address->lastname);
            }
        } else {
            $person->setName($address->firstname . ' ' . $address->lastname);
        }

        $person->setFullStreet($address->street_1);
        $person->setAddressAddition($address->street_2);

        $person->setZip($address->postal);
        $person->setCity($address->city);
//        $person->setCountry((string) 'Germany');
        $person->setCountryISOCode($address->country);

        return $person;
    }

    private function getShipmentDetails(Credentials $credentials, DHLBusiness $shippingMethod)
    {
        $accountNumber = sprintf(
            '%s%s01',
            $credentials->getEpk(10),
            substr(1, 2, $shippingMethod->dhl_product)
        );

        $details = new ShipmentDetails($accountNumber);

        $details->setProduct($shippingMethod->dhl_product);

        return $details;
    }
}
