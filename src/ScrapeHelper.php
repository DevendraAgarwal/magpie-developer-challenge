<?php

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeHelper
{
    /**
     * fetchDocument
     *
     * @param  mixed $url
     * @return Crawler
     */
    public static function fetchDocument(string $url): Crawler
    {
        $client = new Client();

        $response = $client->get($url);

        return new Crawler($response->getBody()->getContents(), $url);
    }

    /**
     * fetchDocuments
     *
     * @param  mixed $urls
     * @return array
     */
    public static function fetchDocuments($urls): array
    {
        $client = new Client();
        $promises = [];
        $crawlers = [];

        foreach ($urls as $url) {
            $promises[$url] = $client->getAsync($url);
        }

        $responses = Promise\Utils::settle($promises)->wait();

        foreach ($responses as $url => $response) {
            $crawlers[] = new Crawler($response['value']->getBody()->getContents(), $url);
        }

        return $crawlers;
    }

    /**
     * convertRelativeUrlToAbsolute
     *
     * @param  mixed $rel
     * @param  mixed $base
     * @return string
     */
    public static function convertRelativeUrlToAbsolute($rel, $base): string
    {
        /* parse base URL and convert to local variables:
        $scheme, $host, $path */
        extract(parse_url($base));

        /* remove non-directory element from path */
        $path = preg_replace('#/[^/]*$#', '', $path);

        /* destroy path if relative url points to root */
        if ($rel[0] == '/')
            $path = '';

        /* dirty absolute URL */
        $abs = $host . $path . '/' . $rel;

        /* replace '//' or '/./' or '/foo/../' with '/' */
        $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
        for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}

        /* absolute URL is ready! */
        return ($scheme . '://' . $abs);
    }
}
