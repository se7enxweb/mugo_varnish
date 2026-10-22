<?php
/**
 * Mugo Varnish extension information, shown on the about page of the administration.
 *
 * @copyright Copyright (C) 2012 - 2018 Mugo Web, 1998 - 2026 7x
 * @license GNU General Public License v2.0
 * @package mugo_varnish
 */

class mugo_varnishInfo
{
    public static function info()
    {
        return array(
            'Name'      => '<a href="https://github.com/se7enxweb/mugo_varnish">Mugo Varnish</a>',
            'Version'   => '1.0.0',
            'Author'    => 'Philipp Kamps, Mugo Web and 7x',
            'Copyright' => 'Copyright &copy; 2012 - 2018 <a href="https://www.mugo.ca">Mugo Web</a> and 1998 - ' . date( 'Y' ) . ' 7x',
            'License'   => 'GNU General Public License v2.0',
            'Info_url'  => 'https://github.com/se7enxweb/mugo_varnish'
        );
    }
}
