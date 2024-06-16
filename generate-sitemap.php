<?php

// This script crawls a domain and creates a sitemap based on pages which are allowed to be crawled.
// It uses the META robots directive of "noindex/nofollow", to make a determination of what to crawl and save.
// It also respects the robots.txt file.
// It CANNOT find orphan pages, so all pages must be linked to at some level from a "dofollow" page in order to make it to the sitemap.
//
// This script uses JSON-LD structure of dates of "datePublished" (https://schema.org/datePublished) and "dateModified" (https://schema.org/dateModified) within each page to determine the date.
// An example of this is available at: https://www.makoboard.com/blog/visual-dashboards/
// If neither dates are available it leaves the field empty.
//
// Once completed it saves the sitemap to https://www.example.com/sitemap.xml - which is already registered inside Google search console

//Cron JOB: (Recommended to run this once a day for active site or once a week)

//## Sitemap Update
//0 2 * * * php /root/sitemap/generate-sitemap.php >/dev/null 2>&1

$baseUrl = 'https://www.example.com/'; // update this to your domain
$sitemapPath = '/var/www/example.com/public_html/sitemap.xml'; // update this to your sitemap location (absolute server location - needs to be writable)

////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
// No further modification is needed

$GLOBALS['REAL_baseUrl'] = $baseUrl;

$visitedUrls = [];

$rules = fetchAndParseRobotsTxt($baseUrl);
$urls = crawlSite($baseUrl, $visitedUrls, $rules);
generateSitemap($urls, $sitemapPath);

echo "[L-34]\nSitemap generated at $sitemapPath\nURL: " . $GLOBALS['REAL_baseUrl'] . "sitemap.xml\n\n";

function fetchUrl($url) {

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'); //Good idea to update it to latest browser
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

	    if ($httpCode == 404) {
	        return null;
	    }

    return $response;

}

function fetchAndParseRobotsTxt($baseUrl) {

    $robotsTxtUrl = $baseUrl . 'robots.txt';
    $robotsTxtContent = fetchUrl($robotsTxtUrl);

	    if ($robotsTxtContent === null) {
	        echo "\033[38;5;196m[L-59] Failed to fetch robots.txt.\033[0m\n--\n\n";
	        exit;
	    }

    return parseRobotsTxt($robotsTxtContent);

}

function parseRobotsTxt($content) {

    $rules = [];
    $lines = explode("\n", $content);
    $currentUserAgent = '*';

	    foreach ($lines as $line) {
	        $line = trim($line);

	        if (empty($line) || strpos($line, '#') === 0) {
	            continue;
	        }

	        list($directive, $value) = array_map('trim', explode(':', $line, 2));
	        if (strcasecmp($directive, 'User-agent') === 0) {
	            $currentUserAgent = $value;
	        } elseif (strcasecmp($directive, 'Disallow') === 0) {
	            if (!isset($rules[$currentUserAgent])) {
	                $rules[$currentUserAgent] = [];
	            }

	            echo "\033[38;5;185m[L-89] Robots.txt value (" . $currentUserAgent . "): " . $line . "\033[0m\n";

	            $rules[$currentUserAgent][] = $value;
	        }
	    }

    return $rules;

}

function isUrlAllowed($url, $baseUrl, $rules, $userAgent = '*') {

    $relativeUrl = str_replace($baseUrl, '/', $url);

	    // Check rules for the specific user-agent first
	    if (isset($rules[$userAgent])) {
	        foreach ($rules[$userAgent] as $disallow) {
	            if (strpos($relativeUrl, $disallow) === 0) {
	                return false;
	            }
	        }
	    }

	    // Fall back to general rules if no specific rules for the user-agent
	    if (isset($rules['*'])) {
	        foreach ($rules['*'] as $disallow) {
	            if (strpos($relativeUrl, $disallow) === 0) {
	                return false;
	            }
	        }
	    }

    return true;

}

