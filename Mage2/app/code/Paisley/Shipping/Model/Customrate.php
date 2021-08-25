<?php
// CRANE TRUCK MAXIMUM VOLUME CUBIC METERS
const CT_VOLUME = 6;

//CRANE TRUCK MAXIMUM WEIGHT IN KILOGRAMS
const CT_WEIGHT = 6000;

// TIPPER TRUCK MAXIMUM VOLUME CUBIC METERS
const TT_VOLUME = 3;

//TIPPER TRUCK MAXIMUM WEIGHT IN KILOGRAMS
const TT_WEIGHT = 3000;


class Trucks {

    public $trucks;

    public $shippingTotal;

    function __construct(TruckItems $items)
    {

        $this->trucks = array();

        while($item = $items->getNextLargestItemAndAssign())
        {
            $this->findSpaceOnTruck($item);
        }
    }

    public function calculateShippingCosts($rate)
    {

        $this->shippingTotal = 0;

        foreach($this->trucks as $key => $truck)
        {
            $this->shippingTotal += $this->trucks[$key]->calculateShipping($rate);
        }

        return $this->shippingTotal;
    }

    public function add(Truck $truck)
    {
        $this->trucks[] = $truck;
    }

    public function findSpaceOnTruck(TruckItem $item)
    {
        $availableTruck = false;

        foreach($this->trucks as $truck)
        {
            if($item->loadType == null)
            {

                if($truck->type == $item->type || $item->type == 'either_truck')
                {


                    if((($truck->type == 'crane_truck' && $item->type == 'either_truck') ||
                            ($truck->type == 'crane_truck' && $item->type == 'crane_truck')) &&
                        ($item->weight+$truck->weight) <= CT_WEIGHT &&
                        ($item->volume+$truck->volume) <= CT_VOLUME)
                    {
                        $availableTruck = $truck;
                        break;
                    }


                    if((($truck->type == 'tipper_truck' && $item->type == 'either_truck') ||
                            ($truck->type == 'tipper_truck' && $item->type == 'tipper_truck')) &&
                        ($item->weight+$truck->weight) <= TT_WEIGHT &&
                        ($item->volume+$truck->volume) <= TT_VOLUME)
                    {
                        $availableTruck = $truck;
                        break;
                    }
                }
            }
            else
            {
                if($truck->type == $item->type &&
                    $truck->loadType == $item->loadType &&
                    $item->weight+$truck->weight <= TT_WEIGHT &&
                    $item->volume+$truck->volume <= TT_VOLUME)
                {
                    $availableTruck = $truck;
                    break;
                }
            }
        }

        if($availableTruck != false)
        {
            $this->assignSpaceOnTruck($availableTruck, $item);
        }
        else
        {
            $newTruck = new Truck($item);
            $this->add($newTruck);
        }

    }

    public function assignSpaceOnTruck(Truck $truck, TruckItem $item)
    {

        $truck->volume += $item->volume;
        $truck->weight += $item->weight;
    }
}

class Truck {

    public $type;
    public $volume;
    public $weight;
    public $loadType;
    public $shippingCost;

    function __construct(TruckItem $item)
    {
        if($item->type == 'either_truck')
            $this->type = 'tipper_truck';
        else
            $this->type = $item->type;

        $this->volume = $item->volume;
        $this->weight = $item->weight;
        $this->loadType = $item->loadType;

        //print_r($item);
    }

    public function calculateShipping($rate)
    {
        $total = 0;
        if($this->type == 'crane_truck')
            $total += 50;

        if($this->weight <= 200 && $this->volume <= .2)
            $total += ($rate * .5);
        else
            $total += $rate;

        // if($this->type != 'crane_truck'){

        // $totals = Mage::getSingleton('checkout/cart')->getQuote()->getTotals();
        // $subtotal    =    $totals['subtotal']->getData('value');
        //  if($subtotal>=300)
        //      $total = 0;
        // }
        $this->shippingCost = $total;
        return $total;
    }

}

class TruckItems {

    public $items;

    function __construct()
    {
        $this->items = array();
    }

    public function addItem(TruckItem $item)
    {
        $this->items[] = $item;
    }

    public function getNextLargestItemAndAssign()
    {
        $largestVolume = 0;
        $largestItem = false;

        foreach($this->items as $item)
        {
            if($item->volume > $largestVolume && $item->qty > $item->qtyAssigned)
            {
                $largestVolume = $item->volume;
                $largestItem = $item;
            }
        }

        if($largestItem != false)
        {
            $largestItem->qtyAssigned = $largestItem->qtyAssigned + 1;
        }

        return $largestItem;
    }
}

