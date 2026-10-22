Mugo Varnish for Exponential
============================

Mugo Varnish connects Exponential to a [Varnish](https://varnish-cache.org) HTTP cache in front of
the site. When content is published it works out which pages changed and tells Varnish to drop
them, so visitors get fast cached pages that are never out of date. It also gives administrators a
**Varnish** tab to purge any part of the cache by hand.

It is for sites that already run behind Varnish. The extension does not decide *what* Varnish
caches or for how long (that is the job of your VCL and of the `Cache-Control` headers the site
sends); it only makes sure the right objects are purged.

Contents
--------

- [Features](#features)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Varnish configuration](#varnish-configuration)
- [Configuration](#configuration)
- [Usage](#usage)
- [Permissions](#permissions)
- [Optional: cache variations for signed-in users](#optional-cache-variations-for-signed-in-users)
- [Upgrading](#upgrading)
- [Known limits](#known-limits)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)
- [Credits and history](#credits-and-history)

Features
--------

- **Purge on publish**: every page a publish affects (the node, its translations, its custom URL
  aliases, optionally its system URLs, and the related nodes the content cache manager lists) is
  purged in Varnish at the end of the request.
- **Purge by hand**: the Varnish tab (`/varnish/main`) takes one ban condition per line. In the
  admin4 design the tab shows the lightning icon before its name.
- **Purge everything** when an administrator clears the static cache, or optionally whenever all
  content cache is cleared.
- **Several Varnish servers**: each purge goes to all of them, in parallel with curl's multi
  handler.
- **Two ways to match pages**: by URL (`MugoVarnishPageUrlBuilder`, the default) or by an
  `X-Location-Id` response header (`MugoVarnishLocationIdBuilder`), or by a builder class of your own.
- **Host matching**, a path prefix modifier, a URL rewrite and patterns of URLs to leave alone,
  for multi-site and subfolder setups.
- **A safety limit** on the number of ban requests per publish, and optionally a global purge
  instead when the content cache threshold is exceeded.
- **Optional cache variations for signed-in users** by a hash of their roles.
- A purge log and a curl debug file for troubleshooting.

How it works
------------

Exponential's content cache manager collects the node ids a publish affects and hands them to the
static cache handler. Mugo Varnish registers itself as that handler (`StaticCacheMugoVarnish`):
it turns each node id into ban conditions with the configured builder, collects them during the
request, and a cleanup handler sends them to Varnish when the page is done.

A ban is an HTTP `PURGE` request with the condition in an `X-Ban-Condition` header. For the URL
`/about_us/contact` the default builder sends one condition that matches the page itself and all
its view parameter variations (such as `/about_us/contact/(offset)/10`):

```
obj.http.X-Ban-Url ~ ^/about_us/contact(/?\(.*)?$
```

Requirements
------------

- Exponential 6.x
- PHP 8.0 or later, with the `curl` extension
- Varnish in front of the site, configured to accept `PURGE` requests with an `X-Ban-Condition`
  header from the web server (see below)

Installation
------------

### With Composer

From the root of the Exponential installation:

```bash
composer require se7enxweb/mugo_varnish
```

The Exponential legacy installer places the package in `extension/mugo_varnish`.

### By hand

1. Download the latest release from https://github.com/se7enxweb/mugo_varnish/releases (or
   `git clone https://github.com/se7enxweb/mugo_varnish.git`).
2. Copy the folder to `extension/mugo_varnish`. The folder name must be `mugo_varnish`.

### Activate it

Add the extension to `settings/override/site.ini.append.php`:

```ini
[ExtensionSettings]
ActiveExtensions[]=mugo_varnish
```

Then regenerate the autoloads and clear the caches, from the installation root:

```bash
php bin/php/ezpgenerateautoloads.php -e
php bin/php/ezcache.php --clear-all
```

**Note:** activating the extension switches the static cache on and makes Mugo Varnish its
handler (its `site.ini.append.php` sets `[ContentSettings] StaticCache=enabled` and
`StaticCacheHandler=StaticCacheMugoVarnish`). Nothing is written to disk as static files: the
handler only sends purges.

Then set your Varnish server(s) in `mugo_varnish.ini` (see [Configuration](#configuration)).

Varnish configuration
---------------------

The VCL below is in Varnish 3 syntax, as the extension was written for it. On Varnish 4 and later
the same three parts are needed: answer `PURGE` with `ban()` and a synthetic response in
`vcl_recv`, store the two headers in `vcl_backend_response`, and remove them in `vcl_deliver`.

Allow purges only from the web server:

```
acl purge
{
    "127.0.0.1";
}
```

At the top of `sub vcl_recv`:

```
if( req.request == "PURGE" )
{
    if( !client.ip ~ purge )
    {
        error 405 "Not allowed.";
    }

    if( req.http.X-Ban-Condition )
    {
        ban( req.http.X-Ban-Condition );
        error 200 "Purged: " + req.http.X-Ban-Condition;
    }

    error 500 "Missing X-Ban-Condition header.";
}
```

Store the URL and the host on each cached object, so bans can be checked by the ban lurker. In
`sub vcl_fetch`:

```
set beresp.http.X-Ban-Url = req.url;
set beresp.http.X-Ban-Host = req.http.host;
```

And keep them out of the response, in `sub vcl_deliver`:

```
unset resp.http.X-Ban-Url;
unset resp.http.X-Ban-Host;
```

How long pages are cached is up to you. A simple way is to let Exponential send a
`Cache-Control` header (site.ini):

```ini
[HTTPHeaderSettings]
CustomHeader=enabled
Cache-Control[]
Cache-Control[/]=public, must-revalidate, max-age=600
```

[doc/INSTALL.md](doc/INSTALL.md) holds the original installation notes with an example
`vcl_deliver` that adds hit and miss debug headers.

Configuration
-------------

The defaults are in `extension/mugo_varnish/settings/mugo_varnish.ini`. Override what you need in
`settings/override/mugo_varnish.ini.append.php`. Every setting the extension reads:

```ini
<?php /* #?ini charset="utf-8"?

[VarnishSettings]
# The Varnish servers, as host:port. Each purge goes to all of them.
VarnishServers[]
VarnishServers[]=127.0.0.1:6081
# The curl timeout of a purge request, in seconds.
ConnectionTimeout=1
# The most ban requests a single request sends (0 = no limit); more are dropped with a warning.
MaxBanRequests=1000
# enabled: when site.ini [ContentSettings] CacheThreshold is exceeded, purge everything instead
# of each page.
UseCacheThresholdValue=disabled
# Send the purges to all servers in parallel (curl multi handler).
UseCurlMultiHandler=enabled
# Write every purge to var/log/mugo_varnish_purges.log.
LogPurges=disabled
# Write a debug file about the curl calls (it starts again with each call).
DebugCurl=disabled
# Mix a once-daily timestamp into the user hash (see the user hash feature).
AppendTimestampToUserHash=enabled

[PurgeUrlBuilder]
# MugoVarnishPageUrlBuilder: match pages by URL alias.
# MugoVarnishLocationIdBuilder: match pages by the X-Location-Id header (needs the content/view
# listener below). Or a class of your own implementing MugoVarnishBuilderInterface.
BuilderClass=MugoVarnishPageUrlBuilder
# Purge the system URLs (/content/view/full/<node id>) too.
PurgeSystemURL=disabled
# The PathPrefix of the public siteaccess, removed from each URL before it is purged.
PathPrefixModifier=
# Apply Exponential's URI transformation (needed when the site lives in a subfolder).
UriTransformation=enabled
# A last rewrite of each URL: preg_replace( ModifierMatch, ModifierReplace, url ).
#ModifierMatch=/^\/backend(.*)/
#ModifierReplace=$1
# URLs never purged, as regular expressions on the relative URL.
OmitUrlPatterns[]
#OmitUrlPatterns[]=/^\/Media\//
# Limit bans to a host: None, or SiteURL (adds "&& obj.http.X-Ban-Host ~ <SiteURL>").
HostMatching=None

*/ ?>
```

The listeners in the extension's `site.ini.append.php` are all off by default. Switch on the ones
you need in `settings/override/site.ini.append.php`:

```ini
[Event]
# Send an X-Location-Id header with each node view (for MugoVarnishLocationIdBuilder).
Listeners[]=content/view@MugoVarnishEvents::addXLocationIdHeader
# Purge everything when all content cache is cleared from the administration.
Listeners[]=content/cache/all@MugoVarnishEvents::purgeAll
```

Debug messages use the debug condition `mugovarnish-general` (debug.ini).

Usage
-----

### Purge on publish

Nothing to do: publish content and its pages are purged. Turn on
`LogPurges` to see what was sent.

### Purge by hand

1. Open the **Varnish** tab of the top menu (`/varnish/main`).
2. Enter one ban condition per line, for example:
   ```
   obj.http.X-Ban-Url ~ ^/.*
   obj.http.X-Ban-Url ~ ^/Blog/.* && obj.http.X-Ban-Host ~ www\.example\.com
   ```
3. Submit. The page lists the conditions that were sent.

### Purge everything

**Setup → Cache management → Clear static cache** sends a ban for all objects, as does the
`content/cache/all` listener when it is enabled.

### See the current bans

`scripts/get_ban_list.php` asks Varnish's management port (127.0.0.1:6082, set in the script) for
its ban list. Run it from the installation root:

```bash
php extension/mugo_varnish/scripts/get_ban_list.php
```

### A builder of your own

```php
class MySiteVarnishBuilder extends MugoVarnishPageUrlBuilder
{
    public function buildConditionForNodeIdCache( $nodeId )
    {
        // Purge the node's pages and every list that shows it.
        $conditions = parent::buildConditionForNodeIdCache( $nodeId );
        $conditions[] = 'obj.http.X-Ban-Url ~ ^/news';
        return $conditions;
    }
}
```

Set `BuilderClass=MySiteVarnishBuilder` and regenerate the autoloads.

Permissions
-----------

The Varnish tab and `/varnish/main` need the policy **varnish/manage**. Purge on publish needs no
policy: it runs for whoever publishes.

Optional: cache variations for signed-in users
----------------------------------------------

Varnish can keep a separate copy of a page for each set of roles, so signed-in users get cached
pages too. Two parts are needed:

1. A VCL that varies the cache by the `vuserhash` cookie; an example is in
   [doc/examples/userhash.vcl](doc/examples/userhash.vcl) (it needs libvmod-header).
2. The listeners that set the cookie, in site.ini:
   ```ini
   [Event]
   Listeners[]=response/preoutput@MugoVarnishEvents::preoutput
   Listeners[]=session/regenerate@MugoVarnishEvents::regenerateSession
   ```

The hash is built from the user's roles and role limitations (and a daily timestamp with
`AppendTimestampToUserHash`). **It is not a security boundary:** the cookie comes from the
browser, so use this only for pages whose content is not sensitive.

Upgrading
---------

1. Replace the extension's folder with the new release (`composer update se7enxweb/mugo_varnish`,
   or copy the new files over the old ones).
2. Regenerate the autoloads and clear the caches (see [Activate it](#activate-it)).
3. Read [CHANGELOG.md](CHANGELOG.md).

Known limits
------------

- The VCL examples are in Varnish 3 syntax.
- `HostMatching=RelatedSiteAccessList` is not implemented; use `SiteURL` or `None`.
- Activating the extension enables the static cache setting, which other static cache handlers
  also use; only one handler can be active.
- The purge page sends what it is given: a wrong condition can empty the whole cache.
- `scripts/get_ban_list.php` has its host and port in the code and the management port must be
  open without a secret.

Troubleshooting
---------------

- **Nothing is purged.** Turn on `LogPurges` and check `var/log/mugo_varnish_purges.log`; check
  `VarnishServers` and that the web server's address is in the VCL's `purge` ACL. Turn on
  `DebugCurl` to see the responses.
- **"Not allowed." (405) from Varnish.** The web server's address is not in the `purge` ACL.
- **"Missing X-Ban-Condition header." (500).** Something other than this extension sent the
  PURGE, or a proxy dropped the header.
- **Pages of one site are purged on another.** Set `HostMatching=SiteURL`.
- **The site is in a subfolder or uses a path prefix.** Check `UriTransformation` and
  `PathPrefixModifier`.
- **"Maximal ban requests limit exceeded" in the debug output.** Raise `MaxBanRequests` or enable
  `UseCacheThresholdValue`.
- **The tab has no icon.** Icons are drawn by the admin4 design; older admin designs show the name only.

Bug reports: https://github.com/se7enxweb/mugo_varnish/issues

Contributing
------------

Pull requests are welcome at https://github.com/se7enxweb/mugo_varnish. Ideas for further work
are in [doc/TODO](doc/TODO).

License
-------

Mugo Varnish is licensed under the GNU General Public License v2.0; see [LICENSE](LICENSE).

Credits and history
-------------------

Philipp Kamps at Mugo Web (https://www.mugo.ca) wrote Mugo Varnish in 2012 for eZ Publish, the
content management system that preceded Exponential; it was published as `mugoweb/mugo_varnish`
(https://github.com/mugoweb/mugo_varnish). Peter Keung, Guillaume Lecerf, Yann Schepens, Luca
Realdi (the customizable builder) and OpenContent (the cache threshold) contributed. 7x maintains
this fork for Exponential.

- Copyright (C) 2012 - 2018 Mugo Web
- Copyright (C) 1998 - 2026 7x
