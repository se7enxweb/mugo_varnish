Mugo Varnish changelog
======================

The changes of each release, newest first.

1.0.0 (2026-10-21)
------------------

The first numbered release of this fork. It holds the extension as Mugo Web and its contributors
left it in 2018, plus:

- Updated: The Varnish tab of the top menu shows the lightning icon in the admin4 design
  (`Icon=lightning` in [Topmenu_varnish] of menu.ini.append.php). Older admin designs ignore the
  setting.
- Updated: The package is published as se7enxweb/mugo_varnish. composer.json requires PHP 8.0,
  the curl extension and the Exponential legacy installer, states the license as GPL-2.0-only,
  links the issues and the source, and lists the original authors and 7x as maintainer.
- Added: ezinfo.php, so the about page shows the release, the copyright, the license and the
  website; extension.xml states the same.
- Added: The text of the GNU GPL v2 as LICENSE.
- Updated: The README is rewritten for new users: how purging works, installation with Composer
  and by hand, the Varnish configuration, every setting with an example, purging by hand, custom
  builders, permissions, the user hash feature, known limits and troubleshooting.
- Added: This changelog.