function parseUrls($html, $baseUrl) {

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $hrefs = $xpath->evaluate("/html/body//a");

    $urls = [];

	    foreach ($hrefs as $href) {
	        $url = $href->getAttribute('href');

	        // Ignore mailto: and javascript: links
	        if (strpos($url, 'mailto:') === 0 || strpos($url, 'javascript:') === 0) {
	            continue;
	        }

	        // Handle root-relative URLs
	        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
	            $fullUrl = rtrim($GLOBALS['REAL_baseUrl'], '/') . $url;
	        } elseif (strpos($url, 'http') === 0) {
	            $fullUrl = $url;
	        } else {
	            $fullUrl = rtrim($GLOBALS['REAL_baseUrl'], '/') . '/' . ltrim($url, '/');
	        }

	        if (strpos($fullUrl, $baseUrl) === 0) {
	            $urls[] = $fullUrl;
	        }
	    }

    return array_unique($urls);
}

function shouldCrawl($html) {

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $metas = $dom->getElementsByTagName('meta');

		foreach ($metas as $meta) {
		    if (strtolower($meta->getAttribute('name')) === 'robots') {
		        $content = strtolower($meta->getAttribute('content'));

		        echo "\033[38;5;240m[L-169] robots=[" . $content . "]\033[0m\n";

		        if (strpos($content, 'noindex') !== false || strpos($content, 'nofollow') !== false) {
		            echo "\033[38;5;250m[L-172] no-index/no-follow FOUND\033[0m\n";
		            return false;
		        }

		    }
		}

    return true;

}

function extractDates($html) {

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $scripts = $dom->getElementsByTagName('script');
    $dates = [];

		foreach ($scripts as $script) {
		    if ($script->getAttribute('type') === 'application/ld+json') {
		        $jsonContent = $script->textContent;
		        $jsonData = json_decode($jsonContent, true);

		        if (json_last_error() === JSON_ERROR_NONE && isset($jsonData['@graph'])) {
		            foreach ($jsonData['@graph'] as $entry) {
		                if (isset($entry['@type']) && ($entry['@type'] === 'Article' || $entry['@type'] === 'WebPage')) {
		                    if (isset($entry['dateModified'])) {
		                        $dates['lastmod'] = $entry['dateModified'];
		                    }
		                    if (isset($entry['datePublished'])) {
		                        $dates['published'] = $entry['datePublished'];
		                    }
		                }
		            }
		        }
		    }
		}

    return $dates;

}

function generateSitemap($urls, $sitemapPath) {

    $dom = new DOMDocument('1.0', 'UTF-8');
    $urlset = $dom->createElement('urlset');
    $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $dom->appendChild($urlset);

	    foreach ($urls as $urlData) {
	        $urlElement = $dom->createElement('url');
	        $loc = $dom->createElement('loc', htmlspecialchars($urlData['url']));
	        $urlElement->appendChild($loc);
	        if ($urlData['lastmod']) {
	            $lastmod = $dom->createElement('lastmod', htmlspecialchars(date('c', strtotime($urlData['lastmod']))));
	            $urlElement->appendChild($lastmod);
	        }
	        $urlset->appendChild($urlElement);
	    }

    $dom->formatOutput = true;
    $dom->save($sitemapPath);
}

function crawlSite($baseUrl, &$visitedUrls, $rules) {

    echo "\033[38;5;202m[L-238] crawling=[" . $baseUrl . "]\033[0m\n";

    $html = fetchUrl($baseUrl);

	    if (!$html) {
	        return [];
	    }

    $urls = parseUrls($html, $baseUrl);
    $filteredUrls = [];

	    foreach ($urls as $url) {

		        if (in_array($url, $visitedUrls)) {
		            continue;
		        }

		        // Check if URL is allowed by robots.txt
		        if (!isUrlAllowed($url, $baseUrl, $rules)) {
		            echo "\033[38;5;250m[L-257] Skipping URL=[" . $url . "] due to robots.txt\033[0m\n";
		            continue;
		        }

	        $visitedUrls[] = $url;

	        $pageHtml = fetchUrl($url);

		        if ($pageHtml && shouldCrawl($pageHtml)) {
		            $dates = extractDates($pageHtml);
		            $lastmod = $dates['lastmod'] ?? $dates['published'] ?? null;
		            $filteredUrls[] = ['url' => $url, 'lastmod' => $lastmod];
		            $filteredUrls = array_merge($filteredUrls, crawlSite($url, $visitedUrls, $rules));
		        } else {
		            echo "\033[38;5;250m[L-271] Skipping URL=[" . $url . "] due to noindex/nofollow\033[0m\n";
		        }

	    }

    return array_unique($filteredUrls, SORT_REGULAR);
}

?>
