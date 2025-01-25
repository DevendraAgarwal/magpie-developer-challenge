<?php

namespace App;

require 'vendor/autoload.php';
require 'Product.php';

use Symfony\Component\DomCrawler\Crawler;

class Scrape
{
    private const URL = 'https://www.magpiehq.com/developer-challenge/smartphones/?page=';
    private const CURRENCY_SYMBOL = '£';
    private const AVAILABILITY_IN_STOCK = 'In Stock';
    private const AVAILABILITY_STR_TEST = 'Availability:';
    private const SHIPPING_DELIVERY_TEST = 'delivery';
    private const SHIPPING_FREE_TEST = 'Free Shipping';

    private const UNITS_MB = [
        'PB' => 1000 * 1000 * 1000, // Maybe one day ;)
        'TB' => 1000 * 1000,
        'GB' => 1000,
        'MB' => 1,
        'KB' => 1 / 1000
    ];

    private const DATES_SHORT = [
        "Jan",
        "Feb",
        "Mar",
        "Apr",
        "May",
        "Jun",
        "Jul",
        "Aug",
        "Sep",
        "Oct",
        "Nov",
        "Dec"
    ];

    private const DATE_OUTPUT_FORMAT = "Y-m-d";

    private array $products = [];
    
    /**
     * run
     *
     * @return void
     */
    public function run(): void
    {
        $this->products = [];

        //Crawling the first page to get the number of pages
        $firstpage = ScrapeHelper::fetchDocument(self::URL . '1');
        $num_pages = $this->getNumPages($firstpage);

        $this->crawlPage($firstpage);

        //Crawling the rest of the pages
        $urls = [];
        for ($page = 2; $page <= $num_pages; ++$page) {
            $urls[] = self::URL . $page;
        }

        //Fetching all pages asynchronously
        $allPages = ScrapeHelper::fetchDocuments($urls);
        foreach ($allPages as $page) {
            $this->crawlPage($page);
        }

        //Removing duplicates
        $this->removeDuplicates();

        foreach ($this->products as $product) {
            echo "   " . $product->title . " ($product->colour)" . "\n";
        }

        file_put_contents('output.json', json_encode($this->products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function getNumPages(Crawler $document): int
    {
        $matches = [];
        if (preg_match("#Page \d+ of (\d+)#", $document->text(), $matches)) {
            return intval($matches[1]);
        }

        return 1;
    }
    
    /**
     * crawlPage
     *
     * @param  mixed $document
     * @return void
     */
    private function crawlPage(Crawler $document): void
    {
        $document->filter('.product')->each(function (Crawler $node, $i) {
            $title = "";
            if (!$this->getProductTitle($node, $title)) {
                echo "Failed to get product title\n";
                return;
            }

            $price = 0.0;
            if (!$this->getProductPrice($node, $price)) {
                echo "Failed to get product price\n";
                return;
            }

            $imageUrl = "N/A";
            if (!$this->getProductImageUrl($node, $imageUrl)) {
                echo "Failed to get product image url\n";
            }

            $capacity = 0;
            if (!$this->getProductCapacity($title, $capacity)) {
                echo "Failed to get product capacity\n";
                return;
            }

            $colours = [];
            if (!$this->getProductColours($node, $colours)) {
                echo "Failed to get product colours\n";
                return;
            }

            $isAvailable = false;
            $availabilityText = [];
            if (!$this->getProductAvailability($node, $isAvailable, $availabilityText)) {
                echo "Failed to get product availability\n";
            }

            $shippingText = "";
            $this->getProductShippingText($node, $shippingText);

            $shippingDate = "";
            $this->getProductShippingDate($node, $shippingDate);

            foreach ($colours as $colour) {
                $product = new Product();
                $product->title = $title;
                $product->price = $this->parsePrice($price);
                $product->imageUrl = $imageUrl;
                $product->capacityMB = $capacity;
                $product->colour = $colour;
                $product->isAvailable = $isAvailable;
                $product->availabilityText = $availabilityText;
                $product->shippingText = $shippingText;
                $product->shippingDate = $shippingDate;

                $this->products[] = $product;
            }
        });
    }
    
    /**
     * getProductTitle
     *
     * @param  mixed $node
     * @param  mixed $title
     * @return bool
     */
    private function getProductTitle($node, &$title): bool
    {
        $filtered = $node->filter('h3');
        $success = $filtered->count() >= 0;

        if ($success) {
            $title = $filtered->text();
        }

        return $success;
    }
    
    /**
     * getProductPrice
     *
     * @param  mixed $node
     * @param  mixed $price
     * @return bool
     */
    private function getProductPrice($node, &$price): bool
    {
        $filtered = $node->filter('div div')->reduce(function (Crawler $price_node, $j): bool {
            return str_contains($price_node->text(), self::CURRENCY_SYMBOL);
        });

        $success = $filtered->count() >= 0;

        if ($success) {
            $price = $filtered->last()->text();
        }

        return $success;
    }
    
    /**
     * getProductImageUrl
     *
     * @param  mixed $node
     * @param  mixed $imageUrl
     * @return bool
     */
    private function getProductImageUrl($node, &$imageUrl): bool
    {
        $filtered = $node->filter('img');
        $success = $filtered->count() >= 0;

        if ($success) {
            $src = $filtered->first()->attr('src');
            $imageUrl = ScrapeHelper::convertRelativeUrlToAbsolute($src, self::URL);
        }

        return $success;
    }
    
    /**
     * getProductCapacity
     *
     * @param  mixed $title
     * @param  mixed $capacity
     * @return bool
     */
    private function getProductCapacity($title, &$capacity): bool
    {
        $capacity_strings = implode('|', array_keys(self::UNITS_MB));
        $matches = [];

        if (preg_match("#(\d+)(\s+)?($capacity_strings)#", $title, $matches)) {
            $raw_capacity = $matches[1];
            $unit = $matches[3];
            $capacity = self::UNITS_MB[$unit] * $raw_capacity;

            return true;
        }

        return false;
    }
    
    /**
     * getProductColours
     *
     * @param  mixed $node
     * @param  mixed $colours
     * @return bool
     */
    private function getProductColours($node, &$colours): bool
    {
        $colours = $node->filter('span[data-colour]')->each(function (Crawler $col_node, $i): string {
            return strtolower($col_node->attr('data-colour'));
        });

        return count($colours) >= 0;
    }
    
    /**
     * getProductAvailability
     *
     * @param  mixed $node
     * @param  mixed $isAvailable
     * @param  mixed $availabilityText
     * @return bool
     */
    private function getProductAvailability($node, &$isAvailable, &$availabilityText): bool
    {
        $filtered = $node->filter('div div')->reduce(function (Crawler $avail_node, $j): bool {
            return str_contains($avail_node->text(), self::AVAILABILITY_STR_TEST);
        });

        if ($filtered->count() < 0) {
            return false;
        }

        $txt = $filtered->last()->text();
        $isAvailable = str_contains($txt, self::AVAILABILITY_IN_STOCK);
        $availabilityText = str_replace(self::AVAILABILITY_STR_TEST . ' ', '', $txt);

        return true;
    }
    
    /**
     * getProductShippingText
     *
     * @param  mixed $node
     * @param  mixed $shippingText
     * @return bool
     */
    private function getProductShippingText($node, &$shippingText): bool
    {
        $txt = $node->filter('div div')->last()->text();

        if (
            str_contains($txt, self::SHIPPING_DELIVERY_TEST) ||
            str_contains($txt, self::SHIPPING_FREE_TEST) ||
            preg_match("#[0-9]+#", $txt)
        ) {
            $shippingText = $txt;
            return true;
        }

        return false;
    }
    
    /**
     * get Product Shipping Date
     *
     * @param  mixed $node
     * @param  mixed $shippingDate
     * @return bool
     */
    private function getProductShippingDate($node, &$shippingDate): bool
    {
        $shippingText = "";
        if (!$this->getProductShippingText($node, $shippingText)) {
            return false;
        }

        $date_strings = implode('|', self::DATES_SHORT);
        $matches = [];
        $date_info = date_parse($shippingText);

        if (count($date_info['errors']) <= 0) {
            $timestamp = mktime(0, 0, 0, $date_info['month'], $date_info['day'], $date_info['year']);
            $shippingDate = date(self::DATE_OUTPUT_FORMAT, $timestamp);
            return true;
        }

        if (preg_match("#\d(.+)($date_strings)(.+)(\d+)#", $shippingText, $matches)) {
            $date_info = date_parse($matches[0]);

            if (count($date_info['errors']) <= 0) {
                $timestamp = mktime(0, 0, 0, $date_info['month'], $date_info['day'], $date_info['year']);
                $shippingDate = date(self::DATE_OUTPUT_FORMAT, $timestamp);
                return true;
            }
        }

        return false;
    }
    
    /**
     * Parse Price
     *
     * @param  mixed $price_str
     * @return float
     */
    private function parsePrice($price_str): float
    {
        return doubleval(str_replace(self::CURRENCY_SYMBOL, '', $price_str));
    }
    
    /**
     * removeDuplicates
     *
     * @return void
     */
    private function removeDuplicates(): void
    {
        $this->products = array_filter($this->products, function ($product) use (&$num_removed) {
            foreach ($this->products as &$other) {
                if ($product != $other && $product->equals($other)) {
                    return false;
                }
            }
            return true;
        });

        $this->products = array_values($this->products);
    }
}

$scrape = new Scrape();
$scrape->run();
