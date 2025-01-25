<?php

namespace App;

class Product
{
    public string $title;
    public float $price;
    public string $imageUrl;
    public float $capacityMB;
    public string $colour;
    public string $availabilityText;
    public bool $isAvailable;
    public string $shippingText;
    public string $shippingDate;
    
    /**
     * equals
     *
     * @param Product $other
     * @return bool
     */
    public function equals(Product $other) : bool
    {
        return $this->title == $other->title &&
                $this->price == $other->price &&
                $this->capacityMB == $other->capacityMB &&
                $this->colour == $other->colour;
    }
}
