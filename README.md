# simple-sitemap-generator
This is a simple PHP sitemap generator for static sites that do not have access to a plugin (like WordPress) to generate a sitemap.

It respects the robots.txt file, and crawls your domain with PHP-CURL, and generates your site map for you.

It crawls your site and respects  "noindex" and "nofollow" directives to generator an update site map.

For massive sites you'll probably need to update this to slow down and not hog resources.

I suggest using once a day for active sites or once a week for less active sites to keep your sitemap up to date. Schedule a cronjob for a time when there is the least amount of traffic on your website.