class TruckItem {

    public $type;
    public $volume;
    public $weight;
    public $loadType;
    public $qty;
    public $qtyAssigned;

    function __construct($type, $volume, $weight, $qty = 1, $loadType = null)
    {
        $this->type = $type;
        $this->volume = floatval($volume);
        $this->weight = floatval($weight);
        $this->qty = intval($qty);
        $this->loadType = $loadType;
        $this->qtyAssigned = 0;
    }
}

class Paisley_Shipping_Model_Carrier_Customrate
    extends Mage_Shipping_Model_Carrier_Abstract
    implements Mage_Shipping_Model_Carrier_Interface
{
    protected $_code = 'paisley_customrate';
    protected $_isFixed = true;

    private $tipperTruck;

    private $craneTruck;

    private $eitherTruck;

    private $totalWeight;

    private $totalVolume;

    private $deliveryFees;

    private $truckRequiredOptions;

    private $trucks;

    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        $this->truckRequiredOptions = array();

        $mitrTruckTypeAttribute = Mage::getModel('eav/config')->getAttribute('catalog_product','mitr_truck_required');

        if ($mitrTruckTypeAttribute->usesSource()) {
            $mitrTruckTypeOptions = $mitrTruckTypeAttribute->getSource()->getAllOptions(false);
        }

        foreach($mitrTruckTypeOptions as $option)
            $this->truckRequiredOptions[$option['value']] = $option['label'];



        $this->deliveryFees = array();

        $file = __DIR__.'/shipping_rates.csv';

        $fp = fopen($file, 'r');

        while($row = fgetcsv($fp))
        {
            if($this->deliveryFees[$row[0]]['base_fee'] <=  trim(str_replace('$', '', $row[2])))
            {
                $this->deliveryFees[$row[0]] = array(
                    'base_fee' => trim(str_replace('$', '', $row[2])),
                    'shop'  => trim($row[3])
                );
            }
        }

        if(isset($this->deliveryFees[$request->getDestPostcode()]) && $this->deliveryFees[$request->getDestPostcode()] > 0)
        {
            $items = Mage::getSingleton('checkout/session')->getQuote()->getAllItems();

            $truckItems = new TruckItems();

            foreach($items as $item) {

                $productDetails = Mage::getSingleton('catalog/product')->load($item->getProductId());

                if($this->truckRequiredOptions[$productDetails['mitr_truck_required']] == 'tipper_truck')
                    $newTruckItem = new TruckItem($this->truckRequiredOptions[$productDetails['mitr_truck_required']], $productDetails['mitr_cubic_m3'], $productDetails['weight'], $item->getQty(), $item->getSku());
                else
                    $newTruckItem = new TruckItem($this->truckRequiredOptions[$productDetails['mitr_truck_required']], $productDetails['mitr_cubic_m3'], $productDetails['weight'], $item->getQty());

                $truckItems->addItem($newTruckItem);
                /*
                echo 'ID: '.$item->getProductId().'<br />';
                echo 'Name: '.$item->getName().'<br />';
                echo 'Sku: '.$item->getSku().'<br />';
                echo 'Quantity: '.$item->getQty().'<br />';
                echo 'Price: '.$item->getPrice().'<br />';
                echo 'Truck: '.$this->truckRequiredOptions[$productDetails['mitr_truck_required']].'<br/>';
                echo 'Weight: '.$productDetails['weight'].'<br/>';
                echo "<br />";
                */


            }

            $trucks = new Trucks($truckItems);
            $shippingCost = $trucks->calculateShippingCosts($this->deliveryFees[$request->getDestPostcode()]['base_fee']);

        //    echo 'Shipping costs: '.$shippingCost.'<br/>';

           // print_r($trucks);

            $methodTitle = 'Fixed Price';
        }
        else
        {
            $methodTitle = 'Unfortunately we can not give you a price to that area. Please contact us to get a price.';
            $shippingCost = 0;
        }

        //print_r($this->deliveryFees);

        //print_r($trucks);

        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $result = Mage::getModel('shipping/rate_result');

        $method = Mage::getModel('shipping/rate_result_method');
        $method->setCarrier('paisley_customrate');
        $method->setCarrierTitle('Delivery');
        $method->setMethod('paisley_customrate');
        $method->setMethodTitle($methodTitle);
        $method->setPrice($shippingCost);
        //$method->setCost(2);

        $result->append($method);

        return $result;
    }

    public function getAllowedMethods()
    {
        return array('paisley_customrate' => $this->getConfigData('name'));
    }
}